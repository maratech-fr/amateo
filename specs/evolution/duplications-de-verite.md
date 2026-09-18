# Duplications de vérité — doctrine + l'ouvert

> **Règle de forme (refonte 2026-09-18, AUD-DOC-43).** Ce fichier ne tient plus un cimetière de
> findings résolus : **un doublon résolu QUITTE ce fichier** — sa trace vit dans
> [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md) §3 (livraison) et son détail
> dans git (`git log -p --follow specs/evolution/duplications-de-verite.md` retrouve l'inventaire
> complet du 2026-08-08 et les 40 corrections qui ont suivi). Ce qui reste ici : la **doctrine**
> (§1, réutilisée par le skill `documentation-update` comme test de fusion), les duplications
> **délibérées** qu'il ne faut PAS mutualiser (§2, référence vivante — ne pas confondre avec un
> résidu), et l'**ouvert** (§3).
>
> **Le critère n'est PAS « peut-on mutualiser ? »** — c'est **« si les deux copies divergent,
> est-ce bruyant ou silencieux ? »** Une divergence qui casse un test ou rend un 422 est
> tolérable : elle se signale. Une divergence qui change le comportement sans rien dire est le
> vrai danger.

---

## 1. La doctrine — le test de fusion (utilisé par `documentation-update`)

Quatre gardes exemplaires montrent la forme à appliquer partout :

| Garde | Ce qu'il tient |
|---|---|
| `TenantOwnedInterfaceCompletenessTest` | marqueur d'interface ⇄ colonne `club_id`, diff **bidirectionnel** |
| `RlsIsolationTest.php:39-46` | liste **dérivée de `pg_class`**, jamais recopiée |
| `TeamTagScopeTest.php:202-221` | constante SQL `INSERT_COLUMNS` lue **par réflexion** et diffée contre les métadonnées Doctrine |
| `BlockingTestsListMatchesCiTest` | liste de `docs/testing/blocking-tests.md` ⇄ steps de `ci.yml`, **dans les deux sens** |

**La forme commune, à appliquer partout ailleurs :**

> **Ne pas synchroniser deux listes. Dériver la seconde de la première, et laisser un test faire
> le diff bidirectionnel.**

Avant de proposer une fusion (deux fichiers qui parlent du même sujet ne sont pas forcément un
doublon) : **la divergence serait-elle SILENCIEUSE ?** Deux audiences distinctes, chacune servie
par sa propre copie → pas un doublon (§2). Un « garder les deux en phase » écrit noir sur blanc
dans un commentaire est un AVEU — c'est un candidat.

## 2. Duplications DÉLIBÉRÉES — ne pas mutualiser

| Duplication | Pourquoi c'est assumé | Garde |
|---|---|---|
| **Contrat backend↔engine** (Pydantic ⇄ payload PHP) | Frontière de zone : le codegen créerait un couplage de build entre deux runtimes (CLAUDE.md §6, « No codegen — synced manually ») | `ContractSchemaTest` + `MatchPlacementContractSchemaTest` + `ValidateAssignmentsContractSchemaTest` |
| **Caps DoS backend vs engine** | Défense en profondeur voulue : le backend refuse tôt avec un message clair, le moteur se protège de tout appelant. Asymétrie **documentée** (`GenerationComplexityGuard.php`, `input_schema.py`) | Chaque côté a son test ; pas de test de parité — acceptable, la divergence est bruyante (422) |
| **`JWT_COOKIE_SECURE`** (plusieurs copies) | La séparation par fichier **EST** le mécanisme de sécurité : `backend/.env` entre dans l'image de prod, donc le `false` de dev doit vivre ailleurs | ⭐ **Le modèle du dépôt** : défaut fail-closed `'true'`, bloc de commentaire expliquant l'omission, et `JwtCookieSecureDefaultTest` qui assert l'**absence** de la variable |
| **Projections structurelles par feature** (ex. `planning.Team` à 4 champs) | Une interface étroite **documente** ce dont l'écran dépend. Ce qui doit être partagé, ce sont les **unions de valeurs**, pas les formes | — |
| **`coachDoubleBooking.ts` front ⇄ `CoachDoubleBookingDetector` back** | La modale doit répondre sans aller-retour réseau (documenté en tête du fichier front) | Cas de test identiques des deux côtés |
| **`docker-compose.yml` vs `.prod.yml`** (ex. image Mercure `latest` vs épinglée) | Divergence voulue et commentée dans le fichier prod | — |

## 3. L'ouvert

| # | Sujet | Preuve | Ce qui se passe en silence | Pourquoi il attend |
|---|---|---|---|---|
| **D-11** | `matchDay` : convention de jour | `TeamInput.php:45` `Range(0,6)`, « 0 = Monday » — vs ISO 1..7 partout ailleurs (`TeamMatchHabitInput.php`, `MatchSlotRotationInput.php` en `Range(1,7)`) et `objective/terms.py` `match_day % 7 + 1` | Le bug d'ÉMISSION est corrigé (`ScheduleConstraintBuilder::deriveMatchDay` convertit le champ déclaré 0-based en ISO avant de l'émettre) ; `TeamInput.php` elle-même reste 0-based — un `matchDay` saisi via l'API accepte encore 0-6, jamais 7 (dimanche ISO) | **Dormant** : `match_day` reste NULL sur toutes les équipes, aucun écran n'écrit ce champ. À traiter le jour où le champ sera exposé, pas avant |
