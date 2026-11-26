#!/usr/bin/env bash
# ==============================================================================
# Keystone Advisors - Script de Despliegue Rápido a dev.keystoneadvisors.cl
# Correlativo: SOL-0322
# ==============================================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "🚀 [1/3] Compilando Next.js estático (out/)..."
npm run build

echo "📡 [2/3] Sincronizando solo Frontend con Hostinger dev.keystoneadvisors.cl vía SSH (respetando api/, lib/ y data/)..."
SSH_KEY="/Users/maritzavasquez/Documents/Antigravity/id_ed25519_nb_mary"
rsync -avz --delete \
  --exclude='.htaccess' \
  --exclude='api/' \
  --exclude='lib/' \
  --exclude='data/' \
  --exclude='*.docx' \
  --exclude='_handoff_*' \
  -e "ssh -i \"$SSH_KEY\" -p 65002 -o StrictHostKeyChecking=no" \
  out/ u705937345@156.67.72.209:/home/u705937345/domains/keystoneadvisors.cl/public_html/dev/

echo "✅ [3/3] Despliegue exitoso en https://dev.keystoneadvisors.cl"

