# ADR-0007 — Le socle froid : une version en vigueur, un état de travail, une copie du froid par version

- **Status**: accepted — Date: 2026-11-28 — **remplace partiellement l'[ADR-0002](adr-0002-pattern-plan.md)** pour le cycle de
  vie du plan de SAISON (valider, rouvrir, charger, ce que lisent matchs, radar et plans de période). Le modèle
  Plan → Versions → Pointeur, la grille possédée par la période, les verrous d'une période, la règle de la version
  chargée et le « Rouvrir » d'un plan de PÉRIODE restent ceux de l'ADR-0002.
- Produit : [`specs/courantes/planning-lifecycle-validated.md`](../../specs/courantes/planning-lifecycle-validated.md).

## Contexte

L'ADR-0002 faisait du pointeur du plan de saison l'unique définition de « validé » et d'« en vigueur » : rouvrir le socle
remettait le pointeur à nul, détruisait les plans de période à venir et la collecte de doléances, et les matchs, le radar
et les plans de période se retrouvaient sans socle. Travailler sur le socle coûtait donc cher, même pour changer un nom.
Une version ne gardait de ses conditions qu'une photo de la seule structure (dix familles listées à la main), restaurée
par deux gestes de « Charger » qui ne disaient pas la même chose. Chaque champ saisi périmait le planning, un
renommage comme un changement de séances.

## Décision

1. **Le froid se déclare.** Chaque famille de niveau saison ou plan dit, dans `#[Family]`, ses champs froids
   (`cold`, `[]` pour « libre »), ses lignes froides (`coldRows`), si elle est du froid du socle (`ofSocle`) et si la
   saison suivante la reprend (`copiedToNextSeason`) ; `FamilyCatalog::coldOf()` et `::copiedToNextSeason()` les lisent
   (extension de l'[ADR-0005](adr-0005-family-declaration.md)). Un nom, un contact, un adjoint, un libellé, le véhiculé
   ou les jours maximum d'un coach, un temps de trajet sont libres ; la place d'une séance et son verrou appartiennent à la
   version, hors du froid.
2. **Toute version terminée porte la copie de son froid** (`schedule_state`, une ligne par version, cascade avec elle,
   RLS forcée ; `App\Plans\PlanStates`). Elle s'écrit dans la transaction du résultat de la génération : un échec fait
   échouer la version, sans crédit. Une retouche du planning qui écrit un réglage du plan synchronise dans la copie de la
   version chargée les seules lignes touchées.
3. **Deux mesures, une garde.** L'empreinte (« périmé ? ») ignore les clés libres. La différence de deux copies sur les seuls
   champs froids (`PlanChanges`) dit « qu'est-ce qui change, faut-il supprimer ». Chaque clé de la demande au moteur a une
   source, libre, froide ou à chaud (`EngineRequestKeySourcesTest`). Le contrat avec le moteur ne change pas.
4. **Une seule porte pour lire l'en vigueur : `App\Plans\InForce`.** Sans socle validé, elle rend les lignes de travail.
   Avec un socle validé, les familles du plan et du socle viennent de la copie de la version en vigueur (moins les lignes
   dont l'équipe, le gymnase ou le coach a disparu), la structure garde ses lignes vivantes avec leurs champs froids pris
   dans la copie (une équipe ou un gymnase né depuis est lu en direct), le libre et le à-chaud se lisent en direct. Matchs,
   radar, plans de période et saison suivante lisent par elle (`InForceDoorTest`). Une version en vigueur sans copie fait
   lever la porte.
5. **Retravailler ne détruit rien.** « Retravailler » (`POST /api/schedules/{id}/rework`) copie la version en vigueur en
   version de travail, avec sa copie d'état, son entrée figée et son empreinte, et y pose l'étoile ; le pointeur ne bouge
   pas, la copie n'a aucun effet tant qu'elle n'est pas validée. « Rouvrir » un plan de saison répond 409 ; il reste le
   geste des plans de PÉRIODE. Générer, régénérer, charger et importer le parc sont permis avec un socle en vigueur.
6. **Seul le froid supprime.** Valider une version compare sa copie à celle en vigueur ; un champ froid qui diffère
   supprime les plans de période PAS COMMENCÉS (critère : la date de début), après une confirmation qui porte le jeton de la
   liste vue (409 `changes_to_confirm`). Une séance déplacée figure dans la liste à titre d'information
   (`destroys: false`), un verrou n'y figure pas, et ni l'un ni l'autre ne supprime rien. La collecte de doléances des
   vacances à venir reste ; une doléance reçue avant un changement de froid (`schedule_plan.cold_changed_at`) porte une mention.
7. **« Charger » n'a qu'une définition, pour tout plan** (`POST /api/schedules/{id}/load`) : il remet l'état de travail du
   plan d'après la copie de la version, sans créer ni supprimer équipe, gymnase, coach ou catégorie et sans toucher aux
   noms, contacts ni libellés. Il remet le froid des équipes et gymnases existants (rang, catégorie, niveau, « divisible »,
   gymnase imposé) et refuse, sans rien écrire, si le niveau d'une équipe engagée changerait.
8. **Exception E6 à « seule la version chargée se modifie » (ADR-0002).** Un changement fait hors du planning se valide
   sans régénérer : « Valider ces modifications » (`POST /api/schedule_plans/{id}/apply-pending-changes`,
   `PendingChanges::apply`) est le SEUL écrivain de la version en vigueur, et n'écrit que sa copie du froid, le coach de
   ses séances (pour les équipes dont le coach principal a changé), son entrée figée et son empreinte. Aucune séance ne
   change de place, de jour, d'heure, de gymnase ni de verrou ; aucune version ne naît ; les guichets de retouche restent
   refusés sur la version en vigueur. Un contrôle d'incohérences par le verdict du moteur précède la confirmation
   (`InForceCoherence` : demandes de 50 séances au plus, six au plus ; au-delà, ou sur un refus ou un délai du moteur,
   « non concluant ») ; il avertit, il ne bloque pas.
9. **La saison suivante reprend le socle en vigueur**, par la déclaration : une routine par famille déclarée reprise
   (`SeasonTransitionService::ROUTINES`, gardée par `FamilyDeclarationSweepTest`), source = la copie de la version en
   vigueur, ou l'état de travail si rien n'a été validé. Réservations (libres avec leur libellé), passerelles, règles de
   bien-être, cran de la règle de trajet, grille et composition suivent ; les groupes mutualisés et l'entente ne suivent pas.
10. **Les lignes de règle des périodes suivent le socle en vigueur**, propagées à la validation
    (`PlanComposition::propagateSocleChanges`), jamais à la saisie.

## Conséquences

- Ce que le gestionnaire voit changer : retravailler et valider un renommage ne détruit plus rien ; valider un changement de
  coach principal supprime les plans de vacances pas commencés, annoncés avant ; régénérer sans rien changer ne supprime rien.
- Une copie de version ne se répare pas après coup : une version née sans copie ne se charge pas, et la porte lève si elle est en vigueur. Les versions
  d'avant la migration, validées ou chargées, ont été capturées par `app:plans:capture-version-states` (`--check` bloque).
- Limites assumées : contraintes permanentes, passerelles, règle de trajet et coach principal restent rangés à la saison
  (déclarés froid du socle, à déplacer avec les règles) ; aucune clé étrangère vers équipe ni gymnase tant que le lot des gestes
  et écrans n'en pose pas ; le contrôle d'incohérences ne voit ni les préférences souples ni la qualité du planning ; l'entrée
  figée d'une version en vigueur, après « Valider ces modifications », décrit l'état adopté et non plus la demande de sa génération.

## Alternatives considérées

- **Rouvrir le pointeur et détruire, comme avant** : écarté, le coût de travailler sur le socle dépassait celui d'une erreur.
- **Obliger à régénérer pour faire valoir un changement de coach** : écarté, régénérer peut déplacer sans raison des séances
  non verrouillées.
- **Lire les lignes vivantes comme en vigueur** : écarté, rien ne doit valoir avant validation.
- **Garder la photo de structure** : écarté, deux définitions de « Charger » et dix familles listées à la main.
