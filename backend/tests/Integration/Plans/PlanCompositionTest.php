<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Plans\PlanComposition;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\ComposesPlanVenuesTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 21 du plan) : toute équipe a sa ligne dans chaque plan de sa saison, posée par une
 * seule porte, `PlanComposition::ensureLines`, que chaque créateur d'équipe appelle ; rappeler la porte ne change
 * aucune ligne existante ; lire un plan où une ligne manque échoue haut et fort, jamais un défaut silencieux.
 *
 * Les sept créateurs (API, « Préparer la saison suivante », import du parc, deux imports FFBB, deux seeds) sont
 * prouvés par un balayage de `src/` : un `new Team` sans appel à la porte rougit. Le premier, le geste du
 * gestionnaire, l'est aussi par l'API.
 *
 * Lot 4, PR 3b (ligne 26 du plan) : même promesse pour les gymnases d'un plan de période — une ligne par (plan de
 * période, gymnase), aucune au plan de saison, posée par la même porte ; les cinq créateurs de gymnase (API,
 * « Préparer la saison suivante », import du parc, deux seeds) l'appellent.
 */
#[Group('integration')]
final class PlanCompositionTest extends WebTestCase
{
    use ComposesPlanTeamsTrait;
    use ComposesPlanVenuesTrait;
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    /** Les cinq fichiers qui créent un gymnase aujourd'hui (§2 du plan) : le balayage doit les voir, témoin. */
    private const array KNOWN_VENUE_CREATORS = [
        'State/Processor/VenueStateProcessor.php',
        'Service/SeasonTransitionService.php',
        'Service/ParcImport/ParcImportExecutor.php',
        'Seed/BcclSeeder.php',
        'Seed/LoadTestClubSeeder.php',
    ];

    /** Les sept fichiers qui créent une équipe aujourd'hui (§2 du plan) : le balayage doit les voir, témoin. */
    private const array KNOWN_TEAM_CREATORS = [
        'State/Processor/TeamStateProcessor.php',
        'Service/SeasonTransitionService.php',
        'Service/ParcImport/ParcImportExecutor.php',
        'Service/Basketball/FfbbTeamImporter.php',
        'Service/Basketball/FfbbExcelImporter.php',
        'Seed/BcclSeeder.php',
        'Seed/LoadTestClubSeeder.php',
    ];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    private string $categoryId;

    /**
     * Le gestionnaire crée une équipe de rang 5 (3 séances, 2 par jour) alors qu'une fermeture et des vacances ont déjà
     * leur plan : elle a sa ligne au socle avec ses valeurs, et une ligne dans chaque plan de période qui participe,
     * aux valeurs du socle du moment — même de rang 5 dans des vacances (la pré-sélection ne vaut qu'à la naissance).
     */
    public function testATeamCreatedByTheManagerGetsItsLineInEveryPlanOfTheSeason(): void
    {
        $closure = $this->periodPlan(CalendarEntryPeriodType::CLOSURE, '2025-11-03', '2025-11-09');
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');

        $this->client->request('POST', '/api/teams', [], [], $this->headers(), json_encode([
            'name' => 'Loisir',
            'sportCategoryId' => $this->categoryId,
            'priorityTierId' => 5,
            'sessionsPerWeek' => 3,
            'maxSessionsPerDay' => 2,
            'isActive' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $teamId = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'] ?? null;
        self::assertIsString($teamId);

        $expected = ['participates' => true, 'sessionsPerWeek' => 3, 'maxSessionsPerDay' => 2, 'source' => null];
        self::assertSame($expected, $this->lineRow($this->seasonPlan(), $teamId), 'le socle porte les séances et le plafond saisis');
        self::assertSame($expected, $this->lineRow($closure, $teamId), 'la fermeture reçoit la ligne, aux valeurs du socle');
        self::assertSame($expected, $this->lineRow($holiday, $teamId), 'les vacances aussi : une équipe ajoutée après leur naissance y est cochée');
        self::assertSame(3, $this->lineCountOf($teamId), 'une ligne par plan, ni plus ni moins');
    }

    /**
     * Rappeler la porte ne touche aucune ligne existante, ni une ligne de période réglée par le gestionnaire, ni la
     * ligne du socle ; une équipe créée depuis reçoit les siennes, et seulement elle.
     */
    public function testCallingTheDoorAgainChangesNoExistingLine(): void
    {
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');
        $sm1 = $this->team('SM1', 1);
        $this->setSocleTeam($this->season->getId(), $sm1, 2);
        $this->teamLine($holiday, $sm1)->setParticipating(false)->setSessionsPerWeek(1)->setMaxSessionsPerDay(2);
        $this->teamLine($this->seasonPlan(), $sm1)->setSessionsPerWeek(4);
        $this->em->flush();
        $before = $this->allLines();
        self::assertCount(2, $before, 'témoin : le socle et les vacances ont chacun la ligne de SM1');

        $this->layTeamLines($this->season->getId());
        self::assertSame($before, $this->allLines(), 'la porte rappelée ne change rien, ligne pour ligne');

        $u13 = $this->team('U13', 4);
        $this->layTeamLines($this->season->getId());
        $after = $this->allLines();
        self::assertSame($before, array_intersect_key($after, $before), 'les lignes de SM1 restent identiques');
        self::assertCount(4, $after, 'U13, créée depuis, reçoit ses deux lignes');
        self::assertSame(2, $this->lineCountOf($u13));
    }

    /** Lire un plan dont une équipe de la saison n'a pas de ligne lève : jamais un défaut silencieux. */
    public function testReadingAPlanWhereALineIsMissingFailsLoudly(): void
    {
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');
        $sm1 = $this->team('SM1', 1);
        $this->layTeamLines($this->season->getId());
        self::assertNotNull($this->lineRow($holiday, $sm1), 'témoin : la ligne existe');
        $this->em->getConnection()->executeStatement('DELETE FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$holiday, $sm1]);
        $this->em->clear();

        $this->expectException(LogicException::class);
        self::getContainer()->get(PlanComposition::class)->teamsOf($holiday);
    }

    /**
     * Balayage : chaque fichier de `src/` qui crée une équipe (`new Team`) appelle la porte `ensureLines`. Témoin : le
     * balayage voit les sept créateurs connus — un motif qui ne verrait rien ne prouverait rien.
     */
    public function testEveryTeamCreatorCallsTheDoor(): void
    {
        $src = \dirname(__DIR__, 3) . '/src/';
        $creators = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (1 === preg_match('/new\s+Team(?![A-Za-z0-9_\\\\])/', $code)) {
                $creators[substr($file->getPathname(), \strlen($src))] = str_contains($code, 'ensureLines(');
            }
        }
        ksort($creators);

        foreach (self::KNOWN_TEAM_CREATORS as $known) {
            self::assertArrayHasKey($known, $creators, \sprintf('témoin : le balayage voit le créateur %s', $known));
        }
        self::assertSame([], array_keys(array_filter($creators, static fn (bool $callsTheDoor): bool => !$callsTheDoor)), 'chaque créateur d\'équipe pose les lignes manquantes par la porte');
    }

    /**
     * Lot 4, PR 3b : le gestionnaire crée un gymnase alors qu'une fermeture et des vacances ont déjà leur plan. Il a sa
     * ligne dans chaque plan de période, qui participe, sans fermeture levée ni masque ; aucune au plan de saison.
     */
    public function testAVenueCreatedByTheManagerGetsItsLineInEveryPeriodPlan(): void
    {
        $closure = $this->periodPlan(CalendarEntryPeriodType::CLOSURE, '2025-11-03', '2025-11-09');
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');

        $this->client->request('POST', '/api/venues', [], [], $this->headers(), json_encode(['name' => 'Gymnase Est', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $venueId = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'] ?? null;
        self::assertIsString($venueId);

        $expected = ['participates' => true, 'closureLifted' => false, 'dayOverrides' => null];
        self::assertSame($expected, $this->venueLineRow($closure, $venueId), 'la fermeture reçoit la ligne du gymnase, au défaut');
        self::assertSame($expected, $this->venueLineRow($holiday, $venueId), 'les vacances aussi');
        self::assertNull($this->venueLineRow($this->seasonPlan(), $venueId), 'aucune ligne au plan de saison');
        self::assertSame(2, $this->venueLineCountOf($venueId), 'une ligne par plan de période, ni plus ni moins');
    }

    /**
     * Lot 4, PR 3b : rappeler la porte ne touche aucune ligne de gymnase existante, ni celle que le gestionnaire a
     * réglée (hors service, fermeture levée, masque) ; un gymnase créé depuis reçoit les siennes, et seulement lui.
     */
    public function testCallingTheDoorAgainChangesNoExistingVenueLine(): void
    {
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');
        $nord = $this->newVenue($this->club->getId(), $this->season->getId(), 'Gymnase Nord');
        $this->em->flush();
        $this->layVenueLines($this->season->getId());
        $this->venueLine($holiday, $nord->getId())->setParticipating(false)->setClosureLifted(true)->setDayOverrides([3 => 'CLOSED']);
        $this->em->flush();
        $before = $this->allVenueLines();
        self::assertCount(1, $before, 'témoin : les vacances ont la ligne du Gymnase Nord');

        $this->layVenueLines($this->season->getId());
        self::assertSame($before, $this->allVenueLines(), 'la porte rappelée ne change rien, ligne pour ligne');

        $sud = $this->newVenue($this->club->getId(), $this->season->getId(), 'Gymnase Sud');
        $this->em->flush();
        $this->layVenueLines($this->season->getId());
        $after = $this->allVenueLines();
        self::assertSame($before, array_intersect_key($after, $before), 'la ligne du Gymnase Nord reste identique');
        self::assertCount(2, $after, 'le Gymnase Sud, créé depuis, reçoit sa ligne');
        self::assertSame(['participates' => true, 'closureLifted' => false, 'dayOverrides' => null], $this->venueLineRow($holiday, $sud->getId()));
    }

    /** Lot 4, PR 3b : lire un plan de période dont un gymnase de la saison n'a pas de ligne lève, jamais un défaut silencieux. */
    public function testReadingAPlanWhereAVenueLineIsMissingFailsLoudly(): void
    {
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');
        $nord = $this->newVenue($this->club->getId(), $this->season->getId(), 'Gymnase Nord');
        $this->em->flush();
        $this->layVenueLines($this->season->getId());
        self::assertNotNull($this->venueLineRow($holiday, $nord->getId()), 'témoin : la ligne existe');
        $this->em->getConnection()->executeStatement('DELETE FROM plan_venue WHERE schedule_plan_id = ? AND venue_id = ?', [$holiday, $nord->getId()]);
        $this->em->clear();

        $this->expectException(LogicException::class);
        self::getContainer()->get(PlanComposition::class)->venuesOf($holiday);
    }

    /**
     * Lot 4, PR 3b — balayage : chaque fichier de `src/` qui construit un gymnase (`new Venue`, lu au jeton : un
     * commentaire ou une chaîne qui le cite ne compte pas) appelle la porte `ensureLines`. Témoin : le balayage voit
     * les cinq créateurs connus.
     */
    public function testEveryVenueCreatorCallsTheDoor(): void
    {
        $src = \dirname(__DIR__, 3) . '/src/';
        $creators = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
            ));
            $buildsAVenue = false;
            $callsTheDoor = false;
            foreach ($tokens as $i => $token) {
                if (!\is_array($token)) {
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                if (\T_NEW === $token[0] && \is_array($next) && \in_array($next[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
                    && 'Venue' === substr((string) strrchr('\\' . $next[1], '\\'), 1)) {
                    $buildsAVenue = true;
                }
                if (\T_STRING === $token[0] && 'ensureLines' === $token[1] && '(' === $next) {
                    $callsTheDoor = true;
                }
            }
            if ($buildsAVenue) {
                $creators[substr($file->getPathname(), \strlen($src))] = $callsTheDoor;
            }
        }
        ksort($creators);

        foreach (self::KNOWN_VENUE_CREATORS as $known) {
            self::assertArrayHasKey($known, $creators, \sprintf('témoin : le balayage voit le créateur %s', $known));
        }
        self::assertSame([], array_keys(array_filter($creators, static fn (bool $callsTheDoor): bool => !$callsTheDoor)), 'chaque créateur de gymnase pose les lignes manquantes par la porte');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $this->club = (new Club)->setName('Composition ' . $uid)->setSlug('composition-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->user = (new User)->setEmail('composition' . $uid . '@test.com')->setFirstName('C')->setLastName('Omposition');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
            $this->em->persist($sport);
        }
        $this->em->flush();
        $category = (new SportCategory)->setClubId($this->club->getId())->setSportId($sport->getId())->setName('U13-' . $uid);
        $this->em->persist($category);
        $this->em->flush();
        $this->categoryId = $category->getId();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
    }

    private function seasonPlan(): string
    {
        return SeasonPlanOf::id(self::getContainer(), $this->season->getId());
    }

    /** Une période et son plan, nés du geste (`provisionPeriodPlan`). */
    private function periodPlan(CalendarEntryPeriodType $type, string $start, string $end): string
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType($type)->setTitle($type->value . ' ' . $start)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);

        return $this->planIdOf($entry);
    }

    /** Une équipe écrite à la main (décor), sans ses lignes : le test appelle la porte lui-même. */
    private function team(string $name, int $tier): string
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setSportCategoryId($this->categoryId)
            ->setPriorityTierId($tier)->setName($name)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    /** @return array<string, array<string, mixed>> chaque ligne de la saison, colonne par colonne, par identifiant */
    private function allLines(): array
    {
        $lines = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM plan_team WHERE season_id = ? ORDER BY id', [$this->season->getId()]) as $row) {
            $lines[(string) $row['id']] = $row;
        }

        return $lines;
    }

    /** @return array<string, array<string, mixed>> chaque ligne de gymnase de la saison, colonne par colonne, par identifiant */
    private function allVenueLines(): array
    {
        $lines = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM plan_venue WHERE season_id = ? ORDER BY id', [$this->season->getId()]) as $row) {
            $lines[(string) $row['id']] = $row;
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }
}
