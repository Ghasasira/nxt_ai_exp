#!/usr/bin/env bash
# Mac Pro (Late 2013) Host Hardening & Maintenance Setup (Ubuntu 26.04 LTS)
# Run once with sudo on the target Mac Pro host before pilot go-live.

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root (sudo $0)" >&2
  exit 1
fi

echo "[setup] Configuring Docker daemon log limits (10m x 3)..."
mkdir -p /etc/docker
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOF
systemctl reload docker || systemctl restart docker

echo "[setup] Enabling thermal fan control (macfanctld)..."
if dpkg -s macfanctld >/dev/null 2>&1; then
  systemctl enable --now macfanctld
else
  echo "WARNING: macfanctld package not installed. Install via: apt-get install -y macfanctld lm-sensors"
fi

echo "[setup] Host hardening complete."
echo "Recommended cron jobs (crontab -e):"
echo "  0 2 * * * /opt/nxt_ai/scripts/backup.sh >> /var/log/nxt_ai_backup.log 2>&1"
echo "  */5 * * * * /opt/nxt_ai/scripts/health_watchdog.sh >> /var/log/nxt_ai_watchdog.log 2>&1"
