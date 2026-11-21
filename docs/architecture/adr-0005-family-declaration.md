# ADR-0005 — Déclaration unique des familles : l'entité dit sa famille, les purges la lisent

- **Status**: accepted — Date: 2026-11-21 (lot conception, lot 1)

## Contexte

Deux purgeurs effacent les données d'un club : `SeasonDataPurger` (remise à zéro, rétention) et
`ErasedClubPurger` (effacement RGPD). Chacun tenait sa propre liste d'entités, écrite à la main et
ordonnée à la main. Une entité neuve oubliée dans une liste survivait en silence à une purge
promise légale, et un ordre faux (un parent avant son enfant) n'échouait jamais : en base aucune
clé étrangère n'est `RESTRICT` ni `NO ACTION`, tout est `CASCADE` ou `SET NULL`, donc la cascade
emportait l'enfant hors de la purge et de son compte rendu.

## Décision

1. **Chaque entité Doctrine déclare sa famille** : `#[Family(Level, keptAtSeasonPurge?, keptAtErasure?)]`
   (`App\Structure\Family`). Une épargne par une purge est une décision, la raison l'accompagne.
2. **Chaque colonne `guid` qui désigne un autre objet déclare ce qu'elle désigne** :
   `#[RefersTo(famille, OnDelete, raison?, owner?)]`. `OnDelete` dit ce que devient la ligne quand
   la cible meurt (`DELETE`, `CLEAR`, `CUSTOM`, `KEEP`) ; `owner: true` marque la famille purgée
   par la saison de son propriétaire (pas de `club_id` + `season_id` qui la borne seule).
3. **`App\Structure\FamilyCatalog` est la maison unique** de ces déclarations : il rend
   `purgedViaOwner()`, `purgedWithSeason()` (chaque enfant avant le parent dont il meurt, ordre
   topologique sur les références `DELETE`), `purgedWithClub()` et `referencesTo()`.
4. **Les purgeurs lisent le catalogue et ne tiennent plus de liste.** Une liste réintroduite dans
   un purgeur est une dérive, gardée par `PurgeCompletenessTest`.
5. **Le catalogue refuse plutôt qu'omet** (`LogicException`) : une classe sans `#[Family]`, un cycle
   entre références `DELETE`, deux propriétaires pour une famille, et une famille de niveau club,
   saison, plan ou version qui n'entre dans aucune purge, ne déclare aucune épargne et n'est pas un
   cas nommé.
6. **Les cas nommés** sont les seuls hors déclaration :
   - `Season` (`SEASON_PIVOT`) : de niveau club, mais sa ligne est supprimée par la purge de saison
     elle-même, sous condition, jamais par la boucle par club ;
   - `Club` et `TransitionReminderLog` (`OUTSIDE_THE_PURGE_LOOPS`, raison écrite) ;
   - `SeasonDataPurger::HANDLED_APART` : les tables que la purge de saison traite à part
     (bornées par la saison seule, ou la ligne pivot), avec leur raison.

## Conséquences

- Une entité tenant neuve se déclare ou fait échouer la purge, jamais l'inverse : le bon défaut
  pour une donnée personnelle promise détruite.
- Le `rowsDeleted` des comptes rendus d'audit (`CLUB_PURGED`, `SEASON_RESET`) peut AUGMENTER :
  créneaux et occupations d'un plan de période sont désormais effacés avant leur plan, donc comptés,
  là où la cascade les emportait hors du compte.
- L'ordre exécuté est vérifié contre les vraies clés (`PurgeOrderRespectsForeignKeysTest`, lit
  `pg_constraint`) : l'absence d'erreur ne prouve rien ici, seul l'enfant-avant-parent compte.
- Limites assumées :
  - `TransitionReminderLog` (par saison, sans `club_id`) n'est effacée par aucune purge ; le
    comportement d'avant est conservé et nommé dans `OUTSIDE_THE_PURGE_LOOPS`.
  - `HANDLED_APART` reste une constante du purgeur de saison : le catalogue la lit pour ne pas lever.
  - Une liste réintroduite sous un autre nom dans un purgeur n'est pas détectée par construction ;
    seule la revue la voit.
  - La justesse d'une déclaration `OnDelete::DELETE` n'est gardée que par l'ordre réel contre les
    vraies clés ; une déclaration fausse mais sans effet d'ordre passe.

## Alternatives considérées

- **Garder les listes et ajouter un test d'égalité avec le catalogue** : double maison, divergence
  silencieuse entre deux sources ; écarté, le test d'égalité n'a servi que le temps de la bascule.
- **Dériver l'ordre des clés étrangères de la base à l'exécution** : fait dépendre une purge légale
  de l'état d'un schéma ; écarté au profit d'une déclaration en code, vérifiée contre le schéma par
  un test.
