<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use App\State\Provider\AbstractStateProvider;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\Unit\Service\PdfGridCoachNameTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Chantier E — le coach affiché à l'export PDF (grille) doit être LE MÊME que celui de l'écran :
 * la séance porte son propre coach s'il en a un, SINON le 1er coach MAIN de son équipe (décision
 * fondateur Q4). « Aucun coach → rien ».
 *
 * Ici on prouve la MOITIÉ « RÉSOLUTION » de la promesse — la seule qui traverse le serveur : le
 * provider dérive, depuis les liens `team_coach`, la carte équipe → 1er coach MAIN, scopée
 * club+saison et ORDONNÉE comme l'endpoint que lit l'écran. L'écran reçoit `team_coaches` dans
 * l'ordre `e.id ASC` imposé par {@see AbstractStateProvider::provideCollection}
 * (« Deterministic order on the UUID PK ») et prend le 1er MAIN dans cet ordre
 * (`frontend/src/features/planning/PlanningPage.tsx:402-409`, consommé par `grid.ts::slotCoachId`).
 * L'export doit suivre le MÊME tri, sinon il nommerait un AUTRE coach que l'écran pour une équipe
 * à deux coachs principaux.
 *
 * La MOITIÉ « APPLICATION » (une séance sans coach retombe sur ce coach d'équipe ; un coach POSÉ
 * sur la séance PRIME) est prouvée au plus bas, sans DB, par le jumeau
 * {@see PdfGridCoachNameTest::testGridFallsBackToTheTeamMainCoachWhenTheSlotCarriesNone}
 * et {@see PdfGridCoachNameTest::testTheSlotOwnCoachWinsOverTheTeamMainCoach}
 * — point sensible « export » : deux angles VOULUS (résolution côté DB ⇄ application côté rendu),
 * cités l'un l'autre.
 *
 * ⚠ Motif du chantier : sur la base locale du fondateur, 233 séances ont `coach_id` NULL alors que
 * leur équipe a un coach MAIN (socle importé / posé à la main / seedé — `BcclSeeder.php:1905/2361`,
 * `LoadTestClubSeeder.php:629`). Sans cette résolution, le PDF coché resterait muet là où l'écran
 * nomme le coach.
 *
 * ⚠ Carte PROPOSÉE (option A, TANK écrit AVANT NEO) : `ScheduleExportData::$teamMainCoachIds`
 * (teamId → coachId du 1er MAIN). NEO peut adapter MÉCANIQUEMENT le nom ; la RÈGLE (MAIN seul,
 * scopée club+saison, 1er par id de LIEN ASC) ne bouge pas. Règle backend réutilisable à ORDONNER :
 * `App\Service\CoachDoubleBookingDetector::mainCoachesByTeam()` (« coach principal d'une équipe »,
 * ligne 448) — aujourd'hui `private` et SANS orderBy ; l'export exige `orderBy ['id' => 'ASC']`
 * pour coïncider avec l'ordre de l'endpoint. L'idéal : promouvoir ce foyer en résolveur partagé
 * ordonné, réutilisé par le provider.
 */
#[Group('integration')]
final class ScheduleExportCoachResolutionTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleExportDataProvider $provider;

    public function testSlotlessTeamResolvesToItsMainCoachShortName(): void
    {
        [$club, $season] = $this->seed();
        $teamId = $this->uuid();
        $coachId = $this->makeCoach($club, $season, 'Karim', 'Benzema');
        $this->linkCoach($club, $season, $teamId, $coachId, TeamCoachRole::MAIN);
        $this->em->flush();

        $data = $this->load($club, $season);

        self::assertSame($coachId, $data->teamMainCoachIds[$teamId] ?? null, 'le 1er coach MAIN de l’équipe est résolu (ce que l’écran montre quand la séance n’a pas de coach)');
        self::assertSame('Karim B.', $data->coachShortNames[$coachId] ?? null, 'et son nom COURT est disponible pour la grille');
    }

    public function testATeamWithOnlyAnAssistantCoachResolvesToNothing(): void
    {
        [$club, $season] = $this->seed();
        $teamId = $this->uuid();
        $coachId = $this->makeCoach($club, $season, 'Léa', 'Martin');
        $this->linkCoach($club, $season, $teamId, $coachId, TeamCoachRole::ASSISTANT);
        $this->em->flush();

        $data = $this->load($club, $season);

        self::assertArrayNotHasKey($teamId, $data->teamMainCoachIds, 'un coach ASSISTANT ne tient jamais lieu de coach d’équipe → rien à l’export (promesse « pas de coach → rien »)');
    }

    public function testTheFirstMainCoachByLinkIdWinsMatchingTheApiOrder(): void
    {
        [$club, $season] = $this->seed();
        $teamId = $this->uuid();
        // Deux coachs MAIN sur la même équipe. On CROISE volontairement l'ordre des ids : le coach
        // du lien de PLUS PETIT id (Karim) doit gagner, alors que son id de COACH est le PLUS GRAND.
        // Si la résolution triait par coachId (ou par l'ordre arbitraire de la base), Louis gagnerait.
        // Attendre Karim prouve le tri par id de LIEN ASC — exactement l'ordre de l'endpoint team_coach.
        $louis = $this->makeCoach($club, $season, 'Louis', 'Durand', '00000000-0000-4000-8000-0000000c0ac1');
        $karim = $this->makeCoach($club, $season, 'Karim', 'Benzema', 'ffffffff-ffff-4fff-bfff-ffffffc0ac22');
        $this->linkCoach($club, $season, $teamId, $karim, TeamCoachRole::MAIN, '00000000-0000-4000-8000-00000011a111');
        $this->linkCoach($club, $season, $teamId, $louis, TeamCoachRole::MAIN, 'ffffffff-ffff-4fff-bfff-ffffff11a122');
        $this->em->flush();

        $data = $this->load($club, $season);

        self::assertSame($karim, $data->teamMainCoachIds[$teamId] ?? null, 'le 1er MAIN par id de LIEN (ASC), jamais par id de coach — cohérent avec l’ordre de l’API team_coach que lit l’écran');
    }

    public function testAnotherClubsMainCoachIsNeverResolved(): void
    {
        [$club, $season] = $this->seed();
        [$other, $otherSeason] = $this->seed();

        // Chez l'AUTRE club : une équipe au MÊME id et un coach MAIN. Étanchéité tenant.
        $sharedTeamId = $this->uuid();
        $this->scopeGucToClub($other->getId());
        $intrus = $this->makeCoach($other, $otherSeason, 'Intrus', 'Voisin');
        $this->linkCoach($other, $otherSeason, $sharedTeamId, $intrus, TeamCoachRole::MAIN);
        $this->em->flush();

        // De retour dans le club A : SA propre équipe (même id) n'a aucun coach à elle.
        $this->scopeGucToClub($club->getId());
        $data = $this->load($club, $season);

        self::assertArrayNotHasKey($sharedTeamId, $data->teamMainCoachIds, 'le lien team_coach d’un autre club n’est jamais lu (scopé club+saison + RLS) — deux clubs ne partagent jamais leurs coachs');
    }

    public function testADifferentSeasonsMainCoachIsNeverResolved(): void
    {
        [$club, $season] = $this->seed();

        // Une saison PASSÉE du MÊME club, avec un coach MAIN sur une équipe d'id partagé.
        $past = new Season;
        $past->setClubId($club->getId());
        $past->setName('2024-2025');
        $past->setStartDate(new DateTimeImmutable('2024-09-01'));
        $past->setEndDate(new DateTimeImmutable('2025-06-30'));
        $past->setStatus(SeasonStatus::ARCHIVED);
        $this->em->persist($past);
        $this->em->flush();

        $sharedTeamId = $this->uuid();
        $pastCoach = $this->makeCoach($club, $past, 'Ancien', 'Coach');
        $this->linkCoach($club, $past, $sharedTeamId, $pastCoach, TeamCoachRole::MAIN);
        $this->em->flush();

        // L'export de la saison COURANTE ne doit jamais ramasser le coach d'une autre saison.
        $data = $this->load($club, $season);

        self::assertArrayNotHasKey($sharedTeamId, $data->teamMainCoachIds, 'un lien team_coach d’une autre saison n’est jamais lu (scopé club+saison)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->provider = self::getContainer()->get(ScheduleExportDataProvider::class);
    }

    private function load(Club $club, Season $season): ScheduleExportData
    {
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setName('Socle');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version);
        $this->em->flush();

        return $this->provider->load($version);
    }

    private function makeCoach(Club $club, Season $season, string $first, string $last, ?string $id = null): string
    {
        $coach = new Coach;
        if (null !== $id) {
            $coach->setId($id);
        }
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName($first);
        $coach->setLastName($last);
        $this->em->persist($coach);

        return $coach->getId();
    }

    private function linkCoach(Club $club, Season $season, string $teamId, string $coachId, TeamCoachRole $role, ?string $id = null): void
    {
        $link = new TeamCoach;
        if (null !== $id) {
            $link->setId($id);
        }
        $link->setClubId($club->getId());
        $link->setSeasonId($season->getId());
        $link->setTeamId($teamId);
        $link->setCoachId($coachId);
        $link->setRole($role);
        $this->em->persist($link);
    }

    private function uuid(): string
    {
        return \sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF));
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('EXP Coach Club');
        $club->setSlug('exp-coach-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('EXP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
