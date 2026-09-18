<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Service\ConflictRadarLoader;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\OpponentTravelProjection;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P2-54 RMM-9 PR-3 / D3 — la MAISON UNIQUE du trajet aller-retour par rencontre AWAY,
 * partagée par le radar de conflits ET le payload de placement. Testée sur le VRAI
 * repository (tenant-filtré) : le trajet lu en base doit se projeter en 2 × aller simple.
 */
#[Group('phase1')]
#[Group('integration')]
final class OpponentTravelProjectionTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private OpponentTravelProjection $projection;

    private Club $club;

    private Season $season;

    public function testAwayFixtureGetsTwiceTheClubOneWayTravel(): void
    {
        $this->travel('ORG1', null, 40);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2');

        self::assertSame([$away->getId() => 80], $this->projection->roundTripByFixtureId($this->season->getId(), [$away]));
    }

    public function testTeamOverrideWinsOverTheClubDefault(): void
    {
        $this->travel('ORG1', null, 40);
        // « ASVEL - 2 » normalise en « asvel 2 » → l'override équipe (55) prime le défaut club.
        $this->travel('ORG1', 'asvel 2', 55);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2');

        self::assertSame([$away->getId() => 110], $this->projection->roundTripByFixtureId($this->season->getId(), [$away]));
    }

    public function testUnknownOpponentNullCodeAndHomeCarryNothing(): void
    {
        $this->travel('ORG1', null, 40);
        $unknown = $this->fixture(FixtureHomeAway::AWAY, 'ORG9', 'Autre');
        $noCode = $this->fixture(FixtureHomeAway::AWAY, null, 'Sans code');
        $home = $this->fixture(FixtureHomeAway::HOME, 'ORG1', 'ASVEL - 2');

        self::assertSame([], $this->projection->roundTripByFixtureId($this->season->getId(), [$unknown, $noCode, $home]));
    }

    public function testNullSeasonProjectsNothing(): void
    {
        $this->travel('ORG1', null, 40);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2');

        self::assertSame([], $this->projection->roundTripByFixtureId(null, [$away]));
    }

    public function testRadarAndPayloadShareTheSameProjection(): void
    {
        // Parité de SOURCE : les deux consommateurs INJECTENT la projection partagée,
        // jamais une copie inline de la boucle de trajet — c'est ce qui garantit qu'ils
        // étendent l'empreinte AWAY à l'identique (radar servi ⇄ payload solveur).
        foreach ([ConflictRadarLoader::class, MatchPlacementPayloadBuilder::class] as $consumer) {
            $types = [];
            foreach (new ReflectionClass($consumer)->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    $types[] = $type->getName();
                }
            }
            self::assertContains(OpponentTravelProjection::class, $types, $consumer . ' doit consommer OpponentTravelProjection (projection partagée)');
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->projection = self::getContainer()->get(OpponentTravelProjection::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName('BC Trajet ' . $uid);
        $this->club->setSlug('bc-trajet-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season->setName((string) $year);
        $this->season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $this->season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    private function travel(string $organismeCode, ?string $teamKey, ?int $minutes): void
    {
        $travel = new OpponentTravel;
        $travel->setClubId($this->club->getId());
        $travel->setSeasonId($this->season->getId());
        $travel->setOpponentOrganismeCode($organismeCode);
        $travel->setOpponentTeamKey($teamKey);
        $travel->setTravelMinutes($minutes);
        $travel->setSource(OpponentTravelSource::MANUAL);
        $this->em->persist($travel);
        $this->em->flush();
    }

    private function fixture(FixtureHomeAway $homeAway, ?string $organismeCode, string $opponentLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-24'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponentLabel);
        if (null !== $organismeCode) {
            $fixture->setOpponentOrganismeCode($organismeCode);
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
