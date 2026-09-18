# Console super-admin (monitoring, exploitation & data ops) — l'ouvert

> **SA0 → SA4 v1 (+ SA2-stats, monitoring, alerting, data-freshness, journaux) sont LIVRÉS** — vérité
> courante dans [`../courantes/superadmin-auth.md`](../courantes/superadmin-auth.md). Ce fichier ne
> tient plus que **l'ouvert** de la console : SA4 v2, SA5, et le reliquat non cadré. Rattachement
> roadmap : **P4-54**.

## Reste à implémenter

- **SA4 v2 — actions support restantes** *(différées au premier cas réel, décision fondateur
  2026-07-18)* : **suspendre/désactiver un club** (aucun impayé/abus à ce jour ; l'effet exact —
  bloque le login ? la génération ? — reste à trancher) et **approuver un gestionnaire en
  fallback** (le circuit normal `MembershipController` suffit tant qu'aucun club n'a de
  gestionnaire injoignable).
- **SA5 — impersonation support** *(le plus sensible, en dernier)* : se mettre à la place d'un
  club — lecture d'abord, bornée dans le temps, bannière visible, tout audité. Écriture éventuelle
  = décision ultérieure séparée.
- **Data ops FFBB — mode batch** : le refresh FFBB à la demande sur un club existe (route lot C),
  mais le rattrapage en lot des ligues/comités périmés reste à cadrer si on le garde dans ce lot.
- **Granularité/rétention de l'audit viewer** : `admin_audit_log` capture déjà acteur, route,
  méthode, statut, date — durée de conservation et filtres UI restent à décider.

## Fonctionnalités intéressantes, non cadrées (au-delà de l'évident)

- **Clubs « chauds » pour la vente** *(business)* : quota Découverte épuisé **+** activité récente
  → liste de prospects (conversion Découverte→payant).
- **Rétention par cohorte** : % de clubs encore actifs N semaines après inscription.
- **Kill switch génération** (mode maintenance) : suspendre globalement les générations pendant un
  incident.
- **Coûts d'infra projetés à N clubs** : extrapolation charge solveur / ressources.
- **Audit viewer dédié** : qui a fait quoi — en particulier les actions **superadmin** elles-mêmes
  (au-delà de la capture brute déjà livrée en SA0).

## Ce que ce fichier engage / n'engage pas

**Engage** : l'ordre SA4 v2 → SA5, et le périmètre du reliquat ci-dessus. **N'engage pas** :
l'architecture détaillée de SA4 v2/SA5, tranchée au `/plan` de chaque lot.
