# ADR-0006 — Personne, rôle et lieu au club : l'identité vit une fois, au club ; la saison ne porte que le rôle

- **Status**: accepted — Date: 2026-11-22 (lot conception, lot 3). La moitié « personne » est en place ; la moitié « lieu »
  (et ses temps de trajet) est décidée et pas encore livrée : elle suit le même patron.

## Contexte

Le nom, le mail, le téléphone et le genre d'un coach vivaient sur la ligne `coach`, qui est une ligne de
SAISON : « Préparer la saison suivante » les recopiait, et une correction de téléphone faite dans une saison
ne corrigeait pas les autres. Les lecteurs d'identité l'obtenaient par les accesseurs de `Coach`, l'un
d'eux en SQL brut sur `coach`. Effacer une personne (RGPD, art. 17) exigeait de retrouver toutes
ses copies. L'adresse et la position d'un gymnase, et le barème de trajet entre deux gymnases, ont le même défaut.

## Décision

1. **Une table `person`** (`App\Entity\Person`, `TenantOwnedInterface`) porte prénom, nom de famille (peut
   être vide), mail, téléphone et genre, une fois, au club. **`coach` reste la ligne de la saison** : c'est le
   RÔLE de la personne cette saison-là (plafond de jours, actif, salarié, véhiculé) et il la pointe par
   `coach.person_id`. Les identifiants de `coach` ne changent pas (le moteur ne voit aucune différence).
2. **La clé étrangère est COMPOSÉE** : `(person_id, club_id)` → `person (id, club_id)`, `ON DELETE RESTRICT`,
   adossée à `UNIQUE (id, club_id)`, posée à la main en migration (invisible de Doctrine). Une clé simple
   `person_id → person(id)` laisserait un coach du club A désigner la fiche du club B : une vérification de
   clé étrangère ignore la RLS. Au plus un rôle coach par personne et par saison (`UNIQUE (season_id, person_id)`).
3. **Une seule porte de lecture** : `App\Structure\PersonDirectory` (`of`, `forCoaches`, `emailsOfClub`)
   rend une `PersonIdentity` ; `Coach` n'a plus d'accesseur d'identité. Toute lecture est bornée par le club,
   SQL brut compris.
4. **Une fiche sans rôle reste au club jusqu'à la purge de sa saison** (décision fondateur 2026-11-21, option A) :
   supprimer un coach garde sa fiche, « Charger » une version d'avant la ramène dessus. `UnusedIdentitySweeper`
   la balaie après la remise à zéro et la purge de rétention ; l'effacement du club emporte toutes les fiches
   (`#[Family(Level::CLUB)]`). L'effacement d'UNE personne à sa demande se fait
   par la commande de support `app:people:erase` (`PersonEraser`, `docs/security/rgpd.md` § La fiche de personne).
5. **La saison suivante réutilise la fiche** : seul le rôle se recopie. Une correction d'identité vaut dans
   toutes les saisons, passées comprises (lecture seule directe inchangée).
6. **Même patron pour le lieu** (adresse, position d'un gymnase) et le temps de trajet entre deux lieux :
   table au club, ligne de saison conservée comme usage, clés composées avec le club.

## Conséquences

- La migration de conversion est **irréversible** (colonnes d'identité supprimées) et déroge à la règle
  « compatible une release en arrière » : elle part avec tout le lot conception, en une mise en production
  site fermé ; le retour arrière est la sauvegarde d'avant migration.
- La clé `RESTRICT` est la seule de ce genre : un rôle doit partir avant sa fiche, ce que l'ordre des purges
  respecte (`PurgeOrderRespectsForeignKeysTest`).
- Pas de fusion à la conversion : deux coachs homonymes donnent deux fiches ; reconnaître une même personne
  entre saisons n'est pas codé.
- Effacer UNE personne (cascade, remplacement de son nom dans les textes figés) est un chantier distinct
  (PR 3 du lot) ; avant lui, aucune commande n'efface une personne seule.

## Alternatives écartées

- **Fiche supprimée avec son dernier rôle** : « Charger » ramènerait les équipes du coach sans lui.
- **Clé étrangère simple** : franchit les clubs (voir 2).
- **Une table de rôle séparée de `coach`** : `coach` tient déjà lieu de table de rôle, et ses identifiants
  circulent dans le moteur, les photos de versions et les liens de collecte.

Plan d'exécution : `specs/evolution/lot-conception-3-personnes-et-lieux.md`.
