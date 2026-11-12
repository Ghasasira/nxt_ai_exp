#!/usr/bin/env bash
# Nightly Backup Script for RAG Knowledge Agent (SRS §3.7 & §6.2: RPO 24h)
# Backs up PostgreSQL, Qdrant snapshot, and uploaded files, verifies checksums,
# prunes old local archives, and optionally syncs off-box via rsync/scp.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -f "${ROOT_DIR}/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  source "${ROOT_DIR}/.env"
  set +a
fi

BACKUP_ROOT="${BACKUP_ROOT:-${ROOT_DIR}/backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
QDRANT_COLLECTION="${QDRANT_COLLECTION:-knowledge_chunks_v1}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST_DIR="${BACKUP_ROOT}/${STAMP}"
STATUS_FILE="${BACKUP_ROOT}/last_backup.json"

mkdir -p "${DEST_DIR}"

echo "[backup] Starting backup at ${STAMP} -> ${DEST_DIR}"

# 1. PostgreSQL dump (custom compressed format)
echo "[backup] Dumping PostgreSQL database (${POSTGRES_DB:-rag_agent})..."
docker exec rag-postgres pg_dump \
  -U "${POSTGRES_USER:-rag_app}" \
  -d "${POSTGRES_DB:-rag_agent}" \
  -Fc > "${DEST_DIR}/postgres.dump"

# 2. Qdrant collection snapshot via internal container loopback
echo "[backup] Creating Qdrant snapshot for ${QDRANT_COLLECTION}..."
SNAP_NAME=$(docker exec -e QDRANT_API_KEY="${QDRANT_API_KEY:-}" rag-qdrant bash -c "
  curl -fsS -X POST -H \"api-key: \${QDRANT_API_KEY}\" \"http://127.0.0.1:6333/collections/${QDRANT_COLLECTION}/snapshots\" \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)[\"result\"][\"name\"])' 2>/dev/null || true
")

if [ -n "${SNAP_NAME}" ]; then
  docker exec -e QDRANT_API_KEY="${QDRANT_API_KEY:-}" rag-qdrant \
    curl -fsS -H "api-key: ${QDRANT_API_KEY:-}" \
    "http://127.0.0.1:6333/collections/${QDRANT_COLLECTION}/snapshots/${SNAP_NAME}" \
    > "${DEST_DIR}/qdrant_${QDRANT_COLLECTION}.snapshot"
  docker exec -e QDRANT_API_KEY="${QDRANT_API_KEY:-}" rag-qdrant \
    curl -fsS -X DELETE -H "api-key: ${QDRANT_API_KEY:-}" \
    "http://127.0.0.1:6333/collections/${QDRANT_COLLECTION}/snapshots/${SNAP_NAME}" >/dev/null || true
else
  echo "[backup] Note: Qdrant collection '${QDRANT_COLLECTION}' not found or curl unavailable; copying storage volume archive."
  docker run --rm -v nxt_ai_qdrant_data:/source:ro -v "${DEST_DIR}:/backup" alpine:3.20 \
    tar -czf /backup/qdrant_volume.tar.gz -C /source .
fi

# 3. Uploaded source documents volume
echo "[backup] Archiving uploaded source documents..."
docker run --rm -v nxt_ai_uploads_data:/source:ro -v "${DEST_DIR}:/backup" alpine:3.20 \
  tar -czf /backup/uploads.tar.gz -C /source .

# 4. Integrity checksums
(
  cd "${DEST_DIR}"
  sha256sum ./* > SHA256SUMS
)

# 5. Optional off-box replication (SRS §3.7: copied to a second machine)
OFFBOX_SYNCED="false"
if [ -n "${BACKUP_OFFBOX_DEST:-}" ]; then
  echo "[backup] Syncing to off-box destination: ${BACKUP_OFFBOX_DEST}"
  rsync -az --delete "${BACKUP_ROOT}/" "${BACKUP_OFFBOX_DEST}"
  OFFBOX_SYNCED="true"
fi

# 6. Prune old local backups to protect 256 GB Mac Pro SSD
find "${BACKUP_ROOT}" -mindepth 1 -maxdepth 1 -type d -mtime "+${RETENTION_DAYS}" -exec rm -rf {} +

TOTAL_BYTES=$(du -sb "${DEST_DIR}" | awk '{print $1}')
cat > "${STATUS_FILE}" <<EOF
{
  "timestamp": "${STAMP}",
  "status": "ok",
  "size_bytes": ${TOTAL_BYTES},
  "offbox_synced": ${OFFBOX_SYNCED},
  "path": "${DEST_DIR}"
}
EOF

echo "[backup] Completed successfully (${TOTAL_BYTES} bytes)."
