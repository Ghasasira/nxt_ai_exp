# Architecture Decision Records (ADRs)

## ADR-001: PostgreSQL as Primary Relational Database
- **Status:** Accepted (2026-11-12)
- **Context:** SRS §11 Q6 asked whether to use PostgreSQL (SRS §3.1 default) or MySQL.
- **Decision:** Use PostgreSQL (`postgres:17.11-alpine3.23`) for all application metadata, jobs, audit logs, and evaluation runs.

## ADR-002: Configurable Edge Reverse Proxy (Caddy)
- **Status:** Accepted (2026-11-12)
- **Context:** Production inbound connectivity (LAN-only, VPN, or Cloudflare/Tailscale tunnel) is still open (SRS §11 Q2).
- **Decision:** Use `caddy:2.11.9-alpine` as the sole public entrypoint (`:80`/`:443`), controlled via `.env`:
  - Default `proxy/Caddyfile`: `tls internal` for LAN/VPN or ACME email for public domains.
  - Tunnel `proxy/Caddyfile.tunnel`: HTTP upstream mode when TLS terminates at a tunnel or corporate proxy.

## ADR-003: Non-Blocking SSE Proxy Architecture for Phase 1 (`web-app`)
- **Status:** Accepted (2026-11-12)
- **Context:** SRS §5.2 requires SSE token streaming on `POST /chat` with 20 concurrent users. Standard PHP-FPM ties up one worker per open stream.
- **Decision:** Run the Phase 1 Laravel application under Laravel Octane (FrankenPHP) or equivalent async worker model so SSE connections do not exhaust PHP workers.

## ADR-004: Cloud-Safe Follow-Up Query Rewriting
- **Status:** Accepted (2026-11-12)
- **Context:** SRS §3.4 step 2 specifies follow-up rewriting while `FR-DOC-09` (`allow_cloud_generation=false`) forbids sending restricted collections' content or queries to cloud LLMs.
- **Decision:** Multi-turn rewriting for restricted/retrieval-only collections must stay on-premises (local rule/context-carry or local micro-model if benchmarked), never routed through Gemini.
