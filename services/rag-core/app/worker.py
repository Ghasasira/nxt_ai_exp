from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import logging
import signal
import sys
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import asdict
from pathlib import Path

import httpx
import redis

from app.chunking import ScannedDocumentError, UnsupportedFileContentError, chunk_blocks, extract_blocks_from_bytes
from app.config import Settings, get_settings
from app.embeddings import create_embedder
from app.vector_store import HybridVectorStore

logging.basicConfig(
    level=logging.INFO,
    format='{"ts":"%(asctime)s","level":"%(levelname)s","logger":"%(name)s","msg":"%(message)s"}',
)
logger = logging.getLogger("ingest-worker")


def sign_callback_payload(body_bytes: bytes, secret: str) -> str:
    """Compute HMAC-SHA256 signature header value for callback verification."""
    digest = hmac.new(secret.encode("utf-8"), body_bytes, hashlib.sha256).hexdigest()
    return f"sha256={digest}"


def send_status_callback(settings: Settings, payload: dict) -> None:
    """Send signed ingestion status callback to Laravel web-app."""
    if not settings.web_app_callback_url:
        return
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    signature = sign_callback_payload(body, settings.rag_callback_secret)
    headers = {
        "Content-Type": "application/json",
        "X-Callback-Signature": signature,
    }
    try:
        with httpx.Client(timeout=10.0) as client:
            resp = client.post(settings.web_app_callback_url, content=body, headers=headers)
            if resp.status_code >= 400:
                logger.warning("Callback returned HTTP %s: %s", resp.status_code, resp.text[:200])
    except Exception as exc:
        logger.warning("Failed to deliver callback for job %s: %s", payload.get("job_id"), exc)


def process_ingestion_job(job: dict, settings: Settings | None = None) -> dict[str, object]:
    """Execute one ingestion job end-to-end with atomic version swap."""
    cfg = settings or get_settings()
    job_id = str(job["job_id"])
    document_id = str(job["document_id"])
    collection_id = str(job["collection_id"])
    title = str(job.get("title", "Untitled"))
    version = int(job.get("version", 1))
    filename = str(job["filename"])
    storage_rel_path = str(job["storage_path"])
    tags = list(job.get("tags") or [])
    effective_ts = job.get("effective_ts")

    # Resolve safely under uploads_dir to prevent path traversal
    uploads_root = Path(cfg.uploads_dir).resolve()
    target_path = (uploads_root / storage_rel_path.lstrip("/\\")).resolve()
    if not str(target_path).startswith(str(uploads_root)):
        raise ValueError(f"Invalid storage_path outside uploads_dir: {storage_rel_path}")

    send_status_callback(cfg, {"job_id": job_id, "document_id": document_id, "version": version, "status": "parsing"})
    data = target_path.read_bytes()
    blocks = extract_blocks_from_bytes(filename, data)
    chunks = chunk_blocks(
        blocks,
        min_tokens=cfg.chunk_min_tokens,
        max_tokens=cfg.chunk_max_tokens,
        overlap_tokens=cfg.chunk_overlap_tokens,
    )
    if not chunks:
        raise ValueError(f"No text chunks could be extracted from '{filename}'.")

    send_status_callback(cfg, {"job_id": job_id, "document_id": document_id, "version": version, "status": "embedding"})

    async def _embed() -> tuple[list[list[float]], str]:
        embedder = create_embedder(cfg)
        try:
            batch_size = 32
            all_vecs: list[list[float]] = []
            texts = [c.text for c in chunks]
            for i in range(0, len(texts), batch_size):
                all_vecs.extend(await embedder.embed_texts(texts[i : i + batch_size], task="document"))
            return all_vecs, embedder.model_name
        finally:
            if hasattr(embedder, "aclose"):
                await embedder.aclose()

    vectors, model_name = asyncio.run(_embed())

    store = HybridVectorStore(
        url=cfg.qdrant_url,
        api_key=cfg.qdrant_api_key,
        collection_name=cfg.qdrant_collection,
        embedding_dim=cfg.embedding_dim,
        on_disk=cfg.qdrant_on_disk,
        quantize=cfg.qdrant_quantize,
    )
    # SRS §3.3 step 5: index inactive first, then atomically swap to active=True
    indexed = store.upsert_chunks(
        document_id=document_id,
        collection_id=collection_id,
        title=title,
        version=version,
        embedding_model=model_name,
        chunks=[asdict(c) for c in chunks],
        dense_vectors=vectors,
        active=False,
        tags=tags,
        effective_ts=effective_ts,
    )
    store.activate_document_version(document_id=document_id, active_version=version)

    result = {
        "job_id": job_id,
        "document_id": document_id,
        "version": version,
        "status": "indexed",
        "chunk_count": indexed,
        "embedding_model": model_name,
    }
    send_status_callback(cfg, result)
    return result


def handle_job_safely(job: dict, settings: Settings) -> None:
    try:
        res = process_ingestion_job(job, settings=settings)
        logger.info("Indexed job %s (%s chunks)", res["job_id"], res["chunk_count"])
    except (ScannedDocumentError, UnsupportedFileContentError, FileNotFoundError, ValueError) as exc:
        logger.warning("Job %s failed validation/parsing: %s", job.get("job_id"), exc)
        send_status_callback(
            settings,
            {
                "job_id": job.get("job_id"),
                "document_id": job.get("document_id"),
                "version": job.get("version"),
                "status": "failed",
                "error_message": str(exc),
            },
        )
    except Exception as exc:
        logger.exception("Job %s failed unexpectedly", job.get("job_id"))
        send_status_callback(
            settings,
            {
                "job_id": job.get("job_id"),
                "document_id": job.get("document_id"),
                "version": job.get("version"),
                "status": "failed",
                "error_message": f"{type(exc).__name__}: {exc}",
            },
        )


def run_worker() -> int:
    settings = get_settings()
    r = redis.from_url(settings.redis_url, decode_responses=True)
    running = True

    def _stop(_signum, _frame) -> None:
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)

    logger.info(
        "Starting ingest-worker (queue=%s, max_workers=%d)",
        settings.ingest_queue_name,
        settings.ingest_max_workers,
    )
    with ThreadPoolExecutor(max_workers=max(1, settings.ingest_max_workers)) as pool:
        in_flight = set()
        while running:
            in_flight = {f for f in in_flight if not f.done()}
            if len(in_flight) >= settings.ingest_max_workers:
                time.sleep(0.25)
                continue

            item = r.brpop(settings.ingest_queue_name, timeout=2)
            if item is None:
                continue
            _, raw_payload = item
            try:
                job = json.loads(raw_payload)
            except json.JSONDecodeError:
                logger.error("Discarding malformed queue payload")
                continue

            fut = pool.submit(handle_job_safely, job, settings)
            in_flight.add(fut)

    logger.info("ingest-worker shutting down gracefully")
    return 0


if __name__ == "__main__":
    sys.exit(run_worker())
