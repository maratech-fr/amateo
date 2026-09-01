# L'unité de placement est le bloc — règle complète (P2-60)

> Détail de la ligne roadmap **P2-60**. Règle **validée par le fondateur le 2026-09-01**
> (exercice solveur reprise-17, programme [`plannings-bccl-2026-08-31.md`](plannings-bccl-2026-08-31.md)) —
> prête à planifier, implémentation sur GO.

## 1. Origine (preuve mesurée)

Pendant l'exercice, le geste « réserver SF1 seule » (équipe fanion, SF1+SF2 mutualisées, toutes
les séances de SF1 dans le bloc) a rendu la génération **INFEASIBLE** : l'allègement de capacité
« un bloc = un occupant » ne compte que les membres LIBRES d'une case — SF1 verrouillée consomme
la place, SF2 (que le bloc force à la rejoindre) ne rentre plus. Le même payload avec le bloc
entier réservé : `completed`. Verbatim fondateur : « on devrait voir uniquement SF1 + SF2 en bloc
et pas comme une équipe individuelle […] on sait qu'aucun [créneau indépendant] ne va être placé
car le bloc réquisitionne tout ».

**Le correctif moteur est délibérément écarté** : réserver individuellement une équipe sans
créneau indépendant est un geste sans objet — à interdire, pas à interpréter (si la fanion
réserve deux cases pour une seule séance commune, laquelle le bloc rejoint-il ? indéfini).

## 2. Définitions

Par plan P et équipe T :
- **S(T)** = séances effectives de T sur P (override de période compris) ;
- **B(T)** = Σ des séances communes des blocs de P contenant T ;
- **résidu solo R(T) = S(T) − B(T)** — jamais négatif (garde centrale Σ de P2-51).

**Invariant unique : sur un plan, pour chaque équipe, réservations individuelles ≤ R(T).**
Tout le reste en découle.

## 3. Frontend — sélecteur de Réservation

- R(T) = 0 et T membre d'un bloc → T **n'apparaît plus individuellement** ; seuls ses blocs sont
  proposés (« SF1 + SF2 »).
- R(T) > 0 → T apparaît, **étiquetée de son résidu** (« SM3 — 3 créneaux libres ») pour prévenir
  le sur-réservage à la source.
- Le bloc reste l'unité réservable (geste groupé livré en P2-51 PR-5).

## 4. Backend — la garantie, aux DEUX portes

- **Poser une réservation individuelle** : 422 si R(T) = 0 (« SF1 s'entraîne uniquement en groupe
  SF1 + SF2 : réservez le groupe ») ; 422 si les réservations individuelles existantes de T
  atteignent déjà R(T) (le message donne le compte).
- **Déclarer/modifier un bloc** : 422 si le nouveau B(T) ferait passer des réservations
  individuelles EXISTANTES au-dessus du résidu (le message les nomme) — sinon l'infaisabilité
  entre par l'autre porte.
- Une réservation posée **via le bloc** n'est jamais comptée comme individuelle — même
  discernement ensemble-complet que le prune step existant
  (`backend/src/Deletion/SharedBlockReservationPruneStep.php`), **une seule maison** pour ce
  discernement.

## 5. Ce qui ne change pas

- Les **contraintes** individuelles (heures, jours, coach) restent telles quelles — elles
  s'appliquent aussi à la case du bloc (le membre s'y entraîne).
- Les réservations existantes ne sont jamais supprimées d'office : la garde agit à la
  création/modification, pas rétroactivement.
- Supprimer un bloc **libère** du résidu — jamais bloquant dans ce sens.
