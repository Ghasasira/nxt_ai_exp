from __future__ import annotations

import io
import json
import os
import sys
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest
from fastapi.testclient import TestClient

RAG_CORE_DIR = Path(__file__).resolve().parents[1]
if str(RAG_CORE_DIR) not in sys.path:
    sys.path.insert(0, str(RAG_CORE_DIR))

from app.chunking import (
    UnsupportedFileContentError,
    chunk_blocks,
    extract_blocks_from_bytes,
    validate_file_content,
)
from app.config import Settings
from app.embeddings import apply_nomic_prefix
from app.generator import GeminiGenerator, build_context_prompt, validate_citations
from app.main import app
from app.vector_store import HybridVectorStore, RetrievedChunk, text_to_sparse_vector
from app.worker import sign_callback_payload


def test_nomic_prefixes() -> None:
    assert apply_nomic_prefix("hello", "document", "nomic-embed-text") == "search_document: hello"
    assert apply_nomic_prefix("hello", "query", "nomic-embed-text") == "search_query: hello"
    assert apply_nomic_prefix("search_query: hello", "query", "nomic-embed-text") == "search_query: hello"
    assert apply_nomic_prefix("hello", "query", "bge-small-en-v1.5") == "hello"


def test_chunking_preserves_headings_and_deduplicates() -> None:
    para = " ".join(["word"] * 180)
    md = f"# Alpha\n\n{para}\n\n# Beta\n\n{para}\n\n## Sub\n\n| ColA | ColB |\n| --- | --- |\n| 1 | 2 |\n"
    blocks = extract_blocks_from_bytes("sample.md", md.encode("utf-8"))
    chunks = chunk_blocks(blocks, min_tokens=150, max_tokens=300, overlap_tokens=30, deduplicate=True)

    assert len(chunks) >= 2
    assert any("Alpha" in c.heading_path for c in chunks)
    hashes = [c.content_hash for c in chunks]
    assert len(hashes) == len(set(hashes))


def test_magic_byte_validation_rejects_spoofed_files() -> None:
    with pytest.raises(UnsupportedFileContentError):
        validate_file_content("fake.pdf", b"not actually a pdf")

    with pytest.raises(UnsupportedFileContentError):
        validate_file_content("fake.docx", b"plain text pretending to be docx")

    with pytest.raises(UnsupportedFileContentError):
        validate_file_content("evil.exe", b"MZ\x90\x00")


def test_html_csv_xlsx_pptx_extractors() -> None:
    # HTML
    html = b"<html><body><h1>Title</h1><p>First paragraph.</p><table><tr><th>K</th><th>V</th></tr><tr><td>A</td><td>1</td></tr></table></body></html>"
    html_blocks = extract_blocks_from_bytes("page.html", html)
    assert any("Title" in b.heading_path for b in html_blocks)
    assert any("| K | V |" in b.text for b in html_blocks)

    # CSV
    csv_bytes = b"Name,Role\nAlice,Admin\nBob,Reader\n"
    csv_blocks = extract_blocks_from_bytes("users.csv", csv_bytes)
    assert "| Alice | Admin |" in csv_blocks[0].text

    # XLSX
    from openpyxl import Workbook

    wb = Workbook()
    ws = wb.active
    ws.title = "Budget"
    ws.append(["Item", "Cost"])
    ws.append(["Gemini", 153])
    xlsx_buf = io.BytesIO()
    wb.save(xlsx_buf)
    xlsx_blocks = extract_blocks_from_bytes("budget.xlsx", xlsx_buf.getvalue())
    assert xlsx_blocks[0].heading_path == "Sheet: Budget"
    assert "| Gemini | 153 |" in xlsx_blocks[0].text

    # PPTX
    from pptx import Presentation

    prs = Presentation()
    slide = prs.slides.add_slide(prs.slide_layouts[1])
    slide.shapes.title.text = "Architecture"
    slide.placeholders[1].text = "Mac Pro hosts Qdrant and Ollama."
    pptx_buf = io.BytesIO()
    prs.save(pptx_buf)
    pptx_blocks = extract_blocks_from_bytes("deck.pptx", pptx_buf.getvalue())
    assert pptx_blocks[0].heading_path == "Architecture"
    assert "Qdrant" in pptx_blocks[0].text


def test_sparse_vector_is_deterministic_and_sorted() -> None:
    v1 = text_to_sparse_vector("Policy AC-12 applies to Qdrant and AC-12.")
    v2 = text_to_sparse_vector("Policy AC-12 applies to Qdrant and AC-12.")
    assert v1.indices == v2.indices
    assert v1.values == v2.values
    assert v1.indices == sorted(v1.indices)
    assert len(v1.indices) == len(set(v1.indices))


def test_acl_search_rejects_empty_collection_ids() -> None:
    store = HybridVectorStore(url="http://127.0.0.1:6333", api_key="test")
    results = store.search(
        query_text="anything",
        query_vector=[0.1] * 768,
        collection_ids=[],
        top_k=5,
    )
    assert results == []


def test_citation_validation_and_local_rewriting() -> None:
    chunk = RetrievedChunk(
        chunk_id="chunk_abc12345",
        document_id="doc-1",
        collection_id="col-1",
        version=1,
        title="Policy Guide",
        heading_path="Access Control",
        page=3,
        text="Admins must rotate API keys every 90 days.",
        score=0.82,
    )
    prompt = build_context_prompt("How often rotate keys?", [chunk])
    assert '<chunk id="chunk_abc12345"' in prompt
    assert "</context>" in prompt

    valid, invalid = validate_citations("Rotate every 90 days [chunk_abc12345] and [fake_99999999].", [chunk])
    assert valid == ["chunk_abc12345"]
    assert invalid == ["fake_99999999"]

    gen = GeminiGenerator(Settings(gemini_api_key=""))
    rewritten = gen.rewrite_followup_query(
        query="What about managers?",
        history=[{"role": "user", "content": "Key rotation policy"}],
        allow_cloud_generation=False,
    )
    assert "Key rotation policy" in rewritten
    assert "What about managers?" in rewritten

    res = gen.generate(query="How often rotate keys?", chunks=[chunk], allow_cloud_generation=False)
    assert res.mode == "retrieval_only"
    assert res.grounded is True
    assert "chunk_abc12345" in res.cited_chunk_ids


def test_callback_signature_format() -> None:
    sig = sign_callback_payload(b'{"status":"indexed"}', "super-secret")
    assert sig.startswith("sha256=")
    assert len(sig) == 7 + 64


def test_api_health_auth_and_ingest_enqueue() -> None:
    client = TestClient(app)
    assert client.get("/health").status_code == 200
    assert client.post("/internal/chunk", json={"filename": "a.md", "content": "# Hi\nHello."}).status_code == 401

    secret = os.environ.get("RAG_INTERNAL_SECRET", "dev-internal-secret")
    authed = client.post(
        "/internal/chunk",
        headers={"X-Internal-Secret": secret},
        json={"filename": "a.md", "content": "# Hi\nHello world."},
    )
    assert authed.status_code == 200
    assert authed.json()["count"] == 1

    mock_redis = MagicMock()
    with patch("app.main.redis.from_url", return_value=mock_redis):
        resp = client.post(
            "/internal/ingest",
            headers={"X-Internal-Secret": secret},
            json={
                "job_id": "job-1",
                "document_id": "doc-1",
                "collection_id": "col-1",
                "title": "Handbook",
                "version": 1,
                "filename": "handbook.pdf",
                "storage_path": "col-1/doc-1/v1.pdf",
            },
        )
    assert resp.status_code == 202
    assert resp.json()["status"] == "queued"
    mock_redis.lpush.assert_called_once()
