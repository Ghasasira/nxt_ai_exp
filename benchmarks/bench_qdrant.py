#!/usr/bin/env python3
"""Phase 0 Qdrant Hybrid Search Scale & Latency Benchmark (SRS §6.1 target: p95 < 150 ms at up to 1M chunks)."""

from __future__ import annotations

import argparse
import concurrent.futures
import os
import random
import statistics
import sys
import time
import uuid
from pathlib import Path

from qdrant_client import models

RAG_CORE_DIR = Path(__file__).resolve().parents[1] / "services" / "rag-core"
if str(RAG_CORE_DIR) not in sys.path:
    sys.path.insert(0, str(RAG_CORE_DIR))

from app.vector_store import HybridVectorStore, text_to_sparse_vector

VOCAB = [
    "qdrant", "ollama", "gemini", "macpro", "xeon", "retrieval", "hybrid", "policy",
    "collection", "citation", "backup", "restore", "latency", "embedding", "chunk",
    "invoice", "contract", "security", "audit", "quota", "budget", "uganda", "privacy",
]


def random_unit_vector(dim: int, rng: random.Random) -> list[float]:
    vec = [rng.gauss(0.0, 1.0) for _ in range(dim)]
    norm = sum(x * x for x in vec) ** 0.5 or 1.0
    return [x / norm for x in vec]


def percentile(sorted_values: list[float], pct: float) -> float:
    if not sorted_values:
        return 0.0
    idx = min(len(sorted_values) - 1, max(0, int(round((pct / 100.0) * (len(sorted_values) - 1)))))
    return sorted_values[idx]


def main() -> int:
    parser = argparse.ArgumentParser(description="Benchmark Qdrant hybrid search latency and scale.")
    parser.add_argument("--url", default="http://127.0.0.1:6333")
    parser.add_argument("--api-key", default=os.environ.get("QDRANT_API_KEY", ""))
    parser.add_argument("--collection", default="bench_hybrid_phase0")
    parser.add_argument("--num-vectors", type=int, default=10_000)
    parser.add_argument("--batch-size", type=int, default=500)
    parser.add_argument("--dim", type=int, default=768)
    parser.add_argument("--queries", type=int, default=100)
    parser.add_argument("--concurrency", type=int, default=20)
    parser.add_argument("--on-disk", action="store_true")
    parser.add_argument("--quantize", action="store_true")
    parser.add_argument("--keep-collection", action="store_true")
    args = parser.parse_args()

    store = HybridVectorStore(
        url=args.url,
        api_key=args.api_key,
        collection_name=args.collection,
        embedding_dim=args.dim,
        on_disk=args.on_disk,
        quantize=args.quantize,
    )

    if store.client.collection_exists(args.collection):
        store.client.delete_collection(args.collection)
    store.ensure_collection()

    rng = random.Random(42)
    collections = ["col_hr", "col_ops", "col_eng", "col_fin", "col_legal"]

    print(
        f"Loading {args.num_vectors:,} hybrid points into '{args.collection}' "
        f"(on_disk={args.on_disk}, quantize={args.quantize})..."
    )
    t_load = time.perf_counter()
    for start in range(0, args.num_vectors, args.batch_size):
        end = min(args.num_vectors, start + args.batch_size)
        points = []
        for idx in range(start, end):
            words = rng.sample(VOCAB, k=8)
            snippet = f"Chunk {idx}: " + " ".join(words)
            points.append(
                models.PointStruct(
                    id=str(uuid.uuid4()),
                    vector={
                        "dense": random_unit_vector(args.dim, rng),
                        "sparse": text_to_sparse_vector(snippet),
                    },
                    payload={
                        "document_id": f"doc_{idx // 25}",
                        "collection_id": collections[idx % len(collections)],
                        "title": f"Doc {idx // 25}",
                        "version": 1,
                        "chunk_index": idx % 25,
                        "heading_path": "Benchmark",
                        "page": 1,
                        "content_hash": f"h{idx}",
                        "embedding_model": "synthetic",
                        "active": True,
                        "text": snippet,
                    },
                )
            )
        store.client.upsert(collection_name=args.collection, points=points, wait=True)
        if end % 25_000 == 0 or end == args.num_vectors:
            print(f"  Inserted {end:,} / {args.num_vectors:,}")

    load_s = time.perf_counter() - t_load
    print(f"Load finished in {load_s:.1f}s ({args.num_vectors / max(load_s, 1e-6):.0f} pts/s)")

    query_vecs = [random_unit_vector(args.dim, rng) for _ in range(args.queries)]
    query_texts = [" ".join(rng.sample(VOCAB, k=4)) for _ in range(args.queries)]

    def run_query(i: int) -> float:
        t0 = time.perf_counter()
        store.search(
            query_text=query_texts[i],
            query_vector=query_vecs[i],
            collection_ids=["col_hr", "col_ops"],
            top_k=20,
        )
        return (time.perf_counter() - t0) * 1000.0

    # Warmup
    run_query(0)

    t_search = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        latencies = list(pool.map(run_query, range(args.queries)))
    search_wall = time.perf_counter() - t_search
    latencies.sort()

    info = store.client.get_collection(args.collection)
    print("\n=== Qdrant Benchmark Summary ===")
    print(f"Points indexed : {info.points_count}")
    print(f"Concurrency    : {args.concurrency}")
    print(f"QPS            : {args.queries / max(search_wall, 1e-6):.2f}")
    print(f"p50 latency    : {statistics.median(latencies):.1f} ms")
    print(f"p95 latency    : {percentile(latencies, 95.0):.1f} ms (Target < 150 ms)")
    print(f"max latency    : {latencies[-1]:.1f} ms")

    if not args.keep_collection:
        store.client.delete_collection(args.collection)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
