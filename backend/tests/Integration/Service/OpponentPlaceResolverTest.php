<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Entity\OpponentVenueSuggestion;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\OpponentVenueSuggestionSource;
use App\Enum\SeasonStatus;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\OpponentPlaceResolver;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-54 « détail par côté » — la résolution du LIEU d'un adversaire extérieur pour
 * le radar (décision fondateur 2026-09-17) : ligne d'override effective (ÉQUIPE via
 * teamKey normalisé, sinon CLUB) → VILLE de la salle CHOISIE (`opponent_venue_suggestion`
 * par (code, venueExternalRef)) → VILLE de l'annuaire fédéral (`opponent_directory`)
 * → null. Le libellé de gymnase d'override et le libellé FBI ne sont PLUS servis.
 * Testé en intégration comme son cousin `OpponentTravelResolverTest` : les repos
 * concrets sont finals (impossibles à mocker sans bypass-finals), et `opponent_travel`
 * est tenant/RLS, donc il faut un vrai contexte club+saison.
 */
#[Group('integration')]
final class OpponentPlaceResolverTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testResolvesLinkRefToSuggestionCityThenDirectory(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $this->scopeGucToClub($club->getId());

        // Étage 1 — le lien de la salle (par libellé FBI) porte une réf → VILLE de la suggestion.
        $fxLink = $this->awayFixture($club, $season, 'CODE1', 'ASVEL - 2', 'Salle FBI 1');
        $this->link($club, 'CODE1', 'Salle FBI 1', 'REF-1');
        $this->suggestion('CODE1', 'REF-1', 'Villeurbanne');
        $this->directoryEntry('CODE1', 'Ville Annuaire Ignorée');

        // Étage 2 — aucun lien → VILLE de l'annuaire.
        $fxDir = $this->awayFixture($club, $season, 'CODE3', 'BC TEST - 3', 'Salle FBI 3');
        $this->directoryEntry('CODE3', 'Grenoble');

        // Étage 3 — un libellé FBI sans lien ni annuaire → ABSENT de la map.
        $fxFbi = $this->awayFixture($club, $season, 'CODE4', 'BC TEST - 4', 'Salle FBI 4');

        // Lien avec réf mais suggestion SANS ville → retombe sur l'annuaire.
        $fxRefNoCity = $this->awayFixture($club, $season, 'CODE5', 'BC TEST - 5', 'Salle FBI 5');
        $this->link($club, 'CODE5', 'Salle FBI 5', 'REF-SANS-VILLE');
        $this->suggestion('CODE5', 'REF-SANS-VILLE', null);
        $this->directoryEntry('CODE5', 'Chambéry');

        // Lien SANS réf (gymnase par coordonnées seules) → aucune suggestion → annuaire.
        $fxRefNull = $this->awayFixture($club, $season, 'CODE6', 'BC TEST - 6', 'Salle FBI 6');
        $this->link($club, 'CODE6', 'Salle FBI 6', null);
        $this->directoryEntry('CODE6', 'Annecy');

        // Rien de rien → absent de la map.
        $fxNone = $this->awayFixture($club, $season, 'CODE7', 'BC TEST - 7', null);

        $this->em->flush();

        $places = $this->resolver()->resolveByFixture(
            $season->getId(),
            [$fxLink, $fxDir, $fxFbi, $fxRefNoCity, $fxRefNull, $fxNone],
        );

        self::assertSame('Villeurbanne', $places[$fxLink->getId()], 'lien de la salle → ville de sa suggestion');
        self::assertSame('Grenoble', $places[$fxDir->getId()], 'aucun lien → ville annuaire');
        self::assertArrayNotHasKey($fxFbi->getId(), $places, 'le libellé FBI seul n\'est plus servi → absent');
        self::assertSame('Chambéry', $places[$fxRefNoCity->getId()], 'suggestion sans ville → repli annuaire');
        self::assertSame('Annecy', $places[$fxRefNull->getId()], 'lien réf null → repli annuaire');
        self::assertArrayNotHasKey($fxNone->getId(), $places, 'rien de résolvable → absent (lieu inconnu)');
    }

    public function testFinderBatchIndexesSuggestionsByCodeSkippingUnknownCodes(): void
    {
        [$club] = $this->seedClubSeason();
        $this->scopeGucToClub($club->getId());

        $this->suggestion('BATCH1', 'REF-A', 'Ville A');
        $this->suggestion('BATCH1', 'REF-B', 'Ville B');
        $this->suggestion('BATCH2', 'REF-C', 'Ville C');
        $this->suggestion('BATCH3', 'REF-D', 'Ville D'); // non demandée
        $this->em->flush();

        $repository = self::getContainer()->get(OpponentVenueSuggestionRepository::class);
        self::assertInstanceOf(OpponentVenueSuggestionRepository::class, $repository);

        // Liste vide → aucune requête, tableau vide.
        self::assertSame([], $repository->findByFfbbOrganismeCodes([]));

        // Doublon de code toléré ; BATCH3 jamais demandé → jamais rendu.
        $rows = $repository->findByFfbbOrganismeCodes(['BATCH1', 'BATCH2', 'BATCH1']);
        $codes = array_values(array_unique(array_map(static fn (OpponentVenueSuggestion $s): string => $s->getFfbbOrganismeCode(), $rows)));
        sort($codes);
        self::assertSame(['BATCH1', 'BATCH2'], $codes);
        self::assertCount(3, $rows, 'deux gymnases pour BATCH1 + un pour BATCH2');
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

    private function link(Club $club, string $code, string $fbiLabel, ?string $venueRef): void
    {
        $link = (new OpponentVenueLink)
            ->setClubId($club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($this->normalizer()->normalize($fbiLabel))
            ->setVenueExternalRef($venueRef)
            ->setVenueLabel('Gymnase ' . $code)
            ->setLatitude(45.5)
            ->setLongitude(4.5)
            ->setSource(OpponentVenueLinkSource::MANUAL);
        $this->em->persist($link);
    }

    /**
     * Une suggestion GLOBALE de gymnase (table de référence, hors RLS). Le seul chemin
     * d'écriture est un upsert natif : le constructeur ne pose ni la réf salle ni la
     * ville, on les fixe par réflexion pour le décor de test (patron setId).
     */
    private function suggestion(string $code, string $externalRef, ?string $city): void
    {
        $suggestion = new OpponentVenueSuggestion($code, 'Salle ' . $externalRef, OpponentVenueSuggestionSource::MANUAL);
        $refVenue = new ReflectionProperty($suggestion, 'venueExternalRef');
        $refVenue->setValue($suggestion, $externalRef);
        if (null !== $city) {
            $refCity = new ReflectionProperty($suggestion, 'city');
            $refCity->setValue($suggestion, $city);
        }
        $this->em->persist($suggestion);
    }

    private function directoryEntry(string $code, string $city): void
    {
        $entry = new OpponentDirectoryEntry($code, 'Adversaire ' . $code, OpponentLocationPrecision::CITY);
        $entry->setCity($city);
        $this->em->persist($entry);
    }
}
