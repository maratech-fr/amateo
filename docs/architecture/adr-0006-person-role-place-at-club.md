# ADR-0006 — Personne, rôle et lieu au club : l'identité vit une fois, au club ; la saison ne porte que le rôle

- **Status**: accepted — Date: 2026-11-22 (lot conception, lot 3). Les moitiés « personne » et « lieu » sont en place ;
  les temps de trajet entre deux lieux, sur le même patron, aussi.

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
6. **Même patron pour le lieu** : `App\Entity\Place` (table `place`, `TenantOwnedInterface`) porte l'adresse et
   la position (latitude, longitude) d'un gymnase, une fois, au club ; `venue` reste la ligne de la saison, l'usage
   du lieu cette saison-là, et le pointe par `venue.place_id`. Clé étrangère COMPOSÉE `fk_venue_place_club`
   `(place_id, club_id)` → `place (id, club_id)`, `ON DELETE RESTRICT`, posée à la main ; `UNIQUE (season_id,
   place_id)` : au plus un gymnase par lieu et par saison. Un lieu est PARTAGÉ entre les saisons : la saison
   suivante réutilise le lieu (`SeasonTransitionService`), elle ne recopie ni adresse ni position. Une seule porte
   de lecture, `App\Structure\PlaceDirectory` (`of`, `forVenues`), bornée par le club ; `Venue` n'a plus
   d'accesseur d'adresse ni de position. Tout gymnase créé reçoit un lieu neuf. « Dissocier » de la salle fédérale
   passe le gymnase de CETTE saison sur un lieu neuf sans adresse (décision 2, A) : le lieu qu'il quitte, et les
   gymnases des autres saisons qui le pointent, ne bougent pas. Un lieu sans gymnase reste jusqu'à la purge de
   sa saison, puis `UnusedIdentitySweeper` le balaie ; l'effacement du club emporte tous les lieux
   (`#[Family(Level::CLUB)]`). « Charger » remet un gymnase supprimé sur son lieu, adresse comprise.
7. **Le temps de trajet vit au club, entre deux lieux** (`place_travel_time`, `Entity/PlaceTravelTime.php`, même
   patron : table au club, deux clés composées avec le club). Couple non ordonné, rangé « plus petit identifiant de
   lieu d'abord » en base ; servi (moteur, API) pour la saison demandée, entre les lieux qui ont un gymnase cette
   saison, nommé et rangé par les gymnases de cette saison (`PlaceTravelTimeRepository::forSeason`). Un temps partagé
   entre saisons n'est jamais recopié ; l'écrire est refusé 409 si la saison de la requête est archivée.
   « Dissocier » un gymnase reporte sur son lieu neuf des copies des couples de sa saison (vides s'il reste sans
   position, avec leurs valeurs si une position arrive dans le même PUT) ; le lieu quitté garde les siens.

## Conséquences

- La migration de conversion est **irréversible** (colonnes d'identité supprimées) et déroge à la règle
  « compatible une release en arrière » : elle part avec tout le lot conception, en une mise en production
  site fermé ; le retour arrière est la sauvegarde d'avant migration.
- La clé `RESTRICT` est la seule de ce genre : un rôle doit partir avant sa fiche, ce que l'ordre des purges
  respecte (`PurgeOrderRespectsForeignKeysTest`).
- Pas de fusion à la conversion : deux coachs homonymes donnent deux fiches ; reconnaître une même personne
  entre saisons n'est pas codé.
- **Le lieu est partagé, donc sa correction vaut pour toutes les saisons** (décision 7A, aucune garde « saison
  close » sur le lieu) : corriger l'adresse d'un gymnase dans la saison en cours corrige aussi le lieu qu'une saison
  passée archivée désigne ; l'archive n'est pas figée sur les adresses d'alors. Le recalcul des trajets qui suit un
  déplacement vaut pour chaque saison où le lieu a un gymnase et qui a déjà une matrice.
- **Un lieu balayé perd son adresse pour de bon** : « Charger » ne crée aucun gymnase (`PlanStates::restore`), donc
  ne recrée jamais un lieu balayé ; l'adresse est à ressaisir.
- Effacer UNE personne (cascade, remplacement de son nom dans les textes figés) est le rôle de la commande
  `app:people:erase` (PR 3 du lot).

## Alternatives écartées

- **Fiche supprimée avec son dernier rôle** : « Charger » ramènerait les équipes du coach sans lui.
- **Clé étrangère simple** : franchit les clubs (voir 2).
- **Une table de rôle séparée de `coach`** : `coach` tient déjà lieu de table de rôle, et ses identifiants
  circulent dans le moteur, les photos de versions et les liens de collecte.

Plan d'exécution : `specs/evolution/lot-conception-3-personnes-et-lieux.md`.
