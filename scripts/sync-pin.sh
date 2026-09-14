#!/bin/bash
# Copia a pública gerada no deploy do stnt-ojs para keys/ojsbr.pub.local (pin).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-${OJSBR_PIN_SRC:-/root/data/ojs-keys/ojsbr.pub}}"
DEST="${ROOT}/keys/ojsbr.pub.local"
if [ ! -f "${SRC}" ]; then
  echo "Pin não encontrada em ${SRC}" >&2
  exit 1
fi
if grep -q 'PIN-PLACEHOLDER' "${SRC}"; then
  echo "A origem ainda é placeholder." >&2
  exit 1
fi
cp "${SRC}" "${DEST}"
chmod 644 "${DEST}"
echo "Pin gravada em ${DEST}"
