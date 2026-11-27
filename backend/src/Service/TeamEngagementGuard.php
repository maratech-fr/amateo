<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Une équipe ENGAGÉE EN COMPÉTITION est intouchable sur deux points : elle ne peut
 * être ni supprimée, ni changer de niveau.
 *
 * Réalité du terrain : valider le planning de la saison valide aussi un PÉRIMÈTRE —
 * les équipes qui jouent. Une fois les matchs envoyés à la fédération, on ne revient
 * plus dessus : « une équipe qui joue ne peut pas être supprimée ni changer de
 * niveau ; elle peut être déplacée ou changer de créneau ». Le planning de la saison
 * s'ajuste (rarement) ; le périmètre engagé, non.
 *
 * Engagée = elle porte AU MOINS UN match d'une compétition NON amicale, quel que soit son statut
 * (lot 7, PR 4a, Q3) : championnat, coupe, brassage. Un amical, posé à la main ou venu de la
 * fédération, n'engage pas. Un match dont la compétition est introuvable engage : le doute
 * protège (pas de jointure interne). Décision fondateur d'origine :
 * « si import FBI pour les matchs, l'équipe est engagée d'office. Une correspondance pour
 * les matchs implique que l'équipe est engagée pour la fédération. Même si le statut est
 * UNPLACED, même si le match n'est pas placé. À partir du moment où l'on valide la
 * correspondance entre l'import et nos équipes, c'est que l'équipe est engagée. »
 *
 * Ne PAS filtrer sur le statut : l'import FBI crée TOUT en `UNPLACED`
 * (`FbiFixtureImporter` : « Status is always UNPLACED — placing requires a CLUB venue +
 * an explicit manager action »). Une garde qui exige `PLACED` serait donc inerte au
 * moment précis où elle doit mordre — juste après l'import, quand la fédération connaît
 * déjà les rencontres.
 *
 * Ce qui reste LIBRE, et doit le rester : le `priorityTierId` / `tierOrder` (la
 * perception que le club a de son équipe — ça bouge) et `isActive` (qui sert aux
 * plannings de période, pas au périmètre de la saison).
 *
 * `isEngaged()` et `engagedTeamIds()` répondent à la MÊME question — l'une pour la
 * garde d'écriture, l'autre pour le contrat de lecture qui l'affiche. Deux façons de
 * la poser feraient deux vérités, et deux vérités finissent toujours par diverger.
 *
 * SQL brut, comme SchedulePlanProvisioner : filter-free (l'équipe visée n'est pas
 * forcément dans la saison active) et une seule requête. RLS scope par club.
 */
final class TeamEngagementGuard
{
    /** « Le match n'est pas d'une compétition amicale » — alias `f` du match. */
    private const string NOT_FRIENDLY = 'NOT EXISTS (SELECT 1 FROM competition c WHERE c.id = f.competition_id AND c.competition_type = \'FRIENDLY\')';

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function isEngaged(string $teamId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM fixture f WHERE f.team_id = :tid AND ' . self::NOT_FRIENDLY . ' LIMIT 1',
            ['tid' => $teamId],
        );
    }

    /** @throws ConflictHttpException si l'équipe joue déjà en compétition */
    public function assertNotEngaged(string $teamId, string $message): void
    {
        if ($this->isEngaged($teamId)) {
            throw new ConflictHttpException($message);
        }
    }

    /**
     * Les équipes engagées parmi `$teamIds`, en UNE requête — un EXISTS par ligne
     * N+1-erait la collection des équipes.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, true>
     */
    public function engagedTeamIds(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        return $this->teamIdsOf('SELECT DISTINCT f.team_id FROM fixture f WHERE f.team_id IN (:tids) AND ' . self::NOT_FRIENDLY, $teamIds);
    }

    /**
     * Les équipes parmi `$teamIds` qui portent un match, amical compris.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, true>
     */
    public function teamIdsHoldingAFixture(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        return $this->teamIdsOf('SELECT DISTINCT team_id FROM fixture WHERE team_id IN (:tids)', $teamIds);
    }

    /**
     * @param list<string> $teamIds
     *
     * @return array<string, true>
     */
    private function teamIdsOf(string $sql, array $teamIds): array
    {
        /** @var list<string> $rows */
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            $sql,
            ['tids' => $teamIds],
            ['tids' => ArrayParameterType::STRING],
        );

        $set = [];
        foreach ($rows as $teamId) {
            $set[$teamId] = true;
        }

        return $set;
    }
}
