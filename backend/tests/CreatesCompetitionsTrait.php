<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Competition;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Matches\CompetitionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Le décor des tests crée une compétition comme l'application le fait depuis la PR 3b du lot 7 : une compétition
 * de la saison de l'équipe, puis l'engagement de l'équipe, par la seule porte ({@see CompetitionRegistry::create},
 * puis {@see CompetitionRegistry::engage}), dans l'ordre des `persist` (compétition, engagement), flushés ici.
 *
 * Club et saison : ceux de l'équipe si elle existe ; sinon (décor à identifiant d'équipe forgé, sans ligne
 * `team`), le club posé par l'appelant (`app.club_id`, `scopeGucToClub`) et sa seule saison. Noyau démarré.
 */
trait CreatesCompetitionsTrait
{
    private function competitionFor(string $teamId, string $name, CompetitionType $type = CompetitionType::CHAMPIONSHIP): Competition
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$clubId, $seasonId] = $this->clubAndSeasonOfTeam($em, $teamId);

        $registry = static::getContainer()->get(CompetitionRegistry::class);
        $competition = $registry->create($clubId, $seasonId, $name, $type);
        $registry->engage($competition, $teamId);
        $em->flush();

        return $competition;
    }

    /** @return array{0: string, 1: string} */
    private function clubAndSeasonOfTeam(EntityManagerInterface $em, string $teamId): array
    {
        $team = $em->find(Team::class, $teamId);
        if ($team instanceof Team) {
            return [$team->getClubId(), $team->getSeasonId()];
        }
        $clubId = $em->getConnection()->fetchOne('SELECT NULLIF(current_setting(\'app.club_id\', true), \'\')');
        Assert::assertIsString($clubId, \sprintf('competitionFor : l\'équipe %s n\'existe pas et aucun club n\'est posé', $teamId));
        $seasons = $em->getRepository(Season::class)->findBy(['clubId' => $clubId]);
        Assert::assertCount(1, $seasons, 'competitionFor : sans équipe en base, le club posé doit avoir une seule saison');

        return [$clubId, $seasons[0]->getId()];
    }
}
