# Déployer Amateo en production — runbook fondateur

> Écrit pour être suivi seul, étape par étape, sans connaissance Docker/GitHub
> Actions préalable. Partie 1 = une seule fois (première mise en prod).
> Partie 2 = le quotidien (déployer, vérifier, revenir en arrière).
> La stack elle-même est décrite dans [`prod-stack.md`](prod-stack.md) ;
> les backups dans [`backup-restore.md`](backup-restore.md).

## Comment ça marche (2 minutes de lecture)

- Chaque release construit **6 images Docker complètes** (code inclus) et les
  pousse sur **ghcr.io** (le registre d'images de GitHub, lié au repo, gratuit).
- La VM ne contient QUE : `docker-compose.prod.yml`, `.env.prod` (les secrets),
  le dossier `jwt/`, et les volumes de données. **Jamais le code source.**
- **La source de vérité des secrets est le repo, chiffrée** : `.env.prod.gpg`
  (GPG symétrique, `make env-encode@prod` / `make env-decode@prod` — voir
  [§ Secrets chiffrés](#secrets-chiffrés-envprodgpg)). Le deploy le décode sur
  le runner et pousse `.env.prod` sur la VM.
- Déployer = la VM télécharge les images taguées `vX.Y.Z` et redémarre dessus.
  Le deploy **ré-envoie aussi `docker-compose.prod.yml` + le script + le
  `.env.prod` décodé** sur la VM à chaque passage — n'édite aucun de ces
  fichiers directement sur la VM (écrasés au prochain deploy).
- Revenir en arrière = redéployer le tag précédent : le workflow détecte que
  ses images existent déjà sur ghcr et les **réutilise telles quelles** (jamais
  de rebuild qui écraserait l'artefact d'origine).
- Le workflow (`.github/workflows/deploy.yml`) a deux moitiés : *build-push*
  (toujours active) et *deploy SSH* (dormante tant que la variable repo
  `DEPLOY_ENABLED` n'est pas à `true` — donc rien ne casse tant que la VM
  n'existe pas).

---

## Partie 1 — Première mise en prod (une seule fois)

> Prérequis : un compte **Scaleway** — hébergeur **CHOISI** (décision fermée,
> `specs/courantes/etat-des-lieux.md` §2), produit **Instances** (VM auto-gérée, pas de base managée : la stack Docker tourne
> entière sur la VM). Plus le domaine choisi,
> et les accès GitHub au repo. Compter ~1 h. Chaque ⬜ est une action à toi ;
> on peut dérouler cette partie ensemble en session.

### 1.1 Créer la VM

⬜ Console Scaleway → *Instances* → créer :
- type **PLAY2-NANO/DEV1-M ou plus** (≥ 4 Go RAM — la stack est bornée à ~3,7 Go pire cas) ;
- image **Ubuntu 24.04** ;
- une IP publique (IPv4).

⬜ SSH sur la VM puis installer Docker (paquet officiel) :

```bash
curl -fsSL https://get.docker.com | sh
docker compose version   # doit afficher v2.24 ou plus (interpolation .env)
```

### 1.2 Poser les fichiers de la stack

⬜ Sur la VM :

```bash
sudo mkdir -p /srv/amateo && sudo chown $USER /srv/amateo
cd /srv/amateo
```

⬜ Copier depuis le repo (scp ou copier-coller) :
- `docker-compose.prod.yml` (racine du repo).

⬜ **En LOCAL** (pas sur la VM) : `.env.prod.dist` → copié en `.env.prod`,
remplir **chaque CHANGEME** (le fichier se commente lui-même), puis
`make env-encode@prod` et **commiter `.env.prod.gpg`** — le premier deploy
(§1.7) poussera le fichier décodé sur la VM, en 600. (Poser un `.env.prod` à la
main sur la VM reste possible en dépannage, mais il sera écrasé au prochain
deploy dès qu'un `.env.prod.gpg` existe dans le repo.)
⬜ Vérifier que `.env.prod` **ne pose PAS `JWT_COOKIE_SECURE=false`** (SEC-16 — le JWT
   applicatif voyage en cookie httpOnly). Le laisser ABSENT est sûr : le `backend/.env.prod`
   committé le met à `true`, et le défaut du conteneur aussi. En revanche une vraie variable
   d'environnement gagne sur tout — c'est le seul cas qu'aucun test ne peut attraper. Ce flag
   ne se dérive PAS du protocole vu par PHP : le nginx du front écoute en 80 derrière la TLS.
   Détail : [`jwt-cookie.md`](../security/jwt-cookie.md).
Générateurs : `openssl rand -hex 32` (secrets), `openssl rand -hex 24` (mots de
passe DB). ⚠ Répéter à la main les mots de passe dans `DATABASE_URL` /
`DATABASE_ADMIN_URL` (pas de `${}` dans ce fichier).

### 1.3 Clés JWT

⬜ Toujours dans `/srv/amateo`, avec le `JWT_PASSPHRASE` posé en 1.2 :

```bash
mkdir -p jwt
openssl genpkey -algorithm RSA -aes256 -pass pass:<JWT_PASSPHRASE> -pkeyopt rsa_keygen_bits:4096 -out jwt/private.pem
openssl pkey -in jwt/private.pem -passin pass:<JWT_PASSPHRASE> -pubout -out jwt/public.pem
chown -R 1000:1000 jwt && chmod 600 jwt/private.pem && chmod 644 jwt/public.pem
```

⚠ Le `chown 1000:1000` n'est PAS optionnel : sans lui la stack démarre verte
mais **tous les logins renvoient 500**.

### 1.4 Accès ghcr.io depuis la VM

⬜ GitHub → *Settings → Developer settings → Personal access tokens →
Tokens (classic)* → générer un token **`read:packages` uniquement**, expiration 1 an.

⬜ Sur la VM (`--password-stdin` : le token ne doit jamais apparaître dans
l'historique shell ni dans la liste des process) :

```bash
echo '<le-token>' | docker login ghcr.io -u <ton-user-github> --password-stdin
history -d $(history 1 | awk '{print $1}')   # efface la ligne du token de l'historique
```

### 1.5 TLS + domaines (Caddy)

**Deux domaines, deux rôles** (convention `.claude/rules/landing.md`) : le domaine **nu**
sert la page de vente, le sous-domaine **`app.`** sert l'application. Caddy tourne SUR la
VM, hors Docker : c'est la seule porte d'entrée, il écoute en 443 et gère seul le
certificat Let's Encrypt.

⬜ DNS : enregistrements A `amateo.app`, `www.amateo.app` et `app.amateo.app` → IP de la VM.

⬜ Sur la VM :

```bash
sudo apt install -y caddy
# Modèle versionné dans le dépôt — à recopier tel quel (adapter les domaines si besoin) :
sudo cp docs/ops/Caddyfile.example /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Le modèle : [`Caddyfile.example`](Caddyfile.example). Trois blocs — la page (`file_server`
sur des fichiers du disque), la redirection `www`, et l'app (`reverse_proxy` vers 8081 =
`FRONTEND_PORT` de `.env.prod`, seul port publié par la stack, sur localhost uniquement).

⚠ **La page de vente ET les pages système sont déposées par le workflow de déploiement**
(`landing/` → `$DEPLOY_PATH/landing`, `system-pages/` → `$DEPLOY_PATH/system-pages`, §1.6) :
ces dossiers n'existent donc qu'**après le premier déploiement**. Avant lui, le domaine nu
répond 404 — c'est normal, pas une panne de Caddy. Et le bloc d'erreur du site `app.` retombe
sur le gestionnaire par défaut de Caddy (5xx à corps vide, jamais un 200) tant que
`system-pages/` n'existe pas.

⚠ **Droits de lecture** : Caddy tourne sous l'utilisateur `caddy`, pas sous l'utilisateur de
déploiement. Il lui faut la traversée sur `$DEPLOY_PATH` et la lecture sur `landing/` **et**
`system-pages/` :

```bash
sudo chmod o+x /srv/amateo            # traverser, sans lire le reste
sudo chmod -R o+rX /srv/amateo/landing
sudo chmod -R o+rX /srv/amateo/system-pages
```

Vérifier plutôt que supposer : `sudo -u caddy cat /srv/amateo/landing/index.html | head -1`
et `sudo -u caddy cat /srv/amateo/system-pages/503.html | head -1`.

### 1.6 Armer le workflow de déploiement

⬜ GitHub → repo → *Settings → Secrets and variables → Actions* :

| Type | Nom | Valeur |
|---|---|---|
| Secret | `DEPLOY_HOST` | IP (ou domaine) de la VM |
| Secret | `DEPLOY_USER` | l'utilisateur SSH (ex. `root` ou ton user) |
| Secret | `DEPLOY_SSH_KEY` | une clé privée SSH dédiée au deploy (générer : `ssh-keygen -t ed25519 -f deploy_key`, mettre `deploy_key.pub` dans `~/.ssh/authorized_keys` de la VM, coller `deploy_key` ici) |
| Secret | `ENV_GPG_PASSPHRASE` | la passphrase du `.env.prod.gpg` (celle du gestionnaire de mots de passe — voir § Secrets chiffrés) |
| Variable | `DEPLOY_ENABLED` | `true` |
| Variable | `DEPLOY_PATH` | `/srv/amateo` (optionnelle, c'est le défaut) |

### 1.7 Premier déploiement

⬜ Depuis ta machine :

```bash
git tag v1.0.0 && git push origin v1.0.0
```

Suivre dans GitHub → *Actions → Deploy*. Le script distant saute le backup
pré-migration (première fois, rien à sauver), pull, démarre, migre, sonde
`/health`. À la fin :

⬜ Ouvrir `https://TON-DOMAINE` → créer TON compte (register + vérif email —
le SMTP doit donc être bon dans `MAILER_DSN`). L'**expéditeur** de ce mail, lui,
vient de `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` : ils doivent porter le domaine
**vérifié** chez le fournisseur transactionnel (SPF/DKIM/DMARC posés pour CE
domaine), sinon l'envoi part en spam ou est refusé.

⬜ Vérifications finales :
- `https://TON-DOMAINE/api/health` → `{"status":"ok"}` ;
- générer un planning de test bout-en-bout ;
- backups : suivre [`backup-restore.md`](backup-restore.md) §4bis (bucket +
  `BACKUP_SYNC_COMMAND`), puis `app:db:backup --force` et vérifier le fichier
  dans le bucket ;
- Sentry : poser les 3 DSN (backup-restore.md §5) ;
- superadmin : `docker compose ... exec php-fpm php bin/console app:superadmin:create <email>`.

### 1.8 Rôle de lecture seule (`amateo_read`) — poser son mot de passe

Le rôle `amateo_read` est **créé par la migration** (jouée au premier déploiement) : lecture seule sur une
**liste blanche** (jamais un secret : ni `super_admin`, ni les tables de tokens, ni `app_user.password_hash`),
jamais de porte `admin_all` (→ [`prod-stack.md`](prod-stack.md) § « Avec quel rôle » +
[`../security/rls.md`](../security/rls.md)). Il naît **sans mot de passe** — donc aucun secret en git — et ne
peut pas se connecter tant que tu n'en poses pas un.

⚠ `amateo_read` est un rôle **de confiance** : le `SET app.club_id` ci-dessous ne fait qu'**éviter de mélanger
les clubs à l'écran**, ce n'est **pas une frontière** (l'opérateur peut poser n'importe quel club). Sa
protection réelle : il ne voit aucun secret et ne peut rien écrire. Conséquence : son mot de passe donne accès
aux données personnelles des clubs — **ne jamais le stocker en clair** sur un poste partagé (gestionnaire de
secrets, jamais un fichier ni l'historique shell), et le changer si un poste est compromis.

À faire **une fois**, sur la VM, en **saisie non historisée** (ne pas mettre le mot de passe dans `.env*` ni
dans l'historique shell) :

```bash
# Sonde préalable (rare) : si l'owner n'est PAS superuser sur ton hébergeur, il lui faut CREATEROLE
# pour que la migration ait pu créer le rôle — sinon elle aurait échoué franchement au déploiement.
ssh <hôte> "docker compose exec postgres psql -U amateo_owner -d amateo -c '\du amateo_read'"

# Pose le mot de passe — \password invite en saisie masquée, rien n'atterrit dans l'historique ni les logs.
ssh -t <hôte> "docker compose exec postgres psql -U amateo_owner -d amateo -c '\\password amateo_read'"
```

⬜ Se connecter ensuite depuis ton poste par **tunnel SSH** (jamais de port Postgres ouvert —
[`prod-stack.md`](prod-stack.md) § Accès opérateur), puis **poser le club** avant toute lecture :

```bash
ssh -N -L 5433:localhost:5432 <hôte>          # publie le port sur la loopback de l'hôte, cf. prod-stack.md
# puis, dans le client (psql/DBeaver) connecté à localhost:5433 en amateo_read :
SET app.club_id = '<uuid-du-club>';           -- sans ce contexte, les tables tenant rendent 0 ligne (fail-closed)
SELECT * FROM team_tag;                        -- ne voit que le club posé ; toute écriture est refusée
```

---

### 1.8 Jour J — données (seed initial, une seule fois)

Une fois la stack déployée et **les migrations passées** (elles tournent au déploiement),
on pose les données de départ. Tous les seeds qui touchent des tables tenant traversent la RLS
et exigent la **connexion ADMIN** : on lance chaque commande avec `DATABASE_URL` forcé sur
`DATABASE_ADMIN_URL` (déjà présent dans l'environnement du conteneur).

⬜ **Sonder d'abord la connexion admin** — le seed échoue vite si `DATABASE_ADMIN_URL` n'est pas
un rôle superuser (il ne peut alors pas traverser la RLS) :

```bash
docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console dbal:run-sql "SELECT usesuper FROM pg_user WHERE usename = current_user"'
```

Si la réponse n'est **pas** `t` (true) : **STOP** — ne rien seeder, remonter la décision (le rôle
admin de prod doit être superuser pour le seed, comme `amateo_owner` en local).

⬜ **Référentiels d'abord** (globaux, non-tenant, idempotents) — les fenêtres de ligue **avant**
le BCCL (le club en dépend) :

```bash
docker compose exec php-fpm php bin/console app:school-holidays:seed
docker compose exec php-fpm php bin/console app:public-holidays:seed
docker compose exec php-fpm php bin/console app:league-windows:seed
```

⬜ **Le club BCCL réel** (`app:bccl:seed-prod`) — CREATE-ONLY (no-op si déjà là). Les mots de passe
sont demandés en **prompt masqué** (ne pas les mettre en `--password` pour ne pas les laisser dans
l'historique shell). `--email` = ton compte fondateur, `--co-email` = celui de Nicolas Barilleau.
⚠ **Lancer ce seed JUSTE APRÈS le déploiement**, avant d'ouvrir l'inscription publique à qui que ce
soit : le seeder crée les comptes gestionnaires à ces e-mails, et il **refuse** si un compte existe
déjà pour l'un d'eux (garde anti-usurpation — un compte créé entre-temps via `/register` avec ton
e-mail ne doit jamais être adopté par le seed).

```bash
docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console app:bccl:seed-prod --email=TON-EMAIL --co-email=EMAIL-NICOLAS'
# → deux prompts masqués : mot de passe gestionnaire, puis co-gestionnaire (min 12 caractères).
```

Si la commande échoue sur « **An account already exists for … »** : un compte porte déjà cet e-mail.
**Ne pas contourner** — vérifier qui l'a créé, le supprimer (ou le traiter à la main) puis relancer.
Rien n'a été créé tant que ce refus s'affiche.

⬜ **Le club de démonstration** (`app:demo:seed`) — jouable dès le jour J. `--password` (min 12) est
requis à la première création ; pour éviter de le laisser en clair dans l'historique, préfixer la
ligne d'un espace (avec `HISTCONTROL=ignorespace`) ou passer par une variable non historisée :

```bash
 docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console app:demo:seed --password=MOT-DE-PASSE-DEMO'
```

⬜ **Rôle PostgreSQL lecture seule** — le mot de passe du rôle SELECT se pose au jour J ; cette
étape est livrée par une **PR distincte** (rôle RO sans `admin_all`). À compléter ici quand elle est
en place.

⬜ **Ré-importer les matchs** — le seed pose l'état terrain (créneaux, contraintes, adversaires
déjà localisés) mais **pas les matchs** : les réimporter depuis l'UI (module Matchs → Importer, le
fichier FBI de la saison). Les localisations d'adversaires étant déjà amorcées, l'import retrouve
les gymnases sans re-résoudre.

⬜ **Vérifications** :
- se connecter aux **3 comptes** (fondateur, Nicolas, démo) — ils naissent pré-vérifiés ;
- non-fuite entre clubs (chaque club voit SES catégories, jamais celles d'un autre) :

```bash
docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console dbal:run-sql "SELECT t.name, t.club_id, sc.club_id FROM team t JOIN sport_category sc ON sc.id = t.sport_category_id WHERE sc.club_id <> t.club_id"'
# → 0 ligne attendue.
```

---

## Partie 2 — Au quotidien

### Déployer une release

```bash
git tag v1.2.0 && git push origin v1.2.0
```

Rien d'autre. Le workflow build → push → déploie → migre → sonde. Vert dans
*Actions* = en prod.

### Hotfix / déployer sans tag

```bash
make deploy                    # commit courant de main, version = sha
make deploy VERSION=v1.2.0     # re-déployer un tag existant
```

(= `gh workflow run deploy.yml`, puis suit le run en direct.)

### Revenir en arrière (rollback)

```bash
make deploy VERSION=v1.1.0     # la version d'avant — les images sont toujours sur ghcr
```

Les images v1.1.0 existent déjà sur ghcr → le workflow **saute le build** et
redéploie **exactement les artefacts qui tournaient** (pas un rebuild aux
couches de base dérivées). Marche aussi pour un hotfix sha :
`make deploy VERSION=sha-abc1234`.

⚠ Le rollback rejoue le code d'avant mais **ne dé-migre pas la base**. Si la
release fautive contenait une migration destructive : restaurer le dump pris
automatiquement AVANT la migration (`backup-restore.md` §3 — le script refuse
de migrer sans ce dump, il est fail-closed).

### Règle d'écriture des migrations (convention, à respecter dans les PRs)

Le deploy migre **avant** de basculer les conteneurs : pendant quelques
secondes l'ancien code tourne sur le nouveau schéma. Toute migration doit donc
être **rétro-compatible une release en arrière** — ajouter une colonne
nullable/DEFAULT : oui ; supprimer/renommer une colonne encore lue par la
release précédente : non (faire en deux releases : arrêter de lire, puis
supprimer).

### Vérifier l'état

- GitHub → *Actions → Deploy* : historique des déploiements (un run = un deploy).
- `https://TON-DOMAINE/health` (edge) + `/api/health` (backend).
- Board fraîcheur superadmin (backups, heartbeats).
- Sentry : erreurs runtime des 3 zones.
- Sur la VM : `docker compose -f docker-compose.prod.yml --env-file .env.prod ps`
  → tout doit être `healthy`.

### Maintenance planifiée

Pour couper volontairement l'app derrière une page « on refait le parquet » (déploiement lourd,
migration risquée, intervention base), Caddy porte un **interrupteur à fichier témoin**
(`docs/ops/Caddyfile.example`). Le matcher est évalué à **chaque requête** : allumer/éteindre
agit **immédiatement, sans reload Caddy**.

⚠ Le témoin vit à `$DEPLOY_PATH/maintenance.on`, **HORS** du dossier `system-pages/` que le
deploy bascule — sinon un déploiement pendant la fenêtre l'effacerait en silence.

🔴 **Le CONTENU de ce fichier est SERVI PUBLIQUEMENT** à `https://app.amateo.app/maintenance-until`
pendant toute la fenêtre de maintenance — c'est ainsi que la page affiche « Retour prévu vers … ».
**N'y écrivez QU'UN horodatage ISO 8601, et rien d'autre.** Jamais de note libre : « restauration
base après incident client X » y serait lisible par n'importe qui. (La page ignore ce qui n'est pas
une date — mais le fichier, lui, reste servi tel quel.) Relevé en revue de sécurité le 2026-08-23 :
le risque n'est pas technique, il est d'usage.

**Allumer** (puis vérifier qu'on répond bien 503) :

```bash
# Avec heure de retour — la page affiche « Retour prévu vers 23:30 » + un décompte :
ssh <hôte> "echo '2026-08-23T23:30:00+02:00' > /srv/amateo/maintenance.on"

# Sans heure connue — un `touch` nu reste valide : page normale, aucun compteur :
ssh <hôte> "touch /srv/amateo/maintenance.on"

curl -sS -o /dev/null -w '%{http_code}\n' https://app.amateo.app/     # attendu : 503
```

**Éteindre** (puis vérifier la réouverture) :

```bash
ssh <hôte> "rm -f /srv/amateo/maintenance.on"
curl -sS -o /dev/null -w '%{http_code}\n' https://app.amateo.app/     # attendu : 200
```

⚠ **Anti-oubli** : `remote-deploy.sh` avertit **bruyamment** en fin de deploy si le témoin est
encore présent. C'est un **rappel**, pas une garantie — il ne le retire jamais tout seul (une
fenêtre peut délibérément durer plus qu'un deploy) et n'échoue pas le deploy (le deploy, lui,
a réussi). La vraie garantie qu'une fenêtre n'a pas été oubliée, c'est le **503 qu'une sonde
voit**.

### Changer un secret / une variable d'env

1. En local : `make env-decode@prod` (rafraîchit `.env.prod` depuis le `.gpg`) ;
2. éditer `.env.prod`, puis `make env-encode@prod` ;
3. commiter `.env.prod.gpg` (et la ligne CHANGEME dans `.env.prod.dist` si la
   variable est nouvelle) → **déployer** (tag ou `make deploy`) : le workflow
   pousse le fichier et `remote-deploy.sh` recrée les conteneurs ;
4. cas particuliers : rotation du `JWT_PASSPHRASE` = régénérer aussi le keypair
   (§1.3) ; rotation DB = `ALTER USER` côté postgres d'abord.

Urgence sans release : éditer `.env.prod` sur la VM +
`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d`, **puis
reporter le changement dans le `.gpg` du repo** — sinon le prochain deploy
restaure l'ancienne valeur.

### Secrets chiffrés (`.env.prod.gpg`)

Modèle à trois fichiers (racine du repo) :

| Fichier | Rôle | Git |
|---|---|---|
| `.env.prod.dist` | template commenté = la liste lisible des variables | commité en clair (zéro secret) |
| `.env.prod` | rempli, secrets réels | jamais commité (gitignoré, chmod 600) |
| `.env.prod.gpg` | le `.env.prod` chiffré (GPG symétrique AES256) | **commité — la vérité** |

- `make env-encode@prod` chiffre, `make env-decode@prod` déchiffre (écrase la
  copie locale : le `.gpg` du repo fait foi, typiquement après un `git pull`).
  En local gpg prompte la passphrase ; en CI elle arrive par la variable
  d'environnement `ENV_GPG_PASSPHRASE` (stdin gpg, jamais en argument).
  ⚠ gpg tourne sur l'**hôte** (exception assumée au « tout dans Docker » —
  standard partout, y compris `ubuntu-latest`).
- **Au deploy** : `.gpg` présent → décodé sur le runner, poussé sur la VM
  (chmod 600) avant `remote-deploy.sh`. `.gpg` **absent** du ref → warning, la
  copie VM existante est conservée (indispensable au rollback : un ancien tag
  n'a pas le fichier). `.gpg` présent mais passphrase absente/fausse → **deploy
  avorté avant toute mutation de la VM**.
- **La ligne `VERSION=` du `.gpg` n'est pas significative** : le step de deploy
  préserve le pin `VERSION` courant de la VM (posé par `remote-deploy.sh` en
  fin de deploy réussi) — un `up -d` manuel entre deux deploys continue de
  tirer la version qui tourne.
- **Passphrase** : générée une fois (`openssl rand -base64 32`), stockée dans
  le gestionnaire de mots de passe du fondateur + le secret Actions
  `ENV_GPG_PASSPHRASE` (§1.6). Rotation : `env-decode` → `env-encode` avec la
  nouvelle passphrase → commit + mise à jour du secret Actions. Perte de la
  passphrase SANS copie claire : repartir du `.env.prod` de la VM (ou du
  `.dist`) et ré-encoder.

### Restaurer un backup

→ [`backup-restore.md`](backup-restore.md) §3 (restore-check puis restauration réelle).

## SEC-16 — migration du stream d'échec Messenger (déployé le 2026-08-07)

Le DSN d'échec par défaut est passé de `redis://redis:6379/messages/failed` (groupe posé
sur le MÊME stream que les messages vifs — tout dispatch rendait 500 dès que ce groupe
était matérialisé) à `redis://redis:6379/failed_messages/failed` (stream dédié).

Au premier déploiement qui embarque ce changement :

1. vérifier qu'aucun `.env.prod` ne surcharge `MESSENGER_FAILURE_TRANSPORT_DSN` avec
   l'ancien DSN — sinon la mine reste armée ;
2. vérifier qu'aucun message n'attend dans l'ancien groupe :
   `docker compose exec php-fpm php bin/console messenger:failed:show` (avant bascule) ;
3. détruire le groupe hérité s'il existe — sans lui l'incident reste possible :
   `docker compose exec redis redis-cli XGROUP DESTROY messages failed`.

NR : `MessengerTransportSeparationTest` (phase1) refuse tout retour à un stream partagé.

