# 🌿 Système de Feedback Client - ORAVIE

## 📋 Description

Système automatisé pour collecter les avis des clients **2 semaines après la livraison** de leur commande.

### Fonctionnalités

✅ **Email automatique** de feedback 2 semaines après livraison  
✅ **Formulaire interactif** avec évaluations (produit, livraison, site)  
✅ **Collecte de remarques** et suggestions d'amélioration  
✅ **Tableau de bord admin** pour visualiser les retours  
✅ **Statistiques** des avis reçus  

---

## 🔧 Installation

### 1. Fichiers créés

```
c:\gout\oravie\
├── feedback_email_cron.php          # Script d'envoi des emails (2 semaines)
├── feedback.php                      # Page de collecte d'avis clients
└── fachfecha\
    └── feedback_avis.php             # Admin: Visualiser les retours
```

### 2. Configuration requise

Vérifier que le fichier `envprod` contient les paramètres mail :

```ini
MAIL_HOST=ssl0.ovh.net
MAIL_PORT=465
MAIL_USER=contact@oravie.tn
MAIL_PASS=Hajer0606**
MAIL_FROM=contact@oravie.tn
MAIL_FROM_NAME=ORAVIE
MAIL_TO=mohamedyassine.nouri@gmail.com
MAIL_CC=contact@oravie.tn
```

### 3. Mise en place du cron job

Le script s'exécute via une URL sécurisée avec token.

**3 options :**

#### Option A : Planifier via le serveur (recommandé)
Ajouter une tâche cron qui exécute toutes les heures :

```bash
0 * * * * curl "https://www.oravie.tn/feedback_email_cron.php?token=oravie_cron_token_2026"
```

#### Option B : Service Unix/Linux (crontab)
```bash
0 * * * * /usr/bin/php /var/www/oravie/feedback_email_cron.php
```

#### Option C : Windows Task Scheduler
Créer une tâche qui exécute toutes les heures :
```
php C:\gout\oravie\feedback_email_cron.php
```

### 4. Bases de données

Les tables sont créées automatiquement au premier accès :

- **commandes** : Ajout colonne `feedback_email_sent_at` (nullable DATETIME)
- **feedback_avis** : Nouvelle table pour stocker les retours

---

## 🚀 Utilisation

### Flux client

1. **Commande livrée** → Statut = "livrée"
2. **+2 semaines** → Script envoie email de feedback
3. **Client clique** sur le lien unique dans l'email
4. **Remplit le formulaire** d'avis
5. **Données sauvegardées** dans `feedback_avis`

### Lien de feedback client

Format : `https://www.oravie.tn/feedback.php?id=COMMANDE_ID&token=TOKEN`

### Accès admin

Page de visualisation : `https://www.oravie.tn/fachfecha/feedback_avis.php`

(Accès sécurisé par authentification admin)

---

## 📊 Données collectées

Par avis :
- ⭐ Note produit (1-5)
- 📦 Note livraison (1-5)
- 🌐 Note site (1-5)
- 📝 Avis général (1-5)
- 💬 Remarques libres
- 💡 Suggestions d'amélioration

### Table feedback_avis

```sql
CREATE TABLE feedback_avis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commande_id INT NOT NULL,
    nom_client VARCHAR(150),
    email_client VARCHAR(150),
    avis_produit VARCHAR(50),
    avis_livraison VARCHAR(50),
    avis_site VARCHAR(50),
    avis_general VARCHAR(50),
    remarques TEXT,
    ameliorations TEXT,
    date_submission DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE
);
```

---

## 🔐 Sécurité

### Tokens utilisés

1. **Cron job** : `oravie_cron_token_2026` (à changer en production)
2. **Feedback URL** : Token généré aléatoirement par commande

### Validation

- Email clients validé (FILTER_VALIDATE_EMAIL)
- Données échappées (htmlspecialchars) contre XSS
- Vérification ID commande présente

---

## 📈 Statistiques & Monitoring

Le tableau de bord admin affiche :

- 📊 Nombre total d'avis reçus
- ⭐ Moyenne des notes (produit, livraison, site)
- 📉 Distribution des avis (excellent/bon/moyen/mauvais)
- 📋 Liste détaillée avec remarques

---

## 🔍 Dépannage

### Emails ne sont pas envoyés

1. Vérifier statut commande = "livrée"
2. Vérifier la colonne `feedback_email_sent_at` est NULL
3. Tester le cron : visiter `feedback_email_cron.php?token=oravie_cron_token_2026`
4. Vérifier credentials mail dans `envprod`
5. Consulter les logs PHP/serveur

### Page feedback qui s'affiche mal

- Vérifier l'accès à `feedback.php` (public)
- Vérifier le jeton/ID dans l'URL
- Vérifier la base de données est accessible

### Pas d'avis sur le dashboard admin

- Attendre que clients remplissent le formulaire
- Vérifier que le formulaire peut être soumis (formulaire valide)
- Vérifier table `feedback_avis` créée et contient des données

---

## 📧 Personnalisation

### Texte de l'email

Modifier le template HTML dans `feedback_email_cron.php` ligne ~50-100.

### Design du formulaire

Modifier CSS dans `feedback.php` ligne ~80-200.

### Délai d'envoi

Modifier la requête SQL dans `feedback_email_cron.php` :
```php
// 2 semaines = INTERVAL 2 WEEK
// 7 jours = INTERVAL 7 DAY
// 1 mois = INTERVAL 1 MONTH
AND date_commande <= DATE_SUB(NOW(), INTERVAL 2 WEEK)
```

---

## 🎯 À faire (optionnel)

- [ ] Ajouter bouton "Feedback avis" dans le menu admin
- [ ] Exporter les retours en CSV
- [ ] Alertes email pour avis négatifs
- [ ] Système de réponse aux avis
- [ ] Affichage des avis positifs sur le site public
- [ ] API pour intégration avec CRM

---

## 📝 Notes

- Les commandes avec email invalide sont marquées comme "envoyées" pour éviter les boucles
- Un cron job exécute max 10 commandes par passage (évite surcharge)
- Chaque client reçoit UN SEUL email (protection via `feedback_email_sent_at`)

---

**Dernière mise à jour** : 2026-09-12
