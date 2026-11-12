#!/usr/bin/env bash
# Clean-Machine Restore Script for RAG Knowledge Agent (SRS §6.2 & §9: RTO 4h)
# Usage: ./scripts/restore.sh /path/to/backups/20261112T020000Z

set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: $0 <backup_directory>" >&2
  exit 1
fi

BACKUP_DIR="$1"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -d "${BACKUP_DIR}" ]; then
  echo "Backup directory not found: ${BACKUP_DIR}" >&2
  exit 1
fi

if [ -f "${ROOT_DIR}/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  source "${ROOT_DIR}/.env"
  set +a
fi

QDRANT_COLLECTION="${QDRANT_COLLECTION:-knowledge_chunks_v1}"

echo "[restore] Verifying SHA256SUMS..."
(
  cd "${BACKUP_DIR}"
  sha256sum -c SHA256SUMS
)

echo "[restore] Restoring PostgreSQL database..."
docker exec -i rag-postgres pg_restore \
  -U "${POSTGRES_USER:-rag_app}" \
  -d "${POSTGRES_DB:-rag_agent}" \
  --clean --if-exists < "${BACKUP_DIR}/postgres.dump"

echo "[restore] Restoring uploaded source files..."
docker run --rm -v nxt_ai_uploads_data:/target -v "${BACKUP_DIR}:/backup:ro" alpine:3.20 \
  sh -c "rm -rf /target/* && tar -xzf /backup/uploads.tar.gz -C /target"

if [ -f "${BACKUP_DIR}/qdrant_${QDRANT_COLLECTION}.snapshot" ]; then
  echo "[restore] Restoring Qdrant snapshot for ${QDRANT_COLLECTION}..."
  docker cp "${BACKUP_DIR}/qdrant_${QDRANT_COLLECTION}.snapshot" rag-qdrant:/tmp/restore.snapshot
  docker exec -e QDRANT_API_KEY="${QDRANT_API_KEY:-}" rag-qdrant bash -c "
    curl -fsS -X POST -H \"api-key: \${QDRANT_API_KEY}\" \
      -F 'snapshot=@/tmp/restore.snapshot' \
      \"http://127.0.0.1:6333/collections/${QDRANT_COLLECTION}/snapshots/upload?priority=snapshot\" && \
    rm -f /tmp/restore.snapshot
  "
elif [ -f "${BACKUP_DIR}/qdrant_volume.tar.gz" ]; then
  echo "[restore] Restoring Qdrant volume archive (restarting rag-qdrant)..."
  docker compose -f "${ROOT_DIR}/compose.yaml" stop qdrant
  docker run --rm -v nxt_ai_qdrant_data:/target -v "${BACKUP_DIR}:/backup:ro" alpine:3.20 \
    sh -c "rm -rf /target/* && tar -xzf /backup/qdrant_volume.tar.gz -C /target"
  docker compose -f "${ROOT_DIR}/compose.yaml" start qdrant
fi

echo "[restore] Running Laravel cache clear & readiness smoke check..."
docker exec rag-web-app php artisan optimize:clear
docker exec rag-web-app php -r "
  \$body = @file_get_contents('http://127.0.0.1:8000/api/v1/ready');
  if (\$body === false) { fwrite(STDERR, \"Readiness probe failed\n\"); exit(1); }
  echo \$body . \"\n\";
"

echo "[restore] Restore complete."
