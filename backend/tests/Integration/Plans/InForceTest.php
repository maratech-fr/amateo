<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Person;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Plans\InForce;
use App\Plans\PlanStates;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestSocleNetDecorTrait;
use App\Tests\EngineRequestTravelDecorTrait;
use App\Tests\Integration\EngineRequestReferenceTest;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : la porte de l'en vigueur ne rend que les lignes de la saison demandée — chez un club qui prépare sa
 * saison suivante, le placement des matchs, le radar et la demande d'une période d'une saison ne lisent jamais les
 * équipes, gymnases, coachs, règles ou créneaux de l'autre.
 *
 * Le worker de placement ne pose que le club (`PlaceMatchesHandler`, jamais le `season_filter`) : avant la porte, il
 * lisait toutes les saisons du club. Les références de demande au moteur ({@see EngineRequestReferenceTest})
 * n'ont de club à deux saisons que pour le socle (cas 9), qui ne passe pas par la porte : une porte sans clause de
 * saison les laisse vertes. Ce cas tient la frontière pour tous les lecteurs nommés d'un coup.
 *
 * Décor : `EngineRequestDecorTrait` (saison en cours) et `EngineRequestTravelDecorTrait` (saison suivante, saisie à
 * la main), identifiants écrits en dur.
 */
#[Group('phase1')]
#[Group('integration')]
final class InForceTest extends KernelTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestSocleNetDecorTrait;
    use EngineRequestTravelDecorTrait;

    public function testTheDoorReadsOnlyTheRowsOfTheAskedSeason(): void
    {
        $this->seedEngineRequestTravelOfCurrentSeason();
        $this->seedEngineRequestNextSeason();
        $door = self::getContainer()->get(InForce::class);

        $witnesses = [
            Team::class => [self::ER_TEAM_U13, self::ER_NEXT_TEAM_U13],
            Venue::class => [self::ER_VENUE_NORD, self::ER_NEXT_VENUE_NORD],
            TeamCoach::class => [self::erId('7c0a', 1), self::erId('7c0a', 21)],
            Constraint::class => [self::erId('c057', 4), self::erId('c057', 21)],
            VenueTrainingSlot::class => [self::erId('a7a1', 7), self::erId('a7a1', 21)],
        ];
        foreach ($witnesses as $family => [$current, $next]) {
            foreach ([[self::ER_SEASON, $current, $next], [self::ER_NEXT_SEASON, $next, $current]] as [$season, $own, $other]) {
                $ids = array_map(static fn (object $row): string => $row->getId(), $door->rows($season, $family));
                $seasons = array_values(array_unique(array_map(static fn (object $row): string => $row->getSeasonId(), $door->rows($season, $family))));

                self::assertContains($own, $ids, \sprintf('témoin : %s de la saison %s est lue', $family, $season));
                self::assertNotContains($other, $ids, \sprintf('%s de l\'autre saison n\'est jamais rendue pour la saison %s', $family, $season));
                self::assertSame([$season], $seasons, \sprintf('%s : toutes les lignes rendues sont de la saison %s', $family, $season));
            }
        }
    }

    /**
     * Promesse (lot 5, PR 5, ligne 28 du plan) : après la validation, la porte rend la structure en vigueur — les
     * champs froids d'une équipe pris dans la copie (son rang, changé depuis, revient à celui de la validation), le
     * libre lu en direct (son nom, changé depuis, est le nouveau) — et une équipe créée après la validation, qui
     * porte un match, est lue en direct, en entier, son coach principal compris. Les objets rendus ne s'écrivent
     * jamais : un flush après la lecture laisse l'état de travail tel quel.
     *
     * Jumeau, cité : la référence 21 de {@see EngineRequestReferenceTest} (la demande de placement qui porte cette
     * équipe) ; ici, la règle de lecture elle-même.
     */
    public function testAfterValidationTheDoorReadsColdFieldsFromTheCopyAndATeamCreatedSinceLive(): void
    {
        $this->validateSocleNow();
        $em = $this->erEm();
        $em->getConnection()->executeStatement('UPDATE team SET priority_tier_id = 1, name = \'U13 Filles\' WHERE id = ?', [self::ER_TEAM_U13]);
        $this->seedEngineRequestTeamCreatedAfterValidation();
        $em->clear();

        $door = self::getContainer()->get(InForce::class);
        $teams = [];
        foreach ($door->rows(self::ER_SEASON, Team::class) as $team) {
            $teams[$team->getId()] = $team;
        }
        self::assertArrayHasKey(self::ER_TEAM_U13, $teams, 'témoin : U13, validée, est lue');
        self::assertSame(3, $teams[self::ER_TEAM_U13]->getPriorityTierId(), 'le rang d\'U13 est celui de la copie (3), pas celui changé depuis sans valider (1)');
        self::assertSame('U13 Filles', $teams[self::ER_TEAM_U13]->getName(), 'le nom, libre, est lu en direct');
        self::assertArrayHasKey(self::ER_TEAM_U17_AFTER_VALIDATION, $teams, 'U17 F, créée après la validation, est lue en direct');

        $links = array_map(static fn (TeamCoach $link): string => $link->getTeamId() . '→' . $link->getCoachId(), $door->rows(self::ER_SEASON, TeamCoach::class));
        $u17Links = array_values(array_filter($links, static fn (string $link): bool => str_starts_with($link, self::ER_TEAM_U17_AFTER_VALIDATION)));
        self::assertNotSame([], $u17Links, 'le coach principal d\'U17 F, créé avec elle après la validation, est lu');

        $em->flush();
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT priority_tier_id FROM team WHERE id = ?', [self::ER_TEAM_U13]), 'lire la porte n\'écrit rien : l\'état de travail garde le rang 1');
    }

    /**
     * Promesse (lot 5, PR 5, ligne 28 du plan) : une ligne de coach principal de la copie dont le coach a été supprimé
     * depuis la validation est ignorée, et une ligne changée depuis sans valider garde la valeur de la copie. Ici :
     * U13 et U15 étaient tenues par A, SM1 par B ; depuis, U13 est passée à un coach C, puis A a été supprimé. La porte
     * ne rend que SM1 → B : ni A (supprimé), ni C (pas validé).
     */
    public function testAMainCoachOfTheCopyDeletedSinceIsIgnored(): void
    {
        $this->validateSocleNow();
        $em = $this->erEm();
        $coachC = (new Person)->setClubId(self::ER_CLUB)->setFirstName('Cyd')->setLastName('Nouveau');
        $em->persist($coachC);
        $em->persist(new Coach($coachC)->setId(self::erId('c0ac', 3))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON));
        $em->flush();
        $connection = $em->getConnection();
        $connection->executeStatement('UPDATE team_coach SET coach_id = ? WHERE id = ?', [self::erId('c0ac', 3), self::erId('7c0a', 1)]);
        $connection->executeStatement('DELETE FROM coach_player_membership WHERE coach_id = ?', [self::ER_COACH_A]);
        $connection->executeStatement('DELETE FROM team_coach WHERE coach_id = ?', [self::ER_COACH_A]);
        $connection->executeStatement('DELETE FROM coach WHERE id = ?', [self::ER_COACH_A]);
        $em->clear();

        $links = array_map(
            static fn (TeamCoach $link): string => $link->getTeamId() . '→' . $link->getCoachId(),
            self::getContainer()->get(InForce::class)->rows(self::ER_SEASON, TeamCoach::class),
        );

        self::assertSame([self::ER_TEAM_SM1 . '→' . self::ER_COACH_B], $links, 'seul SM1 → B reste : A, supprimé, est ignoré ; C, posé sans valider, ne vaut pas');
    }

    /**
     * Promesse (lot 5, PR 5, limite 8 du plan) : une version en vigueur sans copie de son état fait lever la porte ; elle
     * ne retombe jamais en silence sur les lignes de travail, qui serviraient alors aux matchs et aux périodes des
     * modifications pas validées.
     */
    public function testAVersionInForceWithoutACopyMakesTheDoorRaise(): void
    {
        $this->validateSocleNow();
        $this->erEm()->getConnection()->executeStatement('DELETE FROM schedule_state WHERE schedule_id = ?', [self::ER_SOCLE_V1]);
        $this->erEm()->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/n\'a pas de copie de son état/');
        self::getContainer()->get(InForce::class)->rows(self::ER_SEASON, Team::class);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }

    /** La validation du socle : V1 terminée, pointée et chargée, avec la copie de l'état du jour. */
    private function validateSocleNow(): void
    {
        $version = $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($version, $states->capture($version->getSchedulePlanId()));
    }
}
