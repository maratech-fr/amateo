<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Tests\LoadsVersionDecorTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot conception 5, PR 3, ligne 18 du plan) : « Charger » une version écrit UNE trace d'audit agrégée
 * `lock.changed` — l'acteur, le club, la version chargée et le nombre de ses verrous, jamais une ligne par verrou —,
 * pour un plan de saison comme pour un plan de période. La trace était réservée à la période
 * (`LoadLocksController`, retiré) ; elle vaut désormais pour la seule route de chargement.
 */
#[Group('integration')]
final class LoadVersionAuditTest extends WebTestCase
{
    use LoadsVersionDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    #[TestWith(['socle'])]
    #[TestWith(['période'])]
    public function testLoadingRecordsOneAggregatedLockChangedEvent(string $plan): void
    {
        $team = $this->loadTeam('SM1');
        $venue = $this->loadVenue('Gymnase Nord');
        $planId = 'socle' === $plan ? $this->soclePlanId : $this->loadPeriodPlan('Noël', '2026-12-21', '2027-01-03');
        $v1 = $this->loadVersionOf($planId);
        $this->lockedSlot($v1, $team->getId(), $venue->getId(), 1);
        $this->lockedSlot($v1, $team->getId(), $venue->getId(), 3);
        $this->makeLoaded($this->loadVersionOf($planId));

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger la version 1 (' . $plan . ')');
        $this->scopeGucToClub($this->clubId);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT entity_type, entity_id, actor_user_id, details FROM audit_log WHERE club_id = ? AND action = ?',
            [$this->clubId, 'lock.changed'],
        );
        self::assertCount(1, $rows, 'une seule trace agrégée, jamais une par verrou');
        self::assertSame(['Schedule', $v1->getId(), $this->manager->getId()], [$rows[0]['entity_type'], $rows[0]['entity_id'], $rows[0]['actor_user_id']], 'la trace désigne la version chargée et son acteur');
        self::assertSame(['count' => 2], json_decode((string) $rows[0]['details'], true, 512, \JSON_THROW_ON_ERROR), 'le compteur = nombre de verrous de la version chargée, rien d\'autre');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLoadClub();
    }

    private function lockedSlot(Schedule $version, string $teamId, string $venueId, int $day): void
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setScheduleId($version->getId())
            ->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setLockLevel(LockLevel::HARD)->setLockOrigin(LockOrigin::MANUAL));
        $this->em->flush();
    }
}
