<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * PR 6 (lot 8) — NR BLOQUANT (axe §7.1 « constraint semantics ») : « Garder ses créneaux de saison »
 * est HONORÉ par le solveur. La contrainte SEASON_SLOTS ne part PAS au moteur comme contrainte : son
 * effet voyage par les placements de socle émis en `socleReferenceAssignments` avec `keep: true`
 * (parité backend prouvée par `SocleReferencePayloadParityTest`). Ce gate tient l'AUTRE bout — le
 * VRAI moteur `/generate` (contrat 1.5) garde effectivement le créneau — avec DEUX promesses, chacune
 * falsifiable :
 *
 *  (1) TÉMOIN — une équipe qui PRÉFÈRE (souple) un autre jour garde quand même son créneau de saison
 *      (jour+heure+gymnase) grâce au keep : la préférence très forte prime une simple préférence. SANS
 *      le keep, le MÊME payload la pose sur son jour préféré (le témoin tombe).
 *  (2) CONTRE-TÉMOIN (Q3bis) — « placer les séances reste prioritaire » : quand une AUTRE équipe n'a
 *      AUCUNE autre place que le créneau de saison, le keep CÈDE (l'équipe de saison bouge sur un
 *      créneau libre, l'autre est placée). Le keep ne supprime jamais une séance.
 *
 * Le payload est construit par le VRAI builder puis le keep y est greffé (`withSeasonSlotKeepReferences`)
 * comme le fait le handler en génération pleine. Étape NOMMÉE du job `blocking-tests` de
 * `.github/workflows/ci.yml` ET ligne de `docs/testing/blocking-tests.md` (gardé par
 * `BlockingTestsListMatchesCiTest`). Groupe `phase1` (--group phase1) + `contract` (job
 * engine-semantics). Skip propre si l'engine est absent.
 */
#[Group('phase1')]
#[Group('contract')]
final class SeasonSlotKeepSemanticsGateTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private const string SPORT_CATEGORY_ID = '99999999-9999-4999-8999-999999999999';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ?CacheItemPoolInterface $scheduleCache = null;

    public function testKeepHoldsTheSeasonSlotAgainstASoftPreference(): void
    {
        [$club, $season] = $this->seed('keep-wins');
        $venue = $this->venue($club, $season, 'Gymnase Matéo'); // non divisible
        $this->trainingSlot($club, $season, $venue, 1, '18:00'); // créneau de SAISON : lundi 18:00
        $this->trainingSlot($club, $season, $venue, 3, '18:00'); // créneau PRÉFÉRÉ : mercredi 18:00
        $team = $this->team($club, $season, 'SM1');
        // Préférence SOUPLE pour le mercredi : sans keep, l'équipe y va.
        $this->preferredDay($club, $season, $team->getId(), 3);
        $this->em->flush();

        $keepRef = [['teamId' => $team->getId(), 'dayOfWeek' => 1, 'startTime' => '18:00:00', 'venueId' => $venue->getId(), 'keep' => true]];

        // TÉMOIN — sans keep : la préférence mercredi gagne.
        $witness = $this->solve($this->build($club, $season));
        self::assertSame(3, $this->dayOfTeam($witness, $team->getId()), 'témoin cassé : sans keep, l\'équipe doit suivre sa préférence (mercredi)');

        // AVEC keep (lundi 18:00 @ gymnase de saison) : le créneau de saison tient contre la préférence.
        $kept = $this->solve($this->withKeep($this->build($club, $season), $keepRef));
        self::assertSame(1, $this->dayOfTeam($kept, $team->getId()), 'le keep doit garder le créneau de saison (lundi) malgré la préférence mercredi');
        self::assertSame($venue->getId(), $this->venueOfTeam($kept, $team->getId()), 'le keep plein garde aussi le gymnase de saison');
    }

    public function testPlacingASessionTakesPriorityOverKeeping(): void
    {
        [$club, $season] = $this->seed('placing-wins');
        $venue = $this->venue($club, $season, 'Gymnase Matéo'); // non divisible, chaque case capacité 1
        $this->trainingSlot($club, $season, $venue, 1, '18:00'); // créneau de saison de SM1 — et SEULE case des U13
        $this->trainingSlot($club, $season, $venue, 3, '18:00'); // repli libre pour SM1
        $sm1 = $this->team($club, $season, 'SM1');
        $u13 = $this->team($club, $season, 'U13');
        // U13 ne peut aller QUE le lundi (liste blanche HARD) : sa seule place est le créneau de saison de SM1.
        $this->allowedDaysHard($club, $season, $u13->getId(), [1]);
        $this->em->flush();

        $keepRef = [['teamId' => $sm1->getId(), 'dayOfWeek' => 1, 'startTime' => '18:00:00', 'venueId' => $venue->getId(), 'keep' => true]];

        $result = $this->solve($this->withKeep($this->build($club, $season), $keepRef));

        // Les deux équipes sont placées — placer une séance prime le keep (Q3bis).
        self::assertSame(1, $this->dayOfTeam($result, $u13->getId()), 'U13 (sans autre place) doit prendre le créneau de saison : placer une séance prime');
        self::assertSame(3, $this->dayOfTeam($result, $sm1->getId()), 'SM1 cède son créneau de saison (repli mercredi) pour que U13 soit placée');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $pool = self::getContainer()->get('cache.schedule');
        $this->scheduleCache = $pool instanceof CacheItemPoolInterface ? $pool : null;
    }

    /** @return array<string, mixed> */
    private function build(Club $club, Season $season): array
    {
        $this->scheduleCache?->clear();

        return $this->builder->buildForClubSeason($club->getId(), $season->getId());
    }

    /**
     * Greffe le bloc keep comme le fait le handler en génération pleine (après le hash) — on l'injecte
     * ici directement pour exercer la SÉMANTIQUE du moteur, la parité d'émission étant gardée ailleurs.
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $keepRef
     *
     * @return array<string, mixed>
     */
    private function withKeep(array $payload, array $keepRef): array
    {
        $payload['socleReferenceAssignments'] = $keepRef;

        return $payload;
    }

    /** @param array<string, mixed> $result */
    private function dayOfTeam(array $result, string $teamId): ?int
    {
        foreach ($result['slots'] as $slot) {
            if (($slot['teamId'] ?? null) === $teamId) {
                return (int) $slot['dayOfWeek'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $result */
    private function venueOfTeam(array $result, string $teamId): ?string
    {
        foreach ($result['slots'] as $slot) {
            if (($slot['teamId'] ?? null) === $teamId) {
                return (string) $slot['venueId'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $result = $response->toArray(false);
            self::assertSame('completed', $result['status'], 'le scénario de génération doit rester résoluble');

            return $result;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    private function trainingSlot(Club $club, Season $season, Venue $venue, int $day, string $start): void
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setVenueId($venue->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
            ->setSportCategoryId(self::SPORT_CATEGORY_ID)->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }

    private function preferredDay(Club $club, Season $season, string $teamId, int $day): void
    {
        $this->constraint($club, $season, $teamId, ConstraintFamily::DAY, ConstraintRuleType::PREFERRED, ['preferredDays' => [$day]]);
    }

    private function allowedDaysHard(Club $club, Season $season, string $teamId, array $days): void
    {
        $this->constraint($club, $season, $teamId, ConstraintFamily::DAY, ConstraintRuleType::HARD, ['allowedDays' => $days]);
    }

    /** @param array<string, mixed> $config */
    private function constraint(Club $club, Season $season, string $teamId, ConstraintFamily $family, ConstraintRuleType $ruleType, array $config): void
    {
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('règle de test')->setScope(ConstraintScope::TEAM)->setScopeTargetId($teamId)
            ->setFamily($family)->setRuleType($ruleType)->setConfig($config);
        $this->em->persist($constraint);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(string $prefix): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Season Slot Keep Gate Club');
        $club->setSlug($prefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SSK' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('season-slot-gate-' . $uid . '@test.com');
        $user->setFirstName('S');
        $user->setLastName('K');
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
        $this->em->flush();

        return [$club, $season];
    }
}
