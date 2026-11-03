<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Coach;
use App\Service\ConflictCoachMailing;
use App\Tests\Integration\Api\ConflictCoachMailApiTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * « Envoyer les conflits aux coachs » — le service UNIQUE qui dit, pour le GET (compteurs du
 * bouton) comme pour le POST (envoi), QUI reçoit QUOI et qui est encore à prévenir. Pur : on lui
 * passe les conflits servis, les coachs principaux par équipe, les groupes mutualisés par version,
 * la mémoire du dernier envoi et les coachs qui existent encore.
 *
 * Club fictif « Les Aiglons » : Karim (principal U15, joue en SM2), Emma (principale SM2),
 * Léa (principale U13B, U13A et U13B partagent une séance), Paul (principal U13A).
 *
 * Le jumeau de bout en bout (envoi réel, collecteur de mail, mémoire en base) :
 * {@see ConflictCoachMailApiTest}.
 */
#[Group('phase1')]
final class ConflictCoachMailingTest extends TestCase
{
    private const string U15 = 'aaaaaaaa-0000-4000-8000-000000000015';
    private const string SM2 = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const string U13A = 'aaaaaaaa-0000-4000-8000-00000000013a';
    private const string U13B = 'aaaaaaaa-0000-4000-8000-00000000013b';
    private const string U11 = 'aaaaaaaa-0000-4000-8000-000000000011';
    private const string SOCLE = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string OTHER_VERSION = 'bbbbbbbb-0000-4000-8000-000000000002';

    private Coach $karim;

    private Coach $emma;

    private Coach $lea;

    private Coach $paul;

    /** Un conflit de personne part à la personne ET aux coachs principaux de chaque côté — une liste par coach. */
    public function testThePersonAndTheMainCoachOfEachSideReceiveTheConflict(): void
    {
        $conflict = $this->matchMatch($this->karim->getId(), self::U15, self::SM2);

        $plan = $this->plan([$conflict]);

        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma->getId()], array_keys($plan['byCoach']));
        self::assertCount(1, $plan['byCoach'][$this->karim->getId()], 'Karim est la personne ET le principal de U15 : il reçoit le conflit UNE fois');
        self::assertCount(1, $plan['byCoach'][$this->emma->getId()]);
        self::assertSame(1, $plan['personConflictCount']);
    }

    /** Une séance de groupe mutualisé touche le principal de CHAQUE équipe du groupe de CETTE version, pas d'une autre. */
    public function testAMutualisedSessionReachesTheMainCoachOfEveryTeamOfItsGroup(): void
    {
        $conflict = $this->matchTraining($this->karim->getId(), self::U15, self::U13A, self::SOCLE);
        $groups = [
            self::SOCLE => [[self::U13A, self::U13B]],
            // La même équipe groupée AILLEURS (autre version) ne doit rien ajouter.
            self::OTHER_VERSION => [[self::U13A, self::U11]],
        ];

        $plan = $this->plan([$conflict], groups: $groups, mains: $this->mains() + [self::U11 => ['cccccccc-0000-4000-8000-0000000000ff']]);

        self::assertEqualsCanonicalizing(
            [$this->karim->getId(), $this->paul->getId(), $this->lea->getId()],
            array_keys($plan['byCoach']),
            'Léa (U13B) partage la séance de U13A : elle reçoit ; le principal de U11, groupé dans une autre version, non',
        );
    }

    /** Seules les trois familles « personne sur un match » partent ; gymnases, liens, niveaux restent à l'écran. */
    public function testOnlyThePersonFamiliesOnAMatchAreMailed(): void
    {
        $unavailable = $this->coachUnavailable($this->emma->getId(), self::SM2);
        $others = [
            ['type' => 'VENUE_OVERLAP', 'severity' => 3, 'left' => $this->side(self::U15, 'HOME', null), 'right' => $this->side(self::SM2, 'HOME', null)],
            ['type' => 'TEAM_LEVEL_MISSING', 'severity' => 6, 'teamId' => self::U15],
            ['type' => 'AWAY_NO_FOOTPRINT', 'severity' => 7, 'fixture' => $this->side(self::U15, 'AWAY', null)],
        ];

        $plan = $this->plan([$unavailable, ...$others]);

        self::assertSame([$this->emma->getId()], array_keys($plan['byCoach']));
        self::assertSame(1, $plan['personConflictCount'], 'seules les familles personne comptent');
    }

    /** Un conflit déjà traité part quand même (le mail est indicatif, sans statut). */
    public function testATreatedConflictIsStillMailed(): void
    {
        $conflict = $this->matchMatch($this->karim->getId(), self::U15, self::SM2)
            + ['resolution' => ['status' => 'RESOLVED_INTERNALLY', 'note' => 'réglé', 'updatedAt' => '2026-11-01T10:00:00+00:00']];

        self::assertSame(1, $this->plan([$conflict])['personConflictCount']);
    }

    /** Une ligne qu'un autre coach tient n'est jamais envoyée : il n'y a rien à signaler. */
    public function testAHeldLineIsNeverMailed(): void
    {
        $held = $this->matchMatch($this->karim->getId(), self::U15, self::SM2) + ['heldBy' => $this->emma->getId(), 'heldSide' => 'right', 'heldByTeamId' => null];

        $plan = $this->plan([$held]);

        self::assertSame([], $plan['byCoach']);
        self::assertSame(0, $plan['personConflictCount']);
    }

    /**
     * À prévenir = mémoire − porteurs d'un conflit − coachs supprimés ; compté seulement avec une
     * adresse valide. Mardi Karim et Léa ont reçu ; jeudi seul le conflit de Karim a disparu.
     */
    public function testClearedIsTheMemoryWithoutConflictAndPendingCountsOnlyReachableCoaches(): void
    {
        $ghost = 'dddddddd-0000-4000-8000-00000000dead';
        $stillLea = $this->matchTraining($this->paul->getId(), self::U15, self::U13A, self::SOCLE);

        $plan = $this->plan(
            [$stillLea],
            groups: [self::SOCLE => [[self::U13A, self::U13B]]],
            memory: [$this->karim->getId(), $this->lea->getId(), $ghost],
        );

        self::assertSame([$this->karim->getId()], $plan['cleared'], 'Léa a encore un conflit, le coach supprimé ne compte plus');
        self::assertSame(1, $plan['pendingRecipientCount']);
    }

    /** Un coach sans adresse (ou adresse invalide) reste à prévenir en mémoire, mais n'active pas le bouton. */
    public function testACoachWithoutAValidAddressIsClearedButNotPending(): void
    {
        $this->karim->setEmail(null);
        $this->emma->setEmail('pas-une-adresse');

        $plan = $this->plan([], memory: [$this->karim->getId(), $this->emma->getId()]);

        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma->getId()], $plan['cleared']);
        self::assertSame(0, $plan['pendingRecipientCount'], 'Karim a retiré son adresse (refus des mails) : le bouton reste désactivé');
        self::assertSame(0, $plan['personConflictCount']);
    }

    /**
     * Règle de mémoire unique : (ancienne ∪ listes enfilées) − « plus aucun » enfilés − supprimés.
     * Mardi Karim a reçu ; jeudi Emma reçoit la liste à jour et Karim « plus aucun » : il sort.
     */
    public function testACoachLeavesTheMemoryOnlyOnceToldThereIsNothingLeft(): void
    {
        $next = $this->service()->nextMemory([$this->karim->getId()], [$this->emma->getId()], [$this->karim->getId()], $this->coaches());

        self::assertSame([$this->emma->getId()], $next);
    }

    /** Le « plus aucun » de Karim n'est pas parti (échec ou sans adresse) : il reste en mémoire. */
    public function testACoachWhoseClearedMailDidNotLeaveStaysInMemory(): void
    {
        $next = $this->service()->nextMemory([$this->karim->getId()], [$this->emma->getId()], [], $this->coaches());

        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma->getId()], $next);
    }

    /**
     * Léa a reçu une liste lundi, sautée mardi (sans adresse) : elle reste en mémoire et recevra
     * « plus aucun conflit » le jour où elle aura une adresse. Cas inverse : jamais reçu, sautée,
     * puis conflit disparu — elle n'entre jamais en mémoire, donc aucun mail.
     */
    public function testASkippedCoachKeepsHerPlaceOnlyIfSheHadAlreadyReceivedAList(): void
    {
        $alreadyTold = $this->service()->nextMemory([$this->lea->getId()], [$this->emma->getId()], [], $this->coaches());
        self::assertEqualsCanonicalizing([$this->lea->getId(), $this->emma->getId()], $alreadyTold);

        $neverTold = $this->service()->nextMemory([], [$this->emma->getId()], [], $this->coaches());
        self::assertSame([$this->emma->getId()], $neverTold);

        // Vendredi : plus aucun conflit. Seule la Léa déjà informée est à prévenir.
        self::assertContains($this->lea->getId(), $this->plan([], memory: $alreadyTold)['cleared']);
        self::assertNotContains($this->lea->getId(), $this->plan([], memory: $neverTold)['cleared']);
    }

    /** Un coach supprimé depuis sort de la mémoire, et la mémoire ne porte jamais de doublon. */
    public function testDeletedCoachesLeaveTheMemoryAndItHoldsNoDuplicate(): void
    {
        $ghost = 'dddddddd-0000-4000-8000-00000000dead';

        $next = $this->service()->nextMemory([$this->karim->getId(), $ghost], [$this->karim->getId(), $this->emma->getId()], [], $this->coaches());

        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma->getId()], $next);
        self::assertSame($next, array_values(array_unique($next)));
    }

    protected function setUp(): void
    {
        $this->karim = $this->coach('Karim', 'B.', 'karim@aiglons.test');
        $this->emma = $this->coach('Emma', 'D.', 'emma@aiglons.test');
        $this->lea = $this->coach('Léa', 'R.', 'lea@aiglons.test');
        $this->paul = $this->coach('Paul', 'M.', 'paul@aiglons.test');
    }

    private function service(): ConflictCoachMailing
    {
        return new ConflictCoachMailing;
    }

    /**
     * @param list<array<string, mixed>>        $conflicts
     * @param array<string, list<list<string>>> $groups
     * @param list<string>                      $memory
     * @param array<string, list<string>>|null  $mains
     *
     * @return array{byCoach: array<string, list<array<string, mixed>>>, cleared: list<string>, pendingRecipientCount: int, personConflictCount: int}
     */
    private function plan(array $conflicts, array $groups = [], array $memory = [], ?array $mains = null): array
    {
        return $this->service()->plan($conflicts, $mains ?? $this->mains(), $groups, $memory, $this->coaches());
    }

    /** @return array<string, list<string>> */
    private function mains(): array
    {
        return [
            self::U15 => [$this->karim->getId()],
            self::SM2 => [$this->emma->getId()],
            self::U13A => [$this->paul->getId()],
            self::U13B => [$this->lea->getId()],
        ];
    }

    /** @return array<string, Coach> */
    private function coaches(): array
    {
        $byId = [];
        foreach ([$this->karim, $this->emma, $this->lea, $this->paul] as $coach) {
            $byId[$coach->getId()] = $coach;
        }

        return $byId;
    }

    private function coach(string $first, string $last, ?string $email): Coach
    {
        return new Coach()->setFirstName($first)->setLastName($last)->setEmail($email);
    }

    /** @return array<string, mixed> */
    private function matchMatch(string $personId, string $leftTeam, string $rightTeam): array
    {
        return [
            'type' => 'MATCH_MATCH',
            'severity' => 3,
            'coachId' => $personId,
            'coachRole' => 'PLAYER',
            'start' => '2026-03-14 16:00',
            'end' => '2026-03-14 17:25',
            'left' => $this->side($leftTeam, 'HOME', 'MAIN'),
            'right' => $this->side($rightTeam, 'AWAY', 'PLAYER'),
            'fingerprint' => 'MATCH_MATCH:' . $personId,
        ];
    }

    /** @return array<string, mixed> */
    private function matchTraining(string $personId, string $matchTeam, string $trainingTeam, string $scheduleId): array
    {
        return [
            'type' => 'MATCH_TRAINING',
            'severity' => 4,
            'coachId' => $personId,
            'coachRole' => 'MAIN',
            'start' => '2026-03-13 18:30',
            'end' => '2026-03-13 19:00',
            'fixture' => $this->side($matchTeam, 'HOME', 'MAIN'),
            'training' => [
                'slotTemplateId' => 'eeeeeeee-0000-4000-8000-000000000001',
                'scheduleId' => $scheduleId,
                'teamId' => $trainingTeam,
                'venueId' => 'ffffffff-0000-4000-8000-000000000001',
                'dayOfWeek' => 5,
                'startTime' => '18:30',
                'durationMinutes' => 90,
                'role' => 'MAIN',
                'windowStart' => '2026-03-13 18:30',
                'windowEnd' => '2026-03-13 20:00',
            ],
            'fingerprint' => 'MATCH_TRAINING:' . $personId,
        ];
    }

    /** @return array<string, mixed> */
    private function coachUnavailable(string $personId, string $team): array
    {
        return [
            'type' => 'MATCH_COACH_UNAVAILABLE',
            'severity' => 5,
            'coachId' => $personId,
            'coachRole' => 'MAIN',
            'fixture' => $this->side($team, 'HOME', 'MAIN'),
            'fingerprint' => 'MATCH_COACH_UNAVAILABLE:' . $personId,
        ];
    }

    /** @return array<string, mixed> */
    private function side(string $teamId, string $homeAway, ?string $role): array
    {
        $side = [
            'fixtureId' => 'ffffffff-1111-4000-8000-' . substr(md5($teamId . $homeAway), 0, 12),
            'teamId' => $teamId,
            'homeAway' => $homeAway,
            'matchDate' => '2026-03-14',
            'kickoffTime' => '15:30',
            'estimatedKickoff' => false,
            'estimatedKickoffTime' => null,
            'travelOneWayMinutes' => null,
            'matchDurationMinutes' => 115,
            'opponentLabel' => 'Les Loups',
            'windowStart' => '2026-03-14 15:00',
            'windowEnd' => '2026-03-14 17:25',
        ];
        if (null !== $role) {
            $side['role'] = $role;
        }

        return $side;
    }
}
