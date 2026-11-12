# Phase 0 Benchmarks & Golden-Set Harness

These scripts validate the Mac Pro (Late 2013) hardware and SRS §6.1 / §9 acceptance targets before building Phase 1+ application features.

## 1. Mac Pro Host Pre-Flight (Run on the Mac Pro via SSH)

```bash
chmod +x scripts/preflight_macpro.sh
./scripts/preflight_macpro.sh
```

Check specifically:
- Whether Docker volumes (`/var/lib/docker`) sit on the ~98 GB `/` partition or on a larger partition.
- Whether `avx2` is absent (expected on Xeon E5-1620 v2) and whether `macfanctld` is running.

## 2. Start the Local Dev Stack (Windows or Mac Pro)

```bash
cp .env.example .env
# Edit .env to set passwords, QDRANT_API_KEY, RAG_INTERNAL_SECRET, and optionally GEMINI_API_KEY
docker compose -f compose.yaml -f compose.dev.yaml up -d --build
docker exec -it rag-ollama ollama pull nomic-embed-text
```

## 3. Run Embedding Concurrency Benchmark (Target: p95 < 150 ms)

```bash
python benchmarks/bench_embeddings.py --ollama-url http://127.0.0.1:11434 --model nomic-embed-text --concurrencies 1,5,10,20
```

If Ollama p95 exceeds 150 ms at 20 concurrency on the non-AVX2 Xeon, install `fastembed` (`pip install -e "./services/rag-core[onnx]"`) and compare:

```bash
python benchmarks/bench_embeddings.py --provider fastembed --concurrencies 1,5,10,20
```

## 4. Run Qdrant Scale & Memory Benchmark (Target: p95 < 150 ms, 1–3 GB RAM)

```bash
# Quick smoke (10k vectors)
python benchmarks/bench_qdrant.py --url http://127.0.0.1:6333 --api-key "$QDRANT_API_KEY" --num-vectors 10000

# Full 1M-chunk Mac Pro scale test (with memmap + INT8 quantization)
python benchmarks/bench_qdrant.py --url http://127.0.0.1:6333 --api-key "$QDRANT_API_KEY" --num-vectors 1000000 --on-disk --quantize
```

## 5. Run Golden-Set Model Bake-Off

```bash
# Retrieval-only dry run (no GEMINI_API_KEY required)
python benchmarks/run_golden_eval.py --rag-url http://127.0.0.1:8001 --secret "$RAG_INTERNAL_SECRET" --dry-run

# Live Gemini comparison
python benchmarks/run_golden_eval.py --rag-url http://127.0.0.1:8001 --secret "$RAG_INTERNAL_SECRET" --models gemini-3.1-flash-lite,gemini-3.5-flash-lite
```
