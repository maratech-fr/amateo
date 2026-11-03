<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ClosurePinDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR GATE — axe planning lifecycle (ADR-0002) : une fermeture déclarée pendant un plan de période
 * VALIDÉ le rend PÉRIMÉ et le geste complet le régénère SANS le gymnase fermé (plan fermetures C5).
 *
 * Après « Enregistrer » : le payload du plan ne porte plus aucune épingle (verrou HARD ni
 * réservation) à Mistral le mardi fermé, mais garde celles des AUTRES jours et des AUTRES gymnases ;
 * régénérer la version validée est refusé (409, « rouvrez d'abord ») ; une fois rouvert, la nouvelle
 * génération PART (202) — plus aucune épingle orpheline ne la bloque (avant ce chantier,
 * `OrphanPinGuard` la refusait en 422 sur le verrou laissé au jour fermé). Le vrai solve est prouvé
 * en Behat (`plan-de-periode-en-overlay.feature`).
 *
 * Jumeaux : `Security/ClosureRemovesPinsAtomicallyTest` (le retrait lui-même, ligne à ligne) ;
 * `Integration/Service/OrphanPinGuardTest` (le refus nommé si une épingle subsiste).
 */
#[Group('phase1')]
#[Group('integration')]
final class StalePeriodPlanRegenerationTest extends WebTestCase
{
    use ClosurePinDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAClosureLeavesThePeriodPayloadWithoutTheClosedVenueButWithEveryOtherPin(): void
    {
        $d = $this->seedClosurePinDecor('SPR');
        $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $d['closedTuesday'], $d['closedTuesday']);
        self::assertSame(201, $closure['status']);

        $hard = $this->hardPinsOfPayload($d);

        self::assertNotContains($d['mistral'] . '|2', $hard, 'plus aucune épingle à Mistral le mardi fermé');
        self::assertContains($d['mistral'] . '|4', $hard, 'le verrou du jeudi à Mistral voyage toujours HARD');
        self::assertContains($d['brel'] . '|2', $hard, 'le verrou du mardi à Brel voyage toujours HARD');
    }

    public function testTheStalePlanRegeneratesOnlyOnceReopenedAndThenNothingBlocksIt(): void
    {
        $d = $this->seedClosurePinDecor('SPS');
        $closure = $this->declareClosure($this->client, $d['user'], $d['mistral'], $d['closedTuesday'], $d['closedTuesday']);
        self::assertSame(201, $closure['status']);

        $this->client->request('POST', '/api/schedules/' . $d['holidayVersionId'] . '/generate', [], [], $this->decorHeaders($d['user']));
        self::assertResponseStatusCodeSame(409, 'la version validée ne se régénère pas sans être rouverte');

        $this->client->request('POST', '/api/schedules/' . $d['holidayVersionId'] . '/reopen', [], [], $this->decorHeaders($d['user']));
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($d['club']->getId());
        $next = (new Schedule)->setClubId($d['club']->getId())->setSeasonId($d['season']->getId())
            ->setName('Vacances de mars V2')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($next, $d['holidayEntryId']);
        $this->em->flush();

        $this->client->request('POST', '/api/schedules/' . $next->getId() . '/generate', [], [], $this->decorHeaders($d['user']));

        self::assertResponseStatusCodeSame(202, 'rouvert, le plan périmé se régénère : aucune épingle orpheline ne le bloque — ' . $this->client->getResponse()->getContent());
        self::assertSame(
            $d['holidayVersionId'],
            self::getContainer()->get(SchedulePlanProvisioner::class)->lockSourceScheduleIdOfPlan($d['holidayPlanId']),
            'la régénération repart de la version CHARGÉE, celle dont les épingles ont été retirées',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pinDecorClock();
    }

    protected function tearDown(): void
    {
        $this->unpinDecorClock();
        parent::tearDown();
    }

    /**
     * Les épingles HARD que le moteur recevrait pour le plan des vacances (greffe canonique de la
     * version chargée + réservations), en `venueId|jour`.
     *
     * @param array<string, mixed> $d
     *
     * @return list<string>
     */
    private function hardPinsOfPayload(array $d): array
    {
        $this->scopeGucToClub($d['club']->getId());
        $this->em->clear();
        $entry = $this->em->getRepository(CalendarEntry::class)->find($d['holidayEntryId']);
        self::assertInstanceOf(CalendarEntry::class, $entry);
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($d['club']->getId(), $d['season']->getId(), (string) $d['holidayPlanId'], $entry);

        $hard = [];
        foreach (\is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [] as $slot) {
            if (\is_array($slot) && LockLevel::HARD->value === ($slot['lockLevel'] ?? null)) {
                $hard[] = $slot['venueId'] . '|' . $slot['dayOfWeek'];
            }
        }

        return $hard;
    }
}
