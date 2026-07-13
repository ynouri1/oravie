# Configuration Crontab pour ORAVIE Rapport Hebdomadaire

## 🔧 Installation sur OVH

### Étape 1: Se connecter en SSH à votre serveur
```bash
ssh utilisateur@domaine.tn
```

### Étape 2: Ouvrir l'éditeur crontab
```bash
crontab -e
```

### Étape 3: Ajouter la ligne de cron
Copier-coller cette ligne (modifier les chemins si nécessaire):

```bash
0 9 * * 0 /usr/bin/php /var/www/oravie/rapport_hebdo.php >> /var/www/oravie/logs/rapport.log 2>&1
```

**Explication:**
- `0 9 * * 0` = Dimanche à 9h00
- `/usr/bin/php` = Chemin à PHP (voir astuce ci-dessous)
- `/var/www/oravie/rapport_hebdo.php` = Chemin au script
- `>> /var/www/oravie/logs/rapport.log` = Ajoute les logs au fichier
- `2>&1` = Capture aussi les erreurs

### Étape 4: Sauvegarder et quitter
- Vim: `:wq`
- Nano: `Ctrl+X` puis `Y` puis `Enter`

### Étape 5: Vérifier
```bash
crontab -l
```

---

## 🔍 Trouver le chemin correct à PHP

Si vous ne savez pas où est PHP sur votre serveur:

```bash
which php
```

ou

```bash
which php7.4
which php8.0
which php8.1
```

**Exemples courants sur OVH:**
- `/usr/bin/php`
- `/usr/bin/php7.4`
- `/usr/bin/php8.1`
- `/usr/local/bin/php`

---

## 📝 Vérifier les logs

Les rapports s'exécutent chaque dimanche à 9h. Pour vérifier:

```bash
tail -f /var/www/oravie/logs/rapport.log
```

Ou voir les 50 dernières lignes:
```bash
tail -50 /var/www/oravie/logs/rapport.log
```

---

## 🧪 Tester manuellement

Pour tester l'exécution sans attendre le dimanche:

```bash
/usr/bin/php /var/www/oravie/rapport_hebdo.php
```

Vous devriez voir dans les logs:
```
✅ EMAIL ENVOYÉ AVEC SUCCÈS
```

---

## ❌ Dépannage

### Erreur: "envprod introuvable"
- Vérifier que le fichier `envprod` existe dans `/var/www/oravie/`
- Vérifier les permissions: `chmod 644 /var/www/oravie/envprod`

### Erreur: "Impossible de se connecter à la BD"
- Vérifier les identifiants dans `envprod`
- Vérifier que le serveur MySQL est accessible
- Tester: `mysql -h oraviem140.mysql.db -u oraviem140 -p`

### Erreur SMTP
- Vérifier les paramètres MAIL_* dans `envprod`
- Tester la connexion: `telnet ssl0.ovh.net 465`

### Les logs sont vides
- Vérifier les permissions du dossier: `chmod 755 /var/www/oravie/logs`
- Vérifier que le dossier existe: `mkdir -p /var/www/oravie/logs`

---

## 📧 Vérifier que c'est fonctionnel

1. **Via navigateur** (pour tester immédiatement):
   - Aller à: `https://www.oravie.tn/test-rapport.php`
   - Entrer la clé de sécurité: `test123`
   - Cliquer sur "Tester l'envoi du rapport"
   - Vérifier votre email

2. **Via crontab** (pour déboguer):
   ```bash
   /usr/bin/php /var/www/oravie/rapport_hebdo.php
   ```

3. **Voir les logs**:
   ```bash
   tail -20 /var/www/oravie/logs/rapport.log
   ```

---

## 📞 Support

En cas de problème, vérifier les logs d'abord:
```bash
cat /var/www/oravie/logs/rapport.log
```

Tous les succès et erreurs y sont enregistrés avec timestamp.
