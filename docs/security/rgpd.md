# RGPD — registre des traitements & mécanismes

> **Statut** : socle technique **livré** (P0-1). Les textes juridiques
> (CGU, politique de confidentialité, DPA) sont des **placeholders structurés**
> (`frontend/src/features/legal/PrivacyPage.tsx`) à faire rédiger avant commercialisation.
> Ce fichier est le **registre des traitements** (art. 30) côté ingénierie : inventaire → base
> légale → durée → mécanisme de purge → où c'est testé.

## 1. Les deux casquettes

| Casquette | Données | Qui exerce les droits |
|-----------|---------|----------------------|
| **Responsable de traitement** | comptes `User` (identité gestionnaire, email, hash) | l'utilisateur, en self-service (Profil) |
| **Sous-traitant** | données du club (`Coach`, contacts dirigeants, plannings) | le **club** (responsable), via les outils de l'app ; DPA dans les CGU |

## 2. Registre des traitements

| Traitement | Données | Base légale | Durée | Purge (mécanisme) | Testé par |
|------------|---------|-------------|-------|-------------------|-----------|
| Compte gestionnaire | User : email, prénom/nom, hash | contrat | activité + 24 mois (préavis à 23) | `app:users:purge-inactive` (cron horaire) — anonymisation | `InactiveUsersRetentionTest` |
| Compte jamais vérifié | User non vérifié + token | contrat (précontractuel) | 7 jours | `app:users:purge-unverified` (cron) | — |
| Données du club | Coach (email/tél), équipes, plannings, contraintes | contrat (via le club) | saison courante + N-1 | `app:seasons:purge` (cron, grâce 30 j post-bascule) ; suppression manuelle par le club (cascade, auditée) | `AccountErasureTest` (d) |
| Effacement de compte | anonymisation immédiate + purge club orphelin | obligation légale (art. 17) | grâce 30 j (annulable, revalidée) | `DELETE /api/me` → `app:clubs:purge-erased` — **l'identité publique FFBB du club survit** | `AccountErasureTest` |
| Portabilité | export JSON compte / workspace club — **périmètre DÉRIVÉ** des entités `TenantOwnedInterface`, plus recopié (D-01) | obligation légale (art. 20) | à la demande (10/h par user) | `GET /api/me/export`, `GET /api/club/export` (management) | `RgpdExportTest` + **`RgpdExportCompletenessTest`** |
| Contacts officiels FFBB | président/correspondant (nom/tél/email publiés par la FFBB) | **intérêt légitime** (organisation des rencontres, annuaire adverse) | tant que publiés (refresh FFBB) ; **survivent** à la purge du club | opposition : exclusion du refresh (à outiller avec l'annuaire) | revue DP1 |
| Journal d'audit | actions sensibles — **ids uniquement, jamais de PII** | intérêt légitime (accountability art. 5.2) | 12 mois | `app:audit:purge` (connexion admin — append-only DB pour le runtime) | `AuditTrailTest` |
| Doléances coachs (#10) | `CoachWish` (souhaits par équipe × semaine, **commentaire libre**), `CoachWishToken` (lien personnel + horodatage d'envoi `sentAt`) | contrat (via le club) | saison courante + N-1 | `app:seasons:purge` (`SeasonDataPurger` supprime `CoachWish` et `CoachWishCampaign` ; les tokens partent par cascade FK de la campagne) | `PurgeSeasonsCommandTest` |
| Visite du module matchs (RMM-3) | `MatchModuleVisit` — référence de visite PAR utilisateur (club+saison+user), horodatages seulement | contrat (via le club) | saison courante + N-1 (purge saison) ; vie du compte (effacement) | `app:seasons:purge` (`SeasonDataPurger`) **et** `DELETE /api/me` (`AccountErasureService`, boucle sur `findMemberClubIds` — clubs actifs ET quittés, pas seulement actifs) | `MatchVisitDeltaParityTest` |
| Appariement gymnase des adversaires (P2-54) | `OpponentVenueLink` — code organisme adverse, libellé de salle FBI (normalisé), gymnase fédéral apparié (snapshot label/coordonnées/ref), source AUTO\|MANUAL. **Club-scoped SANS saison** (un libellé désigne le même gymnase d'une saison à l'autre — remplace `OpponentTravel`, qui était par saison×équipe) | contrat (via le club) | vie du club — jamais purgé par saison (`SeasonDataPurger::EXCLUDED_FROM_SEASON_PURGE`) ; vie du compte (effacement) | `DELETE /api/me` → `ErasedClubPurger::PURGED_BY_CLUB` (purge par `clubId`) — une ligne `MANUAL` qui épingle un gymnase fédéral **décrémente d'abord** le compteur partagé `opponent_venue_suggestion` (`decrementSharedVenueChoices`, avant le DELETE de masse) | `PurgeCompletenessTest`, `AccountErasureTest` |
| Cache de trajets (C4) | `ClubTravelCache` — coordonnées ARRONDIES (5 décimales, ~1 m) origine/destination + profil (voiture/à pied) + minutes calculées ; **club-scoped, SANS saison** (un trajet routier est une constante) | contrat (via le club) | vie du club — jamais recalculé, jamais purgé par saison (`SeasonDataPurger::EXCLUDED_FROM_SEASON_PURGE`) ; vie du compte (effacement) | `DELETE /api/me` → `ErasedClubPurger::PURGED_BY_CLUB` (purge par `clubId`) ; **exclu de l'export de portabilité** (`RgpdExportService::EXCLUDED_FROM_EXPORT` — donnée d'établissement RECOMPUTABLE, sans PII). ⚠ Un changement de siège (`PATCH /api/club/siege`) laisse les anciennes lignes en base (nouvelle origine = nouvelle clé, l'ancienne n'est simplement plus jamais lue) — dette assumée, `backend/docs/geo-api.md` § cache de trajets, `roadmap.md` P4-249 | `PurgeCompletenessTest`, `RgpdExportCompletenessTest`, `ClubTravelCacheSeedTest` |
| Consentement | `termsAcceptedAt` + `termsVersion` au register | obligation légale (preuve) | vie du compte (anonymisé avec lui). Couvre 100 % des comptes réels : exigé au register avant le premier utilisateur de production (pas de backfill nécessaire — les comptes dev/test antérieurs n'en ont pas) | — | `ConsentTest` |
| Siège du club | `Club.address`/`postalCode`/`city`/`latitude`/`longitude` — l'adresse de l'ÉTABLISSEMENT (le club), posée par un gestionnaire (`PATCH /api/club/siege`) puis re-géocodée serveur-side ; donnée d'établissement, pas une PII (cohérent avec `Club.php:156-158` — ce sont les adresses PERSONNELLES du président/correspondant qui sont délibérément NON stockées) | contrat (via le club) | vie du club — `ErasedClubPurger` ne touche pas la ligne `Club` elle-même (la fiche FFBB, siège compris, **survit** à l'effacement d'un compte, § ligne « Effacement de compte ») ; supprimée avec le club entier par `PurgeErasedClubsCommand` (+30 j sans membre actif revenu) | — | `ClubSiegeTest` |
| Registre « à corriger dans FBI » | `FbiCorrection` — rencontre visée, champ (date/heure/salle), valeur appli, valeur FBI encore affichée, alias FBI du gymnase, `decidedBy` (guid interne, jamais un email/nom) | contrat (via le club) | saison courante + N-1 (purge saison) ; **supprimée avec la rencontre** (cascade applicative, pas de FK — `FixtureStateProcessor::cascadeBeforeDelete` → `FbiCorrectionLedger::removeForFixture`, ouvertes et fermées) | `app:seasons:purge` (`SeasonDataPurger::PURGED_BY_CLUB_SEASON`, ajoutée) — seule porte de sortie d'une entrée FERMÉE dont la rencontre survit (trace) ; exportée par la boucle générique de portabilité (`RgpdExportService::clubScopedTables()`, `TenantOwnedInterface`, aucune liste manuscrite à tenir) | `PurgeCompletenessTest`, `RgpdExportCompletenessTest` |

### Ce que l'export de portabilité NE contient PAS, et pourquoi

Deux tables club-scoped sont **volontairement** hors de `GET /api/club/export`. Ce sont des
décisions, tenues par `RgpdExportCompletenessTest` (qui refuse une exclusion sans raison écrite) :

| Table | Raison |
|---|---|
| `coach_wish_token` | Le token est un **secret stocké en clair** — il EST l'identité de la page publique du coach. Le verser dans un fichier téléchargeable transformerait la portabilité en **fuite de credentials** : le porteur du JSON pourrait écrire des souhaits au nom de n'importe quel coach. Les **souhaits eux-mêmes** (`coach_wish`) sont exportés : c'est là qu'est la donnée de l'art. 20. |
| `audit_log` | Base légale **distincte** : accountability (art. 5.2, intérêt légitime), pas le contrat. L'art. 20 ne couvre que les données **fournies par la personne** sur base contrat ou consentement. La table est de surcroît append-only et sans PII (ids uniquement). |

> ⚑ **Le périmètre ne se recopie plus.** Il est dérivé des métadonnées Doctrine filtrées sur
> `TenantOwnedInterface` (`RgpdExportService::clubScopedTables()`), marqueur déjà prouvé
> équivalent à la colonne `club_id`. La liste manuscrite qui existait avant avait dérivé de
> **9 tables** — dont `coach_wish` — et l'omission était **invisible** : la réponse restait 200
> et le JSON valide, la clé simplement absente. Une entité tenant nouvelle fait
> **échouer** le test tant qu'elle n'est pas explicitement exportée ou exclue.

### La purge ne se recopie pas non plus (BCK-24)

Même patron que l'export (§ ci-dessus) : `SeasonDataPurger` et `ErasedClubPurger` exposent chacun
leur liste d'entités purgées comme une source, jamais une copie — une liste manuscrite laisserait
une entité tenant nouvelle (`OpponentTravel`, P2-54) survivre à un « réinitialiser la saison »
**et** à un effacement RGPD sans qu'aucun test ne le voie (BCK-24).

Les deux purgers exposent leurs listes comme des **constantes publiques introspectables**
et `backend/tests/Security/PurgeCompletenessTest.php` (phase1, régime de garde identique à
`RgpdExportCompletenessTest` — ne gate pas `build-docker`) dérive de ces constantes, sans en
recopier une seule ligne, et **exige** que toute entité `TenantOwnedInterface` soit, pour chaque
chemin, purgée ou nommément exclue avec sa raison :

- `SeasonDataPurger::PURGED_VIA_PARENT` (enfants sans colonne club/saison, résolus via leur
  parent), `::PURGED_BY_CLUB_SEASON` (purge de masse par `club_id`+`season_id`), `::HANDLED_APART`
  (cas particuliers — `team_tag_assignment` scopée saison seule, la ligne `Season` elle-même) et
  `::EXCLUDED_FROM_SEASON_PURGE` (club-scoped sans saison, `audit_log`, `coach_wish_token`).
- `ErasedClubPurger::PURGED_BY_CLUB` (club-scoped sans saison, purgées par `club_id` à
  l'effacement) et `::EXCLUDED_FROM_ERASURE` (`audit_log`, `coach_wish_token`).

Une entité tenant nouvelle fait **échouer** ce test tant qu'elle n'est pas explicitement
couverte ou exclue — le même défaut par défaut que pour l'export.

## 3. Mécanismes clés (pointeurs code)

- **Anonymisation** : `AccountErasureService` — email → `deleted-{id}@anonymized.invalid`, hash aléatoire, transactionnel, memberships désactivés club-par-club sous GUC RLS.
- **Purge club différée** : `Club.erasureScheduledAt` (+30 j) → `PurgeErasedClubsCommand` (revalide à l'échéance, auto-annule si un membre actif est revenu). Fiche FFBB épargnée, état d'abonnement vidé (`ErasedClubPurger`).
- **Win-back** : ré-inscription sur l'ARA d'un club sans membre actif = reprise directe (owner), re-seed si purgé (`AuthController::verifyEmail`).
- **Activité** : `LoginSuccessListener` (throttlé 1 écriture/jour, best-effort — l'authenticator JWT déclenche l'événement à chaque requête).
- **Audit** : `AuditTrail` (INSERT DBAL + SAVEPOINT, no-PII) ; append-only tenu par la DB (aucune policy UPDATE/DELETE) ; lecture = future console superadmin (SA1).
- **Consentement** : requis au register (400 sinon, validation payload-only = enumeration-safe A3) ; version des textes = `AuthController::TERMS_VERSION`.

## 4. Doctrine backups (**livrée** — cf. `docs/ops/backup-restore.md`)

Les sauvegardes contiennent des données effacées : purge **naturelle par rotation des dumps**
(`DatabaseBackupCommand::RETENTION`, les 14 plus récents), **aucune restauration sélective** de
données effacées (une restauration complète post-incident ré-exécute les purges au cron suivant —
les champs `anonymizedAt`/`erasureScheduledAt` restaurés re-déclenchent les mécanismes).

## 5. Logs & PII

- Backend : sweep 2026-07-11 — aucun email/nom dans les `logger->…` (codes FFBB publics, ids,
  messages d'exception). Règle : **jamais d'email/nom dans un log** ; les ids suffisent.
- Engine : les access-logs uvicorn contiennent des IPs (données perso) — la **rotation** est posée
  par l'ancre `logging` de `docker-compose.prod.yml` (cf. `docs/ops/prod-stack.md`) ; la **durée de
  rétention** reste à confirmer côté hébergeur.
- Mercure : payloads `{status, score, unplaced, warnings}` — pas de PII.
- **Doléances (#10)** : le **commentaire libre** d'une doléance est un champ à contenu non maîtrisé
  (le coach y écrit ce qu'il veut, potentiellement des données personnelles) — **jamais loggé**,
  jamais inclus dans un payload Mercure ni dans un message d'erreur.

## 6. Reste à faire (hors P0-1)

- Textes juridiques finaux (CGU, politique, DPA) — fondateur/juriste, avant commercialisation.
- Mécanisme d'opposition outillé pour les contacts FFBB (avec l'annuaire adverse) — pas encore construit.
