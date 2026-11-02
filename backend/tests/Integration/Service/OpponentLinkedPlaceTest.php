<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\OpponentTravelProjection;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : un match à l'extérieur apparié garde son adresse dans l'export même quand le siège du
 * club n'est pas localisé. Angle voisin, cité : `OpponentTravelProjectionTest::testNoClubSiegeProjectsNothing`
 * garde que les trajets, eux, restent vides sans siège — les deux lectures ne dépendent pas l'une
 * de l'autre.
 */
#[Group('integration')]
final class OpponentLinkedPlaceTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private OpponentTravelProjection $projection;

    private Club $club;

    private Season $season;

    public function testALinkedAwayKeepsItsPlaceWithoutClubCoordinates(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', '12 rue X', '69500', 'Bron');
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'SALLE DU PARC');

        self::assertSame(
            [$away->getId() => ['venueLabel' => 'SALLE DU PARC', 'address' => '12 rue X', 'postalCode' => '69500', 'city' => 'Bron']],
            $this->projection->linkedPlaceByFixtureId([$away]),
        );
        self::assertSame([], $this->projection->awayTravelByFixtureId($this->season->getId(), [$away]), 'sans siège, aucun trajet');
    }

    public function testAnOldLinkExposesItsNullParts(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', null, '69500', 'Bron');
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'SALLE DU PARC');

        self::assertNull($this->projection->linkedPlaceByFixtureId([$away])[$away->getId()]['address']);
    }

    public function testACodeLessOpponentIsFoundThroughItsSentinelKey(): void
    {
        $key = 'X' . substr(hash('sha256', mb_strtolower('Les Lions')), 0, 40);
        $this->link($key, 'SALLE AMICALE', null, null, null);
        $away = $this->fixture(FixtureHomeAway::AWAY, null, 'SALLE AMICALE');

        self::assertArrayHasKey($away->getId(), $this->projection->linkedPlaceByFixtureId([$away]));
    }

    /** Le lien EXACT seulement : ni « gymnase supposé », ni domicile, ni salle sans libellé. */
    public function testOnlyAnExactAwayLinkCounts(): void
    {
        $this->link('ORG1', 'SALLE DU PARC', '12 rue X', '69500', 'Bron');
        $otherHall = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'SALLE INCONNUE');
        $home = $this->fixture(FixtureHomeAway::HOME, 'ORG1', 'SALLE DU PARC');
        $noLabel = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', null);

        self::assertSame([], $this->projection->linkedPlaceByFixtureId([$otherHall, $home, $noLabel]));
    }

    public function testNoFixtureNoPlace(): void
    {
        self::assertSame([], $this->projection->linkedPlaceByFixtureId([]));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->projection = self::getContainer()->get(OpponentTravelProjection::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName('Les Lions ' . $uid);
        $this->club->setSlug('lions-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2026');
        $this->season->setStartDate(new DateTimeImmutable('2026-08-01'));
        $this->season->setEndDate(new DateTimeImmutable('2027-07-15'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    private function link(string $code, string $fbiLabel, ?string $address, ?string $postalCode, ?string $city): void
    {
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($this->club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueLabel($fbiLabel)
            ->setLatitude(45.76)
            ->setLongitude(4.86)
            ->setAddress($address)
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function fixture(FixtureHomeAway $homeAway, ?string $code, ?string $fbiVenueLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-11-14'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Les Lions');
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        if (null !== $code) {
            $fixture->setOpponentOrganismeCode($code);
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
