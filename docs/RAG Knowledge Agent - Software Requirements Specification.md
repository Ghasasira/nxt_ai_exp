# RAG Knowledge Agent: Software Requirements Specification

Version 0.1 (draft for review) · 28 October 2026

## 1. Introduction

### 1.1 Purpose

This document specifies a lightweight Retrieval-Augmented Generation (RAG) agent that answers questions from an organisation's own documents. Indexing, retrieval and management run on one on-premises Mac Pro (Late 2013). Answer generation is delegated to a cloud Gemini model. The system provides a REST API and a web interface, both for asking questions and for managing documents, users and settings.

### 1.2 Scope

**In scope:** document upload and ingestion, hybrid retrieval, grounded answer generation with citations, a versioned REST API, a chat web page, a management web interface, role-based access, usage and cost tracking, quality evaluation, backups.

**Out of scope for v1:** local LLM hosting as a primary path, model fine-tuning, voice, multi-node high availability, autonomous tool-calling actions, OCR of scanned documents (planned for a later phase).

### 1.3 Definitions

- **Chunk:** a passage of a document (about 300-500 tokens) that is embedded and retrieved on its own.
- **Collection:** a named group of documents with its own access rules.
- **Grounded answer:** an answer fully supported by retrieved chunks, with citations.
- **Hybrid search:** dense vector search combined with keyword (BM25-style) search.
- **RRF:** Reciprocal Rank Fusion, a method for merging two ranked result lists.

### 1.4 References

- Mac Pro (Late 2013) RAG Deployment and Architecture Guide (Tier 1 is adopted here, refined in section 3).
- Economical LLM API Selection Guide for RAG Pipelines. Its model list and prices are out of date; section 7 replaces them.

## 2. Overall description

### 2.1 Product perspective

A browser or API client talks to a single public entry point (the Laravel web app). The web app handles authentication, permissions and metadata, and calls an internal Python service (rag-core) that does the AI work. Vectors live in Qdrant, embeddings come from a local Ollama instance, and only the final generation step leaves the machine.

### 2.2 User classes

| Role | Needs |
| :-- | :-- |
| Admin | Everything: users, settings, API keys, system health, audit log |
| Knowledge Manager | Upload and maintain documents and collections, review answer quality |
| Reader | Ask questions in the chat page, give feedback |
| API client | A service key with scoped access (for example, other internal apps) |

### 2.3 Operating environment

- Apple MacPro6,1: Xeon E5-1620 v2 (4C/8T, AVX but no AVX2), 16 GB ECC RAM, 256 GB SSD, Ubuntu Server 26.04, headless, Docker Compose.
- The AMD FirePro GPUs are not used (no ROCm support for this generation).
- Outbound HTTPS to the Gemini API. Inbound access method (LAN only, VPN or tunnel) is an open question (section 11).

### 2.4 Key design decisions

1. Tier 1 hybrid architecture: local retrieval and embeddings, cloud generation.
2. Laravel is the only public entry point; rag-core, Qdrant, Ollama, PostgreSQL and Redis are reachable only on the internal Docker network.
3. The generation model is a configuration value, not hard-coded, and is chosen by benchmark (section 7).
4. If the cloud model is unavailable, the system degrades to retrieval-only (returns the best passages with citations) rather than to a weak local LLM.
5. Every answer must cite its sources, and the system must say so when it cannot answer.

### 2.5 Assumptions (to confirm)

- Planning load: 200 active users, about 30 queries each per day (6,000 per day), peak around 20 concurrent queries.
- Corpus: up to about 1 million chunks in v1 (roughly 3 GB of vectors), mostly English text-based PDF, DOCX, XLSX and web content.
- Users have a reliable connection to the Mac Pro; the Mac Pro has a stable outbound internet connection and a UPS.

## 3. Tier 1 reference architecture (refined)

### 3.1 Components

| Component | Technology | Responsibility | Exposure |
| :-- | :-- | :-- | :-- |
| Reverse proxy | Caddy or nginx | TLS, routing, upload size limits | Only public port (443) |
| web-app | Laravel + Inertia/React | UI, auth, RBAC, public REST API, metadata, API keys, audit | Via proxy |
| rag-core | Python, FastAPI | Parse, chunk, embed, retrieve, rerank, generate, cite | Internal |
| ingest-worker | Same image as rag-core | Asynchronous ingestion jobs | Internal |
| PostgreSQL | PostgreSQL | Application data | Internal |
| Redis | Redis | Queues, rate limits, caches | Internal |
| Qdrant | Qdrant | Dense and sparse vectors, payload filters | Internal, API key |
| Ollama | Ollama | Embeddings only (nomic-embed-text); no chat model by default | Internal |
| Gemini API | Google | Answer generation | Outbound only |

All images are pinned to an explicit version or digest (no latest tags), containers use restart: unless-stopped with health checks, and Docker logs are capped (10 MB x 3).

### 3.2 Provisional memory budget (validate in Phase 0)

OS and Docker about 1 GB; PostgreSQL 0.5-1 GB; Redis 0.1 GB; Qdrant 1-3 GB depending on corpus; Ollama with the embedding model 0.5-1 GB; rag-core 1-1.5 GB (plus about 0.5 GB if the reranker is enabled); Laravel and queue workers 0.7-1 GB. Expected total 6-9 GB of 16 GB.

### 3.3 Ingestion flow

1. User uploads a file in the web app; the file is stored on disk and a document record and ingestion job are created.
2. Laravel asks rag-core to ingest (asynchronous, returns 202).
3. rag-core parses the file, splits it into structure-aware chunks, embeds each chunk, and writes dense and sparse vectors plus metadata to Qdrant.
4. rag-core reports status back to Laravel through a signed callback; the UI shows progress and errors.
5. On re-upload, the new version is indexed first and then swapped in, so users never see a half-indexed document.

### 3.4 Query flow

1. Client calls the chat endpoint. Laravel authenticates, checks the rate limit and quota, and resolves which collections the caller may read.
2. rag-core rewrites follow-up questions into standalone ones (using conversation history), embeds the query, and runs one hybrid Qdrant query (dense plus sparse, fused with RRF) filtered to the permitted collections.
3. Optional reranking (CPU cross-encoder) narrows about 20 candidates to the best 4-6.
4. If no chunk passes the calibrated relevance threshold, return a standard no-answer response without calling the paid API.
5. Otherwise build the prompt, call Gemini at temperature 0, validate that the answer carries citations, and return answer, citations and usage.
6. Log the request (query, chunks used, latency, token counts, cost) for review and evaluation.

### 3.5 Retrieval specification

- **Embedding model:** nomic-embed-text, using the required task prefixes (search\_document: for chunks, search\_query: for queries). The embedding model name and version are stored on every chunk; changing the model creates a new collection and a full re-index.
- **Chunking:** respect headings, tables and list boundaries; 300-500 tokens with 10-15% overlap; keep the heading path, page number and document version as metadata.
- **Hybrid search:** dense plus sparse BM25 in Qdrant with RRF fusion. Keyword matching matters for part numbers, policy codes and names, which dense search handles poorly.
- **Threshold:** the similarity cut-off is calibrated on the golden set (section 9), not fixed at 0.70.
- **Reranker:** a small ONNX cross-encoder on CPU, behind a feature flag, enabled only if it improves the golden-set results at acceptable latency.
- **Deduplication:** identical chunks are detected by content hash.

### 3.6 Generation specification

- Model ID, temperature, thinking budget, maximum output tokens and prompt version are settings, editable in the admin UI and stored with each logged answer.
- Thinking or reasoning tokens are disabled or capped for RAG answers, because they are billed as output tokens.
- The prompt treats retrieved text strictly as data (delimited, never as instructions) to resist prompt injection inside documents.
- Rules: answer only from the context; cite chunk IDs; say clearly when the context is insufficient; do not use outside knowledge; match the user's language where possible.
- Timeouts and retries: 20 s timeout, one retry with backoff, then retrieval-only fallback.

### 3.7 Hardening checklist

- Only port 443 is exposed; Qdrant, Ollama, rag-core, PostgreSQL and Redis bind to the internal network only; Qdrant requires an API key.
- Secrets in Docker secrets or protected env files, never in images or the repository.
- Nightly backups (PostgreSQL dump, Qdrant snapshot, uploaded files) copied to a second machine; restore tested before go-live.
- Fan control (macfanctld) and temperature monitoring with alerts; UPS with graceful shutdown.

## 4. Functional requirements

Priority: **M** = must, **S** = should, **C** = could.

### 4.1 Document and collection management

| ID | Requirement | Pri |
| :-- | :-- | :-: |
| FR-DOC-01 | Upload one or many files (PDF, DOCX, XLSX, CSV, PPTX, TXT, MD, HTML), up to 50 MB each, with type validation by content | M |
| FR-DOC-02 | Create, rename and delete collections; assign documents to a collection | M |
| FR-DOC-03 | Edit document metadata (title, tags, owner, effective date) | M |
| FR-DOC-04 | Replace a document with a new version; keep version history; only the active version is searchable | M |
| FR-DOC-05 | Delete a document and remove all its chunks and vectors | M |
| FR-DOC-06 | Reindex a single document or a whole collection on demand | M |
| FR-DOC-07 | View a document's chunks and the exact text that would be retrieved | S |
| FR-DOC-08 | Ingest a web page by URL, with optional scheduled refresh | C |
| FR-DOC-09 | Per-collection flag allow\_cloud\_generation; when off, answers from that collection are retrieval-only | S |
| FR-DOC-10 | Detect duplicate uploads by file hash and warn the user | S |

### 4.2 Ingestion pipeline

| ID | Requirement | Pri |
| :-- | :-- | :-: |
| FR-ING-01 | Asynchronous jobs with states: queued, parsing, embedding, indexed, failed | M |
| FR-ING-02 | Failed jobs show a readable error and can be retried from the UI | M |
| FR-ING-03 | Limit concurrent ingestion so interactive queries stay responsive | M |
| FR-ING-04 | Extract tables as readable text and keep page numbers | S |
| FR-ING-05 | Flag scanned PDFs with no extractable text instead of silently indexing nothing | M |
| FR-ING-06 | OCR for scanned documents | C |

### 4.3 Question answering

| ID | Requirement | Pri |
| :-- | :-- | :-: |
| FR-QA-01 | Answer a question using only retrieved chunks, with citations (document, page or section, snippet) | M |
| FR-QA-02 | Return a standard no-answer response when nothing relevant is found, without calling the LLM | M |
| FR-QA-03 | Multi-turn conversations with follow-up question rewriting | S |
| FR-QA-04 | Filter by collection, tags, or date | S |
| FR-QA-05 | Stream the answer token by token | S |
| FR-QA-06 | Retrieval-only fallback when the LLM is unavailable or over budget | M |
| FR-QA-07 | Per-collection permission filtering is applied inside the vector query, never after it | M |
| FR-QA-08 | Optional semantic cache for repeated questions, cleared when documents change | C |

### 4.4 Users, roles and access

| ID | Requirement | Pri |
| :-- | :-- | :-: |
| FR-AUTH-01 | Login with email and password, password reset, session timeout | M |
| FR-AUTH-02 | Roles Admin, Manager, Reader, each with collection-level read and write grants | M |
| FR-AUTH-03 | API keys with scopes (chat, search, documents), expiry, revocation; stored hashed and shown once | M |
| FR-AUTH-04 | Two-factor authentication for Admin and Manager | S |
| FR-AUTH-05 | Single sign-on (OIDC) | C |
| FR-AUTH-06 | Audit log of logins, uploads, deletions, setting changes and key use | M |

### 4.5 Feedback, evaluation and analytics

| ID | Requirement | Pri |
| :-- | :-- | :-: |
| FR-EVAL-01 | Thumbs up/down with an optional comment on every answer | M |
| FR-EVAL-02 | Query log browser: question, answer, chunks used, latency, cost; filter by unanswered or low-rated | M |
| FR-EVAL-03 | Golden set: store question, expected answer and source; run it against any settings change and compare scores | M |
| FR-EVAL-04 | Dashboard: queries per day, no-answer rate, average latency, token usage, estimated cost | M |
| FR-EVAL-05 | Surface frequently unanswered questions as documentation gaps | S |

### 4.6 Administration

| ID | Requirement | Pri |
| :-- | :-- | :-: |
| FR-ADM-01 | Edit model ID, temperature, thinking budget, top-k, threshold, reranker on/off, and prompt text, with version history and rollback | M |
| FR-ADM-02 | Monthly spend cap, per-user daily quota, alert at 80% of budget | M |
| FR-ADM-03 | System health page: service status, queue depth, disk, RAM, CPU temperature, Qdrant size, last backup | M |
| FR-ADM-04 | Data retention settings for query logs (including option to hide or purge query text) | S |
| FR-ADM-05 | Export documents, logs and settings | C |

## 5. External interface requirements

### 5.1 Web interface pages

| Page | Audience | Purpose |
| :-- | :-- | :-- |
| Login, profile | All | Sign in, password, 2FA |
| Chat | Reader and above | Ask questions, see citations, give feedback, view history |
| Dashboard | Manager, Admin | Usage, cost, no-answer rate, recent failures |
| Documents | Manager, Admin | List, search, filter, upload, status, versions |
| Document detail | Manager, Admin | Metadata, chunk inspector, reindex, delete |
| Collections | Manager, Admin | Create, permissions, cloud-generation flag |
| Ingestion queue | Manager, Admin | Job states, errors, retry |
| Retrieval playground | Manager, Admin | Test a query and see ranked chunks and scores without calling the LLM |
| Query log and feedback | Manager, Admin | Review answers, rate, add to golden set |
| Evaluation | Manager, Admin | Golden set, run results, comparison between settings |
| Settings | Admin | Model, prompt, retrieval parameters, budgets |
| Users and roles | Admin | Accounts, roles, collection grants |
| API keys | Admin | Create, scope, revoke, last used |
| System health | Admin | Services, resources, backups |
| Audit log | Admin | Who did what, when |

### 5.2 REST API

Base path /api/v1, JSON, bearer API key or session cookie. All errors use one format with code, message and request\_id. List endpoints support pagination, and write endpoints that create jobs accept an Idempotency-Key header. Full OpenAPI documentation is generated and served at /api/docs.

| Method and path | Purpose | Scope |
| :-- | :-- | :-- |
| POST /chat | Ask a question (optional conversation\_id, collection\_ids, filters, stream) | chat |
| POST /search | Retrieval only: returns ranked chunks with scores | search |
| GET /conversations, GET /conversations/{id}, DELETE /conversations/{id} | Conversation history | chat |
| POST /feedback | Rate an answer by request\_id | chat |
| GET, POST /collections; GET, PATCH, DELETE /collections/{id} | Collection management | documents |
| GET, POST /documents; GET, PATCH, DELETE /documents/{id} | Document management (POST is multipart) | documents |
| POST /documents/{id}/reindex | Rebuild a document's index | documents |
| GET /documents/{id}/chunks | Inspect chunks | documents |
| GET /ingestion-jobs, GET /ingestion-jobs/{id} | Job status | documents |
| GET, POST, DELETE /api-keys | Key management | admin |
| GET /usage | Usage and cost by day, user or key | admin |
| GET, PUT /settings | Model and retrieval settings | admin |
| GET /health, GET /ready | Liveness and readiness (no auth, no detail) | none |

**POST /chat request fields:** query (required, max 2,000 characters), conversation\_id, collection\_ids, filters (tags, date range), stream (boolean), top\_k (bounded by admin limit).

**POST /chat response fields:** request\_id, answer, grounded (boolean), mode (generated or retrieval\_only), citations (document\_id, title, chunk\_id, page or section, snippet, score), usage (input and output tokens, latency in ms), conversation\_id.

Streaming uses server-sent events: token events, then a final event carrying citations and usage. Rate limits return 429 with Retry-After.

### 5.3 Internal interfaces

- Laravel to rag-core: HTTP on the internal network, shared-secret or signed requests; rag-core to Laravel: signed callbacks for ingestion status.
- rag-core to Qdrant (API key), to Ollama (embeddings), and to Gemini (HTTPS, key held only by rag-core).
- Provider abstraction in rag-core so the generation provider (Gemini, or an OpenAI-compatible API) can be switched by configuration.

### 5.4 Core data entities

User, Role, Collection, CollectionAccess, Document, DocumentVersion, IngestionJob, Conversation, Message, QueryLog, Feedback, ApiKey, Setting, PromptVersion, GoldenSet, GoldenCase, EvalRun, AuditLog. Chunk text and vectors live in Qdrant with payload fields document\_id, version, collection\_id, heading\_path, page, content\_hash, embedding\_model and active.

## 6. Non-functional requirements

### 6.1 Performance (provisional, to be confirmed by the Phase 0 benchmark)

- Query embedding p95 under 150 ms; hybrid search p95 under 150 ms at 1 million chunks.
- Time to first token p95 under 2.5 s; complete answer p95 under 8 s (reranker on).
- Sustain 20 concurrent chat requests for 30 minutes with under 1% errors.
- Ingestion must not push chat latency beyond targets (ingestion workers capped at 2 of 8 threads while chat is active).

### 6.2 Availability and recovery

- Single node, best-effort: 99% availability during business hours; automatic restart of services; alert on failure.
- RPO 24 hours; RTO 4 hours (restore from backup, or rebuild by re-embedding source files).

### 6.3 Security and privacy

- TLS everywhere users connect; password hashing; API keys hashed; least-privilege roles; upload content-type verification and size limits; rate limiting on login and API.
- Retrieved chunks and user questions are sent to Google for generation. Use a paid API tier whose terms exclude training on submitted data (to be verified), document this in a data-handling notice to users, and have the arrangement reviewed against the Uganda Data Protection and Privacy Act, 2019.
- Sensitive collections can be set to retrieval-only (FR-DOC-09).
- Query logs may contain personal data and follow the retention setting FR-ADM-04.

### 6.4 Maintainability and observability

- Structured JSON logs with a request\_id across all services; metrics for latency, queue depth, token use and errors.
- Configuration through environment and admin settings; database migrations versioned; documented upgrade and rollback steps.
- Unit tests for chunking and permission filtering; an automated golden-set run before changing model, prompt or retrieval settings.

### 6.5 Usability

Responsive layout, keyboard-accessible chat, clear error and no-answer messages, citations that open the source document at the cited page where possible.

## 7. Generation model and cost

### 7.1 Gemini 1.5 Flash is unavailable

Google shut down gemini-1.5-flash, gemini-1.5-flash-8b and gemini-1.5-pro in September 2025. Gemini 2.0 Flash was also shut down in 2026, and Google's changelog now points new projects to the 3.x Flash line. The prices and rankings in the selection guide therefore no longer apply, and other models in it (for example Claude 3.5 Haiku) should also be re-checked.

### 7.2 Candidates (prices per 1M tokens, from third-party trackers dated October 2026; confirm on ai.google.dev before committing)

| Model | Input | Output | Monthly cost at planning load\* |
| :-- | :-: | :-: | :-: |
| gemini-3.1-flash-lite | $0.25 | $1.50 | about $153 |
| gemini-3.5-flash-lite | $0.30 | $2.50 | about $221 |
| Gemini 1.5 Flash (retired, for comparison) | $0.075 | $0.30 | about $38 |

\*288M input and 54M output tokens per month (6,000 queries per day, about 1,600 input and 300 output tokens each). Larger Flash models, which may think before answering, cost several times more. A cheaper older Flash-Lite generation appears in some price lists but is on Google's deprecation schedule, so it is not a safe choice.

### 7.3 Decision rule

Run both candidates (and one larger Flash model as an upper bound) against the golden set. Choose the cheapest model that meets the acceptance thresholds in section 9. The result is stored as the default in Settings and can be changed without a code release.

### 7.4 Cost controls

Short-circuit when retrieval finds nothing; cap context to about 4-6 chunks; disable thinking tokens; optional semantic cache (FR-QA-08); monthly spend cap and per-user quota (FR-ADM-02). Real consumption will differ from the planning load, so the first month is measured, not assumed.

## 8. Risks

| Risk | Impact | Mitigation |
| :-- | :-- | :-- |
| Model retired or repriced by Google | Outage or cost surprise | Model ID is a setting; provider abstraction; subscribe to deprecation notices; golden set makes switching safe |
| CPU lacks AVX2; embedding binaries slow or fail | Slow ingestion | Phase 0 benchmark first; fall back to ONNX or fastembed builds; batch ingestion off-hours |
| Single machine failure (2013 hardware, one SSD) | Downtime, data loss | Off-box backups, tested restore, UPS, documented rebuild |
| Hallucination or wrong citations | Loss of trust | Grounding rules, citation validation, threshold, golden-set gate, feedback loop |
| Permission leak across collections | Data exposure | Filter inside the Qdrant query; automated tests for it |
| Sensitive data sent to cloud | Compliance exposure | Retrieval-only collections, paid-tier terms, user notice, legal review |
| Prompt injection in uploaded documents | Manipulated answers | Context treated as data, output validation, upload restricted to trusted roles |
| Ubuntu 26.04 on old Apple hardware (SMC, fan, drivers) | Instability | Soak test and reboot test before pilot; thermal monitoring |
| Cost above plan | Budget overrun | Spend cap, alerts, per-user quotas |

## 9. Acceptance criteria

A golden set of at least 100 questions (including at least 20 that the documents cannot answer) is built with the document owners. The system is accepted when:

- Recall@5 of the correct source is at least 90%.
- At least 95% of answers in a human-reviewed sample of 100 are fully supported by their cited chunks.
- At least 90% of unanswerable questions receive the no-answer response.
- Performance targets in 6.1 are met in a load test.
- Permission tests show no cross-collection leakage.
- A full restore from backup succeeds on a clean machine.

## 10. Delivery phases (indicative)

| Phase | Duration | Content |
| :-- | :-: | :-- |
| 0. Feasibility | 1 week | CPU and embedding benchmark, chunking tests on real documents, model bake-off, hardened Compose stack |
| 1. Core | 2 weeks | Ingestion, hybrid retrieval, generation, /chat and /search API, golden-set runner |
| 2. Management UI | 3 weeks | Auth and roles, documents, collections, chat page, query log, settings, API keys |
| 3. Hardening and pilot | 2 weeks | Backups, monitoring, load test, security review, pilot with a small group |
| 4. Later | Backlog | Reranker tuning, SSO, URL ingestion, OCR, semantic cache |

## 11. Open questions

1. How many documents and pages are in the initial corpus, and in which languages?
2. Who are the users, and how do they reach the machine (office LAN only, VPN, or a tunnel)?
3. Is any content confidential enough that it cannot be sent to a cloud model?
4. Is the 200-user, 6,000-queries-per-day load a real estimate or a ceiling?
5. What monthly budget is acceptable for generation?
6. Is PostgreSQL acceptable, or should the application use MySQL like the other projects?
7. Which identity source (existing accounts, Google, Microsoft) should SSO eventually use?
