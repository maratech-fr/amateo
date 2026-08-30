# Living Specs System

Last verified @ 2026-08-30 (`documentation-update`, rotation — les quatre gardes
`backend/tests/Unit/Documentation/{DocPlacementTest,DocStampFreshnessTest,RoadmapIdentityTest,BlockingTestsListMatchesCiTest}.php`
existent toujours ; `docs/testing/blocking-tests.md` reste la maison canonique pointée par
`CLAUDE.md` §4. Le sommaire `Files Overview` de `specs/courantes/` re-confronté au dossier réel :
les 12 entrées (`etat-des-lieux.md`, `planning-lifecycle-validated.md`, `types-de-planning.md`,
`superadmin-auth.md`, `identite-visuelle-club.md`, `vacances-scolaires-jours-feries.md`,
`accueil-cockpit-temporel.md`, `module-matchs.md`, `canal-signalement.md`,
`generation-pipeline.md`, `openapi-snapshot.json`+`.meta.md`) existent toutes, aucune orpheline.
Les trois fichiers dits absorbés (`features-futures.md`, `backend-gaps.md`,
`contraintes-modele-cible.md`) confirmés absents de `specs/evolution/`. Reste du fichier
(3-tier structure, audiences) non re-vérifié cette passe.)

## 3-Tier Structure

- `specs/initiales/` : besoin d'origine (v2/v3), **figé — jamais modifié**. L'évolution se lit dans le delta `initiales` → `courantes` (+ git). Pas de dossier `archive/`. ⚠ **« Figé » interdit de MODIFIER, pas d'ARCHIVER** : une pièce source reçue du terrain peut être déposée ici (ex. `rechercherRencontre.xlsx`, export FBI joint au cadrage P1-4 le 2026-08-02) — elle entre telle quelle et ne bouge plus. Un dépôt n'est légitime que s'il s'agit d'une **pièce d'origine** ; toute production de l'équipe va dans `courantes/` ou `evolution/`.
- `specs/courantes/` : **ce que l'appli fait aujourd'hui**, TOUS ZONES CONFONDUES. ⚠ Ce n'est pas le
  dossier du « courant » : un doc qui décrit **une seule zone** (inventaire de ses routes, liste de ses
  composants, stratégie de test de la zone) appartient à **`<zone>/docs/`**, pas ici — règle de
  placement du skill `documentation-update`, tenue par `DocPlacementTest`. Migration du 2026-08-18 :
  6 fichiers (~4 000 lignes) sont partis vers `frontend/docs`, `backend/docs` et `engine/docs`. Doit refléter le code : si une spec ne colle plus → on la **met à jour** ; si la feature a disparu → on la **supprime**. Point d'entrée : [`etat-des-lieux.md`](courantes/etat-des-lieux.md) — carte des capacités livrées, **décisions fermées**, traces datées.
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

### La règle du stamp — « Last verified » doit être VÉRIFIABLE, pas déclaratif

**Un fichier édité après la date de son stamp a un stamp qui ment.** C'est mécanique, ça se contrôle en une commande, et ça n'a rien à voir avec la qualité du contenu :

```bash
# Liste les stamps antérieurs au dernier commit qui a touché le fichier.
for f in specs/courantes/*.md docs/project-map.md docs/testing/testing-strategy.md specs/README.md; do
  s=$(grep -m1 -o 'Last verified @ [0-9-]*' "$f" | grep -o '[0-9-]*$'); [ -z "$s" ] && continue
  last=$(git log -1 --format=%ad --date=short -- "$f")
  [[ "$last" > "$s" ]] && echo "STAMP MENTEUR: $f (stamp=$s commit=$last)"
done
```

Le 2026-08-08, cette commande a sorti **9 fichiers sur 16** — dont un modifié le jour même sans bump. Aucun n'avait un contenu périmé : chacun avait été correctement recalé **par la PR qui livrait la feature**. Seul le stamp était resté figé. C'est exactement pour ça que le motif a survécu à six éditions d'audit (`AUD-DOC-04`) : personne ne voyait le mensonge, parce qu'il ne portait pas sur le fond.

**Deux formulations, deux sens — ne pas les confondre :**

- *« Last verified @ D (X re-vérifié contre `<fichier de code>` »* — quelqu'un a **relu le code**. C'est la forme forte, la seule qui autorise à faire confiance au fichier sans re-vérifier.
- *« Last verified @ D (contenu recalé par les livraisons : … »* — le fichier a été **maintenu au fil des PR**, sans relecture d'ensemble. Honnête et utile, mais plus faible : un oubli de PR n'y serait pas vu.

**Bumper sans avoir rien vérifié est pire que ne pas bumper** : ça transforme une promesse vague en fausse garantie. Si la commande ci-dessus signale un fichier, l'ordre est : regarder ce que les commits ont changé (`git log --since=<stamp> -- <fichier>`), vérifier ce point, **puis** dater — en disant laquelle des deux formes s'applique.

**La date est celle du STATUT, pas celle de la dernière édition de fond.** On date le jour où l'on statue sur le fichier ; ce qui a été recalé, et quand, se dit dans la parenthèse (« statut posé ce jour ; contenu recalé jusqu'au JJ par … »). Dater de la dernière édition de fond paraît plus fidèle mais se mord la queue : le commit qui pose le stamp est lui-même une édition, donc le stamp naîtrait déjà en retard sur son propre fichier.

> **Ce n'est plus une promesse : c'est un test.** `backend/tests/Unit/Documentation/DocStampFreshnessTest.php` exécute la règle ci-dessus (groupe `phase1`, donc joué par `unit-tests` — un contexte requis de `main`). Son voisin `BlockingTestsListMatchesCiTest.php` fait le même travail pour la liste canonique des blocking-tests (`docs/testing/blocking-tests.md`, ex-`CLAUDE.md` §4) contre les steps réels de `ci.yml`, dans les deux sens. `RoadmapIdentityTest.php` (2026-08-22) tient la roadmap elle-même : **un identifiant désigne un item et un seul** — il est cité depuis le code, les commits et les PR, donc le réattribuer casse ces références en silence — et le compteur du titre « Roadmap (N) » dit la vérité. Il est né d'une récidive : `P4-119` et `P5-19` désignaient chacun deux items, alors que `P4-119` est cité dans une vingtaine d'endroits de `frontend/src/features/planning/`. Les deux sont nés du même constat : dans ce dépôt, **le seul document qui cesse de mentir est celui qu'un test surveille** — le premier de la série étant `engine/tests/test_contract_version_doc_sync.py`.

## Files Overview

- `specs/initiales/` — `ClubScheduler_v3.md` (spec produit consolidée, figée) · `ClubScheduler_Specification_des_contraintes_v2.md` (modèle de contraintes d'origine) · prompt orchestrateur v3.
- `specs/courantes/` — `etat-des-lieux.md` (**point d'entrée** : ce que l'app sait faire, décisions fermées, traces datées) · specs de features livrées graduées depuis evolution (`planning-lifecycle-validated`, `types-de-planning` — les 3 types (socle / overlay / vacances) et l'axe collecte coach · `superadmin-auth` — console SA0-SA4 + monitoring · `identite-visuelle-club`, `vacances-scolaires-jours-feries`, `accueil-cockpit-temporel` — calendrier d'exceptions/overlays livré #122 · `module-matchs` — import FBI + placement + radar conflits) · `canal-signalement` (signalement/support/reproduction, `X-Request-Id` bout en bout) · `generation-pipeline` (conduite normalisée bout en bout front→backend→engine→import→affichage + invariants silencieux) · `openapi-snapshot.json` + son meta (régénéré à chaque changement d'API).
- `specs/evolution/` — `roadmap.md` (**index unique de l'OUVERT** : toute évolution/gap/idée non livrée y laisse une ligne) · fichiers de détail référencés depuis la roadmap quand une ligne ne suffit pas (liste des fichiers actifs tenue dans le header de la roadmap). Règle : un fichier de détail devenu sans objet (sujet livré/tranché) est supprimé après absorption dans la roadmap (`features-futures.md`, `backend-gaps.md`, `contraintes-modele-cible.md` absorbés le 2026-07-05 — leurs IDs `FF#n`/`G#n` restent cités comme réf historiques).
- `specs/audit/` — éditions d'audit horodatées (`AUDIT-<date>-<model>.md`, skill `/audit`) ; registre de findings à ID stables, comparaison inter-éditions.

## Notes

This README documents the maintenance obligations for the living specs system.

⚠ **Ce fichier a longtemps fini par « does not promise automated drift checks or CI enforcement ».**
C'était faux depuis le 2026-08-08, et contredit dix lignes plus haut par son propre encadré : quatre
tests du dossier `backend/tests/Unit/Documentation/` exécutent ces règles, et ils tournent dans
`unit-tests` — un contexte requis de `main`. Ce qui reste **manuel**, et qu'aucun test ne remplace :
juger si le contenu d'une spec dit encore la vérité. Une machine tient les invariants de FORME
(placement, fraîcheur d'un stamp, unicité d'un identifiant, décompte) ; elle ne sait pas lire.
