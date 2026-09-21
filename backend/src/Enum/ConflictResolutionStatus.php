<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le statut de TRAITEMENT qu'un gestionnaire pose sur un conflit du radar
 * (P4-207). Le conflit lui-même reste toujours rendu ; ce statut ne fait que
 * dire OÙ EN EST sa résolution.
 *
 * ⚠ « À traiter » n'a PAS de cas ici : c'est le défaut, et le défaut = AUCUNE
 * ligne en base (un conflit sans ligne `conflict_resolution` est « à traiter »).
 * Poser « À traiter » = SUPPRIMER la ligne (DELETE), jamais stocker une valeur.
 * Ces trois cas sont donc EXACTEMENT ceux qui se persistent.
 *
 * Patron {@see OpponentVenueLinkSource} (enum string court adossé à HasValues).
 */
enum ConflictResolutionStatus: string
{
    use HasValues;

    case DEROGATION_REQUESTED = 'DEROGATION_REQUESTED';

    case RESOLVED_INTERNALLY = 'RESOLVED_INTERNALLY';

    case NO_SOLUTION_YET = 'NO_SOLUTION_YET';

    // Deux statuts réservés aux conflits où la personne JOUE (un côté servi porte
    // le rôle PLAYER) : le gestionnaire tranche que la personne coache sans jouer,
    // ou joue sans coacher. Colonne `length: 30` — aucune migration.
    case COACHES_NOT_PLAYING = 'COACHES_NOT_PLAYING';

    case PLAYS_NOT_COACHING = 'PLAYS_NOT_COACHING';

    // Statuts propres à une FAMILLE de conflit — un « on a une action, pas
    // forcément directe » que le gestionnaire pose sans régler le conflit sur-le-champ
    // (lot N). Calendrier incomplet → importer les matchs manquants ; collision de
    // gymnase → erreur FBI (alimente le registre « à corriger dans FBI ») ou match à
    // déplacer. Toujours `length: 30` (IMPORT_MISSING_MATCHES = 22) — aucune migration.
    case IMPORT_MISSING_MATCHES = 'IMPORT_MISSING_MATCHES';

    case FBI_ERROR = 'FBI_ERROR';

    case MATCH_TO_MOVE = 'MATCH_TO_MOVE';

    /** Les trois statuts de BASE, proposés sur TOUTE famille. */
    private const array BASE = [self::DEROGATION_REQUESTED, self::RESOLVED_INTERNALLY, self::NO_SOLUTION_YET];

    /**
     * Les statuts EN PLUS de la base, par famille de conflit ({@see App\Enum} côté
     * `ConflictType` du contrat). Les deux statuts de personne restent en outre
     * conditionnés à un côté PLAYER (garde du contrôleur, message dédié) ; ici la
     * table dit seulement quels statuts sont CONCEVABLES pour la famille.
     *
     * @var array<string, list<self>>
     */
    private const array FAMILY_EXTRA = [
        'MATCH_MATCH' => [self::COACHES_NOT_PLAYING, self::PLAYS_NOT_COACHING],
        'MATCH_TRAINING' => [self::COACHES_NOT_PLAYING, self::PLAYS_NOT_COACHING],
        'COMPETITION_INCOMPLETE' => [self::IMPORT_MISSING_MATCHES],
        'VENUE_OVERLAP' => [self::FBI_ERROR, self::MATCH_TO_MOVE],
    ];

    /**
     * Les statuts de traitement qu'un gestionnaire peut poser sur un conflit de cette
     * FAMILLE : les trois de base, plus les statuts propres à la famille. Le serveur
     * refuse tout statut hors de cette table (le front masque le geste voué au refus,
     * mais le serveur reste souverain).
     *
     * @return list<self>
     */
    public static function casesForFamily(string $conflictType): array
    {
        return [...self::BASE, ...(self::FAMILY_EXTRA[$conflictType] ?? [])];
    }
}
