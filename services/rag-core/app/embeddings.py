from __future__ import annotations

from abc import ABC, abstractmethod
from typing import Literal

import httpx

from app.config import Settings

TaskType = Literal["document", "query"]


def apply_nomic_prefix(text: str, task: TaskType, model_name: str) -> str:
    """Apply nomic-embed task prefixes required by SRS section 3.5."""
    if "nomic" not in model_name.lower():
        return text
    prefix = "search_document: " if task == "document" else "search_query: "
    if text.startswith(prefix):
        return text
    return f"{prefix}{text}"


class BaseEmbedder(ABC):
    """Abstract embedding interface shared by runtime and benchmark scripts."""

    provider_name: str
    model_name: str

    @abstractmethod
    async def embed_texts(self, texts: list[str], task: TaskType) -> list[list[float]]:
        """Embed a batch of texts for either document indexing or querying."""

    async def embed_query(self, query: str) -> list[float]:
        """Embed a single search query."""
        vectors = await self.embed_texts([query], task="query")
        return vectors[0]

    @abstractmethod
    async def health_check(self) -> bool:
        """Return True if the embedding backend is ready."""


class OllamaEmbedder(BaseEmbedder):
    """Calls local Ollama /api/embed endpoint (CPU-only on MacPro6,1)."""

    def __init__(self, base_url: str, model_name: str = "nomic-embed-text", timeout: float = 60.0) -> None:
        self.provider_name = "ollama"
        self.base_url = base_url.rstrip("/")
        self.model_name = model_name
        self._client = httpx.AsyncClient(base_url=self.base_url, timeout=timeout)

    async def embed_texts(self, texts: list[str], task: TaskType) -> list[list[float]]:
        if not texts:
            return []
        prefixed = [apply_nomic_prefix(t, task, self.model_name) for t in texts]
        resp = await self._client.post(
            "/api/embed",
            json={"model": self.model_name, "input": prefixed},
        )
        resp.raise_for_status()
        payload = resp.json()
        return payload["embeddings"]

    async def health_check(self) -> bool:
        try:
            resp = await self._client.get("/api/tags", timeout=5.0)
            if resp.status_code != 200:
                return False
            models = [m.get("name", "") for m in resp.json().get("models", [])]
            return any(self.model_name in m for m in models)
        except Exception:
            return False

    async def aclose(self) -> None:
        await self._client.aclose()


class FastEmbedder(BaseEmbedder):
    """ONNX Runtime CPU embedder fallback (useful when testing without AVX2)."""

    def __init__(self, model_name: str = "nomic-ai/nomic-embed-text-v1.5", threads: int | None = None) -> None:
        try:
            from fastembed import TextEmbedding
        except ImportError as exc:
            raise RuntimeError(
                "fastembed is not installed. Install with `pip install .[onnx]` to use ONNX embeddings."
            ) from exc

        self.provider_name = "fastembed"
        self.model_name = model_name
        self._model = TextEmbedding(model_name=model_name, threads=threads)

    async def embed_texts(self, texts: list[str], task: TaskType) -> list[list[float]]:
        if not texts:
            return []
        prefixed = [apply_nomic_prefix(t, task, self.model_name) for t in texts]
        return [vec.tolist() for vec in self._model.embed(prefixed)]

    async def health_check(self) -> bool:
        return self._model is not None


def create_embedder(settings: Settings) -> BaseEmbedder:
    """Instantiate the configured embedding provider."""
    provider = settings.embedding_provider.lower().strip()
    if provider == "ollama":
        return OllamaEmbedder(base_url=settings.ollama_url, model_name=settings.embedding_model)
    if provider == "fastembed":
        model = (
            "nomic-ai/nomic-embed-text-v1.5"
            if settings.embedding_model == "nomic-embed-text"
            else settings.embedding_model
        )
        return FastEmbedder(model_name=model)
    raise ValueError(f"Unsupported embedding_provider: {settings.embedding_provider}")
