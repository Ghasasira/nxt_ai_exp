#!/usr/bin/env bash
# Mac Pro (Late 2013 / MacPro6,1) Phase 0 Pre-Flight Audit
# Verifies hardware, CPU flags, disk partition layout, Docker daemon log caps, and cooling.

set -euo pipefail

echo "=== 1. Host & OS ==="
uname -a
if [ -f /etc/os-release ]; then
  grep -E '^(NAME|VERSION)=' /etc/os-release || true
fi

echo
echo "=== 2. CPU & Vector ISA Flags ==="
lscpu | grep -E 'Model name|CPU\(s\):|Thread|Core' || true
if grep -qE '\bavx2\b' /proc/cpuinfo; then
  echo "ISA check: AVX2 supported"
elif grep -qE '\bavx\b' /proc/cpuinfo; then
  echo "ISA check: AVX supported, AVX2 NOT supported (expected on Xeon E5-1620 v2 — benchmark ONNX vs Ollama carefully)"
else
  echo "WARNING: Neither AVX nor AVX2 detected!"
fi

echo
echo "=== 3. Memory ==="
free -h

echo
echo "=== 4. Disk & Partition Layout (Watch 98 GB / limit) ==="
df -h -T -x tmpfs -x devtmpfs
echo
DOCKER_ROOT=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || echo "/var/lib/docker")
echo "Docker root dir: ${DOCKER_ROOT}"
df -h "${DOCKER_ROOT}" 2>/dev/null || true

echo
echo "=== 5. Docker Daemon Log Rotation (/etc/docker/daemon.json) ==="
if [ -f /etc/docker/daemon.json ]; then
  cat /etc/docker/daemon.json
else
  echo "WARNING: /etc/docker/daemon.json missing. Configure log-driver=json-file with max-size=10m and max-file=3."
fi

echo
echo "=== 6. Thermal & Fan Control ==="
if systemctl is-active --quiet macfanctld 2>/dev/null; then
  echo "macfanctld: active"
else
  echo "WARNING: macfanctld is NOT active (risk of heat soak during bulk embedding)"
fi

if command -v sensors >/dev/null 2>&1; then
  sensors | grep -E 'Core|Package|fan' || sensors
else
  echo "lm-sensors not installed (install with: sudo apt-get install -y lm-sensors)"
fi

echo
echo "=== Pre-Flight Complete ==="
