#!/usr/bin/env bash
# Thermal, Disk & Service Readiness Watchdog (SRS §3.7 & §6.2)

set -euo pipefail

MAX_TEMP_C="${MAX_TEMP_C:-82}"
MAX_DISK_PCT="${MAX_DISK_PCT:-85}"
HEALTH_URL="${HEALTH_URL:-https://127.0.0.1/api/v1/ready}"
ALERT_WEBHOOK_URL="${ALERT_WEBHOOK_URL:-}"

alert() {
  local msg="$1"
  echo "[ALERT] $(date -u +%Y-%m-%dT%H:%M:%SZ) ${msg}" >&2
  if [ -n "${ALERT_WEBHOOK_URL}" ]; then
    curl -fsS -X POST -H "Content-Type: application/json" \
      -d "{\"text\":\"[RAG-MacPro] ${msg}\"}" "${ALERT_WEBHOOK_URL}" >/dev/null || true
  fi
}

# 1. Disk threshold check
DISK_PCT=$(df / --output=pcent | tail -n 1 | tr -dc '0-9')
if [ "${DISK_PCT}" -ge "${MAX_DISK_PCT}" ]; then
  alert "Root disk usage is ${DISK_PCT}% (threshold ${MAX_DISK_PCT}%)"
fi

# 2. CPU thermal check
if command -v sensors >/dev/null 2>&1; then
  MAX_SEEN=$(sensors | awk '/Core|Package/ {gsub(/[+°C]/,""); for(i=1;i<=NF;i++) if($i+0>m && $i+0<130) m=$i+0} END {printf "%d", m}')
  if [ "${MAX_SEEN}" -ge "${MAX_TEMP_C}" ]; then
    alert "CPU temperature ${MAX_SEEN}C exceeded ${MAX_TEMP_C}C threshold"
  fi
fi

# 3. Edge readiness check
if ! curl -fsSk --max-time 8 "${HEALTH_URL}" >/dev/null; then
  alert "Service readiness check failed at ${HEALTH_URL}"
fi
