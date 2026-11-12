# Data Handling & Privacy Notice (Draft for Legal Review)

**System:** RAG Knowledge Agent (On-Premises Retrieval + Cloud Generation)  
**Compliance Reference:** Uganda Data Protection and Privacy Act, 2019 (SRS §6.3)

## 1. What Remains On-Premises
- Uploaded source documents, extracted chunks, dense/sparse vector indices (Qdrant), embedding inference (`nomic-embed-text` via local Ollama), user accounts, role permissions, and audit logs reside exclusively on the organisation's on-premises Mac Pro server and encrypted off-box backups.
- Collections marked **Retrieval-Only** (`allow_cloud_generation = false`) never transmit document passages, user questions, or follow-up rewrites to external cloud APIs.

## 2. What Is Sent to Google Gemini (Cloud Generation)
- When a user queries a collection with cloud generation enabled, only the user's question (plus up to the last 4–6 retrieved text snippets, typically 300–500 tokens each) is transmitted over HTTPS to Google's Gemini API to synthesize a cited answer.
- **Zero-Training API Tier Requirement:** Production deployment must use a paid Google Gemini API tier whose terms exclude submitted prompts and retrieved passages from model training and human review. Confirm active billing tier terms prior to go-live.

## 3. Data Retention & User Rights
- Administrators configure query log retention (`query_log_retention` days, default 90 days) in **Admin → Settings** and may redact historical `query_text` and `answer_text` while preserving anonymized token/cost telemetry (`FR-ADM-04`).
- Audit logs record uploads, deletions, logins, API key usage, and configuration changes for accountability (`FR-AUTH-06`).
