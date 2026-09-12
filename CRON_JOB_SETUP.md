# 🔧 Configuration Cron Job - ORAVIE Feedback

## Option 1 : Planificateur OVH Web (Recommandé)

### Via Control Panel OVH

1. Accédez à votre espace client OVH
2. **Hosting** → **Votre domaine** → **Outils avancés** → **Tâches planifiées (CRON)**
3. Cliquez sur **Ajouter une tâche planifiée (CRON)**

**Formulaire :**
```
URL à appeler:
https://www.oravie.tn/feedback_email_cron.php?token=oravie_cron_token_2026

Fréquence: 
Chaque heure (toutes les heures)

Email de notification:
contact@oravie.tn
```

4. Valider ✅

---

## Option 2 : Commande Crontab (Linux/SSH)

### Via Terminal SSH

Se connecter au serveur :
```bash
ssh user@oravie.tn
cd /var/www/oravie/  # ou le chemin réel
```

Éditer crontab :
```bash
crontab -e
```

Ajouter cette ligne :
```bash
# Exécute toutes les heures à la minute 0
0 * * * * php /var/www/oravie/feedback_email_cron.php

# Ou via curl (à travers HTTP)
0 * * * * /usr/bin/curl -s "https://www.oravie.tn/feedback_email_cron.php?token=oravie_cron_token_2026" > /dev/null 2>&1
```

Sauvegarder : `Ctrl+X` → `Y` → `Enter`

Vérifier :
```bash
crontab -l
```

---

## Option 3 : Windows Task Scheduler (Serveur Windows)

1. Ouvrir **Planificateur des tâches**
2. **Créer une tâche simple**
3. Remplir :
   - **Nom** : ORAVIE Feedback Cron
   - **Description** : Envoie emails feedback après 2 semaines
   - **Trigger** : Toutes les heures
   - **Action** : 
     ```
     C:\php\php.exe
     -f C:\gout\oravie\feedback_email_cron.php
     ```

---

## Test du Cron Job

### Vérifier que tout fonctionne

Visiter dans le navigateur :
```
https://www.oravie.tn/feedback_email_cron.php?token=oravie_cron_token_2026
```

**Résultat attendu :**
```
📧 Traitement de 0 commande(s) pour feedback...

🏁 Cron job terminé.
```

Ou si des commandes sont éligibles :
```
📧 Traitement de 3 commande(s) pour feedback...
✅ Commande #456 → client@email.com
✅ Commande #457 → autre@email.com
✅ Commande #458 → troisieme@email.com

🏁 Cron job terminé.
```

---

## Monitoring & Alertes

### Logs OVH

OVH envoie automatiquement les résultats du cron job par email si configuré.

### Vérifier les emails envoyés

Accéder à l'admin : [https://www.oravie.tn/fachfecha/feedback_avis.php](https://www.oravie.tn/fachfecha/feedback_avis.php)

La colonne `date_submission` dans la table `feedback_avis` montre quand les avis ont été reçus.

---

## Dépannage

### ❌ "Accès refusé" en visitant le cron

**Cause** : Token incorrect  
**Solution** : Vérifier l'URL avec le token exact

### ❌ Pas de commandes traitées

**Cause possible** :
- Pas de commandes avec statut "livrée"
- Commandes livrées depuis < 2 semaines
- Email invalide

**Vérifier en DB** :
```sql
SELECT id, date_commande, statut, feedback_email_sent_at 
FROM commandes 
WHERE statut = 'livrée' 
AND feedback_email_sent_at IS NULL 
AND date_commande <= DATE_SUB(NOW(), INTERVAL 2 WEEK);
```

### ❌ Emails ne sont pas envoyés

1. Vérifier les credentials mail dans `envprod`
2. Vérifier `php_errors.log` sur le serveur
3. Tester PHPMailer directement : [testmail.php](testmail.php?t=oravie_test_2026)

---

## Sécurité

### Changer le token en production

**IMPORTANT** : Ne pas utiliser `oravie_cron_token_2026` en production !

Éditer `feedback_email_cron.php` :
```php
define('CRON_TOKEN', 'votre_nouveau_token_tres_securise');
```

Mettre à jour le cron job avec le nouveau token.

---

## Fréquence recommandée

- **Minimum** : Une fois par jour
- **Idéal** : Toutes les heures
- **Optimal** : Toutes les 30 minutes (si volume élevé)

**Conseil** : Commencer par une fois par jour, augmenter si besoin.

---

**Date** : 2026-09-12
