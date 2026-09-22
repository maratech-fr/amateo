<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\ReservationGroupOccupancy;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * NR BLOQUANT — axe « constraint semantics » (§7.1). PARITÉ du REPLI D'OCCUPANT sur un cas de blocs
 * IMBRIQUÉS, entre le compteur BACKEND ({@see ReservationGroupOccupancy::occupantCount}) et le repli
 * MOTEUR (``_fold_case_occupant_identity``, exercé ici par le VRAI moteur).
 *
 * La règle « un bloc entièrement présent sur une case = UN occupant » est écrite DES DEUX CÔTÉS de la
 * frontière. Quand un bloc en CONTIENT un autre (imbrication), il faut élire le bloc MAXIMAL, de façon
 * DÉTERMINISTE (taille décroissante, puis la clé). Avant ce lot, le moteur repliait « le premier bloc
 * par la clé » (un bloc de 2 l'emportait sur un bloc de 3 par l'alphabet → 2 occupants) et le backend
 * repliait « sans ordre garanti » (la requête n'a pas de tri → l'ordre dépend de la base) : même case
 * physique, le backend pouvait la juger LIBRE (1 occupant) et le moteur PLEINE (2), et le backend se
 * contredire d'un appel à l'autre. Ce test épingle l'accord : les deux comptent le bloc MAXIMAL.
 *
 * Même régime que {@see CapacityMirrorParityTest} : moteur réel sur le réseau docker, skip propre s'il
 * est indisponible (le groupe ``contract`` tourne là où le moteur existe).
 */
#[Group('contract')]
final class NestedBlockOccupantFoldParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private EntityManagerInterface $em;

    public function testNestedBlocksFoldToTheMaximalBlockOnBothSides(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season);
        $t2 = $this->team($club, $season);
        $t3 = $this->team($club, $season);
        $this->em->flush();

        // Deux blocs IMBRIQUÉS de socle : la paire {t1,t2} ⊂ le trio {t1,t2,t3}. Multi-appartenance
        // permise (t1/t2 dans les deux). Les trois sont co-présents sur UNE case cap 1.
        $this->blockOfIds($club, $season, [$t1->getId(), $t2->getId()], 1);
        $this->blockOfIds($club, $season, [$t1->getId(), $t2->getId(), $t3->getId()], 1);
        $this->em->flush();

        // ── CÔTÉ BACKEND — le compteur d'occupants élit le bloc MAXIMAL → UN occupant. Sans le tri
        //    déterministe (taille décroissante) et le bon critère de recouvrement, il comptait 2 (la
        //    paire + t3 isolé), selon l'ordre non garanti de la base.
        $occupancy = self::getContainer()->get(ReservationGroupOccupancy::class);
        $occupantCount = new ReflectionMethod($occupancy, 'occupantCount');
        $occupantCount->setAccessible(true);
        $teamSet = [$t1->getId() => true, $t2->getId() => true, $t3->getId() => true];
        $backendOccupants = $occupantCount->invoke($occupancy, $teamSet, null);
        self::assertSame(
            1,
            $backendOccupants,
            'le backend doit fondre le bloc MAXIMAL {t1,t2,t3} en UN occupant (repli déterministe), '
            . 'pas compter la paire + t3 selon l\'ordre de la base',
        );

        // ── CÔTÉ MOTEUR — les trois HARD-épinglés ENSEMBLE sur l'unique case cap 1 (V1/lun/17:30),
        //    avec les MÊMES blocs imbriqués. Le repli élit le bloc de 3 → UN occupant → AUCUN
        //    diagnostic de sur-capacité sur la case. Sous l'ancien repli par la clé, la paire élue par
        //    l'alphabet laissait t3 isolé → 2 > 1 → faux `diag-conflict-venue`.
        $engine = $this->solveNestedPinnedCase();
        self::assertSame(
            'completed',
            $engine['status'],
            'le comblement du cas imbriqué doit ABOUTIR (parité avec test_nested_fully_pinned_blocks)',
        );
        $conflictIds = array_filter(
            array_column($engine['diagnostics'] ?? [], 'id'),
            static fn (string $id): bool => str_starts_with($id, 'diag-conflict-venue'),
        );
        self::assertSame(
            [],
            array_values($conflictIds),
            'PARITÉ ROMPUE : le moteur crie une sur-capacité sur la case imbriquée — son repli d\'occupant '
            . 'n\'élit pas le bloc MAXIMAL. Aligner _fold_case_occupant_identity ET ReservationGroupOccupancy.',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Le VRAI moteur sur le cas des blocs imbriqués toute-épinglés (réplique de
     * ``engine/tests/semantic/test_fill_pinned_block_partner.py``) : t1/t2 (2 séances) et t3 (1)
     * épinglés HARD ensemble sur V1/lun/17:30 (cap 1) ; V2/V3 offrent les cases libres pour le budget
     * de la paire. Blocs {t1,t2} et {t1,t2,t3}, commonSessions=1 chacun.
     *
     * @return array<string, mixed>
     */
    private function solveNestedPinnedCase(): array
    {
        $team = static fn (string $id, int $sessions): array => [
            'id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat-1',
            'priorityTierId' => 3, 'sessionsPerWeek' => $sessions, 'isActive' => true,
        ];
        $venue = static fn (string $id, int $day): array => [
            'id' => $id, 'name' => $id, 'isActive' => true,
            'trainingSlots' => [['dayOfWeek' => $day, 'startTime' => '17:30', 'durationMinutes' => 90, 'capacity' => 1]],
        ];
        $pin = static fn (string $teamId): array => [
            'id' => 'pin-' . $teamId, 'teamId' => $teamId, 'venueId' => 'V1', 'coachId' => null,
            'dayOfWeek' => 1, 'startTime' => '17:30', 'durationMinutes' => 90,
            'lockLevel' => 'HARD', 'pendingConstraintSuggestion' => null,
        ];
        $payload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-nested-parity',
            'seasonId' => 'season-nested-parity',
            'solverSeed' => 42,
            'teams' => [$team('t1', 2), $team('t2', 2), $team('t3', 1)],
            'venues' => [$venue('V1', 1), $venue('V2', 3), $venue('V3', 5)],
            'coaches' => [],
            'constraints' => [],
            'slotTemplates' => [$pin('t1'), $pin('t2'), $pin('t3')],
            'sharedBlocks' => [
                ['id' => 'b2', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1],
                ['id' => 'b3', 'teamIds' => ['t1', 't2', 't3'], 'commonSessions' => 1],
            ],
        ];

        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());

            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    private function team(Club $club, Season $season): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->uuid());
        $team->setPriorityTierId(3);
        $team->setName('T' . substr($this->uuid(), 0, 6));
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    /**
     * Un bloc de SOCLE (schedulePlanId null) sur des identités d'équipe déjà persistées.
     *
     * @param list<string> $teamIds
     */
    private function blockOfIds(Club $club, Season $season, array $teamIds, int $commonSessions): SharedTrainingBlock
    {
        $block = new SharedTrainingBlock;
        $block->setClubId($club->getId());
        $block->setSeasonId($season->getId());
        $block->setSchedulePlanId(null);
        $block->setCommonSessions($commonSessions);
        $this->em->persist($block);

        foreach ($teamIds as $teamId) {
            $member = new SharedTrainingBlockTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setSchedulePlanId(null);
            $member->setBlockId($block->getId());
            $member->setTeamId($teamId);
            $this->em->persist($member);
        }

        return $block;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Nested Block Parity Club');
        $club->setSlug('nested-block-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('NBP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('nested-block-parity-' . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
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
