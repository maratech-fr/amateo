<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\LockLevel;
use App\Plans\PlanStates;
use App\Service\EngineClient;
use App\Service\RequestIdContext;
use App\Service\SchedulePlanProvisioner;
use App\Tests\LoadsVersionDecorTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Promesse (lot conception 5, PR 6, ligne 43 du plan) : une confirmation porte le jeton de la liste vue ; si la liste de
 * ce qui change a bougé depuis l'annonce, la validation ne passe pas sur l'ancien jeton : 409 `changes_to_confirm`, la
 * liste du jour et son jeton, rien n'est écrit ; le nouveau jeton passe. Deux guichets : valider une version
 * (`POST /api/schedules/{id}/validate`) et valider les modifications en attente sans régénérer
 * (`POST /api/schedule_plans/{id}/apply-pending-changes`).
 *
 * Les autres refus de `apply-pending-changes` (Membre, autre club, plan de période, rien en attente) :
 * `Api/ApplyPendingChangesGuardsTest`. Le parcours : `le-socle-commande-les-plans.feature` et
 * `valider-des-modifications-sans-regenerer.feature`.
 */
#[Group('integration')]
final class ValidateChangesConfirmationTest extends WebTestCase
{
    use LoadsVersionDecorTrait;
    use ProvisionsPeriodPlanTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Team $team;

    private Venue $venue;

    /** Valider une version : la liste annoncée a changé (une séance déplacée depuis) — l'ancien jeton est refusé, le nouveau passe. */
    public function testValidatingWithTheTokenOfAListThatChangedSinceIsRefusedUntilConfirmedAgain(): void
    {
        $inForce = $this->inForceVersion();
        // La période naît avant le changement de froid : sa naissance par le décor recopie l'état du jour dans la copie en vigueur.
        $entryId = $this->futurePeriodWithPlan();
        $this->addGridSlot(2, '19:00'); // du froid change : un créneau de grille
        $candidate = $this->loadVersionOf($this->soclePlanId);
        $this->sessionOf($candidate, 1, '18:00');
        $this->makeLoaded($candidate);

        $impact = $this->get('/api/schedules/' . $candidate->getId() . '/validate-impact');
        $announced = $impact['changesToken'] ?? null;
        self::assertIsString($announced, 'l\'annonce porte le jeton de sa liste');
        self::assertSame([$entryId], array_column(\is_array($impact['destroyedPeriodPlans'] ?? null) ? $impact['destroyedPeriodPlans'] : [], 'entryId'), 'témoin : la période à venir est annoncée supprimée');

        $this->post('/api/schedules/' . $candidate->getId() . '/validate', []);
        $this->assertChangesToConfirm('valider sans jeton');
        $this->assertNothingValidated($inForce, $entryId);

        // Depuis l'annonce, une séance de la version a été déplacée : la liste a changé.
        $this->scopeGucToClub($this->clubId);
        $this->em->getConnection()->executeStatement('UPDATE schedule_slot_template SET day_of_week = 3 WHERE schedule_id = ?', [$candidate->getId()]);

        $this->post('/api/schedules/' . $candidate->getId() . '/validate', ['confirmChanges' => $announced]);
        $refused = $this->assertChangesToConfirm('valider sur le jeton d\'une liste qui a changé');
        $fresh = $refused['changesToken'] ?? null;
        self::assertIsString($fresh, 'le refus porte la liste du jour et son jeton');
        self::assertNotSame($announced, $fresh, 'la liste a changé : son jeton aussi');
        $this->assertNothingValidated($inForce, $entryId);

        $this->post('/api/schedules/' . $candidate->getId() . '/validate', ['confirmChanges' => $fresh]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'le jeton de la liste du jour passe : ' . $this->client->getResponse()->getContent());
        self::assertSame($candidate->getId(), $this->chosenOfSocle(), 'la version est en vigueur');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM schedule_plan WHERE calendar_entry_id = ?', [$entryId]), 'la période à venir annoncée est supprimée');
    }

    /** Valider les modifications en attente : une autre modification froide depuis l'annonce — l'ancien jeton est refusé, rien n'est écrit, le nouveau passe. */
    public function testApplyingPendingChangesWithTheTokenOfAListThatChangedSinceIsRefusedUntilConfirmedAgain(): void
    {
        $inForce = $this->inForceVersion();
        $this->addGridSlot(2, '19:00');

        $impact = $this->get('/api/schedule_plans/' . $this->soclePlanId . '/pending-impact');
        $announced = $impact['changesToken'] ?? null;
        self::assertIsString($announced, 'l\'annonce porte le jeton de sa liste');

        $this->post('/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', []);
        $this->assertChangesToConfirm('valider ces modifications sans jeton');

        // Depuis l'annonce, un second créneau de grille : la liste a changé.
        $this->addGridSlot(3, '19:00');
        $copyBefore = $this->copyOf($inForce);

        $this->post('/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', ['confirmChanges' => $announced]);
        $refused = $this->assertChangesToConfirm('valider ces modifications sur le jeton d\'une liste qui a changé');
        $fresh = $refused['changesToken'] ?? null;
        self::assertIsString($fresh, 'le refus porte la liste du jour et son jeton');
        self::assertNotSame($announced, $fresh, 'la liste a changé : son jeton aussi');
        self::assertSame($copyBefore, $this->copyOf($inForce), 'refusé : la copie de la version en vigueur n\'est pas écrite');

        $this->post('/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', ['confirmChanges' => $fresh]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'le jeton de la liste du jour passe : ' . $this->client->getResponse()->getContent());
        self::assertSame($inForce->getId(), $this->decoded()['id'] ?? null, 'le geste rend la version en vigueur, aucune version ne naît');
        self::assertNotSame($copyBefore, $this->copyOf($inForce), 'la copie de la version en vigueur a adopté l\'état de travail');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        // Le contrôle d'incohérences appelle le verdict du moteur : simulé, il accepte tout.
        self::getContainer()->set(EngineClient::class, new EngineClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}',
            ['http_code' => 200],
        )), new RequestIdContext));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLoadClub('confirmer');
        $this->team = $this->loadTeam('SM1', sessions: 1);
        $this->venue = $this->loadVenue('Gymnase confirmé');
        $this->addGridSlot(1, '18:00');
    }

    /** La version 1, avec sa séance, pointée et chargée : le socle en vigueur, sa copie prise maintenant. */
    private function inForceVersion(): Schedule
    {
        $version = $this->loadVersionOf($this->soclePlanId);
        $this->sessionOf($version, 1, '18:00');
        $this->scopeGucToClub($this->clubId);
        self::assertTrue(self::getContainer()->get(SchedulePlanProvisioner::class)->choose($version), 'témoin : la version 1 se pointe');
        $this->makeLoaded($version);
        $this->em->flush();

        return $version;
    }

    private function addGridSlot(int $day, string $time): void
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setVenueId($this->venue->getId())->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();
    }

    private function sessionOf(Schedule $version, int $day, string $time): void
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setScheduleId($version->getId())
            ->setTeamId($this->team->getId())->setVenueId($this->venue->getId())->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))
            ->setDurationMinutes(90)->setLockLevel(LockLevel::NONE));
        $this->em->flush();
    }

    /** Une période à venir (dans 30 jours) portant son plan : la validation d'un socle dont le froid change la supprime. */
    private function futurePeriodWithPlan(): string
    {
        $this->scopeGucToClub($this->clubId);
        $entry = (new CalendarEntry)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances à venir')->setIsDisruptive(false)->setStatus(CalendarEntryStatus::ACTIVE)
            ->setStartDate(new DateTimeImmutable('today')->modify('+30 days'))->setEndDate(new DateTimeImmutable('today')->modify('+37 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $this->planIdOf($entry);

        return $entry->getId();
    }

    /** @return array<string, mixed> */
    private function assertChangesToConfirm(string $what): array
    {
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), $what . ' : ' . $this->client->getResponse()->getContent());
        $body = $this->decoded();
        self::assertSame('changes_to_confirm', $body['code'] ?? null, $what);

        return $body;
    }

    private function assertNothingValidated(Schedule $inForce, string $entryId): void
    {
        self::assertSame($inForce->getId(), $this->chosenOfSocle(), 'refusé : la version en vigueur reste en vigueur');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM schedule_plan WHERE calendar_entry_id = ?', [$entryId]), 'refusé : la période à venir subsiste');
    }

    private function chosenOfSocle(): ?string
    {
        $chosen = $this->loadScalar('SELECT chosen_schedule_id FROM schedule_plan WHERE id = ?', [$this->soclePlanId]);

        return \is_string($chosen) ? $chosen : null;
    }

    /** @return array<string, mixed> la copie du froid de la version, telle qu'elle est en base */
    private function copyOf(Schedule $version): array
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->clear();

        return self::getContainer()->get(PlanStates::class)->of($version->getId())->toArray();
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken, 'HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url . ' : ' . $this->client->getResponse()->getContent());

        return $this->decoded();
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : [];
    }
}
