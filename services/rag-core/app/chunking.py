from __future__ import annotations

import hashlib
import io
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

try:
    import tiktoken

    _ENCODING = tiktoken.get_encoding("cl100k_base")
except Exception:
    _ENCODING = None


class ScannedDocumentError(ValueError):
    """Raised when a PDF contains no extractable text (FR-ING-05)."""


class UnsupportedFileContentError(ValueError):
    """Raised when file content/magic bytes do not match its declared type (FR-DOC-01)."""


ALLOWED_EXTENSIONS = {".pdf", ".docx", ".xlsx", ".csv", ".pptx", ".txt", ".md", ".markdown", ".html", ".htm"}


def validate_file_content(filename: str, data: bytes) -> str:
    """Validate extension and basic content/magic bytes; returns normalized extension."""
    suffix = Path(filename).suffix.lower()
    if suffix not in ALLOWED_EXTENSIONS:
        raise UnsupportedFileContentError(f"Unsupported file extension: {suffix}")
    if not data:
        raise UnsupportedFileContentError(f"File '{filename}' is empty.")

    if suffix == ".pdf":
        if not data[:1024].lstrip().startswith(b"%PDF-"):
            raise UnsupportedFileContentError(f"'{filename}' does not have a valid PDF header.")
    elif suffix in {".docx", ".xlsx", ".pptx"}:
        if not data.startswith(b"PK\x03\x04"):
            raise UnsupportedFileContentError(f"'{filename}' is not a valid Office OpenXML archive.")
    else:
        # Text-based formats must decode cleanly without null bytes
        if b"\x00" in data[:4096]:
            raise UnsupportedFileContentError(f"'{filename}' appears to contain binary null bytes.")

    return suffix


@dataclass(frozen=True)
class TextBlock:
    """Logical document block extracted prior to chunk assembly."""

    text: str
    heading_path: str
    page: int | None = None


@dataclass(frozen=True)
class DocumentChunk:
    """Final chunk ready for embedding and vector indexing."""

    chunk_index: int
    text: str
    heading_path: str
    page: int | None
    token_count: int
    content_hash: str


def count_tokens(text: str) -> int:
    """Count tokens using cl100k_base with an offline regex fallback."""
    if _ENCODING is not None:
        return len(_ENCODING.encode(text))
    # Approximate token count when tiktoken encoding tables are unavailable offline.
    return max(1, int(len(re.findall(r"\S+", text)) * 1.3))


def normalize_for_hash(text: str) -> str:
    """Normalize whitespace so identical chunks yield identical SHA-256 hashes."""
    return re.sub(r"\s+", " ", text).strip()


def compute_content_hash(text: str) -> str:
    """Return SHA-256 hex digest of normalized chunk text."""
    return hashlib.sha256(normalize_for_hash(text).encode("utf-8")).hexdigest()


def _parse_markdown_or_text(text: str, page: int | None = None) -> list[TextBlock]:
    """Split text into structural blocks while tracking Markdown heading hierarchy."""
    blocks: list[TextBlock] = []
    heading_stack: list[tuple[int, str]] = []
    buffer: list[str] = []
    in_table = False

    def current_heading_path() -> str:
        return " > ".join(title for _, title in heading_stack) or "Document"

    def flush_buffer() -> None:
        nonlocal buffer
        joined = "\n".join(buffer).strip()
        buffer = []
        if joined:
            blocks.append(
                TextBlock(
                    text=joined,
                    heading_path=current_heading_path(),
                    page=page,
                )
            )

    heading_re = re.compile(r"^(#{1,6})\s+(.+?)\s*$")
    table_re = re.compile(r"^\s*\|.*\|\s*$")

    for line in text.splitlines():
        heading_match = heading_re.match(line)
        if heading_match:
            flush_buffer()
            in_table = False
            level = len(heading_match.group(1))
            title = heading_match.group(2).strip()
            heading_stack = [(lvl, t) for lvl, t in heading_stack if lvl < level]
            heading_stack.append((level, title))
            continue

        is_table_line = bool(table_re.match(line))
        if is_table_line:
            if not in_table and buffer:
                flush_buffer()
            in_table = True
            buffer.append(line)
            continue

        if in_table and not is_table_line:
            flush_buffer()
            in_table = False

        if not line.strip():
            flush_buffer()
        else:
            buffer.append(line)

    flush_buffer()
    return blocks


def extract_blocks_from_bytes(filename: str, data: bytes) -> list[TextBlock]:
    """Validate and extract structured blocks from PDF, DOCX, XLSX, CSV, PPTX, HTML, MD, or TXT."""
    suffix = validate_file_content(filename, data)

    if suffix in {".md", ".markdown", ".txt"}:
        decoded = data.decode("utf-8", errors="replace")
        return _parse_markdown_or_text(decoded)

    if suffix == ".csv":
        import csv

        decoded = data.decode("utf-8", errors="replace")
        reader = csv.reader(io.StringIO(decoded))
        rows = ["| " + " | ".join(cell.strip().replace("\n", " ") for cell in row) + " |" for row in reader if any(c.strip() for c in row)]
        if not rows:
            return []
        return [TextBlock(text="\n".join(rows), heading_path="CSV Table", page=None)]

    if suffix in {".html", ".htm"}:
        from bs4 import BeautifulSoup

        soup = BeautifulSoup(data.decode("utf-8", errors="replace"), "lxml")
        for tag in soup(["script", "style", "noscript"]):
            tag.decompose()
        lines: list[str] = []
        for el in soup.find_all(["h1", "h2", "h3", "h4", "p", "li", "tr"]):
            if el.name in {"h1", "h2", "h3", "h4"}:
                level = int(el.name[1])
                lines.append(f"{'#' * level} {el.get_text(' ', strip=True)}")
            elif el.name == "tr":
                cells = [c.get_text(" ", strip=True) for c in el.find_all(["th", "td"])]
                if any(cells):
                    lines.append("| " + " | ".join(cells) + " |")
            elif el.name == "li":
                lines.append(f"- {el.get_text(' ', strip=True)}")
            else:
                txt = el.get_text(" ", strip=True)
                if txt:
                    lines.append(txt)
        return _parse_markdown_or_text("\n\n".join(lines))

    if suffix == ".pdf":
        from pypdf import PdfReader

        reader = PdfReader(io.BytesIO(data))
        blocks: list[TextBlock] = []
        total_chars = 0
        for page_num, page_obj in enumerate(reader.pages, start=1):
            page_text = (page_obj.extract_text() or "").strip()
            total_chars += len(page_text)
            if page_text:
                blocks.extend(_parse_markdown_or_text(page_text, page=page_num))
        if total_chars == 0:
            raise ScannedDocumentError(
                f"'{filename}' contains no extractable text and may be a scanned PDF."
            )
        return blocks

    if suffix == ".docx":
        from docx import Document

        doc = Document(io.BytesIO(data))
        blocks = []
        heading_stack: list[tuple[int, str]] = []
        for para in doc.paragraphs:
            text = para.text.strip()
            if not text:
                continue
            style_name = (para.style.name if para.style else "") or ""
            if style_name.startswith("Heading"):
                try:
                    level = int(style_name.replace("Heading", "").strip())
                except ValueError:
                    level = 1
                heading_stack = [(lvl, t) for lvl, t in heading_stack if lvl < level]
                heading_stack.append((level, text))
                continue
            path = " > ".join(t for _, t in heading_stack) or "Document"
            blocks.append(TextBlock(text=text, heading_path=path, page=None))

        for table in doc.tables:
            rows = []
            for row in table.rows:
                cells = [c.text.strip().replace("\n", " ") for c in row.cells]
                rows.append("| " + " | ".join(cells) + " |")
            if rows:
                path = " > ".join(t for _, t in heading_stack) or "Document"
                blocks.append(TextBlock(text="\n".join(rows), heading_path=path, page=None))
        return blocks

    if suffix == ".xlsx":
        from openpyxl import load_workbook

        wb = load_workbook(io.BytesIO(data), read_only=True, data_only=True)
        blocks = []
        for sheet in wb.worksheets:
            rows = []
            for row in sheet.iter_rows(values_only=True):
                cells = [str(c).strip().replace("\n", " ") if c is not None else "" for c in row]
                if any(cells):
                    rows.append("| " + " | ".join(cells) + " |")
            if rows:
                blocks.append(TextBlock(text="\n".join(rows), heading_path=f"Sheet: {sheet.title}", page=None))
        return blocks

    if suffix == ".pptx":
        from pptx import Presentation

        prs = Presentation(io.BytesIO(data))
        blocks = []
        for slide_idx, slide in enumerate(prs.slides, start=1):
            slide_title = f"Slide {slide_idx}"
            if slide.shapes.title and slide.shapes.title.text.strip():
                slide_title = slide.shapes.title.text.strip()
            texts: list[str] = []
            for shape in slide.shapes:
                if shape.has_text_frame:
                    txt = shape.text_frame.text.strip()
                    if txt and txt != slide_title:
                        texts.append(txt)
                if shape.has_table:
                    for row in shape.table.rows:
                        cells = [c.text.strip().replace("\n", " ") for c in row.cells]
                        if any(cells):
                            texts.append("| " + " | ".join(cells) + " |")
            if texts:
                blocks.append(TextBlock(text="\n\n".join(texts), heading_path=slide_title, page=slide_idx))
        return blocks

    raise UnsupportedFileContentError(f"Unsupported file extension: {suffix}")


def _split_oversized_block(block: TextBlock, max_tokens: int, overlap_tokens: int) -> list[TextBlock]:
    """Split a single block larger than max_tokens along line/sentence boundaries."""
    parts = re.split(r"(?<=[.!?])\s+|\n+", block.text)
    pieces: list[TextBlock] = []
    current: list[str] = []
    current_tokens = 0

    for part in parts:
        part = part.strip()
        if not part:
            continue
        part_tokens = count_tokens(part)

        # Hard word split fallback if a single sentence/row still exceeds max_tokens
        if part_tokens > max_tokens:
            if current:
                pieces.append(TextBlock("\n".join(current), block.heading_path, block.page))
                current = []
                current_tokens = 0
            words = part.split()
            word_buf: list[str] = []
            for w in words:
                candidate = " ".join([*word_buf, w])
                if count_tokens(candidate) > max_tokens and word_buf:
                    pieces.append(TextBlock(" ".join(word_buf), block.heading_path, block.page))
                    # Carry tail words for overlap
                    overlap_words = max(1, min(len(word_buf) // 4, overlap_tokens))
                    word_buf = [*word_buf[-overlap_words:], w]
                else:
                    word_buf.append(w)
            if word_buf:
                pieces.append(TextBlock(" ".join(word_buf), block.heading_path, block.page))
            continue

        if current_tokens + part_tokens > max_tokens and current:
            pieces.append(TextBlock(" ".join(current), block.heading_path, block.page))
            # Retain trailing parts up to overlap_tokens
            overlap_buf: list[str] = []
            overlap_count = 0
            for prev in reversed(current):
                t = count_tokens(prev)
                if overlap_count + t > overlap_tokens:
                    break
                overlap_buf.insert(0, prev)
                overlap_count += t
            current = [*overlap_buf, part]
            current_tokens = overlap_count + part_tokens
        else:
            current.append(part)
            current_tokens += part_tokens

    if current:
        pieces.append(TextBlock(" ".join(current), block.heading_path, block.page))
    return pieces


def chunk_blocks(
    blocks: Iterable[TextBlock],
    min_tokens: int = 300,
    max_tokens: int = 500,
    overlap_tokens: int = 50,
    deduplicate: bool = True,
) -> list[DocumentChunk]:
    """Assemble structural blocks into 300-500 token chunks with overlap and deduplication."""
    expanded: list[TextBlock] = []
    for block in blocks:
        if count_tokens(block.text) > max_tokens:
            expanded.extend(_split_oversized_block(block, max_tokens, overlap_tokens))
        else:
            expanded.append(block)

    chunks: list[DocumentChunk] = []
    seen_hashes: set[str] = set()
    buf: list[TextBlock] = []
    buf_tokens = 0

    def emit_chunk(items: list[TextBlock]) -> None:
        if not items:
            return
        combined = "\n\n".join(b.text for b in items).strip()
        if not combined:
            return
        content_hash = compute_content_hash(combined)
        if deduplicate and content_hash in seen_hashes:
            return
        seen_hashes.add(content_hash)
        chunks.append(
            DocumentChunk(
                chunk_index=len(chunks),
                text=combined,
                heading_path=items[-1].heading_path,
                page=items[0].page,
                token_count=count_tokens(combined),
                content_hash=content_hash,
            )
        )

    for block in expanded:
        blk_tokens = count_tokens(block.text)
        heading_changed = bool(buf and buf[-1].heading_path != block.heading_path)
        page_changed = bool(buf and buf[-1].page != block.page and buf_tokens >= min_tokens)

        if buf and (buf_tokens + blk_tokens > max_tokens or (heading_changed and buf_tokens >= min_tokens) or page_changed):
            emit_chunk(buf)
            # Carry trailing overlap only within the same heading section
            if not heading_changed and overlap_tokens > 0:
                carried: list[TextBlock] = []
                carried_tokens = 0
                for prev in reversed(buf):
                    pt = count_tokens(prev.text)
                    if carried_tokens + pt > overlap_tokens:
                        break
                    carried.insert(0, prev)
                    carried_tokens += pt
                buf = [*carried, block]
                buf_tokens = carried_tokens + blk_tokens
            else:
                buf = [block]
                buf_tokens = blk_tokens
        else:
            buf.append(block)
            buf_tokens += blk_tokens

    emit_chunk(buf)
    return chunks
