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
- **La source de vérité des secrets est le secret GitHub `ENV_PROD`** (le
  `.env.prod` entier collé tel quel — voir
  [§ Secret `ENV_PROD`](#secret-env_prod)). Le deploy l'écrit sur le runner et
  pousse `.env.prod` sur la VM. Le dépôt ne contient plus aucun secret.
- Déployer = la VM télécharge les images taguées `vX.Y.Z` et redémarre dessus.
  Le deploy **ré-envoie aussi `docker-compose.prod.yml` + le script + le
  `.env.prod` du secret `ENV_PROD`** sur la VM à chaque passage — n'édite aucun de ces
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
- type **PRO2-XXS ou plus** (≥ 8 Go RAM — la somme des `mem_limit` de `docker-compose.prod.yml` vaut ~5,1 Go pire cas, à laquelle s'ajoutent le système et Docker ; la prod tourne sur une PRO2-XXS à 7,8 Go, sans swap) ;
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
remplir **chaque CHANGEME** (le fichier se commente lui-même), puis coller le
contenu intégral du fichier dans le **secret GitHub `ENV_PROD`** (§1.6,
`gh secret set ENV_PROD < .env.prod`). Le premier deploy (§1.7) poussera le
fichier sur la VM, en 600. (Poser un `.env.prod` à la main sur la VM reste
possible en dépannage, mais il sera écrasé au prochain deploy dès que le secret
`ENV_PROD` est renseigné.)
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

Le modèle : [`Caddyfile.example`](Caddyfile.example). Quatre blocs — la page (`file_server`
sur des fichiers du disque), la redirection `www`, l'app (`reverse_proxy` vers 8081 =
`FRONTEND_PORT` de `.env.prod`, seul port publié par la stack, sur localhost uniquement) et
`stats.amateo.app` (collecte Umami seule, §1.11). Les blocs page et app portent `encode zstd
gzip` ; sans danger pour les flux de l'app (SSE Mercure jamais compressé/bufferisé, PDF/xlsx
hors liste compressible — voir le bloc `app.amateo.app` du modèle).

⚠ **VM déjà en service** : un `/etc/caddy/Caddyfile` posé avant l'ajout de `encode` sur le bloc
`app.amateo.app` ne compresse pas l'app. Geste de rattrapage (une fois) : ajouter la ligne
`encode zstd gzip` au bloc `app.amateo.app` du Caddyfile de la VM, puis `sudo systemctl reload
caddy`. Vérifier (vide = pas compressé) :

```bash
curl -sI -H 'Accept-Encoding: gzip' https://app.amateo.app/ | grep -i content-encoding
# → attendu : content-encoding: gzip   (rien = la ligne encode manque ou n'a pas été rechargée)
```

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
| Secret | `ENV_PROD` | le contenu intégral du `.env.prod` collé tel quel (`gh secret set ENV_PROD < .env.prod`) — voir § Secret `ENV_PROD` |
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

Sur le déploiement courant, ce mot de passe **est posé** (le rôle est utilisable).

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

#### Clé SSH dédiée au tunnel de lecture seule — à poser **une fois**

Depuis un poste, la lecture passe par un **tunnel SSH vers le port loopback de Postgres**
(`127.0.0.1:5432`, publié seulement sur la VM — [`prod-stack.md`](prod-stack.md) § Accès
opérateur). Le tunnel s'ouvre avec une **clé dédiée à phrase de passe**, portée par un **compte
Unix dédié `amateo-tunnel`** — jamais le compte de déploiement.

> ⚠ **Pourquoi un compte Unix dédié, et pas juste une ligne dans l'`authorized_keys` du compte de
> deploy ?** `permitopen` ne filtre QUE les redirections TCP (`-L host:port`), **pas** les
> redirections de **socket Unix** (`direct-streamlocal`, `ssh -L /chemin.sock:/var/run/docker.sock`).
> Le compte de déploiement étant dans le groupe `docker`, une clé posée chez lui pourrait tunneler
> vers `docker.sock` et obtenir **root** sur la VM. Parade : un compte `amateo-tunnel` **hors du
> groupe docker, sans shell**, + un `Match User` sshd qui **interdit** le forward de socket Unix.

**a. Sur le poste — générer la clé dédiée** (jamais une clé d'admin). **SURTOUT PAS `-N ''`** :
ssh-keygen DOIT demander une **phrase de passe** — c'est l'interrupteur que tu déverrouilleras dans
l'agent pour une durée limitée. Une clé en clair sur le disque donnerait un accès permanent.

```bash
ssh-keygen -t ed25519 -f ~/.ssh/amateo_prod_read -C amateo-prod-read
```

**b. Sur la VM (root/sudo) — créer `amateo-tunnel` et y poser la clé publique.** Les options
d'`authorized_keys` restent, en **défense en profondeur** — mais la vraie barrière est le bloc sshd
de l'étape c (le transfert inverse `-R` y est bloqué par `AllowTcpForwarding local` +
`PermitListen none`). ⚠ **Ne PAS mettre `permitlisten=""`** dans cette ligne : sshd le rejette
(« invalid permission port ») et ignore la clé entière.

```bash
sudo useradd --system --create-home --shell /usr/sbin/nologin amateo-tunnel
sudo install -d -m 700 -o amateo-tunnel -g amateo-tunnel /home/amateo-tunnel/.ssh
# Coller la clé PUBLIQUE du poste (~/.ssh/amateo_prod_read.pub) à la place de « ssh-ed25519 AAAA… » :
printf '%s %s\n' \
  'restrict,port-forwarding,permitopen="127.0.0.1:5432",command="/bin/false"' \
  'ssh-ed25519 AAAA… amateo-prod-read' | sudo tee /home/amateo-tunnel/.ssh/authorized_keys
sudo chown amateo-tunnel:amateo-tunnel /home/amateo-tunnel/.ssh/authorized_keys
sudo chmod 600 /home/amateo-tunnel/.ssh/authorized_keys
```

**c. Sur la VM — le bloc sshd** (`/etc/ssh/sshd_config.d/amateo-tunnel.conf`) — LA barrière :

```
Match User amateo-tunnel
    AllowTcpForwarding local
    AllowStreamLocalForwarding no
    PermitOpen 127.0.0.1:5432
    PermitListen none
    X11Forwarding no
    AllowAgentForwarding no
    PermitTTY no
    ForceCommand /bin/false
    ChannelTimeout direct-tcpip=10m
    UnusedConnectionTimeout 1m
```

```bash
sudo sshd -t && sudo systemctl reload ssh   # (le service s'appelle `sshd` sur certaines distros)
```

> **Version minimale d'OpenSSH.** `ChannelTimeout` et `UnusedConnectionTimeout` existent **depuis
> OpenSSH 9.2** (Debian 12 : 9.2 ✓ ; Ubuntu 24.04 : 9.6 ✓ ; **Ubuntu 22.04 : 8.9 ✗**). Vérifier
> `ssh -V` sur la VM. Si **< 9.2** : **retirer ces deux dernières lignes** du bloc (sinon `sshd -t`
> échoue et le reload est refusé) — la borne de durée est alors tenue côté client
> (`ServerAliveInterval`/`timeout` de `prod-read.sh`, clé déverrouillée 1 h max) et côté base
> (`idle_in_transaction_session_timeout` du rôle `amateo_read`) ; ou mieux, mettre OpenSSH à jour.

**d. Sur le poste — l'alias SSH** (`~/.ssh/config`) : `User amateo-tunnel` et **agent dédié** :

```bash
cat >> ~/.ssh/config <<'EOF'
Host amateo-prod-read
    HostName <ip-ou-dns-de-la-vm>
    User amateo-tunnel
    IdentityFile ~/.ssh/amateo_prod_read
    IdentitiesOnly yes
    IdentityAgent ~/.ssh/agent-prodread.sock
EOF
```

**e. Sur le poste — le mot de passe de `amateo_read` dans `~/.pgpass-amateo`** (chmod 600), SANS
l'historiser (`read -rsp` lit en masqué ; l'espace initial évite le stockage si
`HISTCONTROL=ignorespace`) :

```bash
 read -rsp 'Mot de passe amateo_read : ' PW && \
   printf '127.0.0.1:15432:amateo:amateo_read:%s\n' "$PW" > ~/.pgpass-amateo && \
   chmod 600 ~/.pgpass-amateo && unset PW && echo OK
```

> ⚠ **`~/.pgpass-amateo` en 600 reste lisible par TOUT processus de ton compte** sur le poste — ce
> n'est pas un coffre. La vraie barrière n'est pas ce fichier mais la **phrase de passe de la clé +
> l'agent dédié**. Garde le poste sain et **coupe l'accès dès que tu as fini** (ci-dessous).

⚠ **La clé a peut-être été posée d'abord chez le compte de déploiement** (première mise en place).
Une fois `amateo-tunnel` créé, la **RETIRER de l'`authorized_keys` du compte de deploy** :

```bash
ssh <hôte> "sed -i '/amateo-prod-read/d' ~/.ssh/authorized_keys"
```

⬜ **Prérequis de sécurité du POSTE (finding C2).** Le modèle ne tient que si **aucune clé non
verrouillée vers la VM ne vit sur le poste**. Deux clés à ne pas confondre :
- **`amateo_deploy`** — la clé SSH **du CI**, qui vit **dans le seul secret GitHub `DEPLOY_SSH_KEY`**
  (§1.6, ligne « Secret `DEPLOY_SSH_KEY` »). Si une copie traîne sur le poste, la **retirer** ou la
  **chiffrer** ;
- **`amateo_prod`** — la clé **admin du fondateur** vers la VM, qui DOIT être **à phrase de passe**
  (jamais en clair à côté de `amateo_prod_read`).

⚠ **Jamais une clé privée dans `ENV_PROD`** (ce secret ne porte que le `.env.prod`). Et **ne jamais
charger `amateo_deploy` ni `amateo_prod` dans l'agent dédié** (étape suivante).

⬜ **Agent SSH dédié + déverrouillage** (une fois par session). Un agent **dédié** au tunnel, sur
un socket fixe — **jamais l'agent ambiant** : lui seul porte cette clé, et l'alias y est routé par
`IdentityAgent` (étape d). Cela remplace tout `SSH_AUTH_SOCK` global dans `~/.bashrc` :

```bash
# démarrer l'agent dédié (une fois ; peut aller dans ~/.bashrc, SANS export global)
[ -S ~/.ssh/agent-prodread.sock ] || ssh-agent -a ~/.ssh/agent-prodread.sock >/dev/null

# AVANT chaque enquête — déverrouiller la clé dans CET agent, 1 h max :
SSH_AUTH_SOCK=~/.ssh/agent-prodread.sock ssh-add -t 1h ~/.ssh/amateo_prod_read
SSH_AUTH_SOCK=~/.ssh/agent-prodread.sock ssh-add -l      # vérifier qu'elle est chargée
```

Plus court = mieux : garde la fenêtre serrée (`-t 1h`) et re-déverrouille au besoin. Si un askpass
est disponible, `ssh-add -c ~/.ssh/amateo_prod_read` exige en plus une **confirmation interactive à
chaque usage** de la clé — encore plus sûr quand on enchaîne peu de requêtes.

C'est **l'interrupteur** : clé absente de cet agent = `scripts/prod-read.sh` **refuse de démarrer**
(il compare l'empreinte `ssh-keygen -lf …pub` à l'agent dédié) et le tunnel s'ouvre en
`BatchMode=yes` — jamais d'invite qui quémanderait la phrase en douce. L'accès **expire seul** au
bout du délai. **Ne charge JAMAIS `amateo_deploy`/`amateo_prod` dans cet agent.**

⬜ **Vérifier que la VM applique bien le bloc**, après le `reload` sshd, DANS CET ORDRE (un test de
forward qui « échoue » parce que l'**authentification** a échoué est un FAUX vert — toujours
confirmer d'abord que la clé authentifie, étape 2) :

```bash
# 1. CONFIG EFFECTIVE sur la VM — prouve que l'Include de sshd_config.d est actif ET que le bloc
#    s'applique à amateo-tunnel. Attendu : allowstreamlocalforwarding no · allowtcpforwarding local
#    · permitopen 127.0.0.1:5432 · permitlisten none · forcecommand /bin/false.
sudo sshd -T -C user=amateo-tunnel,host=x,addr=127.0.0.1 \
  | grep -Ei 'allowstreamlocalforwarding|allowtcpforwarding|permitopen|permitlisten|forcecommand'
#    Et le compte de déploiement GARDE ses réglages normaux (le bloc ne déborde pas) :
sudo sshd -T -C user=<compte-deploy>,host=x,addr=127.0.0.1 \
  | grep -Ei 'allowtcpforwarding|allowstreamlocalforwarding|forcecommand'

# 2. POSITIF d'abord — le seul usage permis marche (prouve que la clé authentifie) :
scripts/prod-read.sh "SELECT 1;"

# 3. Transfert inverse -R : DOIT sortir avec « remote port forwarding failed » :
ssh -o ExitOnForwardFailure=yes -N -R 127.0.0.1:8080:127.0.0.1:8080 amateo-prod-read

# 4. Forward de socket Unix vers docker.sock : le curl DOIT échouer, ssh dit « administratively prohibited » :
ssh -N -L /tmp/x.sock:/var/run/docker.sock amateo-prod-read & sleep 2
curl --unix-socket /tmp/x.sock http://x/version    # échec attendu (connexion refusée / fermée)
kill %1 2>/dev/null; rm -f /tmp/x.sock

# 5. Shell : NE DOIT PAS en donner (ForceCommand /bin/false) :
ssh amateo-prod-read
```

⬜ **Utiliser** — `scripts/prod-read.sh` fait tout : tunnel `127.0.0.1:15432` → `127.0.0.1:5432`
via l'alias, `amateo_read` en transaction **read-only** (requêtes bornées à 60 s côté rôle, migration `Version20261010150000`),
fermeture du tunnel en sortant. `--club` pose `SET app.club_id` (une AIDE pour ne pas mélanger les
clubs, **PAS une frontière** — l'opérateur peut poser n'importe quel club) :

```bash
scripts/prod-read.sh "SELECT id, name FROM club ORDER BY name;"
scripts/prod-read.sh --club "<uuid|nom>" "SELECT * FROM team_tag;"   # pose SET app.club_id
echo "SELECT count(*) FROM team;" | scripts/prod-read.sh --csv        # requête sur stdin, sortie CSV
```

Un client graphique (DBeaver, PhpStorm, TablePlus) peut viser le même port via son propre onglet
« SSH tunnel » (hôte SSH `amateo-prod-read`, distant `127.0.0.1:5432`, rôle `amateo_read`) —
[`prod-stack.md`](prod-stack.md) § Accès opérateur.

⬜ **Révoquer** (poste compromis, fin de mission) — couper les sessions en cours, retirer la clé,
détruire la paire locale :

```bash
# 1. D'ABORD fermer la porte : vider l'authorized_keys du compte dédié (plus aucune nouvelle session) :
ssh <hôte> "sudo truncate -s 0 /home/amateo-tunnel/.ssh/authorized_keys"
# 2. PUIS tuer les sessions en cours, SANS condition (loginctl en plus si tu veux) :
ssh <hôte> "sudo pkill -u amateo-tunnel; sudo loginctl terminate-user amateo-tunnel 2>/dev/null || true"
# 3. côté poste : détruire la paire locale + le pgpass, et retirer la clé de l'agent dédié :
rm -f ~/.ssh/amateo_prod_read ~/.ssh/amateo_prod_read.pub ~/.pgpass-amateo
SSH_AUTH_SOCK=~/.ssh/agent-prodread.sock ssh-add -d ~/.ssh/amateo_prod_read 2>/dev/null || true
```

Pour couper l'accès BASE (au-delà de la clé), changer/retirer le mot de passe de `amateo_read`
(§ Supprimer `amateo_read`, ou `\password amateo_read`).

#### Supprimer `amateo_read` (si tu n'en veux plus)

Le rôle est isolé (que du `SELECT`, aucune dépendance) : le `down()` de la migration le retire, ou à la main
sur la VM en `amateo_owner`. `DROP OWNED BY` d'abord — il révoque tous les droits (table ET colonne) et permet
le `DROP ROLE`. (Le rôle est cluster-level : `DROP OWNED BY` ne touche qu'une base — le rejouer par base s'il a
été créé dans plusieurs.)

```bash
ssh <hôte> "docker compose exec postgres psql -U amateo_owner -d amateo -c 'DROP OWNED BY amateo_read; DROP ROLE amateo_read;'"
```

### 1.9 Réparer une donnée en prod — le geste sûr

Pour LIRE, `amateo_read` suffit (§1.8). Pour ÉCRIRE (corriger une donnée), il faut l'accès complet :
`amateo_owner`. **Toujours SSH sur la VM puis `psql` — JAMAIS depuis un poste** (pas de tunnel : un client
graphique en `amateo_owner`, c'est toutes les données de tous les clubs sur un portable, et un `UPDATE` mal
collé qui touche du vrai sans filet).

**Préférer une commande console applicative quand elle existe** (`bin/console app:…`) : elle passe par les
règles métier et laisse une trace. La retouche SQL directe est le dernier recours.

⚠ **RLS : `amateo_owner` bypasse le tenant** (policies `admin_all`, cf. [`../security/rls.md`](../security/rls.md)) —
`SET app.club_id` NE le filtre PAS. Donc **filtrer TOUJOURS par `club_id` à la main** dans le `WHERE`, sinon la
correction frappe TOUS les clubs.

1. **AVANT — sauvegarder la cible** : dump ciblé de la/les table(s) ou du club, ou backup complet.
   ```bash
   ssh <hôte>
   # dump ciblé d'une table (rejouable) :
   docker compose exec postgres pg_dump -U amateo_owner -d amateo -t public.<table> -Fc -f /tmp/repair-<table>-$(date +%F).dump
   # ou un backup complet applicatif (cf. backup-restore.md) :
   docker compose exec php-fpm php bin/console app:db:backup --force
   ```
2. **PENDANT — transaction explicite, jamais d'autocommit** : contrôler, agir avec un `WHERE` explicite
   (`club_id` COMPRIS), vérifier le nombre de lignes touchées, puis committer — ou tout annuler au moindre doute.
   ```sql
   BEGIN;
   -- 1. contrôle : voir exactement les lignes visées (club_id explicite)
   SELECT id, name FROM public.<table> WHERE club_id = '<uuid-du-club>' AND <condition>;
   -- 2. correction : WHERE explicite, club_id TOUJOURS présent
   UPDATE public.<table> SET <col> = <val> WHERE club_id = '<uuid-du-club>' AND <condition>;
   -- 3. vérifier le compte de lignes touchées AVANT de committer (psql affiche « UPDATE <n> »)
   SELECT count(*) FROM public.<table> WHERE club_id = '<uuid-du-club>' AND <condition>;
   COMMIT;   -- ou ROLLBACK; si le compte n'est pas EXACTEMENT celui attendu
   ```
3. **APRÈS — tracer le geste** dans le journal ops : qui, quand, quoi (table + condition + lignes touchées),
   pourquoi, et le dump pris avant. Une écriture directe en prod se justifie et se retrouve.

---

### 1.10 Jour J — données (seed initial, une seule fois)

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
l'historique shell). `--email` = ton compte fondateur, `--first-name`/`--last-name` = ton identité
(aucun nom n'est en dur ; d'éventuels co-gestionnaires passent par le fichier local d'identités).
⚠ **Lancer ce seed JUSTE APRÈS le déploiement**, avant d'ouvrir l'inscription publique à qui que ce
soit : le seeder crée les comptes gestionnaires à ces e-mails, et il **refuse** si un compte existe
déjà pour l'un d'eux (garde anti-usurpation — un compte créé entre-temps via `/register` avec ton
e-mail ne doit jamais être adopté par le seed).

```bash
docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console app:bccl:seed-prod --email=TON-EMAIL --first-name=TON-PRENOM --last-name=TON-NOM'
# → un prompt masqué : mot de passe gestionnaire (min 12 caractères).
```

Si la commande échoue sur « **An account already exists for … »** : un compte porte déjà cet e-mail.
**Ne pas contourner** — vérifier qui l'a créé, le supprimer (ou le traiter à la main) puis relancer.
Rien n'a été créé tant que ce refus s'affiche.

⬜ **Le club de démonstration** (`app:demo:seed`) — le compte naît **INACTIF** par défaut
(`app_user.demo_active_until` NULL) : ce seed ne suffit pas pour jouer la démo en rendez-vous,
**activer sa fenêtre depuis la console superadmin AVANT chaque rendez-vous** (`/admin` → onglet
« Démos » → « Activer 4 h » — `specs/courantes/superadmin-auth.md` §« Démos — console de
pilotage »). `--password` (min 12) est requis à la première création ; pour éviter de le laisser
en clair dans l'historique, préfixer la ligne d'un espace (avec `HISTCONTROL=ignorespace`) ou
passer par une variable non historisée :

```bash
 docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console app:demo:seed --password=MOT-DE-PASSE-DEMO'
```

⬜ **Rôle PostgreSQL lecture seule** — poser le mot de passe de `amateo_read` (créé par la
migration) : procédure §1.8.

⬜ **Ré-importer les matchs** — le seed pose l'état terrain (créneaux, contraintes, adversaires
déjà localisés) mais **pas les matchs** : les réimporter depuis l'UI (module Matchs → Importer, le
fichier FBI de la saison). Les localisations d'adversaires étant déjà amorcées, l'import retrouve
les gymnases sans re-résoudre.

⬜ **Vérifications** :
- se connecter aux **2 comptes** (fondateur, démo) — ils naissent pré-vérifiés ; le
  compte démo exige d'avoir activé sa fenêtre depuis la console superadmin au préalable
  (ci-dessus), sinon la connexion échoue comme un mot de passe faux (aucun oracle) ;
- non-fuite entre clubs (chaque club voit SES catégories, jamais celles d'un autre) :

```bash
docker compose exec php-fpm sh -c 'DATABASE_URL="$DATABASE_ADMIN_URL" php bin/console dbal:run-sql "SELECT t.name, t.club_id, sc.club_id FROM team t JOIN sport_category sc ON sc.id = t.sport_category_id WHERE sc.club_id <> t.club_id"'
# → 0 ligne attendue.
```

---

### 1.11 Umami — mesure d'audience de la vitrine (P4-276)

Umami mesure l'audience de la **page de vente** (`landing/`) SEULE — pas l'application. Service
`umami` dans `docker-compose.prod.yml`, sous-domaine `stats.amateo.app` servi par Caddy, base
`umami` **séparée** dans le postgres existant (**hors sauvegardes applicatives**, couverte par les
seuls snapshots disque — [`backup-restore.md`](backup-restore.md)). Détail de la stack :
[`prod-stack.md`](prod-stack.md) § Mesure d'audience.

⚠ **ORDRE OBLIGATOIRE** — ne JAMAIS taguer un deploy avant d'avoir fait **F1 ET F2** : le service
porte `:?` sur ses trois variables, un deploy sans elles est **refusé au `compose config`**
(fail-closed, avant toute mutation de la VM) ; et sans la base, le conteneur partirait en boucle
d'échec — sans jamais toucher l'app (aucun service ne `depends_on` umami).

⬜ **F1 — créer la base et le rôle** (une seule fois, sur la VM). Le cluster prod est déjà
initialisé, donc `docker/postgres/init/04-umami.sh` ne rejouera pas : on le fait à la main.
Générer d'abord le mot de passe et le coller dans la copie de référence `.env.prod`
(`UMAMI_DB_PASSWORD`) — jamais dans l'historique shell, le relire depuis le fichier :

```bash
ssh <hôte>
cd /srv/amateo
# psql en superuser bootstrap du cluster (POSTGRES_USER = amateo_owner). `$POSTGRES_USER` doit
# être développé par le shell DU CONTENEUR (où il est défini), pas par celui de l'hôte → `sh -c` :
docker compose -f docker-compose.prod.yml --env-file .env.prod exec postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres'
```

```sql
-- dans psql. Le mot de passe NE se tape PAS dans CREATE ROLE (il finirait dans le .psql_history
-- du conteneur) : rôle d'abord, mot de passe ensuite via \password (saisie masquée).
CREATE ROLE umami LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password umami   -- saisie MASQUÉE : coller la valeur de UMAMI_DB_PASSWORD du .env.prod
CREATE DATABASE umami OWNER umami;
REVOKE CONNECT ON DATABASE umami FROM PUBLIC;
\l    -- la base `umami` doit apparaître
\q
```

Vérif d'isolation — le rôle `umami` PEUT se connecter à `amateo` (`CONNECT` n'est PAS révoqué de
`PUBLIC` sur `amateo`), mais n'y a **aucun droit de lecture** : aucune table applicative ne lui est
accessible. Le prouver (résultat attendu : `permission denied`) :

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec postgres \
  psql -U umami -d amateo -c 'select count(*) from club'
# → ERROR:  permission denied for table club   (attendu ; si un mot de passe est demandé,
#   coller UMAMI_DB_PASSWORD). Un SELECT qui PASSE = STOP, l'isolation est cassée.
```

`\l` doit montrer `umami`. Durcissement possible (à décider — non appliqué ici) :
`REVOKE CONNECT, TEMP ON DATABASE amateo FROM PUBLIC;` fermerait aussi la connexion elle-même.
Si `CREATE ROLE` est refusé (owner non-superuser et sans CREATEROLE sur l'hébergeur) : **STOP**,
remonter — le runbook serait à revoir.

⬜ **F2 — secrets** : remplir les **trois** variables dans la copie de référence `.env.prod`
(`UMAMI_PORT`, `UMAMI_DB_PASSWORD` identique à F1, `UMAMI_APP_SECRET` = `openssl rand -hex 32`),
puis `gh secret set ENV_PROD < .env.prod` (§ Secret `ENV_PROD`).

⬜ **F3 — DNS** : enregistrement A `stats.amateo.app` → IP de la VM.

⬜ **F4 — déployer** : taguer / `make deploy` quand tu veux (§ Partie 2). `umami` démarre, crée son
schéma au premier boot, passe `healthy`. L'app n'est pas touchée.

⬜ **F5 — UI Umami par tunnel SSH** (le SEUL accès au tableau de bord, maintenant et toujours).
🔵 **Décision fondateur 2026-10-06 (option A) : le tableau de bord Umami n'est JAMAIS exposé
publiquement.** Caddy n'expose que les routes de COLLECTE (F6) ; le dashboard (login, listes de
sites, lecture des chiffres, toute l'admin) se consulte UNIQUEMENT par tunnel SSH. Le conteneur
n'écoute que sur `127.0.0.1:8082` de la VM — on l'atteint sans passer par Caddy :

```bash
ssh -L 8082:127.0.0.1:8082 <hôte>    # laisser ouvert le temps de la consultation
```

puis, dans le navigateur local, `http://localhost:8082` → login par défaut `admin` / `umami` →
🔴 **CHANGER LE MOT DE PASSE IMMÉDIATEMENT** (dès cette première connexion) → créer le site
« amateo.app » → copier le `websiteId`. Reporter l'URL interne, l'identifiant admin et où vit le
mot de passe dans la fiche d'instance `business/3-runbooks/` (**hors dépôt**, aucun secret ni URL
d'admin en git). Fermer le tunnel. **Ce même tunnel SSH est le geste de consultation courante** :
pour lire les chiffres plus tard, rouvrir `ssh -L 8082:127.0.0.1:8082 <hôte>` et aller sur
`http://localhost:8082`. (Item roadmap P4-307 : un futur onglet « Statistiques » dans la console
superadmin lira ces chiffres via l'API Umami, jeton côté serveur — alors plus besoin du tunnel.)

⬜ **F6 — Caddy (exposition de la COLLECTE seule)** : SEULEMENT une fois F5 fait. Ajouter le 4ᵉ
bloc `stats.amateo.app` du modèle ([`Caddyfile.example`](Caddyfile.example)) à
`/etc/caddy/Caddyfile`, puis `sudo systemctl reload caddy`. Ce bloc n'expose QUE `GET /script.js`
et `POST /api/send` (+ son préflight CORS `OPTIONS /api/send`) ; **tout le reste répond 404** — le
dashboard n'est donc jamais joignable depuis Internet, seulement par le tunnel SSH de F5. ⚠ Caddy
demande aussitôt un certificat → le nom `stats.amateo.app` paraît dans les journaux publics
Certificate Transparency → des scanners le découvrent en minutes ; ils ne trouveront que les deux
routes de collecte et des 404 partout ailleurs (aucune page de login à attaquer). L'ordre strict
« mot de passe admin changé (F5) **avant** l'exposition Caddy (F6) » reste **recommandé** par
hygiène, mais n'est plus une fenêtre d'attaque : ce bloc n'expose plus aucune UI de login.
(Avant que le DNS propage / le conteneur tourne, le bloc rend juste 502 sur la collecte, sans
effet ailleurs.)

⬜ **F7 — brancher le script** : ouvrir une **PR** qui remplit `analytics.scriptUrl` +
`analytics.websiteId` dans `landing/config.js` (et bumpe le `?v=` des DEUX pages). **Jamais une
édition directe sur la VM** : le deploy réécrit `landing/` à chaque passage, elle serait écrasée.
Un `websiteId` est public (visible dans toute page trackée) — sa place en git est correcte.
Prochain deploy → premiers hits dans le dashboard.

---

## Partie 2 — Au quotidien

### Déployer une release

```bash
git tag v1.2.0 && git push origin v1.2.0
```

Rien d'autre. Le workflow build → push → déploie → migre → sonde. Vert dans
*Actions* = en prod.

### Rituel sécurité post-déploiement (ZAP + Nuclei) — obligatoire à CHAQUE déploiement

Une fois le déploiement vert, rejouer le rituel décrit dans
[`docs/security/scanners.md`](../security/scanners.md) §Rituel (commandes ZAP baseline + Nuclei
contre l'hôte exposé). Résultat à consigner dans la même note que le déploiement (canal de suivi
du fondateur) ; un finding ouvre une ligne de roadmap (sévérité par le barème `/audit`) plutôt
qu'un correctif improvisé sur l'hôte.

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

⚠ **Toujours `make deploy VERSION=<tag>`, jamais re-pousser un ancien tag.**
`make deploy` fait un *dispatch* : le workflow qui s'exécute est celui de `main`
(secrets courants, dont `ENV_PROD`). Re-pousser un ancien tag ferait tourner le
**workflow d'alors**, qui attendait le rail chiffré disparu depuis SEC-23 et
avorterait avant toute mutation de la VM.

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

### Surveillance externe (sonde d'uptime)

Les vérifications ci-dessus sont **manuelles** : elles ne préviennent de rien quand personne ne
regarde. La sonde externe est le garde-fou qui alerte **depuis l'extérieur** quand l'app ne répond
plus (ou qu'une fenêtre de maintenance a été oubliée → 503, cf. *Maintenance planifiée*).

**Choix fondateur : Better Stack** (offre gratuite suffisante), deux moniteurs HTTP :

- `https://app.amateo.app/api/health` — l'app répond et son backend est vivant ;
- `https://amateo.app` — la page de vente est servie.

Alerte par **e-mail + application mobile** Better Stack. **Geste fondateur**, hors dépôt : la
configuration vit dans le compte Better Stack, **aucun secret ni URL de sonde en git** (les deux
URL surveillées sont publiques, elles). Rien à déployer. (Un moniteur voit le **503** d'une
fenêtre de maintenance oubliée — c'est la « sonde » dont parle *Maintenance planifiée*.)

**Facultatif — alerte budget Scaleway** : les coûts sont **majoritairement fixes** (le VPS). Les
seules variables pouvant déraper : le **stockage des sauvegardes** off-site, les **e-mails** si le
service d'envoi est facturé au volume, et une **clé API compromise** (usage frauduleux). Poser une
alerte de budget dans la console Scaleway couvre ces trois cas pour un geste de quelques minutes —
geste fondateur, hors dépôt.

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
a réussi). La vraie garantie qu'une fenêtre n'a pas été oubliée, c'est le **503 que voit la sonde
externe** (Better Stack, § *Surveillance externe*).

### Changer un secret / une variable d'env

1. En local : éditer la copie du `.env.prod` du gestionnaire de mots de passe
   du fondateur (la copie de référence, hors dépôt) ;
2. mettre à jour le secret GitHub `ENV_PROD` avec le contenu intégral
   (`gh secret set ENV_PROD < .env.prod`, ou coller dans *Settings → Secrets*) ;
3. si la variable est nouvelle, ajouter aussi sa ligne CHANGEME dans
   `.env.prod.dist` → **déployer** (tag ou `make deploy`) : le workflow pousse
   le fichier et `remote-deploy.sh` recrée les conteneurs ;
4. cas particuliers : rotation du `JWT_PASSPHRASE` = régénérer aussi le keypair
   (§1.3) ; rotation DB = `ALTER USER` côté postgres d'abord ; ⚠ **rotation
   d'`APP_SECRET` = re-chiffrer le TOTP des superadmins** : `super_admin.totp_secret`
   est chiffré en AES-256-GCM avec `sha256(APP_SECRET)` (`TotpService::key()`) —
   sans re-chiffrement (déchiffrer avec l'ancienne valeur, rechiffrer avec la
   nouvelle), plus aucun code TOTP ne passe et la console `/admin` est fermée
   (vécu lors de la rotation SEC-23 du 2026-10-04). Le faire avant d'effacer la
   copie de l'ancien `.env.prod`.

Urgence sans release : éditer `.env.prod` sur la VM +
`docker compose -f docker-compose.prod.yml --env-file .env.prod up -d`, **puis
reporter le changement dans le secret `ENV_PROD`** — sinon le prochain deploy
restaure l'ancienne valeur.

### Secret `ENV_PROD`

Modèle à deux fichiers (racine du repo) + un secret :

| Élément | Rôle | Git |
|---|---|---|
| `.env.prod.dist` | template commenté = la liste lisible des variables | commité en clair (zéro secret) |
| `.env.prod` | rempli, secrets réels | jamais commité (gitignoré, chmod 600) |
| secret GitHub `ENV_PROD` | le `.env.prod` entier collé tel quel | **la source de vérité** (hors dépôt) |

- Le secret se renseigne avec le contenu **intégral** du `.env.prod` collé tel
  quel, ou `gh secret set ENV_PROD < .env.prod`. GitHub masque les `secrets.*`
  ligne à ligne dans les logs ; le deploy n'affiche jamais le contenu.
- **Umami (P4-276)** ajoute trois variables à `.env.prod` (donc au secret) :
  `UMAMI_PORT`, `UMAMI_DB_PASSWORD`, `UMAMI_APP_SECRET`. Elles sont **requises** par
  le service `umami` (`:?` dans le compose) — un deploy sans elles est refusé au
  `compose config`. Procédure complète : §1.11.
- **Au deploy** : le step écrit `ENV_PROD` sur le runner (`umask 077`), le pousse
  sur la VM (chmod 600) avant `remote-deploy.sh`. Secret **vide ou absent** →
  **deploy avorté avant toute mutation de la VM** (message : créer le secret).
- **La ligne `VERSION=` du secret n'est pas significative** : le step de deploy
  préserve le pin `VERSION` courant de la VM (posé par `remote-deploy.sh` en
  fin de deploy réussi) — un `up -d` manuel entre deux deploys continue de
  tirer la version qui tourne.
- **Copie de référence** : le `.env.prod` rempli vit dans le gestionnaire de
  mots de passe du fondateur. Rotation : éditer cette copie → mettre à jour le
  secret `ENV_PROD` → redéployer. Perte de la copie SANS secret : repartir du
  `.env.prod` de la VM (ou du `.dist`).

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

