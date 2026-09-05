# Programme Behat — les promesses à couvrir pour ne laisser aucun angle mort

> **Fichier de détail ouvert** (référencé depuis [`roadmap.md`](roadmap.md), ligne P4-175). Demande
> fondateur du 2026-09-05 : « Behat couvre quoi ? qu'est-ce qui est sensible et mérite d'être couvert ?
> Le but c'est d'avoir une couverture suffisante pour ne pas avoir d'angle mort. » Jugement de
> l'orchestrateur, consigné ici ; chaque feature livrée quitte ce fichier et rejoint
> [`../../docs/testing/test-coverage-map.md`](../../docs/testing/test-coverage-map.md) §5.

## Ce que Behat est, et n'est pas

Behat est **la couche lisible** : un scénario en français, relu par le fondateur ou un client, qui
parle HTTP à la vraie stack (worker, moteur, base). Il ne remplace ni PHPUnit (unitaires, intégration,
sécurité — bloquants), ni pytest (sémantique du solveur), ni Playwright (écrans). Deux audiences,
donc pas un doublon au sens de [`duplications-de-verite.md`](duplications-de-verite.md) : PHPUnit
prouve au développeur, Behat prouve au **client**. Une feature Behat = **une promesse en une phrase**,
1 à 3 scénarios, jamais un test technique traduit.

## Ce qui est couvert aujourd'hui (5 features, 7 scénarios — `backend/features/`)

| Feature | Promesse prouvée |
|---|---|
| `generation-du-planning-de-saison` | le rail complet (demande → worker → moteur → import) aboutit, plan `COMPLETED` |
| `inscription-et-premier-planning` | un club neuf s'inscrit, valide son e-mail, saisit le minimum, obtient un planning |
| `placement-des-matchs` | un match tombe dans sa fenêtre d'accès et sur la rotation ; l'impossible est **nommé** |
| `plan-de-periode-en-overlay` | une fermeture obtient son plan sur sa propre grille ; le remplissage recolle un bloc ; re-dater garde le plan |
| `voeux-des-coachs` | un entraîneur répond par un lien public, sans compte ; le vœu remonte |

## Ce qui est sensible et n'a pas de preuve lisible — par ordre de livraison

Critère de « sensible » : une règle qui **détruit**, **verrouille**, **refuse** ou **isole** — celles
dont un client se plaindrait le plus fort si elles cédaient. Toutes ont une preuve technique
(PHPUnit, souvent bloquante) ; aucune n'a de scénario qu'un gestionnaire puisse relire.

| # | Feature à écrire | Promesse | Scénarios | Preuve technique existante |
|---|---|---|---|---|
| 1 | `le-socle-commande-les-plans` | Le socle commande les plans de période : le valider ou le rouvrir **détruit les plans FUTURS** (critère : date de début > aujourd'hui), jamais un plan commencé ; aucune génération de période sans socle en vigueur | (a) socle validé → plan futur disparu, plan commencé intact ; (b) socle rouvert → idem ; (c) génération de période sans socle → refusée | `SocleGuard*`, `PeriodPlanBirthTest`, `SeasonPlanInForceTest` (bloquants) |
| 2 | `une-contrainte-saisie-est-honoree` | Ce que le gestionnaire saisit, le planning le respecte ; l'impossible est dit, jamais contourné | (a) « SM1 pas avant 20:30 » → aucune séance SM1 avant 20:30 ; (b) contrainte impossible → génération **échouée** avec un diagnostic nommé, sans plan bricolé | `engine/tests/semantic/`, job `engine-semantics`, `GenerateScheduleFailureTest` |
| 3 | `un-club-ne-voit-jamais-un-autre-club` | Isolation stricte : un autre club ne liste, ne lit, ne modifie rien ; un membre sans rôle de gestion ne modifie rien | (a) A crée, B liste → vide ; (b) B lit l'id de A → introuvable ; (c) B supprime → introuvable ; (d) membre simple → refusé | `TenantIsolationTest`, `RlsIsolationTest`, `ManagementRoleTest`, `MemberRoleTest` (bloquants) |
| 4 | `l-unite-de-placement-est-le-bloc` | Une équipe qui s'entraîne en groupe ne se réserve pas seule ; retirer une séance du groupe retire le groupe | (a) résidu 0 → réservation seule refusée, nommée ; (b) réserver l'entraînement mutualisé passe ; (c) retirer une séance emporte le lot | `ReservationApiTest`, `GroupReservationApiTest` (P2-60, P2-62) |
| 5 | `un-verrou-est-souverain` | Une séance verrouillée ne bouge pas à la régénération ; un verrou impossible est diagnostiqué | (a) verrou HARD → même case après régénération ; (b) verrou impossible → diagnostic nommé | `LockOriginProvenanceTest`, `SlotMoveVerdictTest`, `SlotPlacementVerdictTest` (bloquants) |
| 6 | `le-perimetre-engage-est-protege` | Une équipe engagée en compétition n'est ni supprimée ni changée de niveau | (a) suppression refusée ; (b) changement de niveau refusé ; (c) équipe non engagée → libre | `EngagedTeamGuardTest` (bloquant) |
| 7 | `une-indisponibilite-se-decoupe-en-debut-milieu-fin` | Le découpage est début · milieu · fin ; jamais une semaine complète isolée ; d'un bloc seulement sans semaine entamée | (a) enfant hors segment → refusé ; (b) d'un bloc sur mer→mar → refusé ; (c) mer→mar en 3 plans → accepté | `WeekChildEntryTest`, `PeriodPlanBirthTest`, parité |
| 8 | `une-semaine-de-vacances-couvre-lundi-vendredi` | Une semaine n'est de vacances que si lun→ven est couvert | (a) semaine du 31/08 refusée en reprise ; (b) semaine du 24/08 acceptée | `WeekChildEntryTest`, parité D4 |
| 9 | `la-semaine-de-reprise` | Une semaine de vacances s'adapte : son plan naît, sa génération aboutit sur sa propre grille | (a) adapter la semaine du 24/08 → plan → `COMPLETED` | `PeriodPlanBirthTest`, `ScheduleConstraintBuilderOverlayTest` (le rail vacances n'a pas de scénario, l'overlay n'a que la fermeture) |
| 10 | `le-planning-se-dit-a-regenerer` | Modifier une ressource (gymnase, contrainte) marque le planning à régénérer, sans le détruire | (a) contrainte ajoutée → planning marqué ; (b) planning intact | `ConstraintChangeStaleScheduleTest`, `ResourceChangeStaleScheduleTest` (bloquants) |
| 11 | `l-export-du-planning` | Une version validée s'exporte (PDF) ; rien ne s'exporte sans session | (a) export → fichier non vide ; (b) sans session → refusé | `ExportPdfHandlerRlsTest` (technique) |

Non retenu : la bascule de saison (déjà un parcours Playwright, `season-transition.spec.ts`), les
écrans (Playwright), la performance (`engine-perf`).

## Règles d'écriture (les mêmes que les 5 premières)

- Français, `# language: fr`, aucun identifiant interne dans la feature ni dans un message d'échec.
- Un context par feature sur `BaseContext` ; ce qu'un scénario crée, son `@AfterScenario` le retire,
  échec compris ; chaque feature passe seule et dans n'importe quel ordre.
- HTTP contre la stack qui tourne (`http://nginx/api`), jamais de kernel in-process ni de DAMA.
- Une feature par PR, coder + `make -C backend behat` vert (n + 1 scénarios), la ligne quitte ce
  fichier et rejoint la carte §5.

## Ordre et effort

1 → 2 → 3 (les trois invariants « qui détruisent, refusent, isolent »), puis 4 → 5 → 6 (règles
métier fortes), puis 7 → 8 → 9 (lots de la semaine), puis 10 → 11. Chaque feature : S (une PR).
