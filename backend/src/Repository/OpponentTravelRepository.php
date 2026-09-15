<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpponentTravel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpponentTravel>
 */
final class OpponentTravelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpponentTravel::class);
    }

    /**
     * The club+season travel rows (tenant + season Doctrine filters already scope
     * the query to the current club).
     *
     * @return list<OpponentTravel>
     */
    public function findBySeason(string $seasonId): array
    {
        return $this->findBy(['seasonId' => $seasonId]);
    }

    /**
     * The row at the given grain: the CLUB default when `$opponentTeamKey` is null,
     * else the override of that single opponent TEAM. `findOneBy` turns a null value
     * into `opponent_team_key IS NULL`, so the club row and a team row never collide.
     */
    public function findOneByCode(string $seasonId, string $opponentOrganismeCode, ?string $opponentTeamKey = null): ?OpponentTravel
    {
        return $this->findOneBy([
            'seasonId' => $seasonId,
            'opponentOrganismeCode' => $opponentOrganismeCode,
            'opponentTeamKey' => $opponentTeamKey,
        ]);
    }

    /**
     * The row that GOVERNS a rencontre of `(code, teamKey)`: the team override when
     * one exists, else the club default (team → club). Null when neither exists.
     */
    public function findEffective(string $seasonId, string $opponentOrganismeCode, ?string $opponentTeamKey): ?OpponentTravel
    {
        if (null !== $opponentTeamKey) {
            $team = $this->findOneByCode($seasonId, $opponentOrganismeCode, $opponentTeamKey);
            if ($team instanceof OpponentTravel) {
                return $team;
            }
        }

        return $this->findOneByCode($seasonId, $opponentOrganismeCode, null);
    }

    /**
     * ONE-WAY car travel minutes for the club+season, resolvable at the TEAM grain
     * with a CLUB fallback — the structure the radar reads to give an AWAY fixture
     * its round trip (2 ×). Per opponent organisme code: the club default (nullable)
     * and the per-team overrides. A row without minutes is omitted (best-effort: no
     * minutes, no spatial conflict); the radar reads `teams[teamKey] ?? club`.
     *
     * @return array<string, array{club: int|null, teams: array<string, int>}>
     */
    public function travelMinutesBySeason(string $seasonId): array
    {
        $map = [];
        foreach ($this->findBySeason($seasonId) as $row) {
            $code = $row->getOpponentOrganismeCode();
            $map[$code] ??= ['club' => null, 'teams' => []];
            $minutes = $row->getTravelMinutes();
            if (null === $minutes) {
                continue;
            }
            $teamKey = $row->getOpponentTeamKey();
            if (null === $teamKey) {
                $map[$code]['club'] = $minutes;
            } else {
                $map[$code]['teams'][$teamKey] = $minutes;
            }
        }

        return $map;
    }
}
