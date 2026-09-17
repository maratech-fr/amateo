Last verified @ 2026-09-17 (montée du lot Dependabot — `api-platform/*` 4.3.17 → **4.4.0** : l'export
OpenAPI passe en **3.2.0** (était 3.1.0), qui autorise une `description` en frère d'un `$ref` (la 3.1
l'interdisait, API Platform la supprimait) ; régénéré par le coder après `cache:pool:clear --all` +
`api:openapi:export`).
**200 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** : aucune route,
aucun schéma, aucune propriété ne bouge — seules 3 propriétés typées par RÉFÉRENCE
(`Schedule.capabilities`, `ScheduleDiagnostic.causes`, `SchedulePlan.staleness`) gagnent leur description
docblock, plus la version de spec `3.1.0`→`3.2.0` (9 clés `description` ajoutées = 3 propriétés × base/html/jsonld).
· SHA-256 `c017ce7eba83b58db5c6936493cad4f7962e19aa8d0493735ed298a63d238add`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **Montée Dependabot — API Platform 4.4 / OpenAPI 3.2.0 (2026-09-17)** : **+0 path** — la montée
  `api-platform/*` 4.3.17 → 4.4.0 fait passer l'export de `openapi: 3.1.0` à `3.2.0`. La 3.2 autorise
  une `description` en frère d'un `$ref` (interdit en 3.1, API Platform la supprimait) : 3 propriétés
  typées par référence publient donc désormais leur docblock — `Schedule.capabilities` (→ `ScheduleCapabilities`),
  `ScheduleDiagnostic.causes` (→ liste de `DiagnosticCause`), `SchedulePlan.staleness` (→ `SchedulePlanStaleness`).
  Les docblocks de `capabilities` et `causes` ont été RÉÉCRITS dans la même passe (la référence interne
  part en commentaire `//`, la phrase publique reste — garde `PublicTextIsFreeOfInternalIdentifiersTest`).
  Aucune route, aucun schéma, aucune propriété ne change ; contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **VILLE de l'adversaire extérieur (au lieu du gymnase), backend (2026-09-17)** : **+0 path** — la
  description du champ `opponentPlace` (côtés `left`/`right` de MATCH_MATCH, `fixture` de MATCH_TRAINING sur
  le radar `GET /api/fixtures/conflicts`) est recalée : ligne d'override effective (équipe puis club) → VILLE
  de la salle CHOISIE (`opponent_venue_suggestion` par (code, venueExternalRef)) → VILLE de l'annuaire fédéral
  → null ; le libellé de gymnase d'override et le libellé FBI ne sont PLUS servis. Aucune forme de schéma ne
  change (seule la description). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21,
  aucun appel moteur). Le faux positif d'échauffement du même radar est corrigé dans la MÊME PR côté détecteur
  (`MatchConflictDetector`) — aucun impact OpenAPI, hors de ce snapshot.
- **« Détail par côté » d'un conflit de personne, backend (2026-09-17)** : **+0 path** — le radar
  `GET /api/fixtures/conflicts` gagne cinq champs ADDITIFS PAR CÔTÉ sur les familles PERSONNE (les côtés
  `left`/`right` de MATCH_MATCH, `fixture` de MATCH_TRAINING) pour rendre une ligne par côté : `estimatedKickoffTime`
  (heure estimée `HH:MM`, non-null seulement quand le coup d'envoi est estimé), `travelOneWayMinutes` (trajet aller
  simple ; null = non modélisé, toujours null en domicile), `matchDurationMinutes` (durée de match du côté),
  `opponentLabel` (le libellé adverse), et `opponentPlace` (où joue l'adversaire — décoré côté AWAY seulement :
  override manuel équipe > club > annuaire fédéral > libellé FBI > null). Aucune empreinte de conflit ne change
  (ces champs sont hors identité). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun
  appel moteur, aucun payload solveur ne lit ces champs).
- **PR-2b « adversaire multi-gymnases » — auto-localisation depuis le fichier + orchestrateur, backend (2026-09-16)** :
  **+1 path** — `POST /api/opponents/refresh` (management) enchaîne EN UN APPEL les trois passes best-effort qui
  mettent à jour les adversaires AWAY : (`codes`) rattrapage des codes fédéraux dans l'annuaire + estampille des
  rencontres, (`autoLocated`) auto-localisation du gymnase de chaque équipe adverse depuis le libellé de salle
  du FICHIER FBI (salle FÉDÉRALE, surcharge de trajet TENANT source AUTO, jamais le partagé ni le texte client),
  (`travel`) recalcul des trajets AUTO. Réponse à trois blocs (`codes`/`autoLocated`/`travel`), chaque passe
  indépendante ; cap dur 200 avant réseau (422) + limiteur `opponent_refresh` (429). Les routes fines
  `/api/opponents/resolve` et `/api/opponents/travel/resolve` restent (compat). 199 → **200 paths**. Backend PUR,
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun appel moteur, aucun payload solveur ne lit
  `opponent_travel`).
- **« Une personne = ses équipes coachées + ses équipes où elle joue », backend (2026-09-15)** : **+0 path** —
  le radar `GET /api/fixtures/conflicts` unit les coachs (`team_coach`) et les joueurs (`CoachPlayerMembership`
  actifs) dans une même carte personne→équipes. Champs ADDITIFS : `coachRole` gagne la valeur `PLAYER` (agrégat
  MAIN si tous MAIN, ASSISTANT dès qu'un côté ASSISTANT, PLAYER sinon) ; chaque côté d'un conflit personne porte
  son `role` (`MAIN`|`ASSISTANT`|`PLAYER`) — `left.role`/`right.role` sur MATCH_MATCH, `fixture.role`/`training.role`
  sur MATCH_TRAINING ; l'enum `type` du conflit est recalé sur ses 10 familles réelles (VENUE_OVERLAP,
  LEAGUE_WINDOW_VIOLATION, MATCH_MATCH, MATCH_TRAINING, VENUE_UNAVAILABLE, ACCESS_WINDOW_LOST, TEAM_LINK_OVERLAP,
  COMPETITION_INCOMPLETE, AWAY_NO_FOOTPRINT, FRIENDLY_ON_MATCH_SLOT). Aucune empreinte de conflit ne change
  (le rôle est hors identité). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun
  appel moteur, aucun payload solveur ne lit les adhésions joueur).
- **PR-2 « adversaire multi-gymnases », backend (2026-09-15)** : **+1 path** — les SUGGESTIONS partagées de
  gymnases par club adverse. `GET /api/opponents/{code}/venue-suggestions` (management, A6) rend les gymnases
  connus d'un adversaire — vus dans le calendrier fédéral (`FFBB_API`) ou choisis par des clubs (`MANUAL`) —
  avec un `chosenByCount` (« un compte, jamais un qui »), FFBB_API d'abord puis MANUAL par compte décroissant ;
  422 si le code n'est pas un adversaire AWAY de la saison. 198 → **199 paths**. Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun appel moteur, aucun payload solveur ne lit le
  partagé).
- **PR-1 « adversaire multi-gymnases », backend (2026-09-15)** : **+0 path** — le trajet adverse gagne le
  grain ÉQUIPE. `GET /api/opponents/travel` : une entrée par (code, équipe) au lieu d'une par code, avec les
  champs additifs `opponentTeamKey`, `scope` (`TEAM`|`CLUB`|null), `city`, `postalCode`. Les corps
  `POST /api/opponents/travel/manual` et `/auto` acceptent `opponentTeamKey` (+ `scope` optionnel sur
  `manual`) ; leurs réponses gagnent `opponentTeamKey`/`scope`. Le schéma read `Fixture` gagne
  `opponentOrganismeCode` + `opponentTeamKey` (libellé adverse normalisé, servi pour joindre le trajet par
  équipe sans re-dériver). 198 → **198 paths**. Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur, aucun payload solveur ne lit `opponent_travel`).
- **B1 — P4-207 « Résolution des conflits », backend (2026-09-15)** : **+1 path** — `PUT`/`DELETE`
  `/api/fixtures/conflicts/{fingerprint}/resolution` (poser/remplacer ou retirer le statut de traitement d'un
  conflit — management-only ; « à traiter » = absence de ligne = `DELETE` idempotent) ; le radar
  `GET /api/fixtures/conflicts` gagne le champ additif `resolution` (objet `status`/`note`/`updatedAt`,
  nullable = « à traiter »). 197 → **198 paths**. Backend PUR, contrat backend⇄engine **inchangé** (aucun appel moteur).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
