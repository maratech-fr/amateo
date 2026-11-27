<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Competition;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CompetitionSource;
use App\Enum\CompetitionType;
use App\Matches\CompetitionRegistry;
use App\Matches\FriendlyCompetitions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Le décor des tests crée une compétition comme l'application le fait depuis la PR 3b du lot 7 : une compétition
 * de la saison de l'équipe, puis l'engagement de l'équipe, par la seule porte ({@see CompetitionRegistry::create},
 * puis {@see CompetitionRegistry::engage}), dans l'ordre des `persist` (compétition, engagement), flushés ici.
 *
 * Club et saison : ceux de l'équipe si elle existe ; sinon (décor à identifiant d'équipe forgé, sans ligne
 * `team`), le club posé par l'appelant (`app.club_id`, `scopeGucToClub`) et sa seule saison. Noyau démarré.
 *
 * ⚠ Gestionnaire d'entités : la compétition rendue est gérée par celui du conteneur COURANT, qui n'est plus le
 * `$this->em` du test dès que le client a redémarré le noyau. Ce qui la façonne (échéance, références fédérales,
 * poule) passe donc par `$shape`, appliqué avant le flush d'ici : un `$this->em->flush()` après coup n'écrirait
 * rien, en silence.
 */
trait CreatesCompetitionsTrait
{
    /**
     * @param (callable(Competition, CompetitionRegistry): void)|null $shape façonne la compétition (et l'engagement, par la porte) avant le flush
     */
    private function competitionFor(string $teamId, string $name, CompetitionType $type = CompetitionType::CHAMPIONSHIP, ?callable $shape = null): Competition
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$clubId, $seasonId] = $this->clubAndSeasonOfTeam($em, $teamId);

        $registry = static::getContainer()->get(CompetitionRegistry::class);
        $competition = $registry->create($clubId, $seasonId, $name, $type);
        $registry->engage($competition, $teamId);
        if (null !== $shape) {
            $shape($competition, $registry);
        }
        $em->flush();

        return $competition;
    }

    /**
     * Une compétition de source FBI, comme l'import par fichier la crée (lot 7, PR 6b) : ses matchs sont suivis par la
     * fédération. La source est posée en SQL après {@see competitionFor}, sous le club posé par l'appelant
     * (`scopeGucToClub`) : le décor ne suppose rien de la signature de `CompetitionRegistry::create`.
     */
    private function fbiCompetitionFor(string $teamId, string $name, CompetitionType $type = CompetitionType::CHAMPIONSHIP): Competition
    {
        $competition = $this->competitionFor($teamId, $name, $type);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $updated = $em->getConnection()->executeStatement('UPDATE competition SET source = \'FBI\' WHERE id = ?', [$competition->getId()]);
        Assert::assertSame(1, $updated, \sprintf('fbiCompetitionFor : la compétition %s n\'est pas visible sous le club posé', $competition->getId()));
        // L'identité en mémoire relit la source écrite : un flush ultérieur ne la remet pas à sa valeur d'avant.
        $em->refresh($competition);

        return $competition;
    }

    /**
     * Une compétition amicale de la fédération (« AMICAL PNM », source FBI) : ses matchs sont suivis, « à saisir » puis
     * validés seuls une fois passés — le sens qu'avait un amical de « Amicaux » avant la PR 6b du lot 7.
     */
    private function fbiFriendlyCompetitionIdFor(string $teamId): string
    {
        return $this->competitionFor($teamId, 'AMICAL PNM ' . uniqid('', true), CompetitionType::FRIENDLY, static fn (Competition $c): Competition => $c->setSource(CompetitionSource::FBI))->getId();
    }

    /**
     * « Amicaux », la compétition d'office de la saison de l'équipe, l'équipe y engagée ({@see FriendlyCompetitions::engageInDefault}) :
     * la compétition d'un match de décor qui n'en portait pas avant la PR 4a du lot 7, l'ancien sens d'un
     * `competitionId` nul (un amical). Écriture immédiate, aucun flush requis. Club et saison : ceux passés, sinon
     * ceux de l'équipe (règle de {@see competitionFor}).
     */
    private function friendlyCompetitionIdFor(string $teamId, ?string $clubId = null, ?string $seasonId = null): string
    {
        if (null === $clubId || null === $seasonId) {
            [$clubId, $seasonId] = $this->clubAndSeasonOfTeam(static::getContainer()->get(EntityManagerInterface::class), $teamId);
        }

        // Ni `find` ni filtre Doctrine : un décor pose parfois les lignes d'un AUTRE club que celui du filtre (RLS
        // seule, `scopeGucToClub`) ; la compétition d'office n'y serait pas lisible.
        $friendlies = static::getContainer()->get(FriendlyCompetitions::class);
        $id = $friendlies->ensureDefault($clubId, $seasonId);
        $friendlies->engageIn(new Competition()->setId($id)->setClubId($clubId)->setSeasonId($seasonId)->setCompetitionType(CompetitionType::FRIENDLY), $teamId);

        return $id;
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
