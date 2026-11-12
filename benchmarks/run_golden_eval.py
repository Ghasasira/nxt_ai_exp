#!/usr/bin/env python3
"""Phase 0 Golden-Set Evaluation & Gemini Model Bake-Off Runner (SRS §7.3 & §9)."""

from __future__ import annotations

import argparse
import json
import os
import statistics
import sys
from pathlib import Path

import httpx

# Approximate October 2026 pricing from SRS §7.2 (USD per 1M tokens)
MODEL_PRICING_PER_1M = {
    "gemini-3.1-flash-lite": {"input": 0.25, "output": 1.50},
    "gemini-3.5-flash-lite": {"input": 0.30, "output": 2.50},
}

PLANNING_MONTHLY_QUERIES = 180_000  # 6,000 queries/day * 30 days


def ingest_golden_docs(client: httpx.Client, repo_root: Path, docs: list[dict]) -> None:
    for doc in docs:
        file_path = repo_root / doc["file"]
        content = file_path.read_text(encoding="utf-8")
        resp = client.post(
            "/internal/ingest-text",
            json={
                "document_id": doc["document_id"],
                "collection_id": doc["collection_id"],
                "title": doc["title"],
                "version": 1,
                "filename": file_path.name,
                "content": content,
            },
        )
        resp.raise_for_status()
        print(f"Ingested {doc['document_id']}: {resp.json()['indexed_chunks']} chunks")


def evaluate_model(
    client: httpx.Client,
    cases: list[dict],
    model_name: str | None,
    dry_run: bool,
    threshold: float | None,
) -> dict[str, object]:
    recall_hits = 0
    answerable_total = 0
    unanswerable_correct = 0
    unanswerable_total = 0
    valid_grounded = 0
    latencies: list[int] = []
    input_tokens_list: list[int] = []
    output_tokens_list: list[int] = []

    for case in cases:
        search_resp = client.post(
            "/internal/search",
            json={
                "query": case["question"],
                "collection_ids": case["collection_ids"],
                "top_k": 5,
            },
        )
        search_resp.raise_for_status()
        top5_docs = {r["document_id"] for r in search_resp.json()["results"]}

        payload: dict[str, object] = {
            "query": case["question"],
            "collection_ids": case["collection_ids"],
            "top_k": 20,
            "allow_cloud_generation": not dry_run,
        }
        if threshold is not None:
            payload["similarity_threshold"] = threshold
        if model_name:
            payload["model_override"] = model_name

        ask_resp = client.post("/internal/ask", json=payload)
        ask_resp.raise_for_status()
        data = ask_resp.json()

        latencies.append(int(data["usage"]["latency_ms"]))
        input_tokens_list.append(int(data["usage"]["input_tokens"]))
        output_tokens_list.append(int(data["usage"]["output_tokens"]))

        if case["answerable"]:
            answerable_total += 1
            if set(case["expected_document_ids"]) & top5_docs:
                recall_hits += 1
            if data["grounded"] and not data.get("invalid_citations"):
                valid_grounded += 1
        else:
            unanswerable_total += 1
            if data["mode"] == "no_answer":
                unanswerable_correct += 1

    avg_in = statistics.mean(input_tokens_list) if input_tokens_list else 0.0
    avg_out = statistics.mean(output_tokens_list) if output_tokens_list else 0.0
    pricing = MODEL_PRICING_PER_1M.get(model_name or "", {"input": 0.0, "output": 0.0})
    est_monthly_usd = (
        (avg_in * PLANNING_MONTHLY_QUERIES / 1_000_000.0) * pricing["input"]
        + (avg_out * PLANNING_MONTHLY_QUERIES / 1_000_000.0) * pricing["output"]
    )

    return {
        "model": model_name or "retrieval_only",
        "recall_at_5": recall_hits / max(answerable_total, 1),
        "grounded_rate": valid_grounded / max(answerable_total, 1),
        "no_answer_accuracy": unanswerable_correct / max(unanswerable_total, 1),
        "p50_latency_ms": statistics.median(latencies) if latencies else 0,
        "avg_input_tokens": round(avg_in, 1),
        "avg_output_tokens": round(avg_out, 1),
        "est_monthly_usd": round(est_monthly_usd, 2),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Run golden-set evaluation against rag-core.")
    parser.add_argument("--rag-url", default="http://127.0.0.1:8001")
    parser.add_argument("--secret", default=os.environ.get("RAG_INTERNAL_SECRET", "dev-internal-secret"))
    parser.add_argument("--golden-file", default="benchmarks/golden_set.sample.json")
    parser.add_argument("--models", default="gemini-3.1-flash-lite,gemini-3.5-flash-lite")
    parser.add_argument("--threshold", type=float, default=None)
    parser.add_argument("--dry-run", action="store_true", help="Skip cloud LLM calls (retrieval-only test).")
    parser.add_argument("--skip-ingest", action="store_true")
    args = parser.parse_args()

    repo_root = Path(__file__).resolve().parents[1]
    golden = json.loads((repo_root / args.golden_file).read_text(encoding="utf-8"))

    with httpx.Client(
        base_url=args.rag_url.rstrip("/"),
        headers={"X-Internal-Secret": args.secret},
        timeout=60.0,
    ) as client:
        if not args.skip_ingest:
            ingest_golden_docs(client, repo_root, golden["documents"])

        models_to_run = [None] if args.dry_run else [m.strip() for m in args.models.split(",") if m.strip()]
        print("\n=== Golden-Set Evaluation Results ===")
        for model_name in models_to_run:
            summary = evaluate_model(client, golden["cases"], model_name, args.dry_run, args.threshold)
            print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
