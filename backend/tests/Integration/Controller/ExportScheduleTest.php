<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The schedule export endpoints (PDF async + XLSX sync) are authenticated,
 * routed, and fail-closed on an unknown schedule. The tenant scoping + actual
 * file production are covered by ExportPdfHandlerRlsTest and manual e2e.
 *
 * Abus (lot B) — un planning d'un AUTRE club ne s'exporte pas (RLS fail-closed, PDF ET XLSX), et une
 * portée mono-gymnase ne peut pas être détournée vers le gymnase d'un autre club ni un gymnase
 * inconnu (404 « Gymnase introuvable » via le trait partagé des deux endpoints).
 */
#[Group('integration')]
final class ExportScheduleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testExportXlsxRequiresAuth(): void
    {
        $this->client->request('POST', '/api/schedules/' . $this->uuid() . '/export-xlsx');
        self::assertResponseStatusCodeSame(401, 'export must be behind the JWT firewall');
    }

    public function testExportPdfOnUnknownScheduleReturns404(): void
    {
        $token = $this->register();
        $this->client->request('POST', '/api/schedules/' . $this->uuid() . '/export-pdf', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExportXlsxOnUnknownScheduleReturns404(): void
    {
        $token = $this->register();
        $this->client->request('POST', '/api/schedules/' . $this->uuid() . '/export-xlsx', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertResponseStatusCodeSame(404, 'the xlsx route is wired and fail-closed on a missing schedule');
    }

    public function testExportOfAnotherClubsScheduleIsRefused(): void
    {
        [$clubA, , $seasonA] = $this->seedClub('a');
        $scheduleAId = $this->seedSchedule($clubA, $seasonA)->getId();
        [, $userB] = $this->seedClub('b');
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($userB);
        // Vider la carte d'identité : le `find` du contrôleur doit toucher SQL (donc RLS), comme
        // sur une vraie requête de prod — sinon l'entité restée en mémoire court-circuiterait la garde.
        $this->em->clear();

        foreach (['export-pdf', 'export-xlsx'] as $route) {
            $this->requestAs($token, '/api/schedules/' . $scheduleAId . '/' . $route, '{}');
            self::assertContains(
                $this->client->getResponse()->getStatusCode(),
                [403, 404],
                \sprintf('%s : un planning d’un autre club ne s’exporte pas (RLS fail-closed → 404, ou 403 défense en profondeur)', $route),
            );
        }
    }

    public function testExportScopedToAnotherClubsVenueIs404(): void
    {
        [$clubA, $userA, $seasonA] = $this->seedClub('va');
        $scheduleAId = $this->seedSchedule($clubA, $seasonA)->getId();
        // Un gymnase d'un AUTRE club — inséré sous le GUC de CET autre club (RLS WITH CHECK).
        [$clubB, , $seasonB] = $this->seedClub('vb');
        $this->scopeGucToClub($clubB->getId());
        $foreignVenue = new Venue;
        $foreignVenue->setClubId($clubB->getId());
        $foreignVenue->setSeasonId($seasonB->getId());
        $foreignVenue->setName('Gym étranger');
        $foreignVenue->setSource('manual');
        $this->em->persist($foreignVenue);
        $this->em->flush();
        $foreignVenueId = $foreignVenue->getId();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($userA);
        $this->em->clear();

        foreach (['export-pdf', 'export-xlsx'] as $route) {
            // Gymnase d'un AUTRE club : invisible et hors du club du planning → 404.
            $this->requestAs($token, '/api/schedules/' . $scheduleAId . '/' . $route, json_encode(['venueId' => $foreignVenueId], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(404, $route . ' : un gymnase d’un autre club ne peut pas être ciblé');
            $foreignBody = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('Gymnase introuvable', $foreignBody);

            // Gymnase INCONNU (id supprimé/forgé) → même refus.
            $this->requestAs($token, '/api/schedules/' . $scheduleAId . '/' . $route, json_encode(['venueId' => $this->uuid()], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(404, $route . ' : un gymnase inconnu est refusé');
            $unknownBody = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('Gymnase introuvable', $unknownBody);

            // 404 IDENTIQUE HORS TRACE : un gymnase d'un AUTRE club (existant) ne doit pas se
            // distinguer d'un gymnase INEXISTANT — sinon la réponse divulguerait, par différence,
            // l'existence d'un gymnase d'autrui (énumération). En APP_ENV=test (debug=true) le corps
            // porte une clé `trace` de débogage qui cite la ligne de l'appelant (112 vs 118) ; elle
            // n'existe pas en prod (debug off) et c'est la SEULE clé propre au debug qui diffère ici
            // (title/detail/description/status/type… sont stables, cf. ExportPdfHandlerRlsTest). On la
            // retire — et elle seule — des deux corps décodés, puis on compare le reste.
            $foreign = json_decode($foreignBody, true, 512, \JSON_THROW_ON_ERROR);
            $unknown = json_decode($unknownBody, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($foreign);
            self::assertIsArray($unknown);
            unset($foreign['trace'], $unknown['trace']);
            self::assertSame($foreign, $unknown, $route . ' : les deux 404 doivent être identiques hors trace de débogage — aucune divulgation d’existence');
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** POST authentifié (Bearer) avec un corps JSON brut, sur une IP neutre. */
    private function requestAs(string $token, string $uri, string $body): void
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client->request('POST', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], $body);
    }

    /**
     * Un club + utilisateur admin + saison (avec plan SEASON), scopé au GUC du club (RLS).
     *
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function seedClub(string $prefix): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('EXP ' . $prefix);
        $club->setSlug('exp-' . $prefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($prefix . $uid), 0, 11)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail($prefix . '-' . $uid . '@test.com');
        $user->setFirstName('E');
        $user->setLastName('X');
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

        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();

        return [$club, $user, $season];
    }

    /** Une version de saison liée à son plan (comme la prod au POST). */
    private function seedSchedule(Club $club, Season $season): Schedule
    {
        $this->scopeGucToClub($club->getId());
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Export');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function uuid(): string
    {
        return \sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFFFFFFFFFF));
    }

    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'exp' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'E', 'lastName' => 'Export', 'ara' => strtoupper($suffix), 'club_name' => 'Club Export', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        return $token;
    }
}
