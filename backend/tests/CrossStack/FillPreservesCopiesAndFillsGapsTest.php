<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Service\ScheduleConstraintBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P2-44 PR-3 (ADR-0004) — LE COMBLEMENT prouvé de bout en bout, sur le VRAI moteur.
 *
 * « Tout ce qui est déjà placé reste EXACTEMENT en place, seules les séances à replacer sont
 * placées par le solveur. » Le mécanisme n'exige AUCUN changement moteur : le comblement injecte
 * les placements de la version source comme épingles HARD dans le payload
 * ({@see ScheduleConstraintBuilder::withPinnedAssignments}), et un HARD n'a pas de variable côté
 * solveur — il fige la séance sans la départager.
 *
 * La grille est construite pour que le solveur PENCHE contre le placement copié : une équipe à
 * DEUX séances, trois créneaux (lundi/mercredi/vendredi), et une préférence SOUPLE pour
 * mercredi+vendredi. Sans épingle, le solveur remplit mercredi+vendredi — lundi reste VIDE. Avec
 * l'épingle du lundi (le placement « copié du socle »), le lundi tient malgré la préférence
 * inverse (preuve que l'épingle est SOUVERAINE, pas un heureux hasard), et le solveur COMBLE le
 * trou restant (la 2ᵉ séance) sur un créneau libre.
 *
 * Falsification : si le comblement DÉPLAÇAIT le placement copié, le lundi 18:00 disparaîtrait du
 * résultat — l'assertion « intact » rougit, nommément. Si l'épingle n'était pas souveraine, le
 * témoin (sans épingle, lundi vide) tomberait.
 *
 * Tourne dans le job CI « Engine semantics » (groupe `contract`), moteur réel ; skip propre s'il
 * est indisponible, comme les autres tests cross-stack sémantiques.
 */
#[Group('contract')]
final class FillPreservesCopiesAndFillsGapsTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';
    private const string TEAM = 't-fill';
    private const string VENUE = 'v-fill';
    private const int MONDAY = 1;
    private const int WEDNESDAY = 3;
    private const int FRIDAY = 5;
    private const string SLOT_TIME = '18:00';

    /**
     * Le cœur : l'épingle du placement copié tient (INTACTE), et le trou est comblé.
     */
    public function testTheCopiedPlacementStaysIntactAndTheGapIsFilled(): void
    {
        $withPin = $this->solve($this->pinnedPayload());
        self::assertSame('completed', $withPin['status'], 'le comblement doit rester résoluble');

        $slots = $withPin['slots'];
        self::assertCount(2, $slots, 'le comblement place les 2 séances de l\'équipe : l\'épingle + le trou comblé');

        self::assertTrue(
            $this->occupiesMonday($slots),
            'le placement COPIÉ (lundi 18:00) a été déplacé par le comblement — il devait rester EXACTEMENT en place',
        );
    }

    /**
     * Le TÉMOIN : sans l'épingle, le solveur suit la préférence souple et évite le lundi. C'est ce
     * qui prouve que, avec l'épingle, c'est bien ELLE qui tient le lundi — pas le hasard.
     */
    public function testWithoutThePinTheSolverAvoidsThatSlot(): void
    {
        $withoutPin = $this->solve($this->basePayload());
        self::assertSame('completed', $withoutPin['status']);

        self::assertFalse(
            $this->occupiesMonday($withoutPin['slots']),
            'témoin cassé : sans épingle, le solveur occupe déjà le lundi — le scénario ne prouverait pas que l\'épingle le tient',
        );
    }

    /**
     * Un créneau du résultat occupe-t-il EXACTEMENT le placement copié (équipe + gymnase + jour +
     * heure lundi 18:00) ? C'est la définition de « intact ».
     *
     * @param list<array<string, mixed>> $slots
     */
    private function occupiesMonday(array $slots): bool
    {
        foreach ($slots as $slot) {
            if (self::TEAM === ($slot['teamId'] ?? null)
                && self::VENUE === ($slot['venueId'] ?? null)
                && self::MONDAY === ($slot['dayOfWeek'] ?? null)
                && str_starts_with((string) ($slot['startTime'] ?? ''), self::SLOT_TIME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le payload d'overlay AVEC l'épingle du placement copié : on part du payload de base et on
     * applique la MÊME greffe que le handler en mode comblement. Prouver l'effet passe donc par le
     * vrai code de production ({@see ScheduleConstraintBuilder::withPinnedAssignments}), pas par un
     * payload recopié à la main.
     *
     * @return array<string, mixed>
     */
    private function pinnedPayload(): array
    {
        $builder = new ScheduleConstraintBuilder(new NullLogger);

        return $builder->withPinnedAssignments($this->basePayload(), [$this->copiedSlot()]);
    }

    /**
     * Le placement « copié du socle » qu'on fige : l'équipe, lundi 18:00 au gymnase. En mémoire
     * (aucune DB) — `withPinnedAssignments` ne lit que ses champs via `serializeSlotTemplate`.
     */
    private function copiedSlot(): ScheduleSlotTemplate
    {
        return (new ScheduleSlotTemplate)
            ->setClubId('club-fill')
            ->setSeasonId('season-fill')
            ->setScheduleId('source-fill')
            ->setTeamId(self::TEAM)
            ->setVenueId(self::VENUE)
            ->setDayOfWeek(self::MONDAY)
            ->setStartTime(new DateTimeImmutable(self::SLOT_TIME))
            ->setDurationMinutes(90)
            ->setLockLevel(LockLevel::HARD);
    }

    /**
     * La grille : une équipe à 2 séances, 3 créneaux (lun/mer/ven 18:00), une préférence SOUPLE
     * pour mercredi+vendredi. Sans épingle → mer+ven, lundi vide.
     *
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-fill',
            'seasonId' => 'season-fill',
            'solverSeed' => 42,
            'teams' => [
                ['id' => self::TEAM, 'name' => 'FILL', 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 2],
            ],
            'venues' => [
                [
                    'id' => self::VENUE, 'name' => 'V-FILL',
                    'trainingSlots' => [
                        ['dayOfWeek' => self::MONDAY, 'startTime' => self::SLOT_TIME, 'durationMinutes' => 90, 'capacity' => 1],
                        ['dayOfWeek' => self::WEDNESDAY, 'startTime' => self::SLOT_TIME, 'durationMinutes' => 90, 'capacity' => 1],
                        ['dayOfWeek' => self::FRIDAY, 'startTime' => self::SLOT_TIME, 'durationMinutes' => 90, 'capacity' => 1],
                    ],
                ],
            ],
            'coaches' => [],
            'constraints' => [
                [
                    'id' => 'prefer-wed-fri',
                    'scope' => 'TEAM',
                    'scopeTargetId' => self::TEAM,
                    'family' => 'DAY',
                    'ruleType' => 'PREFERRED',
                    'name' => 'préfère mercredi et vendredi',
                    'config' => ['preferredDays' => [self::WEDNESDAY, self::FRIDAY]],
                    'sortOrder' => 0,
                    'isActive' => true,
                ],
            ],
            'slotTemplates' => [],
        ];
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

            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }
}
