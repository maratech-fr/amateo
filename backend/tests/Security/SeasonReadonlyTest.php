<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only season NR (transition-de-saison §3, planning-lifecycle axis): once
 * a season is archived (N-1 and older), every write targeting it is refused
 * with 409 — both the generic API Platform mutations (SeasonAccessGuard in
 * AbstractStateProcessor) and the custom write controllers
 * (SeasonReadonlyGuardListener). Reads stay open; the current and draft
 * seasons remain writable.
 *
 * SEC-13 (2026-08-21) — le garde dérive la saison de la RESSOURCE écrite, pas
 * seulement celle du header. SANS `X-Season-Id`, la saison sélectionnée est la
 * courante (writable), mais écrire sur une ressource d'une saison archivée doit
 * quand même 409 : transcrire un plan de période archivé (le 409 « archived »
 * gagne sur celui de SocleGuard), vider la grille d'un plan archivé nommé dans le
 * CORPS (`clear-grid` — le cas destructif), combler (`/fill`) un planning archivé
 * (refus jadis 404, désormais uniforme et nommé). Symétrie : la même action sur un
 * plan N+1 (futur, writable) ne sur-verrouille PAS. On assert le message EXACT du
 * garde, pas juste « archived » (la page d'erreur HTML de test cite des noms de
 * méthodes en « ...Archived » — seul le libellé complet distingue CE garde).
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonReadonlyTest extends WebTestCase
{
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testGenericWriteOnAPastSeasonIs409(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $auth = $this->authHeaders($user);

        // POST a venue into the archived season → refused.
        $this->client->request('POST', '/api/venues', [], [], $auth + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Gym archive', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testDeleteOnAPastSeasonIs409(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $venue = $this->createVenue($club, $past, 'Gym N-1');

        $this->client->request('DELETE', '/api/venues/' . $venue->getId(), [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testManagementGatedControllerOnAPastSeasonIs409AfterAuth(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;

        // reset-season gates management-role (403) THEN refuses the archive
        // (409, inline so authorization wins first). Admin user → 409.
        $this->client->request('DELETE', '/api/reset-season', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testListenerGuardedControllerOnAPastSeasonIs409(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;

        // reorder-teams is a SeasonScopedWrite controller: the kernel.controller
        // listener refuses the archive (409) before __invoke even reads the body.
        $this->client->request('POST', '/api/teams/reorder', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['teamIds' => []], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testImplicitRuleUpsertAndResetOnAPastSeasonAre409(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $auth = $this->authHeaders($user);

        // Upsert (PUT) sur une saison archivée → refusé (le processor 409 après le 403 management).
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['intensity' => 'PREFERRED'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);

        // Réinitialiser (DELETE) sur une saison archivée → refusé de même.
        $this->client->request('DELETE', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTravelRuleLeverUpsertOnAPastSeasonIs409(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $auth = $this->authHeaders($user);

        // P2-53 RMM-8 PR-4 — le levier d'intensité de trajet (PUT) sur une saison archivée →
        // refusé (le processor 409 après le 403 management, même idiome).
        $this->client->request('PUT', '/api/venue_travel_rule_settings/travelTime', [], [], $auth + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['intensity' => 'MANDATORY'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testConstraintValidationStaysReadableOnAPastSeason(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;

        // Pure read (validation report) — NOT a write, must not 409 on an archive.
        $this->client->request('POST', '/api/constraints/validate', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testReadOnAPastSeasonIsAllowed(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $this->createVenue($club, $past, 'Gym N-1');

        $this->client->request('GET', '/api/venues', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testWriteOnCurrentAndDraftSeasonsIsAllowed(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [, , $draft] = $seasons;
        $auth = $this->authHeaders($user);

        // Current (no header).
        $this->client->request('POST', '/api/venues', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Gym courant', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Draft N+1.
        $this->client->request('POST', '/api/venues', [], [], $auth + [
            'HTTP_X-Season-Id' => $draft->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Gym brouillon', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * SEC-13 — sans header X-Season-Id, la saison sélectionnée est la COURANTE
     * (writable), mais la cible de l'écriture vit dans une saison archivée. Le
     * garde doit dériver la saison de la RESSOURCE, pas du header : transcrire
     * depuis le socle sur un plan de PÉRIODE archivé est refusé 409 « archived »,
     * AVANT même le 409 du SocleGuard (le plan porte un calendarEntryId, donc
     * sans ce garde le contrôleur atteindrait SocleGuard et rendrait un 409 sans
     * le mot « archived »).
     */
    public function testTranscribeOnArchivedPeriodPlanWithoutHeaderIs409Archived(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $entry = $this->createPeriodEntry($club, $past);
        $planId = $this->createPeriodPlan($club->getId(), $past->getId(), $entry->getId());

        // Pas de header X-Season-Id : la saison sélectionnée est la courante.
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertResponseStatusCodeSame(409);
        // Message EXACT du garde saison, pas juste « archived » : la page d'erreur
        // HTML de test cite du code source (noms de méthodes en « ...Archived »), donc
        // seul le libellé complet distingue CE garde du 409 de SocleGuard.
        self::assertStringContainsString('Cette saison est archivée — elle est en lecture seule.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * SEC-13 — le cas destructif : clear-grid vide la grille d'un gymnase pour un
     * plan de période dont l'id vit dans le CORPS de la requête (pas dans l'URL).
     * Sur un plan archivé, sans header, l'écriture doit être refusée 409 « archived » —
     * avant, RIEN ne gardait cette route côté saison (le contrôleur détruisait les
     * créneaux et rendait 200).
     */
    public function testClearGridOnArchivedPeriodPlanInBodyWithoutHeaderIs409Archived(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $planId = $this->createPeriodPlan($club->getId(), $past->getId());

        $this->client->request('POST', '/api/venue_period_overrides/clear-grid', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['schedulePlanId' => $planId, 'venueId' => $this->uuid()], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Cette saison est archivée — elle est en lecture seule.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * SEC-13 — ⚠ LE CONTOURNEMENT, gardé par son propre test (security-review 2026-08-21).
     *
     * `uuid_in` de PostgreSQL accepte plusieurs orthographes du même identifiant : sans tirets,
     * avec accolades, tirets déplacés, majuscules. Une version antérieure du garde pré-filtrait
     * l'id sur une regex d'UUID CANONIQUE — elle rendait donc `null` (« cible introuvable »,
     * repli) là où la base, elle, résolvait parfaitement le plan. Le garde ne mordait plus, et
     * `clear-grid` rendait 200 sur une saison archivée ; `reset-grid` y écrivait des lignes
     * neuves, l'appel étant rejouable.
     *
     * C'est LA forme qui a menti : c'est elle qu'on garde. Un futur pré-filtre plus strict que
     * Postgres — quelle que soit sa bonne intention — rougira ici.
     */
    public function testClearGridOnArchivedPlanWithHyphenlessUuidIsAlso409Archived(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $planId = $this->createPeriodPlan($club->getId(), $past->getId());

        $this->client->request('POST', '/api/venue_period_overrides/clear-grid', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            // La MÊME cible, écrite autrement. Postgres la résout ; le garde doit la voir aussi.
            'schedulePlanId' => str_replace('-', '', $planId),
            'venueId' => $this->uuid(),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Cette saison est archivée — elle est en lecture seule.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * SEC-13 — /fill sur un planning d'une saison archivée, sans header : le refus
     * devient uniforme et NOMMÉ (409 « archived »). Avant, le contrôleur chargeait
     * la version par l'ORM season-filtré : introuvable dans la saison courante,
     * donc 404 — un refus, mais muet sur la vraie cause.
     */
    public function testFillOnArchivedScheduleWithoutHeaderIs409Archived(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $schedule = $this->createScheduleInSeason($club, $past);

        $this->client->request('POST', '/api/schedules/' . $schedule->getId() . '/fill', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Cette saison est archivée — elle est en lecture seule.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * SEC-13 — la dérivation ne SUR-verrouille pas le futur : la même action (clear-grid)
     * sur un plan de période d'une saison N+1 (brouillon, writable), sans header, ne rend
     * PAS le 409 « archived ». C'est le flux légitime d'écriture hors saison courante.
     */
    public function testClearGridOnFuturePeriodPlanWithoutHeaderIsNotArchived409(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [, , $draft] = $seasons;
        $planId = $this->createPeriodPlan($club->getId(), $draft->getId());

        $this->client->request('POST', '/api/venue_period_overrides/clear-grid', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['schedulePlanId' => $planId, 'venueId' => $this->uuid()], \JSON_THROW_ON_ERROR));
        // Le garde ne mord pas : l'action s'exécute (grille vide → idempotent → 200).
        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('Cette saison est archivée — elle est en lecture seule.', (string) $this->client->getResponse()->getContent());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: User, 1: Club, 2: array{0: Season, 1: Season, 2: Season}}
     */
    private function createClubWithThreeSeasons(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club readonly');
        $club->setSlug('club-readonly-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('RDO' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('readonly' . $uid . '@test.com');
        $user->setFirstName('Read');
        $user->setLastName('Only');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $past = $this->createSeason($club, $year - 1);
        $current = $this->createSeason($club, $year);
        $draft = $this->createSeason($club, $year + 1);
        $this->em->flush();

        return [$user, $club, [$past, $current, $draft]];
    }

    private function createSeason(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createPeriodEntry(Club $club, Season $season): CalendarEntry
    {
        $this->scopeGucToClub($club->getId());
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Vacances archive');
        $entry->setStartDate(new DateTimeImmutable($season->getStartDate()->format('Y') . '-10-19'));
        $entry->setEndDate(new DateTimeImmutable($season->getStartDate()->format('Y') . '-11-02'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** Une version terminée d'un plan de PÉRIODE, ancrée dans la saison voulue. */
    private function createScheduleInSeason(Club $club, Season $season): Schedule
    {
        $this->scopeGucToClub($club->getId());
        $planId = $this->createPeriodPlan($club->getId(), $season->getId());

        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setVersionNumber(1)
            ->setName('V archive')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** Un UUID valide arbitraire (venueId de remplissage : la grille visée est vide). */
    private function uuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
