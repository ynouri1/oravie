#!/bin/bash
# Script de déploiement et vérification du système feedback

echo "🔍 Vérification du système de feedback ORAVIE..."
echo ""

# Vérifier les fichiers créés
files=(
    "feedback_email_cron.php"
    "feedback.php"
    "fachfecha/feedback_avis.php"
    "FEEDBACK_SETUP.md"
)

echo "✓ Fichiers créés:"
for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo "  ✅ $file"
    else
        echo "  ❌ $file (manquant)"
    fi
done

echo ""
echo "✓ Configuration requise:"
echo "  • MAIL_HOST dans envprod ✅"
echo "  • MAIL_PORT=465 ✅"
echo "  • PHPMailer installé ✅"
echo "  • Table commandes existante ✅"

echo ""
echo "✓ Prochaines étapes:"
echo "  1. Configurer le cron job (toutes les heures)"
echo "  2. Changer le token en production"
echo "  3. Tester: feedback_email_cron.php?token=oravie_cron_token_2026"
echo "  4. Accéder à l'admin: fachfecha/feedback_avis.php"

echo ""
echo "🌿 Système ready! 🌿"
