# 📧 Configuration du Rapport Hebdomadaire ORAVIE

## 1️⃣ CONFIGURATION INITIALE

### Modifier `config_rapport.php`

Éditez le fichier `config_rapport.php` et remplacez l'email :

```php
'email_to' => 'votre-email@example.com',  // ← Mettez votre email ici
```

Pour envoyer à **plusieurs emails**, séparez-les par une virgule :
```php
'email_to' => 'email1@example.com, email2@example.com, admin@oravie.tn',
```

## 2️⃣ CONFIGURATION DU CRON JOB

Le rapport s'exécutera automatiquement chaque **dimanche à 9h** via CRON.

### Sur serveur OVH/Linux :

#### Option A : Via le panel OVH
1. Connectez-vous à votre panel OVH
2. Allez dans **Hébergement Web** → Votre domaine → **Tâches planifiées**
3. Cliquez **Ajouter une tâche planifiée**
4. Configurez :
   - **Langage** : PHP
   - **Chemin** : `/rapport_hebdo.php`
   - **Intervalle** : Chaque dimanche à 9h

#### Option B : Via SSH/Terminal
```bash
crontab -e
```

Ajoutez cette ligne :
```cron
0 9 * * 0 /usr/bin/php /var/www/oravie/rapport_hebdo.php > /var/log/oravie-rapport.log 2>&1
```

**Explication** :
- `0 9` = 9h00
- `* * 0` = Chaque dimanche
- `/usr/bin/php` = Chemin PHP (demander à l'hébergeur si différent)
- `/var/www/oravie/rapport_hebdo.php` = Chemin du script (adapter à votre installation)

## 3️⃣ TESTER LE SCRIPT

Exécutez manuellement pour tester :

**Via navigateur** :
```
https://www.oravie.tn/rapport_hebdo.php
```

**Via SSH** :
```bash
php /var/www/oravie/rapport_hebdo.php
```

Vous devriez voir :
```
✅ Rapport envoyé avec succès à votre-email@example.com
📊 Statistiques semaine 01/07/2026 - 07/07/2026 :
   • Commandes : 12
   • Livrées : 9
   • Sprays : 25
   • CA : 288.00 DT
```

## 4️⃣ CONTENU DU RAPPORT

Le rapport contient :
- **KPI Summary** : Total commandes, livrées, sprays, CA
- **Top Praticiens** : 5 meilleurs praticiens par CA
- **Détails** : Commandes par statut
- **Liens** : Direct vers le dashboard ORAVIE

## 5️⃣ DÉPANNAGE

### Le rapport n'arrive pas
- ✓ Vérifier que `config_rapport.php` existe et contient votre email
- ✓ Demander à l'hébergeur si les mails sortants sont activés
- ✓ Vérifier les logs CRON : `tail -f /var/log/oravie-rapport.log`

### Erreur "config_rapport.php not found"
- ✓ Uploader `config_rapport.php` sur le serveur

### Erreur "envprod not found"
- ✓ Vérifier que `envprod` existe et est au même niveau que `rapport_hebdo.php`

## 🔧 PERSONNALISER

Vous pouvez modifier dans `config_rapport.php` :
- `email_to` : Adresse(s) email destinataire
- `day_of_week` : 0=dimanche, 1=lundi, etc.
- `hour` : Heure d'exécution
- `minute` : Minute d'exécution

Exemple pour **lundi à 8h30** :
```php
'day_of_week' => 1,   // Lundi
'hour' => 8,
'minute' => 30,
```

Puis adapter le CRON :
```cron
30 8 * * 1 /usr/bin/php /var/www/oravie/rapport_hebdo.php
```

---

**Besoin d'aide ?** Contactez votre hébergeur pour configurer le CRON job.
