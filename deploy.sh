#!/bin/bash
# Script de déploiement sécurisé pour la crèche
# Ce script compile le frontend et déploie le backend + frontend sur OVH via lftp
# Il exclut formellement le config.php pour éviter l'écrasement de la config de prod.
#
# Usage: ./deploy.sh
# Les credentials FTP doivent être définis dans les variables d'environnement :
#   FTP_USER, FTP_PASS, FTP_HOST
# Ou dans un fichier ~/.creche-ftp-credentials (sourcé automatiquement)

set -e

# Charger les credentials depuis un fichier externe s'il existe
CRED_FILE="$HOME/.creche-ftp-credentials"
if [ -f "$CRED_FILE" ]; then
    source "$CRED_FILE"
fi

# Vérifier que les credentials sont définis
if [ -z "$FTP_USER" ] || [ -z "$FTP_PASS" ] || [ -z "$FTP_HOST" ]; then
    echo "❌ Erreur : Variables FTP_USER, FTP_PASS et FTP_HOST requises."
    echo "   Créez le fichier $CRED_FILE avec :"
    echo "     export FTP_USER='votre_user'"
    echo "     export FTP_PASS='votre_pass'"
    echo "     export FTP_HOST='ftp.cluster113.hosting.ovh.net'"
    exit 1
fi

echo "🚀 Début du déploiement sécurisé..."

# 1. Build frontend
echo "📦 Build du frontend..."
cd /data/homes/kah/creche-planning-frontend
npm run build

cd /data/homes/kah/creche-planning-php

# 2. Création d'un fichier de commandes lftp pour le backend
echo "📤 Upload du backend vers /api..."
cat << EOF > deploy_backend.lftp
set ftp:ssl-allow no
set ftp:passive-mode false
set ftp:list-options -a
open -u $FTP_USER,$FTP_PASS $FTP_HOST
mirror -R \
    --exclude-glob "config.php" \
    --exclude-glob "tests/" \
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
cat << EOF > deploy_frontend.lftp
set ftp:ssl-allow no
set ftp:passive-mode false
set ftp:list-options -a
open -u $FTP_USER,$FTP_PASS $FTP_HOST
mirror -R \
    --exclude-glob ".git/" \
    --exclude-glob ".DS_Store" \
    /data/homes/kah/creche-planning-frontend/dist /www/planning
quit
EOF

lftp -f deploy_frontend.lftp
rm deploy_frontend.lftp

echo "✅ Déploiement terminé avec succès ! (config.php préservé)"
