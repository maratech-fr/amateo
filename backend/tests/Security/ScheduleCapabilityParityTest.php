<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleCapabilityResolver;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — P2-8 PR A, LE test du chantier : PARITÉ capacité affichée == verdict du refus.
 *
 * Le bloc `capabilities` sérialisé sur chaque `Schedule` (GET /api/schedules) est calculé
 * par {@see ScheduleCapabilityResolver} — le MÊME code que les gardes
 * d'écriture. Ce test épingle qu'il ne peut pas DÉRIVER du verdict réel : pour une matrice
 * d'états (socle choisi · sœur COMPLETED · sœur en vol · dernière terminée de saison ·
 * version sans photo · version d'overlay), la capacité LUE annonce EXACTEMENT ce que le
 * garde FAIT.
 *
 *  - canDelete:false        ⇒ DELETE → 409 ; canDelete:true ⇒ DELETE passe (overlay compris) ;
 *  - canValidate            ⇒ cohérent avec ValidateScheduleController (COMPLETED, aucune sœur en vol) ;
 *  - overlaysDroppedOnValidate>0 ⇒ validate SANS flag → 409 `overlays_exist`, count IDENTIQUE ;
 *  - versionsDeletedOnValidate   == nombre de sœurs réellement supprimées après validation ;
 *  - canRegenerateFrom      == accepte/refuse de RegenerateFromVersionController (restore sans solve).
 *
 * Décision fondateur D2 (2026-08-10) : le serveur accepte DÉJÀ de supprimer une version
 * d'overlay non-choisie ; on ne DURCIT PAS ce chemin. `canDelete:true` pour un overlay
 * reflète donc le VRAI verdict — zéro nouveau refus HTTP dans cette PR.
 *
 * Les états sont construits À LA MAIN (couche entité + provisioning de plan), et chaque
 * cas destructif (DELETE / validate qui supprime / regenerate-from qui restaure) vit dans
 * SA propre méthode sur des fixtures fraîches (setUp par test) — l'ordre ne peut pas
 * fausser un autre cas.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleCapabilityParityTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    private string $token;

    // ---- canDelete ⇄ ScheduleStateProcessor::processDelete -----------------------------

    /** Socle choisi : canDelete false, DELETE → 409. */
    public function testChosenVersionIsNotDeletableAndCapabilityAgrees(): void
    {
        $chosen = $this->settleSeasonPlan($this->season);

        self::assertFalse($this->capabilitiesOf($chosen->getId())['canDelete']);

        $this->request('DELETE', '/api/schedules/' . $chosen->getId());
        self::assertResponseStatusCodeSame(409);
    }

    /** Deux versions COMPLETED (aucune choisie, aucune « seule terminée ») : canDelete true, DELETE passe. */
    public function testDeletableSiblingIsDeletableAndCapabilityAgrees(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSeasonVersion(ScheduleStatus::COMPLETED); // une sœur → ni « seule terminée »

        self::assertTrue($this->capabilitiesOf($version->getId())['canDelete']);

        $this->request('DELETE', '/api/schedules/' . $version->getId());
        self::assertResponseStatusCodeSame(204);
    }

    /** Seule version terminée de la saison : canDelete false, DELETE → 409. */
    public function testLastFinishedSeasonVersionIsNotDeletableAndCapabilityAgrees(): void
    {
        $only = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        self::assertFalse($this->capabilitiesOf($only->getId())['canDelete']);

        $this->request('DELETE', '/api/schedules/' . $only->getId());
        self::assertResponseStatusCodeSame(409);
    }

    /** Version en cours de solve : canDelete false, DELETE → 409. */
    public function testInFlightVersionIsNotDeletableAndCapabilityAgrees(): void
    {
        $inFlight = $this->seedSeasonVersion(ScheduleStatus::GENERATING);
        $this->seedSeasonVersion(ScheduleStatus::COMPLETED); // pour isoler « en vol » de « seule terminée »

        self::assertFalse($this->capabilitiesOf($inFlight->getId())['canDelete']);

        $this->request('DELETE', '/api/schedules/' . $inFlight->getId());
        self::assertResponseStatusCodeSame(409);
    }

    /** Version d'overlay non-choisie : canDelete true (D2 — pas de durcissement), DELETE passe. */
    public function testOverlayVersionIsDeletableAndCapabilityAgrees(): void
    {
        $overlay = $this->seedOverlayVersion(ScheduleStatus::COMPLETED);

        self::assertTrue($this->capabilitiesOf($overlay->getId())['canDelete']);

        $this->request('DELETE', '/api/schedules/' . $overlay->getId());
        self::assertResponseStatusCodeSame(204);
    }

    // ---- canValidate ⇄ ValidateScheduleController --------------------------------------

    /** COMPLETED sans sœur en vol : canValidate true, validate → 200. */
    public function testCanValidateTrueMatchesValidateController(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        self::assertTrue($this->capabilitiesOf($version->getId())['canValidate']);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/validate');
        self::assertResponseStatusCodeSame(200);
    }

    /** Une sœur en cours de solve : canValidate false, validate → 409. */
    public function testCanValidateFalseWithInFlightSiblingMatchesValidateController(): void
    {
        $target = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSeasonVersion(ScheduleStatus::GENERATING); // sœur en vol

        self::assertFalse($this->capabilitiesOf($target->getId())['canValidate']);

        $this->request('POST', '/api/schedules/' . $target->getId() . '/validate');
        self::assertResponseStatusCodeSame(409);
    }

    /** versionsDeletedOnValidate == nombre de sœurs réellement supprimées par une validation. */
    public function testVersionsDeletedOnValidateMatchesActualDeletions(): void
    {
        $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $target = $this->seedSeasonVersion(ScheduleStatus::COMPLETED); // la version chargée, seule validable
        $planId = $target->getSchedulePlanId();

        self::assertSame(2, $this->capabilitiesOf($target->getId())['versionsDeletedOnValidate']);

        $this->request('POST', '/api/schedules/' . $target->getId() . '/validate');
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(1, $this->em->getRepository(Schedule::class)->count([
            'clubId' => $this->club->getId(),
            'seasonId' => $this->season->getId(),
            'schedulePlanId' => $planId,
        ]), 'après validation : seule la version choisie subsiste (2 sœurs supprimées)');
    }

    /** overlaysDroppedOnValidate>0 ⇒ validate SANS flag → 409 `overlays_exist`, count IDENTIQUE. */
    public function testOverlaysDroppedOnValidateMatchesConflictCount(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedFuturePeriodWithPlan(); // une période à venir portant un plan → invalidée par le socle

        $dropped = $this->capabilitiesOf($version->getId())['overlaysDroppedOnValidate'];
        self::assertSame(1, $dropped);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/validate');
        self::assertResponseStatusCodeSame(409);
        $body = $this->decodedResponse();
        self::assertSame('overlays_exist', $body['code'] ?? null);
        self::assertSame($dropped, $body['count'] ?? null, 'le count du 409 == overlaysDroppedOnValidate');
    }

    // ---- canRegenerateFrom ⇄ RegenerateFromVersionController ----------------------------

    /** Socle terminé non-choisi avec photo, rien en vol : canRegenerateFrom true, regenerate-from → 200. */
    public function testCanRegenerateFromTrueMatchesController(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSnapshot($version);

        self::assertTrue($this->capabilitiesOf($version->getId())['canRegenerateFrom']);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/regenerate-from');
        self::assertResponseStatusCodeSame(200);
    }

    /** Overlay : canRegenerateFrom false (pas un socle), regenerate-from → 409. */
    public function testCanRegenerateFromFalseForOverlayMatchesController(): void
    {
        $overlay = $this->seedOverlayVersion(ScheduleStatus::COMPLETED);
        $this->seedSnapshot($overlay); // même avec une photo, un overlay est refusé

        self::assertFalse($this->capabilitiesOf($overlay->getId())['canRegenerateFrom']);

        $this->request('POST', '/api/schedules/' . $overlay->getId() . '/regenerate-from');
        self::assertResponseStatusCodeSame(409);
    }

    /** Socle sans photo : canRegenerateFrom false (readSnapshot refuse), regenerate-from → 409. */
    public function testCanRegenerateFromFalseWithoutPhotoMatchesController(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        self::assertFalse($this->capabilitiesOf($version->getId())['canRegenerateFrom']);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/regenerate-from');
        self::assertResponseStatusCodeSame(409);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Club capabilities')
            ->setSlug('scap-' . $uid)
            ->setTimezone('Europe/Paris')
            ->setLocale('fr')
            ->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('scap-' . $uid . '@test.com');
        $this->user->setFirstName('Cap');
        $this->user->setLastName('Ability');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->em->persist((new ClubUser)
            ->setClubId($this->club->getId())
            ->setUserId($this->user->getId())
            ->setRole('admin')
            ->setIsActive(true));

        // Une vraie saison : ses versions vivent dans son plan SEASON (le pointeur dit
        // « en vigueur »). Fabriquer un seasonId laisserait les versions sans plan.
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)
            ->setClubId($this->club->getId())
            ->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->provisionSeasonPlan($this->season);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /** Une version de saison seedée par la couche entité, liée au plan SEASON puis numérotée. */
    private function seedSeasonVersion(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('V ' . $status->value)
            ->setStatus($status);
        $this->linkSeededSchedule($schedule); // plan AVANT persist (lot D)
        $this->em->flush();

        return $schedule;
    }

    /** Une version d'overlay : une entrée de période (HOLIDAY) + son plan, à laquelle la version se lie. */
    private function seedOverlayVersion(ScheduleStatus $status): Schedule
    {
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Vacances capabilities');
        $entry->setStartDate(new DateTimeImmutable('today')->modify('+30 days'));
        $entry->setEndDate(new DateTimeImmutable('today')->modify('+37 days'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('Overlay ' . $status->value)
            ->setStatus($status);
        $this->linkSeededSchedule($schedule, $entry->getId()); // plan de période AVANT persist
        $this->em->flush();

        return $schedule;
    }

    /** Une période à venir portant un plan — la cible de periodPlansInvalidatedBySeasonChange. */
    private function seedFuturePeriodWithPlan(): void
    {
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Période à venir');
        $entry->setStartDate(new DateTimeImmutable('today')->modify('+30 days'));
        $entry->setEndDate(new DateTimeImmutable('today')->modify('+37 days'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        $this->planIdOf($entry); // rejoue le geste : la période reçoit son plan
    }

    /** Une photo de structure (vide) pour que regenerate-from franchisse readSnapshot. */
    private function seedSnapshot(Schedule $schedule): void
    {
        $this->em->persist((new ScheduleStructureSnapshot)
            ->setClubId($this->club->getId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setData([]));
        $this->em->flush();
    }

    /**
     * Le bloc `capabilities` lu sur GET /api/schedules/{id}.
     *
     * @return array{canDelete: bool, canValidate: bool, canRegenerateFrom: bool, versionsDeletedOnValidate: int, overlaysDroppedOnValidate: int}
     */
    private function capabilitiesOf(string $scheduleId): array
    {
        $this->request('GET', '/api/schedules/' . $scheduleId);
        self::assertResponseIsSuccessful();
        $body = $this->decodedResponse();
        self::assertArrayHasKey('capabilities', $body);
        $capabilities = $body['capabilities'];
        self::assertIsArray($capabilities, 'capabilities doit être un objet sérialisé, pas null (chemin provider)');

        /* @var array{canDelete: bool, canValidate: bool, canRegenerateFrom: bool, versionsDeletedOnValidate: int, overlaysDroppedOnValidate: int} $capabilities */
        return $capabilities;
    }

    /** @return array<string, mixed> */
    private function decodedResponse(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }
}
