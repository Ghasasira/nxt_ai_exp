from functools import lru_cache
from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Runtime configuration loaded from environment variables."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    rag_internal_secret: str = Field(default="dev-internal-secret")
    rag_callback_secret: str = Field(default="dev-callback-secret")
    web_app_callback_url: str = Field(default="http://web-app:8000/api/internal/ingestion-callback")
    redis_url: str = Field(default="redis://:change_me_redis_password@redis:6379/0")
    ingest_queue_name: str = Field(default="rag:ingest:queue")
    ingest_max_workers: int = Field(default=2)
    uploads_dir: str = Field(default="/data/uploads")

    # Vector DB
    qdrant_url: str = Field(default="http://qdrant:6333")
    qdrant_api_key: str = Field(default="")
    qdrant_collection: str = Field(default="knowledge_chunks_v1")
    qdrant_on_disk: bool = Field(default=True)
    qdrant_quantize: bool = Field(default=True)

    # Embeddings
    embedding_provider: str = Field(default="ollama")  # "ollama" or "fastembed"
    embedding_model: str = Field(default="nomic-embed-text")
    embedding_dim: int = Field(default=768)
    ollama_url: str = Field(default="http://ollama:11434")

    # Chunking
    chunk_min_tokens: int = Field(default=300)
    chunk_max_tokens: int = Field(default=500)
    chunk_overlap_tokens: int = Field(default=50)

    # Retrieval
    retrieval_top_k: int = Field(default=20)
    rerank_top_n: int = Field(default=5)
    reranker_enabled: bool = Field(default=False)
    similarity_threshold: float = Field(default=0.35)

    # Generation (Gemini)
    gemini_api_key: str = Field(default="")
    gemini_model: str = Field(default="gemini-3.1-flash-lite")
    gemini_temperature: float = Field(default=0.0)
    gemini_thinking_budget: int = Field(default=0)
    gemini_max_output_tokens: int = Field(default=1024)
    gemini_timeout_seconds: float = Field(default=20.0)
    gemini_max_retries: int = Field(default=1)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Return cached application settings."""
    return Settings()
