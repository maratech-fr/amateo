<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWishMutualization;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rail gestionnaire des demandes de mutualisation (feature #10, lot D2) : l'API stampe le
 * tenant/saison côté serveur, scope la todo-list à une période, borne l'ancre (vacances mère),
 * valide les partenaires (existence, pas soi-même) et est MANAGEMENT-ONLY (SEC-07). Demande
 * informative, jamais une contrainte.
 */
#[Group('phase1')]
final class CoachWishMutualizationApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    private CalendarEntry $mother;

    private Team $team;

    private Team $partner;

    private Coach $coach;

    public function testCreateStampsTenantAndListScopesToPeriod(): void
    {
        $created = $this->post($this->payload(['partnerTeamIds' => [$this->partner->getId()], 'sharedSlots' => 1]));
        self::assertResponseStatusCodeSame(201);
        self::assertSame([$this->partner->getId()], $created['partnerTeamIds']);
        self::assertSame(1, $created['sharedSlots']);
        self::assertSame($this->coach->getId(), $created['coachId']);
        self::assertFalse($created['done']);

        // Une demande d'une AUTRE période ne doit pas apparaître au listing de celle-ci.
        $other = $this->holidayMother('2026-04-06', '2026-04-12');
        $this->post($this->payload(['calendarEntryId' => $other->getId(), 'partnerTeamIds' => [$this->partner->getId()]]));

        $this->client->request('GET', '/api/coach_wish_mutualizations?calendarEntryId=' . $this->mother->getId(), [], [], $this->headers());
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertCount(1, $members, 'la todo-list est scopée à la période demandée');
        self::assertSame($this->mother->getId(), $members[0]['calendarEntryId']);
    }

    public function testUpdateRoundTripsPartnersSlotsAndDone(): void
    {
        $created = $this->post($this->payload(['partnerTeamIds' => [$this->partner->getId()], 'sharedSlots' => 1]));

        $third = $this->newTeam('SF3');
        $this->client->request('PUT', '/api/coach_wish_mutualizations/' . $created['id'], [], [], $this->headers(), json_encode($this->payload([
            'partnerTeamIds' => [$this->partner->getId(), $third->getId()], 'sharedSlots' => 2, 'done' => true,
        ]), \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([$this->partner->getId(), $third->getId()], $body['partnerTeamIds']);
        self::assertSame(2, $body['sharedSlots']);
        self::assertTrue($body['done']);
    }

    public function testDuplicateForSameTeamIsRejectedWith422(): void
    {
        $this->post($this->payload(['partnerTeamIds' => [$this->partner->getId()]]));
        self::assertResponseStatusCodeSame(201);

        $this->post($this->payload(['partnerTeamIds' => [$this->partner->getId()]]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('existe déjà', (string) $this->client->getResponse()->getContent());
    }

    public function testCreateWithoutACoachIsRejected(): void
    {
        $this->post($this->payload(['coachId' => null, 'partnerTeamIds' => [$this->partner->getId()]]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateWithoutAPartnerIsRejected(): void
    {
        // Une demande sans partenaire n'a pas de sens : on la refuse (le gestionnaire supprime).
        $this->post($this->payload(['partnerTeamIds' => []]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsSelfAsPartner(): void
    {
        $this->post($this->payload(['partnerTeamIds' => [$this->team->getId()]]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('elle-même', (string) $this->client->getResponse()->getContent());
    }

    public function testRejectsANonExistentPartner(): void
    {
        $this->post($this->payload(['partnerTeamIds' => ['99999999-9999-4999-8999-999999999999']]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletingTheMotherEntryPurgesItsMutualizations(): void
    {
        $created = $this->post($this->payload(['partnerTeamIds' => [$this->partner->getId()]]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/calendar_entries/' . $this->mother->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNull($this->em->getRepository(CoachWishMutualization::class)->find($created['id']), 'les mutualisations partent avec la période mère');
    }

    public function testWriteIsRefusedForANonManager(): void
    {
        // SEC-07 : un membre non gestionnaire (rôle non management) ne peut pas écrire.
        $this->scopeGucToClub($this->club->getId());
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid('', true);
        $viewer = (new User)->setEmail('v' . $uid . '@test.com')->setFirstName('V')->setLastName('V');
        $viewer->setPasswordHash($hasher->hashPassword($viewer, 'Password123!'));
        $this->em->persist($viewer);
        $this->em->flush();
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($viewer->getId())->setRole('editor')->setIsActive(true));
        $this->em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($viewer);

        $this->client->request('POST', '/api/coach_wish_mutualizations', [], [], [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($this->payload(['partnerTeamIds' => [$this->partner->getId()]]), \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CWM ' . $uid)->setSlug('cwm-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('cwm' . $uid . '@test.com')->setFirstName('C')->setLastName('M');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->team = $this->newTeam('SM1');
        $this->partner = $this->newTeam('SF1');
        $this->coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Maxime')->setLastName('Durand');
        $this->em->persist($this->coach);
        $this->em->flush();

        $this->mother = $this->holidayMother('2026-02-16', '2026-03-01');

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function newTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName($name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function holidayMother(string $start, string $end): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'calendarEntryId' => $this->mother->getId(),
            'teamId' => $this->team->getId(),
            'coachId' => $this->coach->getId(),
            'partnerTeamIds' => [$this->partner->getId()],
            'sharedSlots' => 1,
            'done' => false,
        ], $over);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/coach_wish_mutualizations', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
