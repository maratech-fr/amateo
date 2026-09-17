<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\OpponentPlaceResolver;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-54 « détail par côté » — la résolution du LIEU d'un adversaire extérieur pour
 * le radar, à ses QUATRE étages : override ÉQUIPE (manuel) > override CLUB (manuel)
 * > annuaire fédéral (ville) > libellé FBI de la rencontre > null. Testé en
 * intégration comme son cousin `OpponentTravelResolverTest` : les repos concrets
 * sont finals (impossibles à mocker sans bypass-finals), et `opponent_travel` est
 * tenant/RLS, donc il faut un vrai contexte club+saison.
 */
#[Group('integration')]
final class OpponentPlaceResolverTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testResolvesThroughTheFourTiersInOrder(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $this->scopeGucToClub($club->getId());
        $normalizer = $this->normalizer();

        // Tier 1 — override ÉQUIPE gagne sur club + annuaire + FBI.
        $fxTeam = $this->awayFixture($club, $season, 'CODE1', 'ASVEL - 2', 'Salle FBI 1');
        $this->travelRow($club, $season, 'CODE1', $normalizer->normalize('ASVEL - 2'), 'Gymnase Équipe');
        $this->travelRow($club, $season, 'CODE1', null, 'Gymnase Club');
        $this->directoryEntry('CODE1', 'Villeurbanne');

        // Tier 2 — override CLUB (pas de ligne équipe) gagne sur annuaire + FBI.
        $fxClub = $this->awayFixture($club, $season, 'CODE2', 'BC TEST - 1', 'Salle FBI 2');
        $this->travelRow($club, $season, 'CODE2', null, 'Gymnase Club 2');
        $this->directoryEntry('CODE2', 'Ville 2');

        // Tier 3 — annuaire (aucun manuel) gagne sur FBI.
        $fxDir = $this->awayFixture($club, $season, 'CODE3', 'BC TEST - 3', 'Salle FBI 3');
        $this->directoryEntry('CODE3', 'Ville 3');

        // Tier 4 — libellé FBI (aucun manuel, aucun annuaire).
        $fxFbi = $this->awayFixture($club, $season, 'CODE4', 'BC TEST - 4', 'Salle FBI 4');

        // Tier 5 — rien de rien → absent de la map.
        $fxNone = $this->awayFixture($club, $season, 'CODE5', 'BC TEST - 5', null);

        // Un code sans annuaire mais avec une ligne club VIDE (label blanc) retombe sur FBI.
        $fxBlank = $this->awayFixture($club, $season, 'CODE6', 'BC TEST - 6', 'Salle FBI 6');
        $this->travelRow($club, $season, 'CODE6', null, '   ');

        $this->em->flush();

        $places = $this->resolver()->resolveByFixture($season->getId(), [$fxTeam, $fxClub, $fxDir, $fxFbi, $fxNone, $fxBlank]);

        self::assertSame('Gymnase Équipe', $places[$fxTeam->getId()]);
        self::assertSame('Gymnase Club 2', $places[$fxClub->getId()]);
        self::assertSame('Ville 3', $places[$fxDir->getId()]);
        self::assertSame('Salle FBI 4', $places[$fxFbi->getId()]);
        self::assertArrayNotHasKey($fxNone->getId(), $places, 'rien de résolvable → absent (lieu inconnu)');
        self::assertSame('Salle FBI 6', $places[$fxBlank->getId()], 'un override blanc est ignoré, on retombe sur FBI');
    }

    public function testEmptyInputIsEmptyMapWithNoQuery(): void
    {
        [, $season] = $this->seedClubSeason();

        self::assertSame([], $this->resolver()->resolveByFixture($season->getId(), []));
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function resolver(): OpponentPlaceResolver
    {
        $resolver = self::getContainer()->get(OpponentPlaceResolver::class);
        self::assertInstanceOf(OpponentPlaceResolver::class, $resolver);

        return $resolver;
    }

    private function normalizer(): VenueLabelNormalizer
    {
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        self::assertInstanceOf(VenueLabelNormalizer::class, $normalizer);

        return $normalizer;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubSeason(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club place ' . $uid);
        $club->setSlug('club-place-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function awayFixture(Club $club, Season $season, string $code, string $label, ?string $fbiVenueLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($label);
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        $this->em->persist($fixture);

        return $fixture;
    }

    private function travelRow(Club $club, Season $season, string $code, ?string $teamKey, string $overrideLabel): void
    {
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey($teamKey)
            ->setOverrideVenueLabel($overrideLabel);
        $this->em->persist($row);
    }

    private function directoryEntry(string $code, string $city): void
    {
        $entry = new OpponentDirectoryEntry($code, 'Adversaire ' . $code, OpponentLocationPrecision::CITY);
        $entry->setCity($city);
        $this->em->persist($entry);
    }
}
