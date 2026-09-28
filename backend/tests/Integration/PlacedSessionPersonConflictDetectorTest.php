<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\CoachDoubleBookingDetector;
use App\Service\PlacedSessionPersonConflictDetector;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P4-269 — « une personne à deux endroits en même temps » sur le planning
 * d'entraînement EN VIGUEUR (version pointée du plan SEASON). Cette détection
 * n'existait pas APRÈS génération : {@see CoachDoubleBookingDetector}
 * n'est consommé que pré-solve. Un simple lien (coach adjoint ajouté tard, joueur
 * lié) peut mettre une personne sur deux séances déjà placées qui se chevauchent.
 *
 * La règle de collision est CELLE du pré-solve, réutilisée sans être réécrite
 * ({@see CoachDoubleBookingDetector::bookingsCollide}) : personne
 * identique, équipes DIFFÉRENTES, gymnases DIFFÉRENTS (même gymnase = mutualisation),
 * même jour, intervalles réels demi-ouverts. L'ÉLARGISSEMENT (assumé) est ici :
 * personnes = MAIN, ASSISTANT ET joueur — pas seulement le MAIN du solveur.
 *
 * ⚠ Chaque cas négatif ne diffère du cas positif QUE par la variable testée (même
 * club, saison, équipes, jour) : un cas qui rougirait pour un motif étranger
 * rougirait aussi le témoin positif.
 *
 * PREUVES DE CHUTE (2026-09-28) :
 *  - inclusion des ASSISTANT retirée (MAIN seul dans `sessionCoachesByTeam`) →
 *    {@see self::testAssistantOnSecondTeamIsDetected} rougit seul ;
 *  - inclusion des joueurs retirée (`$playersByTeam` ignoré) →
 *    {@see self::testPlayerWhoAlsoPlaysElsewhereIsDetected} rougit seul ;
 *  - repli `slot.coachId ?? coachs de l'équipe` neutralisé (toujours les coachs de
 *    l'équipe) → {@see self::testSlotOwnCoachOverridesTeamCoaches} rougit seul.
 * Règles remises → tout vert.
 */
#[Group('integration')]
final class PlacedSessionPersonConflictDetectorTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private PlacedSessionPersonConflictDetector $detector;

    /** Cas positif nominal : MAIN des deux équipes, chevauchement, deux gymnases. */
    public function testMainCoachOnTwoTeamsOverlappingInTwoGymsIsDetected(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:00', 90);

        $result = $this->detector->detect($ctx['clubId'], $ctx['seasonId']);

        self::assertTrue($result['seasonPlanChosen']);
        self::assertCount(1, $result['conflicts']);
        $conflict = $result['conflicts'][0];
        self::assertSame($ctx['coach'], $conflict['personId']);
        self::assertSame('Anna Dupont', $conflict['personName']);
        self::assertSame(2, $conflict['dayOfWeek']);
        // Les deux côtés portent équipe + gymnase + heure — sans quoi l'affichage
        // « Anna à deux endroits » n'a rien à montrer.
        $teams = [$conflict['first']['teamName'], $conflict['second']['teamName']];
        self::assertContains('U13F', $teams);
        self::assertContains('U11M1', $teams);
        $gyms = [$conflict['first']['venueName'], $conflict['second']['venueName']];
        self::assertContains('Gymnase A', $gyms);
        self::assertContains('Gymnase B', $gyms);
        self::assertSame('18h00', $conflict['first']['startTime']);
    }

    /** L'ASSISTANT compte AUSSI (élargissement vs pré-solve) — l'exemple fondateur. */
    public function testAssistantOnSecondTeamIsDetected(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::ASSISTANT);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:00', 90);

        self::assertCount(1, $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts']);
    }

    /** Retirer le lien fait disparaître le conflit : la détection suit les liens COURANTS. */
    public function testRemovingTheLinkClearsTheConflict(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $second = $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::ASSISTANT);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:00', 90);

        self::assertCount(1, $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts'], 'témoin : le conflit existe tant que le lien est là');

        $this->em->remove($second);
        $this->em->flush();

        self::assertSame([], $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts'], 'lien retiré → plus de personne présente aux deux séances → plus de conflit');
    }

    /** MÊME gymnase = mutualisation voulue, jamais un conflit. */
    public function testSameGymIsMutualisationNotAConflict(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym1'], 2, '18:00', 90);

        self::assertSame([], $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts']);
    }

    /** Créneaux ADJACENTS (fin de l'un = début de l'autre) : demi-ouverts, aucun chevauchement. */
    public function testAdjacentSessionsDoNotConflict(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '17:00', 90); // → 18h30
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:30', 90);

        self::assertSame([], $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts']);
    }

    /** Même chevauchement, jours DIFFÉRENTS. */
    public function testDifferentDaysDoNotConflict(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 3, '18:00', 90);

        self::assertSame([], $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts']);
    }

    /** Un JOUEUR (membership actif « joue aussi dans ») est détecté comme une personne présente. */
    public function testPlayerWhoAlsoPlaysElsewhereIsDetected(): void
    {
        $ctx = $this->seed();
        // Anna coache A, et JOUE dans B — deux séances qui se chevauchent, deux gymnases.
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->playIn($ctx, $ctx['teamB'], $ctx['coach'], true);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:00', 90);

        self::assertCount(1, $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts']);
    }

    /** Un membership joueur INACTIF ne compte pas (la personne ne joue plus là). */
    public function testInactivePlayerMembershipIsIgnored(): void
    {
        $ctx = $this->seed();
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->playIn($ctx, $ctx['teamB'], $ctx['coach'], false);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:00', 90);

        self::assertSame([], $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts']);
    }

    /** Le coach DÉSIGNÉ de la séance (slot.coachId) prime sur les coachs de l'équipe (repli planning). */
    public function testSlotOwnCoachOverridesTeamCoaches(): void
    {
        $ctx = $this->seed();
        $bob = $this->createCoach($ctx, 'Bob');
        // Anna est MAIN des DEUX équipes, mais la séance de B est explicitement confiée à Bob.
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->place($ctx, $ctx['teamA'], $ctx['gym1'], 2, '18:00', 90);
        $this->place($ctx, $ctx['teamB'], $ctx['gym2'], 2, '18:00', 90, $bob);

        self::assertSame([], $this->detector->detect($ctx['clubId'], $ctx['seasonId'])['conflicts'], 'la séance de B est à Bob : Anna n\'y est pas, malgré son lien MAIN');

        // Témoin : sans coach désigné, le repli tombe sur les coachs de l'équipe (Anna) → conflit.
        $ctx2 = $this->seed();
        $this->link($ctx2, $ctx2['teamA'], $ctx2['coach'], TeamCoachRole::MAIN);
        $this->link($ctx2, $ctx2['teamB'], $ctx2['coach'], TeamCoachRole::MAIN);
        $this->place($ctx2, $ctx2['teamA'], $ctx2['gym1'], 2, '18:00', 90);
        $this->place($ctx2, $ctx2['teamB'], $ctx2['gym2'], 2, '18:00', 90);
        self::assertCount(1, $this->detector->detect($ctx2['clubId'], $ctx2['seasonId'])['conflicts']);
    }

    /** Aucune version pointée → pas de planning en vigueur : seasonPlanChosen false, rien scanné. */
    public function testNoChosenVersionMeansNoScan(): void
    {
        $ctx = $this->seed(chooseVersion: false);
        $this->link($ctx, $ctx['teamA'], $ctx['coach'], TeamCoachRole::MAIN);
        $this->link($ctx, $ctx['teamB'], $ctx['coach'], TeamCoachRole::MAIN);

        $result = $this->detector->detect($ctx['clubId'], $ctx['seasonId']);
        self::assertFalse($result['seasonPlanChosen']);
        self::assertSame([], $result['conflicts']);
    }

    /** Les séances d'un AUTRE club ne fuient jamais : chaque club ne voit que son planning. */
    public function testForeignClubSessionsDoNotLeak(): void
    {
        // Club B a un vrai conflit ...
        $ctxB = $this->seed();
        $this->link($ctxB, $ctxB['teamA'], $ctxB['coach'], TeamCoachRole::MAIN);
        $this->link($ctxB, $ctxB['teamB'], $ctxB['coach'], TeamCoachRole::MAIN);
        $this->place($ctxB, $ctxB['teamA'], $ctxB['gym1'], 2, '18:00', 90);
        $this->place($ctxB, $ctxB['teamB'], $ctxB['gym2'], 2, '18:00', 90);
        self::assertCount(1, $this->detector->detect($ctxB['clubId'], $ctxB['seasonId'])['conflicts'], 'témoin : B a bien son conflit');

        // ... club A n'a rien, et ne voit jamais celui de B.
        $ctxA = $this->seed();
        $this->scopeGucToClub($ctxA['clubId']);
        self::assertSame([], $this->detector->detect($ctxA['clubId'], $ctxA['seasonId'])['conflicts']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->detector = self::getContainer()->get(PlacedSessionPersonConflictDetector::class);
    }

    /**
     * Deux équipes (U13F, U11M1), deux gymnases (A, B), un coach Anna, une saison dont
     * le plan SEASON POINTE une version (sauf variante) — c'est cette version qui est
     * scannée. Le contexte est identique pour tous les cas.
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string|null, teamA: string, teamB: string, gym1: string, gym2: string, coach: string}
     */
    private function seed(bool $chooseVersion = true): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('pspc-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $gymIds = [];
        foreach (['Gymnase A', 'Gymnase B'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $gymIds[] = $venue->getId();
        }

        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . $suffix)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U11')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();
        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $teamIds = [];
        foreach (['U13F', 'U11M1'] as $name) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())->setPriorityTierId($tier->getId())->setName($name)->setSessionsPerWeek(2);
            $this->em->persist($team);
            $this->em->flush();
            $teamIds[] = $team->getId();
        }

        $scheduleId = null;
        if ($chooseVersion) {
            // Le plan SEASON pointe une version : c'est « le planning en vigueur ».
            $schedule = $this->settleSeasonPlan($season);
            $scheduleId = $schedule->getId();
        } else {
            $this->provisionSeasonPlan($season); // plan présent, mais aucune version pointée
        }

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $scheduleId,
            'teamA' => $teamIds[0], 'teamB' => $teamIds[1],
            'gym1' => $gymIds[0], 'gym2' => $gymIds[1],
            'coach' => $this->createCoach(['clubId' => $clubId, 'seasonId' => $seasonId], 'Anna'),
        ];
    }

    /**
     * @param array{clubId: string, seasonId: string} $ctx
     */
    private function createCoach(array $ctx, string $firstName): string
    {
        $coach = (new Coach)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setFirstName($firstName)->setLastName('Dupont');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach->getId();
    }

    /**
     * @param array{clubId: string, seasonId: string} $ctx
     */
    private function link(array $ctx, string $teamId, string $coachId, TeamCoachRole $role): TeamCoach
    {
        $link = (new TeamCoach)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTeamId($teamId)->setCoachId($coachId)->setRole($role);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    /**
     * @param array{clubId: string, seasonId: string} $ctx
     */
    private function playIn(array $ctx, string $teamId, string $coachId, bool $active): void
    {
        $membership = (new CoachPlayerMembership)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTeamId($teamId)->setCoachId($coachId)->setIsActive($active);
        $this->em->persist($membership);
        $this->em->flush();
    }

    /**
     * @param array{clubId: string, seasonId: string, scheduleId: string|null} $ctx
     */
    private function place(array $ctx, string $teamId, string $venueId, int $dayOfWeek, string $start, int $minutes, ?string $coachId = null): void
    {
        \assert(null !== $ctx['scheduleId']);
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setScheduleId($ctx['scheduleId'])
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setCoachId($coachId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes($minutes);
        $this->em->persist($slot);
        $this->em->flush();
    }
}
