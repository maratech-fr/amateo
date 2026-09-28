<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\ClubUser;
use App\Entity\LeagueMatchWindow;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\MatchPlacementPayloadBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes *constraint semantics* + *backend↔engine contract* (§7.1).
 *
 * P4-272 ① : le bloc `leagueWindows` émis au solveur (par équipe) est EXACTEMENT la
 * COPIE club stockée (`club_league_window`) — plus jamais le catalogue GLOBAL
 * (`league_match_window`). Le placement, le radar et l'écran des contraintes lisent
 * la MÊME maison.
 *
 * Falsifié dans les DEUX sens :
 *  - une fenêtre ÉDITÉE par le club apparaît telle quelle dans le payload (un builder
 *    resté sur le catalogue global échouerait) ;
 *  - le catalogue global NE FUIT PLUS : une fenêtre présente au catalogue mais absente
 *    de la copie n'atteint pas le payload (un builder aveugle à la copie échouerait) ;
 *  - copie VIDE ⇒ AUCUN HARD ligue (chaque équipe émet `leagueWindows: []`) et UN SEUL
 *    diagnostic INFO club (`league_envelope_empty`), jamais un par équipe.
 */
#[Group('phase1')]
#[Group('integration')]
final class LeagueWindowsPayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    /**
     * Sens 1 — la copie éditée voyage : une fenêtre stockée (bornes distinctives)
     * est EXACTEMENT ce que le payload émet pour l'équipe mappée.
     */
    public function testPayloadLeagueWindowsMirrorTheStoredCopy(): void
    {
        [$club, $season, $category] = $this->seed();
        $team = $this->team($club, $season, $category->getId());
        // Bornes distinctives (« corrigées par le club ») : samedi 15:15 → 16:45.
        $this->copyWindow($club, $season, $category->getName(), 'REGIONAL', null, 6, '15:15', '16:45');
        $this->em->flush();

        $windows = $this->leagueWindowsOf($team->getId(), $club, $season->getId());

        self::assertSame(
            [['dayOfWeek' => 6, 'kickoffMin' => '15:15', 'kickoffMax' => '16:45']],
            $windows,
            'le payload émet EXACTEMENT la fenêtre de la copie club',
        );
    }

    /**
     * Sens 2 — le catalogue global ne fuit plus : une fenêtre au catalogue (qui
     * mapperait l'équipe) mais ABSENTE de la copie n'atteint pas le payload ; seule la
     * fenêtre de la copie (bornes différentes) est émise.
     */
    public function testGlobalCatalogDoesNotLeakIntoThePayload(): void
    {
        [$club, $season, $category] = $this->seed();
        $team = $this->team($club, $season, $category->getId());
        // La copie : samedi 10:00 → 10:30. Le catalogue global : MÊME jour, bornes
        // différentes (20:00 → 22:00) — s'il fuyait, le payload porterait deux fenêtres.
        $this->copyWindow($club, $season, $category->getName(), 'REGIONAL', null, 6, '10:00', '10:30');
        $this->globalWindow('AURA', $category->getName(), 'REGIONAL', null, 6, '20:00', '22:00');
        $this->em->flush();

        $windows = $this->leagueWindowsOf($team->getId(), $club, $season->getId());

        self::assertSame(
            [['dayOfWeek' => 6, 'kickoffMin' => '10:00', 'kickoffMax' => '10:30']],
            $windows,
            'seule la copie voyage — la fenêtre du catalogue global ne fuit pas',
        );
    }

    /**
     * Copie VIDE : aucune fenêtre pour aucune équipe (zéro HARD) et EXACTEMENT un
     * diagnostic INFO club, jamais un par équipe.
     */
    public function testEmptyCopyYieldsNoHardAndExactlyOneClubDiagnostic(): void
    {
        [$club, $season, $category] = $this->seed();
        $team = $this->team($club, $season, $category->getId());
        // Une fenêtre AU CATALOGUE qui mapperait l'équipe : la copie reste vide malgré
        // elle → le placement n'applique AUCUNE règle fédérale.
        $this->globalWindow('AURA', $category->getName(), 'REGIONAL', null, 6, '10:00', '10:30');
        $this->em->flush();

        $result = $this->build($club, $season->getId());

        self::assertSame([], $this->leagueWindowsOfPayload($result['payload'], $team->getId()), 'copie vide ⇒ aucun HARD ligue pour l\'équipe');

        $types = array_column($result['infoDiagnostics'], 'type');
        self::assertSame(['league_envelope_empty'], array_values(array_filter($types, static fn (string $t): bool => str_starts_with($t, 'league_envelope'))), 'copie vide ⇒ un SEUL diagnostic club, jamais un par équipe');
    }

    /**
     * Copie NON vide mais équipe non mappée : le diagnostic PAR ÉQUIPE subsiste (et
     * jamais le diagnostic club, qui n'est réservé qu'à la copie vide).
     */
    public function testNonEmptyCopyKeepsPerTeamDiagnosticForUnmappedTeam(): void
    {
        [$club, $season, $category] = $this->seed();
        // La copie cadre une AUTRE catégorie ; l'équipe (catégorie $category) reste non mappée.
        $team = $this->team($club, $season, $category->getId());
        $this->copyWindow($club, $season, 'Une Autre Categorie', 'REGIONAL', null, 6, '10:00', '10:30');
        $this->em->flush();

        $result = $this->build($club, $season->getId());

        self::assertSame([], $this->leagueWindowsOfPayload($result['payload'], $team->getId()), 'l\'équipe non mappée n\'a pas de fenêtre');

        $perTeam = array_filter($result['infoDiagnostics'], static fn (array $d): bool => 'league_envelope_unresolved' === $d['type']);
        self::assertCount(1, $perTeam, 'la copie non vide garde le diagnostic par équipe pour l\'équipe non mappée');
        $clubLevel = array_filter($result['infoDiagnostics'], static fn (array $d): bool => 'league_envelope_empty' === $d['type']);
        self::assertCount(0, $clubLevel, 'aucun diagnostic club quand la copie n\'est pas vide');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
    }

    /**
     * @return list<array{dayOfWeek: int, kickoffMin: string, kickoffMax: string}>
     */
    private function leagueWindowsOf(string $teamId, Club $club, string $seasonId): array
    {
        return $this->leagueWindowsOfPayload($this->build($club, $seasonId)['payload'], $teamId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{dayOfWeek: int, kickoffMin: string, kickoffMax: string}>
     */
    private function leagueWindowsOfPayload(array $payload, string $teamId): array
    {
        /** @var list<array<string, mixed>> $teams */
        $teams = $payload['teams'];
        foreach ($teams as $teamRow) {
            if ($teamRow['id'] === $teamId) {
                /** @var list<array{dayOfWeek: int, kickoffMin: string, kickoffMax: string}> $windows */
                $windows = $teamRow['leagueWindows'];

                return $windows;
            }
        }

        self::fail(\sprintf('équipe %s absente du payload', $teamId));
    }

    /**
     * @return array{payload: array<string, mixed>, toPlaceCount: int, infoDiagnostics: list<array<string, mixed>>}
     */
    private function build(Club $club, string $seasonId): array
    {
        // Le contexte non-HTTP n'active pas les filtres Doctrine : la RLS (GUC posé au
        // seed) scope le club, et le club de test ne porte qu'UNE saison → findBy([])
        // reste borné à cette saison.
        return $this->builder->build($club, $seasonId);
    }

    private function team(Club $club, Season $season, string $categoryId): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($categoryId);
        $team->setPriorityTierId(3);
        $team->setName('T' . substr($this->uuid(), 0, 6));
        $team->setLevel(TeamLevel::REGIONAL);
        $team->setGender(Gender::M);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    private function copyWindow(Club $club, Season $season, string $category, string $level, ?string $gender, int $day, string $min, string $max): ClubLeagueWindow
    {
        $window = new ClubLeagueWindow;
        $window->setClubId($club->getId());
        $window->setSeasonId($season->getId());
        $window->setLeague('AURA');
        $window->setCategory($category);
        $window->setLevel($level);
        $window->setGender($gender);
        $window->setDayOfWeek($day);
        $window->setKickoffMin(new DateTimeImmutable($min));
        $window->setKickoffMax(new DateTimeImmutable($max));
        $this->em->persist($window);

        return $window;
    }

    private function globalWindow(string $league, string $category, string $level, ?string $gender, int $day, string $min, string $max): LeagueMatchWindow
    {
        $window = new LeagueMatchWindow;
        $window->setLeague($league);
        $window->setCategory($category);
        $window->setLevel($level);
        $window->setGender($gender);
        $window->setDayOfWeek($day);
        $window->setKickoffMin(new DateTimeImmutable($min));
        $window->setKickoffMax(new DateTimeImmutable($max));
        $this->em->persist($window);

        return $window;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season, 2: SportCategory}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('League Windows Parity Club');
        $club->setSlug('league-windows-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('LWP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('league-windows-parity-' . $uid . '@test.com');
        $user->setFirstName('L');
        $user->setLastName('W');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('basketball-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('Seniors Region ' . substr($uid, -4));
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        return [$club, $season, $category];
    }
}
