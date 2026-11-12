#!/usr/bin/env python3
"""Phase 3 Soak & Acceptance Load Test (SRS §6.1 & §9).

Sustains N concurrent /api/v1/chat (SSE) or /api/v1/search requests for a target duration,
reporting error rate (<1%), TTFT p95 (<2.5 s), and complete response p95 (<8.0 s).
"""

from __future__ import annotations

import argparse
import asyncio
import statistics
import sys
import time

import httpx

SAMPLE_QUESTIONS = [
    "What are the hardware limits of the Mac Pro 2013?",
    "How does hybrid search combine dense vectors and BM25?",
    "What happens when a collection has cloud generation disabled?",
    "What is the monthly budget alert percentage?",
    "What is the RPO and RTO target for backups?",
]


def percentile(sorted_values: list[float], pct: float) -> float:
    if not sorted_values:
        return 0.0
    idx = min(len(sorted_values) - 1, max(0, int(round((pct / 100.0) * (len(sorted_values) - 1)))))
    return sorted_values[idx]


async def run_soak(args: argparse.Namespace) -> int:
    deadline = time.monotonic() + args.duration_seconds
    ttft_ms: list[float] = []
    total_ms: list[float] = []
    errors = 0
    total_reqs = 0
    lock = asyncio.Lock()

    headers = {"Authorization": f"Bearer {args.token}"}
    if args.mode == "chat":
        headers["Accept"] = "text/event-stream"

    async with httpx.AsyncClient(
        base_url=args.base_url.rstrip("/"),
        headers=headers,
        verify=not args.insecure,
        timeout=30.0,
    ) as client:

        async def worker(worker_id: int) -> None:
            nonlocal errors, total_reqs
            idx = worker_id
            while time.monotonic() < deadline:
                q = SAMPLE_QUESTIONS[idx % len(SAMPLE_QUESTIONS)]
                idx += 1
                t0 = time.perf_counter()
                first_byte_ms: float | None = None
                try:
                    if args.mode == "chat":
                        async with client.stream(
                            "POST",
                            "/api/v1/chat",
                            json={"query": q, "stream": True},
                        ) as resp:
                            if resp.status_code != 200:
                                raise RuntimeError(f"HTTP {resp.status_code}")
                            async for line in resp.aiter_lines():
                                if first_byte_ms is None and line.startswith("event: token"):
                                    first_byte_ms = (time.perf_counter() - t0) * 1000.0
                        elapsed = (time.perf_counter() - t0) * 1000.0
                    else:
                        resp = await client.post("/api/v1/search", json={"query": q, "top_k": 10})
                        if resp.status_code != 200:
                            raise RuntimeError(f"HTTP {resp.status_code}")
                        elapsed = (time.perf_counter() - t0) * 1000.0
                        first_byte_ms = elapsed

                    async with lock:
                        total_reqs += 1
                        total_ms.append(elapsed)
                        if first_byte_ms is not None:
                            ttft_ms.append(first_byte_ms)
                except Exception as exc:
                    async with lock:
                        total_reqs += 1
                        errors += 1
                    print(f"[worker {worker_id}] error: {exc}", file=sys.stderr)

        print(
            f"Starting {args.mode} soak test: concurrency={args.concurrency}, "
            f"duration={args.duration_seconds}s against {args.base_url}"
        )
        await asyncio.gather(*(worker(i) for i in range(args.concurrency)))

    ttft_ms.sort()
    total_ms.sort()
    err_rate = (errors / max(total_reqs, 1)) * 100.0
    ttft_p95 = percentile(ttft_ms, 95.0)
    total_p95 = percentile(total_ms, 95.0)

    print("\n=== Soak Test Report ===")
    print(f"Total requests : {total_reqs}")
    print(f"Errors         : {errors} ({err_rate:.2f}%, target < 1.00%)")
    print(f"TTFT p50 / p95 : {statistics.median(ttft_ms) if ttft_ms else 0:.1f} ms / {ttft_p95:.1f} ms (target < 2500 ms)")
    print(f"Total p50 / p95: {statistics.median(total_ms) if total_ms else 0:.1f} ms / {total_p95:.1f} ms (target < 8000 ms)")

    passed = err_rate < 1.0 and ttft_p95 < 2500.0 and total_p95 < 8000.0 and total_reqs > 0
    print(f"Verdict        : {'PASS' if passed else 'FAIL'}")
    return 0 if passed else 1


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="20-concurrent 30-minute SRS acceptance soak test.")
    parser.add_argument("--base-url", default="https://localhost")
    parser.add_argument("--token", required=True, help="Bearer API key with chat/search scope")
    parser.add_argument("--mode", choices=["chat", "search"], default="chat")
    parser.add_argument("--concurrency", type=int, default=20)
    parser.add_argument("--duration-seconds", type=int, default=1800)
    parser.add_argument("--insecure", action="store_true", help="Skip TLS verification for internal Caddy cert")
    return parser.parse_args()


if __name__ == "__main__":
    raise SystemExit(asyncio.run(run_soak(parse_args())))
