# RAG Knowledge Agent (`nxt_ai_exp`)

An on-premises Hybrid Retrieval-Augmented Generation (RAG) system designed for a Late 2013 Apple Mac Pro (`MacPro6,1`, Ubuntu Server 26.04 LTS) with cloud answer generation delegated to Google Gemini.

## Architecture

- **Edge Proxy (`caddy`):** Single public entrypoint (`:80` / `:443`) with configurable internal/ACME TLS ([proxy/Caddyfile](proxy/Caddyfile)) or upstream tunnel termination ([proxy/Caddyfile.tunnel](proxy/Caddyfile.tunnel)), configured for SSE streaming (`flush_interval -1`).
- **Control Plane & UI (`web-app`):** Laravel 13 + Octane (FrankenPHP) + Inertia.js + React + Tailwind CSS v4. Handles authentication, TOTP 2FA, RBAC (`admin`, `manager`, `reader`), scoped API keys, `/api/v1` REST + SSE endpoints, collection permissions, usage/cost tracking, golden-set evaluation UI, and audit logging.
- **Internal AI Engine (`rag-core` & `ingest-worker`):** Python FastAPI service + Redis-backed worker (`INGEST_MAX_WORKERS=2`) handling content validation, structure-aware chunking (`PDF`, `DOCX`, `XLSX`, `CSV`, `PPTX`, `HTML`, `MD`, `TXT`), local `nomic-embed-text` embeddings (Ollama or ONNX/`fastembed` fallback), Qdrant hybrid dense + sparse BM25-IDF + RRF retrieval with pre-retrieval ACL filtering, atomic version swaps, and grounded Gemini generation with citation validation and retrieval-only fallback.
- **Data Stores (Internal Network Only):** PostgreSQL 17, Redis 7.4, Qdrant v1.20.0, Ollama 0.40.16.

## Repository Layout

```text
.
├── compose.yaml                  # Hardened production stack (pinned tags, internal networks, log caps)
├── compose.dev.yaml              # Loopback-only (127.0.0.1) override for local dev & benchmarks
├── .env.example                  # Environment configuration template
├── proxy/                        # Caddy reverse proxy configurations
├── services/
│   ├── rag-core/                 # FastAPI service, Redis ingest-worker, parsers, Qdrant & Gemini clients
│   └── web-app/                  # Laravel 13 + Inertia/React application & /api/v1
├── benchmarks/                   # Phase 0 embedding/Qdrant/golden-set benchmarks & Phase 3 soak test
├── scripts/                      # Mac Pro pre-flight, host hardening, watchdog, backup & restore
└── docs/                         # SRS, Mac Pro hardware guide, ADRs, privacy notice & runbook
```

## Quick Start (Local Development)

1. Copy `.env.example` to `.env` and fill in secrets (`POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `QDRANT_API_KEY`, `RAG_INTERNAL_SECRET`, `RAG_CALLBACK_SECRET`, `APP_KEY`, and optionally `GEMINI_API_KEY`).
   ```bash
   cp .env.example .env
   ```
2. Start the development stack:
   ```bash
   docker compose -f compose.yaml -f compose.dev.yaml up -d --build
   docker exec -it rag-ollama ollama pull nomic-embed-text
   docker exec -it rag-web-app php artisan migrate --seed
   ```
3. Run unit & feature tests locally:
   - **Python (`rag-core`):**
     ```bash
     pytest -v services/rag-core/tests
     ```
   - **Laravel (`web-app`):**
     ```bash
     cd services/web-app && npm run build && php artisan test
     ```

## Benchmarks, Hardening & Operations

- **Phase 0 Benchmarks:** See [benchmarks/README.md](benchmarks/README.md) for concurrency (`bench_embeddings.py`), 1M-chunk scale (`bench_qdrant.py`), and golden-set evaluation (`run_golden_eval.py`).
- **Mac Pro Provisioning & Operations:** See [docs/deployment-runbook.md](docs/deployment-runbook.md), [scripts/preflight_macpro.sh](scripts/preflight_macpro.sh), [scripts/setup_macpro_host.sh](scripts/setup_macpro_host.sh), [scripts/backup.sh](scripts/backup.sh), and [scripts/restore.sh](scripts/restore.sh).
- **Compliance & Decisions:** See [docs/data-handling-notice.md](docs/data-handling-notice.md) and [docs/architecture-decisions.md](docs/architecture-decisions.md).
