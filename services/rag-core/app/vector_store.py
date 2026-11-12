from __future__ import annotations

import hashlib
import re
import uuid
from collections import Counter
from dataclasses import dataclass
from typing import Sequence

from qdrant_client import QdrantClient, models

TOKEN_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{1,}")


@dataclass(frozen=True)
class RetrievedChunk:
    """Chunk returned from Qdrant hybrid retrieval."""

    chunk_id: str
    document_id: str
    collection_id: str
    version: int
    title: str
    heading_path: str
    page: int | None
    text: str
    score: float


def text_to_sparse_vector(text: str) -> models.SparseVector:
    """Build a deterministic term-frequency sparse vector paired with Qdrant IDF."""
    tokens = [t.lower() for t in TOKEN_RE.findall(text)]
    if not tokens:
        return models.SparseVector(indices=[], values=[])

    counts = Counter(tokens)
    index_weights: dict[int, float] = {}
    for token, tf in counts.items():
        # Stable 31-bit hash index across processes and restarts
        digest = hashlib.blake2b(token.encode("utf-8"), digest_size=4).digest()
        idx = int.from_bytes(digest, "big") & 0x7FFFFFFF
        index_weights[idx] = index_weights.get(idx, 0.0) + float(tf)

    sorted_items = sorted(index_weights.items(), key=lambda kv: kv[0])
    return models.SparseVector(
        indices=[k for k, _ in sorted_items],
        values=[v for _, v in sorted_items],
    )


class HybridVectorStore:
    """Qdrant hybrid (dense + sparse BM25-IDF + RRF) store with strict ACL filters."""

    def __init__(
        self,
        url: str,
        api_key: str = "",
        collection_name: str = "knowledge_chunks_v1",
        embedding_dim: int = 768,
        on_disk: bool = True,
        quantize: bool = True,
    ) -> None:
        self.collection_name = collection_name
        self.embedding_dim = embedding_dim
        self.on_disk = on_disk
        self.quantize = quantize
        self.client = QdrantClient(
            url=url,
            api_key=api_key or None,
            timeout=15.0,
        )

    def ensure_collection(self) -> None:
        """Create the hybrid collection and payload indexes if absent."""
        if self.client.collection_exists(self.collection_name):
            return

        quantization_cfg = (
            models.ScalarQuantization(
                scalar=models.ScalarQuantizationConfig(
                    type=models.ScalarType.INT8,
                    quantile=0.99,
                    always_ram=True,
                )
            )
            if self.quantize
            else None
        )

        self.client.create_collection(
            collection_name=self.collection_name,
            vectors_config={
                "dense": models.VectorParams(
                    size=self.embedding_dim,
                    distance=models.Distance.COSINE,
                    on_disk=self.on_disk,
                )
            },
            sparse_vectors_config={
                "sparse": models.SparseVectorParams(
                    modifier=models.Modifier.IDF,
                )
            },
            hnsw_config=models.HnswConfigDiff(on_disk=self.on_disk),
            quantization_config=quantization_cfg,
        )

        for field_name, schema_type in (
            ("collection_id", models.PayloadSchemaType.KEYWORD),
            ("document_id", models.PayloadSchemaType.KEYWORD),
            ("tags", models.PayloadSchemaType.KEYWORD),
            ("active", models.PayloadSchemaType.BOOL),
            ("version", models.PayloadSchemaType.INTEGER),
            ("effective_ts", models.PayloadSchemaType.INTEGER),
        ):
            self.client.create_payload_index(
                collection_name=self.collection_name,
                field_name=field_name,
                field_schema=schema_type,
            )

    def upsert_chunks(
        self,
        *,
        document_id: str,
        collection_id: str,
        title: str,
        version: int,
        embedding_model: str,
        chunks: Sequence[dict],
        dense_vectors: Sequence[list[float]],
        active: bool = True,
        tags: list[str] | None = None,
        effective_ts: int | None = None,
    ) -> int:
        """Upsert dense + sparse chunk points into Qdrant."""
        self.ensure_collection()
        points: list[models.PointStruct] = []
        for chunk, dense_vec in zip(chunks, dense_vectors, strict=True):
            chunk_idx = chunk["chunk_index"]
            point_id = str(
                uuid.uuid5(
                    uuid.NAMESPACE_URL,
                    f"{document_id}:v{version}:{chunk_idx}",
                )
            )
            points.append(
                models.PointStruct(
                    id=point_id,
                    vector={
                        "dense": dense_vec,
                        "sparse": text_to_sparse_vector(chunk["text"]),
                    },
                    payload={
                        "document_id": document_id,
                        "collection_id": collection_id,
                        "title": title,
                        "version": version,
                        "chunk_index": chunk_idx,
                        "heading_path": chunk.get("heading_path", "Document"),
                        "page": chunk.get("page"),
                        "content_hash": chunk["content_hash"],
                        "embedding_model": embedding_model,
                        "active": active,
                        "tags": tags or [],
                        "effective_ts": effective_ts,
                        "text": chunk["text"],
                    },
                )
            )

        if points:
            self.client.upsert(collection_name=self.collection_name, points=points, wait=True)
        return len(points)

    def activate_document_version(self, document_id: str, active_version: int) -> None:
        """Atomically mark active_version=True and deactivate older versions (SRS §3.3)."""
        if not self.client.collection_exists(self.collection_name):
            return
        # Activate newly indexed version first so there is no gap in availability
        self.client.set_payload(
            collection_name=self.collection_name,
            payload={"active": True},
            points=models.Filter(
                must=[
                    models.FieldCondition(key="document_id", match=models.MatchValue(value=document_id)),
                    models.FieldCondition(key="version", match=models.MatchValue(value=active_version)),
                ]
            ),
            wait=True,
        )
        # Deactivate any other versions of this document
        self.client.set_payload(
            collection_name=self.collection_name,
            payload={"active": False},
            points=models.Filter(
                must=[models.FieldCondition(key="document_id", match=models.MatchValue(value=document_id))],
                must_not=[models.FieldCondition(key="version", match=models.MatchValue(value=active_version))],
            ),
            wait=True,
        )

    def delete_document(self, document_id: str) -> None:
        """Remove all chunks and vectors belonging to document_id (FR-DOC-05)."""
        if not self.client.collection_exists(self.collection_name):
            return
        self.client.delete(
            collection_name=self.collection_name,
            points_selector=models.FilterSelector(
                filter=models.Filter(
                    must=[models.FieldCondition(key="document_id", match=models.MatchValue(value=document_id))]
                )
            ),
            wait=True,
        )

    def get_document_chunks(self, document_id: str, version: int | None = None, limit: int = 200) -> list[dict]:
        """Inspect indexed chunks for a document (FR-DOC-07)."""
        if not self.client.collection_exists(self.collection_name):
            return []
        must: list[models.Condition] = [
            models.FieldCondition(key="document_id", match=models.MatchValue(value=document_id))
        ]
        if version is not None:
            must.append(models.FieldCondition(key="version", match=models.MatchValue(value=version)))
        else:
            must.append(models.FieldCondition(key="active", match=models.MatchValue(value=True)))

        records, _ = self.client.scroll(
            collection_name=self.collection_name,
            scroll_filter=models.Filter(must=must),
            limit=limit,
            with_payload=True,
            with_vectors=False,
        )
        items = []
        for rec in records:
            p = rec.payload or {}
            items.append(
                {
                    "chunk_id": str(rec.id),
                    "chunk_index": p.get("chunk_index", 0),
                    "version": p.get("version"),
                    "heading_path": p.get("heading_path"),
                    "page": p.get("page"),
                    "content_hash": p.get("content_hash"),
                    "active": p.get("active"),
                    "text": p.get("text", ""),
                }
            )
        items.sort(key=lambda x: (int(x.get("version") or 0), int(x.get("chunk_index") or 0)))
        return items

    def search(
        self,
        *,
        query_text: str,
        query_vector: list[float],
        collection_ids: list[str],
        top_k: int = 20,
        document_ids: list[str] | None = None,
        tags: list[str] | None = None,
        date_from_ts: int | None = None,
        date_to_ts: int | None = None,
    ) -> list[RetrievedChunk]:
        """Hybrid search filtered INSIDE Qdrant by permitted collection_ids and active=True."""
        # FR-QA-07: Never query without an explicit collection allow-list.
        if not collection_ids:
            return []

        must_conditions: list[models.Condition] = [
            models.FieldCondition(
                key="collection_id",
                match=models.MatchAny(any=collection_ids),
            ),
            models.FieldCondition(
                key="active",
                match=models.MatchValue(value=True),
            ),
        ]
        if document_ids:
            must_conditions.append(
                models.FieldCondition(
                    key="document_id",
                    match=models.MatchAny(any=document_ids),
                )
            )
        if tags:
            must_conditions.append(
                models.FieldCondition(
                    key="tags",
                    match=models.MatchAny(any=tags),
                )
            )
        if date_from_ts is not None or date_to_ts is not None:
            must_conditions.append(
                models.FieldCondition(
                    key="effective_ts",
                    range=models.Range(gte=date_from_ts, lte=date_to_ts),
                )
            )

        acl_filter = models.Filter(must=must_conditions)
        sparse_query = text_to_sparse_vector(query_text)
        prefetch_limit = max(top_k * 2, 20)

        prefetches = [
            models.Prefetch(
                query=query_vector,
                using="dense",
                filter=acl_filter,
                limit=prefetch_limit,
            ),
        ]
        if sparse_query.indices:
            prefetches.append(
                models.Prefetch(
                    query=sparse_query,
                    using="sparse",
                    filter=acl_filter,
                    limit=prefetch_limit,
                )
            )

        response = self.client.query_points(
            collection_name=self.collection_name,
            prefetch=prefetches,
            query=models.FusionQuery(fusion=models.Fusion.RRF),
            limit=top_k,
            with_payload=True,
        )

        results: list[RetrievedChunk] = []
        for pt in response.points:
            payload = pt.payload or {}
            results.append(
                RetrievedChunk(
                    chunk_id=str(pt.id),
                    document_id=str(payload.get("document_id", "")),
                    collection_id=str(payload.get("collection_id", "")),
                    version=int(payload.get("version", 1)),
                    title=str(payload.get("title", "Untitled")),
                    heading_path=str(payload.get("heading_path", "")),
                    page=payload.get("page"),
                    text=str(payload.get("text", "")),
                    score=float(pt.score or 0.0),
                )
            )
        return results

    def health_check(self) -> bool:
        """Verify Qdrant API connectivity."""
        try:
            self.client.get_collections()
            return True
        except Exception:
            return False
