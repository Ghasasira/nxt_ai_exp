from __future__ import annotations

import hmac
import json
import time
import uuid
from dataclasses import asdict

import redis
from fastapi import Depends, FastAPI, Header, HTTPException, status
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field

from app.chunking import TextBlock, chunk_blocks, extract_blocks_from_bytes
from app.config import Settings, get_settings
from app.embeddings import create_embedder
from app.generator import GeminiGenerator, NO_ANSWER_MESSAGE
from app.vector_store import HybridVectorStore

app = FastAPI(title="rag-core", version="0.2.0")


def verify_internal_secret(
    x_internal_secret: str | None = Header(default=None),
    settings: Settings = Depends(get_settings),
) -> None:
    """Authenticate internal calls from web-app or Phase 0 local benchmarks."""
    if not x_internal_secret or not hmac.compare_digest(x_internal_secret, settings.rag_internal_secret):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing X-Internal-Secret header.",
        )


def get_vector_store(settings: Settings = Depends(get_settings)) -> HybridVectorStore:
    return HybridVectorStore(
        url=settings.qdrant_url,
        api_key=settings.qdrant_api_key,
        collection_name=settings.qdrant_collection,
        embedding_dim=settings.embedding_dim,
        on_disk=settings.qdrant_on_disk,
        quantize=settings.qdrant_quantize,
    )


class ChunkTextRequest(BaseModel):
    filename: str = Field(default="document.md")
    content: str = Field(..., min_length=1)


class IngestTextRequest(BaseModel):
    document_id: str
    collection_id: str
    title: str
    version: int = Field(default=1, ge=1)
    filename: str = Field(default="document.md")
    content: str = Field(..., min_length=1)
    tags: list[str] = Field(default_factory=list)
    effective_ts: int | None = None


class EnqueueIngestRequest(BaseModel):
    job_id: str
    document_id: str
    collection_id: str
    title: str
    version: int = Field(default=1, ge=1)
    filename: str
    storage_path: str
    tags: list[str] = Field(default_factory=list)
    effective_ts: int | None = None


class SearchRequest(BaseModel):
    query: str = Field(..., min_length=1, max_length=2000)
    collection_ids: list[str] = Field(..., min_length=1)
    top_k: int = Field(default=10, ge=1, le=50)
    document_ids: list[str] | None = None
    tags: list[str] | None = None
    date_from_ts: int | None = None
    date_to_ts: int | None = None


class HistoryMessage(BaseModel):
    role: str
    content: str


class AskRequest(SearchRequest):
    allow_cloud_generation: bool = True
    similarity_threshold: float | None = None
    model_override: str | None = None
    history: list[HistoryMessage] = Field(default_factory=list)


def _format_citations(chunks, cited_ids: list[str], mode: str) -> list[dict]:
    cited_set = set(cited_ids)
    return [
        {
            "document_id": c.document_id,
            "title": c.title,
            "chunk_id": c.chunk_id,
            "page": c.page,
            "heading_path": c.heading_path,
            "snippet": c.text[:240],
            "score": c.score,
        }
        for c in chunks
        if c.chunk_id in cited_set or mode == "retrieval_only"
    ]


@app.get("/health")
async def health() -> dict[str, str]:
    """Liveness probe (no external dependency checks, no secrets)."""
    return {"status": "ok"}


@app.get("/ready")
async def ready(
    settings: Settings = Depends(get_settings),
    store: HybridVectorStore = Depends(get_vector_store),
) -> dict[str, object]:
    """Readiness probe verifying Qdrant and the configured embedding backend."""
    embedder = create_embedder(settings)
    qdrant_ok = store.health_check()
    embedder_ok = await embedder.health_check()
    if hasattr(embedder, "aclose"):
        await embedder.aclose()

    if not (qdrant_ok and embedder_ok):
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={"qdrant": qdrant_ok, "embedder": embedder_ok},
        )
    return {
        "status": "ready",
        "qdrant": qdrant_ok,
        "embedder": {"provider": embedder.provider_name, "model": embedder.model_name, "ok": embedder_ok},
    }


@app.post("/internal/chunk", dependencies=[Depends(verify_internal_secret)])
async def preview_chunks(req: ChunkTextRequest, settings: Settings = Depends(get_settings)) -> dict[str, object]:
    """Phase 0/1 utility endpoint to inspect structure-aware chunking output."""
    blocks = extract_blocks_from_bytes(req.filename, req.content.encode("utf-8"))
    chunks = chunk_blocks(
        blocks,
        min_tokens=settings.chunk_min_tokens,
        max_tokens=settings.chunk_max_tokens,
        overlap_tokens=settings.chunk_overlap_tokens,
    )
    return {"count": len(chunks), "chunks": [asdict(c) for c in chunks]}


@app.post("/internal/ingest", status_code=status.HTTP_202_ACCEPTED, dependencies=[Depends(verify_internal_secret)])
async def enqueue_ingest(
    req: EnqueueIngestRequest,
    settings: Settings = Depends(get_settings),
) -> dict[str, object]:
    """Enqueue an asynchronous file ingestion job in Redis and return 202 Accepted (SRS §3.3)."""
    r = redis.from_url(settings.redis_url, decode_responses=True)
    r.lpush(settings.ingest_queue_name, json.dumps(req.model_dump()))
    return {"job_id": req.job_id, "document_id": req.document_id, "status": "queued"}


@app.post("/internal/ingest-text", dependencies=[Depends(verify_internal_secret)])
async def ingest_text(
    req: IngestTextRequest,
    settings: Settings = Depends(get_settings),
    store: HybridVectorStore = Depends(get_vector_store),
) -> dict[str, object]:
    """Synchronously chunk, embed, and upsert a text/markdown document into Qdrant."""
    blocks: list[TextBlock] = extract_blocks_from_bytes(req.filename, req.content.encode("utf-8"))
    chunks = chunk_blocks(
        blocks,
        min_tokens=settings.chunk_min_tokens,
        max_tokens=settings.chunk_max_tokens,
        overlap_tokens=settings.chunk_overlap_tokens,
    )
    if not chunks:
        return {"document_id": req.document_id, "indexed_chunks": 0}

    embedder = create_embedder(settings)
    try:
        vectors = await embedder.embed_texts([c.text for c in chunks], task="document")
    finally:
        if hasattr(embedder, "aclose"):
            await embedder.aclose()

    indexed = store.upsert_chunks(
        document_id=req.document_id,
        collection_id=req.collection_id,
        title=req.title,
        version=req.version,
        embedding_model=embedder.model_name,
        chunks=[asdict(c) for c in chunks],
        dense_vectors=vectors,
        active=False,
        tags=req.tags,
        effective_ts=req.effective_ts,
    )
    store.activate_document_version(document_id=req.document_id, active_version=req.version)
    return {"document_id": req.document_id, "indexed_chunks": indexed}


@app.get("/internal/documents/{document_id}/chunks", dependencies=[Depends(verify_internal_secret)])
async def list_document_chunks(
    document_id: str,
    version: int | None = None,
    store: HybridVectorStore = Depends(get_vector_store),
) -> dict[str, object]:
    """Inspect indexed chunks for a document (FR-DOC-07)."""
    chunks = store.get_document_chunks(document_id=document_id, version=version)
    return {"document_id": document_id, "count": len(chunks), "chunks": chunks}


@app.delete("/internal/documents/{document_id}", dependencies=[Depends(verify_internal_secret)])
async def delete_document_vectors(
    document_id: str,
    store: HybridVectorStore = Depends(get_vector_store),
) -> dict[str, object]:
    """Delete all vectors and chunks for a document (FR-DOC-05)."""
    store.delete_document(document_id=document_id)
    return {"document_id": document_id, "deleted": True}


@app.post("/internal/search", dependencies=[Depends(verify_internal_secret)])
async def search_chunks(
    req: SearchRequest,
    settings: Settings = Depends(get_settings),
    store: HybridVectorStore = Depends(get_vector_store),
) -> dict[str, object]:
    """Hybrid dense + sparse retrieval filtered by permitted collections."""
    embedder = create_embedder(settings)
    try:
        q_vec = await embedder.embed_query(req.query)
    finally:
        if hasattr(embedder, "aclose"):
            await embedder.aclose()

    results = store.search(
        query_text=req.query,
        query_vector=q_vec,
        collection_ids=req.collection_ids,
        top_k=req.top_k,
        document_ids=req.document_ids,
        tags=req.tags,
        date_from_ts=req.date_from_ts,
        date_to_ts=req.date_to_ts,
    )
    return {"count": len(results), "results": [asdict(r) for r in results]}


async def _retrieve_for_ask(
    req: AskRequest,
    settings: Settings,
    store: HybridVectorStore,
    generator: GeminiGenerator,
):
    effective_query = generator.rewrite_followup_query(
        query=req.query,
        history=[h.model_dump() for h in req.history],
        allow_cloud_generation=req.allow_cloud_generation,
    )
    embedder = create_embedder(settings)
    try:
        q_vec = await embedder.embed_query(effective_query)
    finally:
        if hasattr(embedder, "aclose"):
            await embedder.aclose()

    threshold = req.similarity_threshold if req.similarity_threshold is not None else settings.similarity_threshold
    candidates = store.search(
        query_text=effective_query,
        query_vector=q_vec,
        collection_ids=req.collection_ids,
        top_k=req.top_k,
        document_ids=req.document_ids,
        tags=req.tags,
        date_from_ts=req.date_from_ts,
        date_to_ts=req.date_to_ts,
    )
    filtered = [c for c in candidates if c.score >= threshold][: settings.rerank_top_n]
    return effective_query, filtered


@app.post("/internal/ask", dependencies=[Depends(verify_internal_secret)])
@app.post("/internal/chat", dependencies=[Depends(verify_internal_secret)])
async def ask_question(
    req: AskRequest,
    settings: Settings = Depends(get_settings),
    store: HybridVectorStore = Depends(get_vector_store),
) -> dict[str, object]:
    """End-to-end RAG query endpoint (rewrite -> retrieve -> threshold -> generate -> cite)."""
    start = time.perf_counter()
    request_id = str(uuid.uuid4())
    generator = GeminiGenerator(settings)
    effective_query, filtered = await _retrieve_for_ask(req, settings, store, generator)

    if not filtered:
        return {
            "request_id": request_id,
            "rewritten_query": effective_query,
            "answer": NO_ANSWER_MESSAGE,
            "grounded": False,
            "mode": "no_answer",
            "citations": [],
            "usage": {"input_tokens": 0, "output_tokens": 0, "latency_ms": int((time.perf_counter() - start) * 1000)},
        }

    gen = generator.generate(
        query=effective_query,
        chunks=filtered,
        allow_cloud_generation=req.allow_cloud_generation,
        model_override=req.model_override,
    )
    total_ms = int((time.perf_counter() - start) * 1000)
    return {
        "request_id": request_id,
        "rewritten_query": effective_query,
        "answer": gen.answer,
        "grounded": gen.grounded,
        "mode": gen.mode,
        "model": gen.model,
        "citations": _format_citations(filtered, gen.cited_chunk_ids, gen.mode),
        "invalid_citations": gen.invalid_citations,
        "usage": {
            "input_tokens": gen.input_tokens,
            "output_tokens": gen.output_tokens,
            "latency_ms": total_ms,
        },
    }


@app.post("/internal/chat/stream", dependencies=[Depends(verify_internal_secret)])
async def stream_question(
    req: AskRequest,
    settings: Settings = Depends(get_settings),
    store: HybridVectorStore = Depends(get_vector_store),
) -> StreamingResponse:
    """SSE token-streaming RAG endpoint (FR-QA-05 & SRS §5.2)."""
    start = time.perf_counter()
    request_id = str(uuid.uuid4())
    generator = GeminiGenerator(settings)
    effective_query, filtered = await _retrieve_for_ask(req, settings, store, generator)

    def event_stream():
        if not filtered:
            yield f"event: token\ndata: {json.dumps({'delta': NO_ANSWER_MESSAGE})}\n\n"
            final_payload = {
                "request_id": request_id,
                "rewritten_query": effective_query,
                "answer": NO_ANSWER_MESSAGE,
                "grounded": False,
                "mode": "no_answer",
                "citations": [],
                "usage": {"input_tokens": 0, "output_tokens": 0, "latency_ms": int((time.perf_counter() - start) * 1000)},
            }
            yield f"event: done\ndata: {json.dumps(final_payload)}\n\n"
            return

        for kind, payload in generator.generate_stream(
            query=effective_query,
            chunks=filtered,
            allow_cloud_generation=req.allow_cloud_generation,
            model_override=req.model_override,
        ):
            if kind == "token":
                yield f"event: token\ndata: {json.dumps({'delta': payload})}\n\n"
            elif kind == "final":
                total_ms = int((time.perf_counter() - start) * 1000)
                final_payload = {
                    "request_id": request_id,
                    "rewritten_query": effective_query,
                    "answer": payload.answer,
                    "grounded": payload.grounded,
                    "mode": payload.mode,
                    "model": payload.model,
                    "citations": _format_citations(filtered, payload.cited_chunk_ids, payload.mode),
                    "invalid_citations": payload.invalid_citations,
                    "usage": {
                        "input_tokens": payload.input_tokens,
                        "output_tokens": payload.output_tokens,
                        "latency_ms": total_ms,
                    },
                }
                yield f"event: done\ndata: {json.dumps(final_payload)}\n\n"

    return StreamingResponse(event_stream(), media_type="text/event-stream")
