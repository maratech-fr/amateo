# Copie des messages d'erreur — quelle langue, où

> Maison canonique de la **règle de classification** des messages d'erreur backend.
> Pas d'inventaire ligne à ligne (il dériverait), pas de décompte (« N messages »).
> Le code fait foi ; ce doc dit **comment décider**, pas **combien**.

Last verified @ 2026-09-04 (rotation `documentation-update`, PR D3 v1 cockpit — hors sujet de la
PR, contrôle de fraîcheur). Re-confronté au code : `status < 500` toujours à `errorMessage.ts:32`
✓ · `new ValidationException(` toujours confiné à `AbstractStateProcessor.php` (seule occurrence
de `src/`) ✓ · `ValidationExceptionCarriesViolationsTest` (`tests/Unit/`) et
`PublicTextIsFreeOfInternalIdentifiersTest` (`tests/OpenApi/`) existent sous ces noms ✓. Rien de
faux trouvé.

## La règle

Un corps d'erreur `4xx` (`< 500`) revient TEL QUEL dans le toast du frontend :
`frontend/src/shared/lib/errorMessage.ts:32` ne reprend `body.error ?? body.message ??
body.detail` **que** pour `status < 500`. Donc :

- **Français métier** — obligatoire dès qu'un **gestionnaire** peut lire le message via l'app,
  chemin nominal **ou course** (onglet périmé, deux gestionnaires concurrents, ressource
  disparue entre deux clics). Ton : **cause + geste**, vouvoiement, jamais d'identifiant interne,
  jamais de stack / SQL / détail technique. Modèle pour une course :
  `Cette version n'existe plus — rechargez le planning.`
- **Anglais toléré** — un message qui n'atteint **jamais** l'écran d'un gestionnaire :
  - **≥ 500** : jamais affiché (`errorMessage.ts:32` l'ignore, son corps peut porter du détail
    interne). On ne le traduit pas.
  - **Défense pure / API-only** : échos de contrat que seul un script/outil déclenche
    (`Invalid JSON`, `Unauthorized`, `Missing required field: …`, noms de champ renvoyés tels
    quels — `seasonId …`, `accentColor …`, params `from`/`to`), et gardes « No club in context. »
    qui ne se produisent pas depuis un front authentifié normal.
  - **Superadmin `SA0`** (`Controller/Admin*`, firewall `/api/admin/**`) et **outillage `Dev*`** :
    hors app gestionnaire, laissés tels quels.

## Le rail 422 des state processors — un refus PARLE (P4-126, 2026-08-23)

`throw new ValidationException('chaîne')` rend un 422 **muet** : le constructeur-chaîne crée une
`ConstraintViolationList` VIDE, et le normalizer d'API Platform dérive `detail` et `violations[]`
**exclusivement de la liste** — l'écran affiche « An error occurred » pendant que le message
français soigné meurt dans un champ que personne ne lit. Mesuré le 2026-08-22 : 35 occurrences
dans 11 processors, dont les 3 messages de la mutualisation que la passe #700 avait traduits
**pour personne**.

**L'idiome est unique** : `$this->refuse('…')` (`AbstractStateProcessor` — vraie liste, le message
ressort dans `violations[].message` ET `detail`, les deux champs que lit `errorMessage.ts`).
`new ValidationException(` est **interdit partout ailleurs** dans `src/`, gardé par
`tests/Unit/ValidationExceptionCarriesViolationsTest` — une liste ad hoc hors du helper
recréerait une deuxième maison.

⚠ Piège d'assertion connu : API Platform sérialise l'apostrophe en `\u0027` — une assertion sur
le CORPS BRUT manque « d'accès » ou « L'indisponibilité ». Décoder le JSON et lire
`violations[0].message`, comme le fait le consommateur réel.

## Intouchables (ne relèvent pas de la copie humaine)

- **Codes machine** : toute clé `'code' => …` et sa valeur (`generation_failed`, `stuck_timeout`,
  `expired`, `window_already_planned`, `ara_taken`, `invalid_credentials`, `not_demo_account`,
  `teardown_refused`, `slot_unavailable`, `duration_mismatch`, …). Seule la valeur de `'error'`
  (la phrase lue par l'humain) porte la copie. Le front route sur le `code`, pas sur la phrase.
- **404 à parité byte-identique** des pages à token (invariant sécurité, anti-énumération) :
  `Controller/ClubApprovalController.php` (`Not found`) et
  `Controller/PublicCoachWishController.php` (`not found`) — INTOUCHABLES, la moindre variation
  distinguerait un token valide d'un token inconnu.
- **Formes de réponse, statuts HTTP, en-têtes** : rien d'autre que le littéral de la phrase ne
  change quand on traduit.

## Familles laissées en anglais (et pourquoi)

- **Timeouts / passerelle moteur** de l'édition manuelle (`ManualEditController` : « The engine
  did not answer in time … », « The engine did not respond … ») : renvoyés en `504`/`502`, donc
  `≥ 500` → jamais affichés.
- **Gardes de contrat d'import / logo** non listées côté nominal (`Club not found.`, `Forbidden.`,
  champ de fichier) : défense de contrat, pas un chemin gestionnaire réel.
- **`AbstractStateProcessor`** garde des voies API-Platform génériques ; ses deux messages
  atteignables par un `PUT`/`DELETE` cross-tenant (`Resource not found`, `Access denied`) SONT en
  français (un gestionnaire peut les provoquer), le reste de la défense de contrat non.

## Pointeurs

- Consommateur frontend : `frontend/src/shared/lib/errorMessage.ts`
- Interdiction d'identifiant interne dans un texte lu : `.claude/rules/backend.md`, gardée par
  `PublicTextIsFreeOfInternalIdentifiersTest`.
