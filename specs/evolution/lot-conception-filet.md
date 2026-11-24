# Lot conception : le filet (enregistreur des demandes au moteur)

Maison de l'outil du lot 0 de [`lot-conception-decoupage.md`](lot-conception-decoupage.md) : règle 4
(« le moteur ne change dans aucun lot ») et preuve commune des lots qui déplacent des données. Ce
document dit à quoi sert l'outil, ce qu'il enregistre, **ce qu'il n'enregistre pas**, et la procédure
à jouer sur une copie de la production.

## 1. À quoi sert le filet

Chaque club envoie au moteur des demandes (`POST /generate`, `POST /place-matches`) que le backend
compose à partir de ses données. Une migration de structure ne doit pas les changer. L'outil
enregistre, par club, les demandes que le moteur recevrait, sous une forme comparable :
enregistrer « avant », migrer, enregistrer « après », puis `diff -r`. Zéro ligne de différence = la
demande est identique, club par club ; une différence est nommée par son chemin.

## 2. La commande

```
php bin/console app:engine-requests:record --out=<dossier> [--club=<uuid>]…
```

- `--out` (requis) : dossier absent ou vide, jamais un lien symbolique. Une racine créée par l'outil
  naît en `0700` ; un dossier existant et vide garde ses droits.
- `--club` (répétable) : sans lui, tous les clubs de la base.
- Source : `backend/src/Command/EngineRequestsRecordCommand.php`, `Service/EngineRequestRecorder.php`
  (quelles demandes), `Service/EngineRequestFingerprint.php` (format). Fiche de commande :
  [`backend/docs/commands.md`](../../backend/docs/commands.md).

Codes de sortie (les refus 2, 3 et 4 précèdent toute écriture ; 4 précède les deux autres) :

| Code | Sens |
|---|---|
| 0 | Enregistré |
| 1 | Une demande atteignable n'a pas pu être composée (nommée, par club et par clé) ; le reste est écrit |
| 2 | Option invalide : `--out` absent, lien symbolique, fichier ou dossier non vide ; `--club` n'est pas un UUID |
| 3 | La sécurité par club (RLS) n'est pas effective sur la connexion : rôle superuser, `bypassrls`, membre de `amateo_owner`, ou la sonde voit des lignes sans club posé |
| 4 | Environnement de production |

L'outil ne persiste rien et ne flush pas : il pose le club sur la connexion comme les workers, compose,
vide l'EntityManager après chaque club. Le club est relâché même si la purge échoue. Il purge le tag de cache `cache.schedule` du club avant
chaque construction et à la fin du club — le payload du socle est servi du cache (4 h,
`ScheduleConstraintBuilder.php:59`) et une écriture SQL brute, comme une migration, ne l'invalide
pas.

## 3. Le format

Une demande = un fichier de texte. Une ligne par feuille, `<chemin>\t<valeur>`, lignes triées
(ordre d'octets), saut de ligne final.

- **Désignation par id** : un élément de liste se nomme par son `id` — `teams[<uuid>].sessionsPerWeek`.
  Un id répété dans la liste : `<id>#1`, `<id>#2`. Sans id : son index. Un id n'est écrit en clair que s'il est
  un UUID, un nombre, ou un préfixe connu (`team-coach`, `coach-player-unavailability`,
  `priority-tier`) suivi d'UUID ou de nombres ; tout autre id est désigné par son `sha256:`.
- **`#order`** : une ligne `<chemin>#order` par liste porte l'ordre des éléments. Un changement de
  contenu ne se noie donc jamais dans un décalage d'index, et un changement d'ordre ne touche que
  cette ligne.
- **En clair** : nombres (`1.0` garde sa décimale), booléens, `null`, UUID, heures, dates, valeurs
  d'une liste FERMÉE d'énumérations, version du contrat. **Toute autre chaîne** : `sha256:<empreinte>`
  — aucun nom d'équipe, de coach, de gymnase n'est écrit sur disque.
- L'ordre des clés d'un objet n'est pas un changement.

## 4. L'arborescence de sortie

```
<out>/requests/club-<id>/<clé>      un fichier par demande
<out>/views/club-<id>/fixtures.json et conflicts.json   les vues des matchs (lot 7), saison courante
<out>/manifest.json                 hors de l'arbre comparé
```

Les vues des matchs (`App\Matches\MatchViewRecorder`) portent l'extension `.json` mais sont rendues au format d'empreintes (§3). Elles ne couvrent QUE la saison courante du club, alors que les demandes parcourent toutes les saisons ; comparer : `diff -r <avant>/views <apres>/views`.

`manifest.json` : par club, la liste triée des clés enregistrées et les `skipped` (`scope`, `reason`).
Comparer : `diff -r <avant>/requests <apres>/requests`.

## 5. L'inventaire des clés

Gardes d'ÉTAT ignorées : l'outil veut la demande, pas l'autorisation du jour.

| Clé (sous `club-<id>/`) | Demande |
|---|---|
| `season-<s>/plan-<p>/regenerate` | Plan de socle qui a au moins une version terminée ou en échec : la version neuve (non persistée), source = version chargée du plan |
| `season-<s>/plan-<p>/version-<v>.generate` | Socle : chaque version brouillon ou en échec. Période : la version chargée ; à défaut, chacune de ses versions non terminées |
| `season-<s>/plan-<p>/fill` | Plan de période qui a une version chargée : « Combler » |
| `season-<s>/placement/all` | Placement des matchs, club entier — seulement s'il y a un match à placer |
| `season-<s>/placement/<lundi>_<dimanche>` | Placement sur chaque semaine ISO qui porte un match à placer |

Sans socle en vigueur, aucune demande de période n'est composable : elles sont sautées et nommées
dans le manifeste (`socle_not_in_force`), jamais tues.

## 6. Limites de l'enregistrement (demandes NON enregistrées)

1. Un plan de période dont **aucune version n'est chargée et dont toutes les versions sont
   terminées** n'a aucune demande enregistrée : ni génération, ni comblement.
2. Les versions du socle **en cours de génération** (en attente ou en cours) ne sont pas
   enregistrées : seules le sont les versions brouillon ou en échec, et la régénération depuis la
   version chargée.
3. Le placement n'est enregistré que pour le **club entier** et pour chaque **semaine lundi→dimanche
   qui porte un match à placer** ; une fenêtre de placement choisie librement n'est pas enregistrée.

Une migration qui ne toucherait que ces demandes ne se verrait pas dans `diff -r`.

Autres limites :

- **`/validate-assignments` n'est pas enregistré.**
- **« Jamais en production » (code 4) ne repose que sur l'environnement** : il ne protège ni d'un
  processus de développement branché par `DATABASE_URL` sur la base de production, ni d'un `APP_ENV`
  autre que `prod` sur la machine de production.
- **Les empreintes ne sont pas salées** : une chaîne courte (prénom, nom d'équipe) se retrouve par
  dictionnaire ; les fichiers se traitent comme la base dont ils viennent.
- **En `-vvv`**, une exception de base de données peut afficher du SQL.
- **La classe de test de garde de la connexion** (`EngineRequestsRecordConnectionGuardTest`) crée des
  rôles à l'échelle du cluster PostgreSQL : elle refuse de tourner hors d'une base `*_test`.
- **Une demande de placement dépend de la date du jour** : enregistrer « avant » et « après » le même
  jour, ou à horloge figée.
- Une équipe nommée **exactement comme une valeur d'énumération** de la liste fermée reste en clair.
- La sonde du code 3 lit toutes les tables du schéma `public` qui ont une colonne `club_id` et la RLS,
  sauf `club_user`, `coach_wish_token` et `club_invitation` (lecture hybride,
  `Security/RlsIsolationTest.php:174-180`) : une table de club sans RLS n'est pas vue.
- Le tri par id des lectures des constructeurs ([`etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2) est l'unique exception à
  « la demande est identique avant et après » ; son effet sur le moteur n'est pas connu au-delà de
  la mesure faite avant le merge de ce lot.

## 7. Procédure : rejouer sur une copie de la production

Ce que cette procédure prouve : la migration d'un lot laisse intacte la demande de chaque club réel.
Elle n'est jouée qu'**avec l'accord du fondateur, à chaque restauration**.

⚠ **En tête : la restauration documentée dans [`backup-restore.md`](../../docs/ops/backup-restore.md)
(§3a et §3b, `pg_restore --no-owner --no-privileges`) SUPPRIME les droits de `amateo_app`** : la
commande, jouée avec ce rôle, échouerait en `permission denied`. Pour cette procédure, restaurer
comme ci-dessous.

1. **Transférer le dump.** Il contient TOUS les clubs : transfert chiffré (`ssh`/`scp`), aucun
   disque partagé. Il est effacé à la fin, avec les dossiers `--out`.
2. **Créer la base jetable**, vide, sous un nom qui n'est ni la base de travail (`amateo_dev`,
   `amateo_local`) ni le bac à sable de test. Les rôles `amateo_app` et `amateo_owner` doivent
   exister dans la grappe locale.
3. **Restaurer en gardant droits et RLS** :
   `pg_restore --no-owner --exit-on-error -U amateo_owner -d <base_jetable> <dump>`.
   - Sans `--no-privileges` : les droits d'`amateo_app` et les policies RLS sont rejoués (établi par
     un essai sur base jetable).
   - `--exit-on-error` rend bruyant l'échec d'une policy, silencieux sans lui.
   - Le nom du rôle propriétaire en production n'est PAS vérifié : la restauration est à constater
     (code retour de `pg_restore`), jamais à supposer.
4. **Cibler la base jetable à chaque commande** : `DATABASE_URL` (rôle `amateo_app`) ET
   `DATABASE_ADMIN_URL` (rôle `amateo_owner`, celui des migrations, `doctrine.yaml:15`) passés en
   clair dans la commande, jamais via `.env.local`
   ([`migration-rollback-drill.md`](../../docs/ops/migration-rollback-drill.md) § Fail-closed).
5. **« Avant »**, sur le code de base : `app:engine-requests:record --out=<avant>`.
6. **Migrer**, avec le code du lot : `doctrine:migrations:migrate`.
7. **« Après »** : `app:engine-requests:record --out=<apres>`, le même jour que « avant ».
8. **Comparer** : `diff -r <avant>/requests <apres>/requests`. Vide = demande identique. Une sortie 3
   ou 1 est lue, jamais contournée.
9. **Nettoyer** : `DROP DATABASE <base_jetable>`, effacer les dossiers `--out` et le dump, ici et
   sur la machine d'origine du transfert.

## 8. Mesure du temps de rendu du PDF (réserve du §21 de la conception)

Procédure à jouer, aucune commande livrée. Elle sert au fondateur à choisir entre « je demande, je
reçois le fichier » et « tout en file » (lot 10).

- **Décor** : le seed BCCL (49 équipes, 9 gymnases, `backend/src/Seed/LoadTestClubSize.php:15-16`) complété à 60
  équipes fictives par l'API, **seulement dans la stack d'épreuve** (`make epreuve-up`) ; il
  disparaît avec `make epreuve-down`, aucun seed n'est modifié.
- **Séries, par les vraies routes** : PDF du planning le plus lourd, file vide, 1 puis 4 demandes
  rapprochées ; idem pendant une génération en cours (la file est partagée, `backend/config/packages/messenger.yaml:36-40`,
  un seul consommateur, `docker-compose.prod.yml:214`) ; PDF des matchs (rendu dans la requête,
  `backend/src/Controller/ExportMatchesPdfController.php:84`) à 1, 4 puis 8 demandes simultanées.
- **Critères** : PDF du planning, de la demande au fichier prêt, file vide : ≤ 120 s ; temps de
  traitement d'un export par processus ≤ 120 s ; pendant une génération : relevé et comparé, un
  dépassement est signalé, non tranché.
- **Faits qui bornent** : 120 s = `max_execution_time` de FPM (`docker/php/Dockerfile:176`) = `fastcgi_read_timeout`
  (`docker/nginx/default.conf:48`) = `proxy_read_timeout` du nginx du frontend (`docker/frontend/nginx.conf:74`) : marge NULLE
  pour un PDF rendu dans la requête. L'appel au `pdf-worker` est coupé à 30 s
  (`backend/src/Service/PdfGenerator.php:155-158`). Pool de 4 processus, 192 Mo (`docker/php/Dockerfile:186`, `:190`).
- **Limite** : la mesure se fait sur les réglages de la stack d'épreuve, pas sur la mémoire PHP d'une
  requête de production.

## 9. Règle de purge du cache au déploiement

Après toute migration qui réécrit une table lue par le constructeur de demande, le payload du socle
reste servi du cache pendant 4 h (`ScheduleConstraintBuilder.php:59`, lecture `:260-268`).
**Automatisée** : `scripts/remote-deploy.sh` lance `bin/console cache:pool:clear cache.schedule`
après « Deploy … OK », dans ses deux branches. Un échec ne fait pas échouer le déploiement : il écrit
une alerte au journal, et la purge se relance alors à la main ([`deploy.md`](../../docs/ops/deploy.md)
§ « Purge du cache des demandes au moteur »). Limite : le cache peut rester périmé 4 h au plus quand
l'alerte passe inaperçue.
