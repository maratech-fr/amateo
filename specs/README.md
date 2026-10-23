# Living Specs System

## 3-Tier Structure

- `specs/initiales/` : besoin d'origine (v2/v3), **figé — jamais modifié**. L'évolution se lit dans le delta `initiales` → `courantes` (+ git). Pas de dossier `archive/`. ⚠ **« Figé » interdit de MODIFIER, pas d'ARCHIVER** : une pièce source reçue du terrain peut être déposée ici (ex. `rechercherRencontre.xlsx`, export FBI joint au cadrage P1-4 le 2026-08-02) — elle entre telle quelle et ne bouge plus. Un dépôt n'est légitime que s'il s'agit d'une **pièce d'origine** ; toute production de l'équipe va dans `courantes/` ou `evolution/`.
- `specs/courantes/` : **ce que l'appli fait aujourd'hui**, TOUS ZONES CONFONDUES. ⚠ Ce n'est pas le
  dossier du « courant » : un doc qui décrit **une seule zone** (inventaire de ses routes, liste de ses
  composants, stratégie de test de la zone) appartient à **`<zone>/docs/`**, pas ici — règle de
  placement du skill `documentation-update`, tenue par `DocPlacementTest`. Migration du 2026-08-18 :
  6 fichiers (~4 000 lignes) sont partis vers `frontend/docs`, `backend/docs` et `engine/docs`. Doit refléter le code : si une spec ne colle plus → on la **met à jour** ; si la feature a disparu → on la **supprime**. Point d'entrée : [`etat-des-lieux.md`](courantes/etat-des-lieux.md) — carte des capacités livrées, **décisions fermées**, traces datées. ⚠ **Seul `etat-des-lieux.md` porte un journal** (décisions fermées §2, une ligne par livraison §3, fondateur 2026-09-26) : les autres fichiers de `courantes/` décrivent le présent seulement — pas de « désormais », de section datée d'une PR ni de bloc « historique/superseded » ; l'historique de CE contenu vit dans git. Gardé mécaniquement par `SpecsCarryNoHistoryTest` (`backend/tests/Unit/Documentation/`) sur tout `courantes/*.md` sauf ce fichier.
- `specs/evolution/` : **ce que l'appli fera plus tard** (backlog + gaps ouverts). Quand un item est **livré**, il **quitte** evolution (il gradue dans `courantes`). Les notes de process/décisions **résolues** n'y restent pas.

**La règle des deux fichiers (refonte 2026-07-31).** `evolution/roadmap.md` ne tient que **l'ouvert** ; `courantes/etat-des-lieux.md` tient **le livré**. Un item livré **MOVE** : sa ligne est supprimée de la roadmap et une trace datée est ajoutée à l'état des lieux, avec le comportement documenté dans la spec courante qui le reçoit. Jamais les deux, jamais aucun. Corollaire : « est-ce que X est fait ? » ne se répond **pas** dans la roadmap.

## Audiences

- initiales = origine (référence historique).
- courantes = développeurs / agents (vérité courante).
- evolution = planification (futur).

## Update Triggers

- **Déclencheur unique : `documentation-update`, exécuté AVANT chaque PR** (CLAUDE.md §7 étape 6). La doc est vivante — une PR qui corrige ou ajoute quelque chose a de la doc à mettre à jour quelque part.
- `courantes` : mise à jour quand le comportement change (ou suppression si la feature disparaît) ; `etat-des-lieux.md` reçoit la trace datée et, si besoin, la décision fermée.
- `evolution` : on **retire** un item quand il est livré (graduation vers courantes) ; on **ajoute** un item quand un gap/bug/feature futur est identifié — avec sa preuve `fichier:ligne` vérifiée dans le code.
- `initiales` : jamais modifié.

## Files Overview

- `specs/initiales/` — `ClubScheduler_v3.md` (spec produit consolidée, figée) · `ClubScheduler_Specification_des_contraintes_v2.md` (modèle de contraintes d'origine) · prompt orchestrateur v3.
- `specs/courantes/` — `etat-des-lieux.md` (**point d'entrée** : ce que l'app sait faire, décisions fermées, traces datées) · `modules-produit.md` (la carte ci-dessus **en langage club** : une promesse, ce qui est livré, où c'est dans l'app — nourrit la page de vente/FAQ/démo) · specs de features livrées graduées depuis evolution (`planning-lifecycle-validated`, `types-de-planning` — les 3 types (socle / overlay / vacances) et l'axe collecte coach · `superadmin-auth` — console SA0-SA4 + monitoring · `identite-visuelle-club`, `identite-visuelle-produit` (base produit + accent club, en-tête, fond d'écran commun), `vacances-scolaires-jours-feries`, `accueil-cockpit-temporel` — calendrier d'exceptions/overlays livré #122 (son modèle de données `CalendarEntry` vit à part dans `calendar-entry-modele-donnees.md`, découpé par thème) · `module-matchs` — import FBI + placement + radar conflits) · `mutualisation` — le modèle `SharedTrainingBlock` (« groupe »), les gestes (wizard + planning, socle ET période) et les décisions fondateur, pointe la sémantique moteur sans la dupliquer · `canal-signalement` (signalement/support/reproduction, `X-Request-Id` bout en bout) · `generation-pipeline` (conduite normalisée bout en bout front→backend→engine→import→affichage + invariants silencieux) · `import-du-parc` (chantier G — format `parc-v1`, gate, statuts d'appariement, bornes anti-abus (PR 1, dry-run) ; import réel tout-ou-rien, ordre d'écriture, catégorisation, idempotence (PR 2) ; écran de pilotage et prompt restent à livrer) · `openapi-snapshot.json` + son meta (régénéré à chaque changement d'API).
- `specs/evolution/` — `roadmap.md` (**index unique de l'OUVERT** : toute évolution/gap/idée non livrée y laisse une ligne) · fichiers de détail référencés depuis la roadmap quand une ligne ne suffit pas (liste des fichiers actifs tenue dans le header de la roadmap). Règle : un fichier de détail devenu sans objet (sujet livré/tranché) est supprimé après absorption dans la roadmap (`features-futures.md`, `backend-gaps.md`, `contraintes-modele-cible.md` absorbés le 2026-07-05 — leurs IDs `FF#n`/`G#n` restent cités comme réf historiques).
- `specs/audit/` — éditions d'audit horodatées (`AUDIT-<date>-<model>.md`, skill `/audit`) ; registre de findings à ID stables, comparaison inter-éditions.

## Notes

This README documents the maintenance obligations for the living specs system.

⚠ **Ce fichier a longtemps fini par « does not promise automated drift checks or CI enforcement ».**
C'était faux depuis le 2026-08-08 : plusieurs tests du dossier `backend/tests/Unit/Documentation/`
(placement, taille, liste bloquante ⇄ `ci.yml`, unicité d'un identifiant, absence d'historique…)
exécutent ces règles de FORME, et ils tournent dans `backend-tests` — un contexte requis de `main`.
Ce qui reste **manuel**, et qu'aucun test ne remplace : juger si le contenu d'une spec dit encore
la vérité. Une machine tient les invariants de FORME (placement, unicité d'un identifiant,
décompte) ; elle ne sait pas lire.
