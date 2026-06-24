#!/bin/bash
# Script de déploiement sécurisé pour la crèche
# Ce script compile le frontend et déploie le backend + frontend sur OVH via lftp
# Il exclut formellement le config.php pour éviter l'écrasement de la config de prod.

set -e

echo "🚀 Début du déploiement sécurisé..."

# 1. Build frontend
echo "📦 Build du frontend..."
cd /data/homes/kah/creche-planning-frontend
npm run build

cd /data/homes/kah/creche-planning-php

# 2. Création d'un fichier de commandes lftp pour le backend
echo "📤 Upload du backend vers /api..."
cat << 'EOF' > deploy_backend.lftp
set ftp:ssl-allow no
set ftp:passive-mode false
set ftp:list-options -a
open -u lesfruitp,LfdlP1980 ftp.cluster113.hosting.ovh.net
mirror -R \
    --exclude-glob "config.php" \
    --exclude-glob "tests/" \
    --exclude-glob "vendor/" \
    --exclude-glob ".git/" \
    --exclude-glob ".DS_Store" \
    --exclude-glob "deploy*" \
    --exclude-glob "*.log" \
    . /www/planning/api
quit
EOF

lftp -f deploy_backend.lftp
rm deploy_backend.lftp

# 3. Création d'un fichier de commandes lftp pour le frontend
echo "📤 Upload du frontend vers /planning..."
cat << 'EOF' > deploy_frontend.lftp
set ftp:ssl-allow no
set ftp:passive-mode false
set ftp:list-options -a
open -u lesfruitp,LfdlP1980 ftp.cluster113.hosting.ovh.net
mirror -R \
    --exclude-glob ".git/" \
    --exclude-glob ".DS_Store" \
    /data/homes/kah/creche-planning-frontend/dist /www/planning
quit
EOF

lftp -f deploy_frontend.lftp
rm deploy_frontend.lftp

echo "✅ Déploiement terminé avec succès ! (config.php préservé)"
