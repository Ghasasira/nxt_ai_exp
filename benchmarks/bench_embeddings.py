#!/usr/bin/env python3
"""Phase 0 Embedding Concurrency Benchmark (SRS §6.1 target: p95 < 150 ms at 20 concurrency)."""

from __future__ import annotations

import argparse
import asyncio
import statistics
import sys
import time
from pathlib import Path

RAG_CORE_DIR = Path(__file__).resolve().parents[1] / "services" / "rag-core"
if str(RAG_CORE_DIR) not in sys.path:
    sys.path.insert(0, str(RAG_CORE_DIR))

from app.embeddings import BaseEmbedder, FastEmbedder, OllamaEmbedder

SAMPLE_QUERIES = [
    "What is the monthly spend cap alert threshold?",
    "How does Qdrant enforce collection permissions?",
    "What happens when a scanned PDF has no extractable text?",
    "Which ports are exposed on the Mac Pro reverse proxy?",
    "What is the target Recall@5 for the golden set?",
    "How are duplicate document uploads detected?",
    "Why are AMD FirePro D300 GPUs unused?",
    "What is the RPO and RTO target for backups?",
]


def percentile(sorted_values: list[float], pct: float) -> float:
    if not sorted_values:
        return 0.0
    idx = min(len(sorted_values) - 1, max(0, int(round((pct / 100.0) * (len(sorted_values) - 1)))))
    return sorted_values[idx]


async def run_concurrency_level(embedder: BaseEmbedder, concurrency: int, total_requests: int) -> dict[str, float]:
    sem = asyncio.Semaphore(concurrency)
    latencies_ms: list[float] = []
    errors = 0

    async def worker(i: int) -> None:
        nonlocal errors
        query = SAMPLE_QUERIES[i % len(SAMPLE_QUERIES)]
        async with sem:
            t0 = time.perf_counter()
            try:
                await embedder.embed_query(query)
                latencies_ms.append((time.perf_counter() - t0) * 1000.0)
            except Exception as exc:
                errors += 1
                print(f"  [error c={concurrency} req={i}] {exc}", file=sys.stderr)

    wall_start = time.perf_counter()
    await asyncio.gather(*(worker(i) for i in range(total_requests)))
    wall_s = time.perf_counter() - wall_start

    latencies_ms.sort()
    return {
        "concurrency": concurrency,
        "requests": total_requests,
        "errors": errors,
        "qps": total_requests / max(wall_s, 1e-6),
        "p50_ms": statistics.median(latencies_ms) if latencies_ms else 0.0,
        "p95_ms": percentile(latencies_ms, 95.0),
        "max_ms": latencies_ms[-1] if latencies_ms else 0.0,
    }


async def main_async(args: argparse.Namespace) -> int:
    if args.provider == "ollama":
        embedder: BaseEmbedder = OllamaEmbedder(base_url=args.ollama_url, model_name=args.model)
    else:
        model = "nomic-ai/nomic-embed-text-v1.5" if args.model == "nomic-embed-text" else args.model
        embedder = FastEmbedder(model_name=model)

    try:
        if not await embedder.health_check():
            print(f"Embedder '{args.provider}' ({args.model}) is not ready.", file=sys.stderr)
            return 1

        # Warmup
        await embedder.embed_query("warmup query")

        print(f"Benchmarking {embedder.provider_name} / {embedder.model_name}")
        print(f"{'Concurrency':>11} | {'Reqs':>5} | {'Err':>3} | {'QPS':>6} | {'p50 ms':>8} | {'p95 ms':>8} | {'max ms':>8} | {'SRS <150ms':>10}")
        print("-" * 82)

        for conc in [int(x.strip()) for x in args.concurrencies.split(",") if x.strip()]:
            reqs = max(conc * 3, args.requests_per_level)
            res = await run_concurrency_level(embedder, conc, reqs)
            status = "PASS" if res["p95_ms"] < 150.0 and res["errors"] == 0 else "FAIL"
            print(
                f"{res['concurrency']:>11} | {res['requests']:>5} | {res['errors']:>3} | "
                f"{res['qps']:>6.2f} | {res['p50_ms']:>8.1f} | {res['p95_ms']:>8.1f} | "
                f"{res['max_ms']:>8.1f} | {status:>10}"
            )
    finally:
        if hasattr(embedder, "aclose"):
            await embedder.aclose()
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Benchmark query embedding latency under concurrency.")
    parser.add_argument("--provider", choices=["ollama", "fastembed"], default="ollama")
    parser.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    parser.add_argument("--model", default="nomic-embed-text")
    parser.add_argument("--concurrencies", default="1,5,10,20")
    parser.add_argument("--requests-per-level", type=int, default=40)
    return parser.parse_args()


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main_async(parse_args())))
