<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWish;
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
 * Doléances coachs (feature #10, lot C1) : l'API stampe le tenant/saison côté serveur,
 * scope la todo-list à une période, borne l'ancre (vacances mère + lundi de la fenêtre) et
 * fait l'aller-retour volume/jours/commentaire/coche. Souhait, jamais une contrainte.
 */
#[Group('phase1')]
final class CoachWishApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    /** L'entrée MÈRE des vacances (holiday), fenêtre lun 2026-02-16 → dim 2026-03-01 (2 semaines). */
    private CalendarEntry $mother;

    private Team $team;

    private Coach $coach;

    public function testCreateStampsTenantAndListScopesToPeriod(): void
    {
        $created = $this->post($this->payload(['weekStart' => '2026-02-16', 'slotsWanted' => 2, 'unavailableDays' => [3, 5], 'comment' => 'Mutualise avec SM2']));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-02-16', $created['weekStart']);
        self::assertSame(2, $created['slotsWanted']);
        self::assertSame([3, 5], $created['unavailableDays']);
        self::assertSame('Mutualise avec SM2', $created['comment']);
        self::assertFalse($created['done']);

        // Une doléance d'une AUTRE période ne doit pas apparaître au listing de celle-ci.
        $other = $this->holidayMother('2026-04-06', '2026-04-12');
        $this->post($this->payload(['calendarEntryId' => $other->getId(), 'weekStart' => '2026-04-06']));

        $this->client->request('GET', '/api/coach_wishes?calendarEntryId=' . $this->mother->getId(), [], [], $this->headers());
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertCount(1, $members, 'la todo-list est scopée à la période demandée');
        self::assertSame($this->mother->getId(), $members[0]['calendarEntryId']);
    }

    public function testUpdateRoundTripsVolumeDaysAndDone(): void
    {
        $created = $this->post($this->payload(['weekStart' => '2026-02-16', 'slotsWanted' => 1]));

        $this->client->request('PUT', '/api/coach_wishes/' . $created['id'], [], [], $this->headers(), json_encode($this->payload([
            'weekStart' => '2026-02-16', 'slotsWanted' => 3, 'unavailableDays' => [6], 'comment' => 'Ok', 'done' => true,
        ]), \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(3, $body['slotsWanted']);
        self::assertSame([6], $body['unavailableDays']);
        self::assertTrue($body['done']);
    }

    public function testDuplicateForSameTeamAndWeekIsRejectedWith422(): void
    {
        $this->post($this->payload(['weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(201);

        $this->post($this->payload(['weekStart' => '2026-02-16', 'slotsWanted' => 4]));
        self::assertResponseStatusCodeSame(422);
        // P4-126 — le motif voyage jusque dans le corps (un 422 muet rendrait `violations: []`).
        self::assertStringContainsString('Une doléance existe déjà pour cette équipe et cette semaine — modifiez-la.', (string) $this->client->getResponse()->getContent());
    }

    public function testRejectedOnAClosurePeriod(): void
    {
        $closure = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-22'));
        $this->em->persist($closure);
        $this->em->flush();

        $this->post($this->payload(['calendarEntryId' => $closure->getId(), 'weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectedOnAChildWeekEntry(): void
    {
        $child = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Semaine 1')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-22'))
            ->setParentEntryId($this->mother->getId());
        $this->em->persist($child);
        $this->em->flush();

        $this->post($this->payload(['calendarEntryId' => $child->getId(), 'weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectedWhenWeekStartIsNotAMonday(): void
    {
        $this->post($this->payload(['weekStart' => '2026-02-17'])); // mardi
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectedWhenWeekDoesNotTouchThePeriod(): void
    {
        $this->post($this->payload(['weekStart' => '2026-03-30'])); // un lundi bien après la fenêtre
        self::assertResponseStatusCodeSame(422);
    }

    public function testAcceptsAMondayBeforeStartWhenTheWeekStillIntersects(): void
    {
        // Vacances commençant un mardi (2026-02-17) : le LUNDI 2026-02-16 est avant startDate
        // mais sa semaine (lun→dim) recoupe la période — `weeksCovering` le produit.
        $entry = $this->holidayMother('2026-02-17', '2026-02-23');
        $this->post($this->payload(['calendarEntryId' => $entry->getId(), 'weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(201);
    }

    public function testAcceptsAWeekWhoseMondayEqualsTheHolidayEndDate(): void
    {
        // Revue #10 C1 : une semaine dont le LUNDI égale l'endDate des vacances recoupe
        // encore la période (le front l'offre). Ancrer weekStart à midi la rejetait à tort
        // (12:00 > endDate 00:00) ; la comparaison est désormais date-à-date.
        $entry = $this->holidayMother('2026-02-02', '2026-02-16'); // endDate = un lundi
        $this->post($this->payload(['calendarEntryId' => $entry->getId(), 'weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(201);
    }

    public function testDetachedWishCanBeEditedAndToggledWithoutACoach(): void
    {
        // Revue #10 C1 : une doléance dé-attribuée (coach supprimé → coachId null) doit
        // pouvoir être re-cochée ou éditée sans coach. Le DTO l'autorise en écriture ; seule
        // la CRÉATION exige un coach.
        $created = $this->post($this->payload(['weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('PUT', '/api/coach_wishes/' . $created['id'], [], [], $this->headers(), json_encode([
            'calendarEntryId' => $this->mother->getId(), 'weekStart' => '2026-02-16', 'teamId' => $this->team->getId(),
            'coachId' => null, 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => 'complété à la main', 'done' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($body['coachId'], 'la doléance reste dé-attribuée');
        self::assertTrue($body['done']);
    }

    public function testCreateWithoutACoachIsRejected(): void
    {
        // « Une doléance se saisit au nom d'un coach » : la CRÉATION sans coach est refusée.
        $this->post($this->payload(['weekStart' => '2026-02-16', 'coachId' => null]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testPutWithAStaleDeletedCoachIdDetachesInsteadOfResurrecting(): void
    {
        // Revue #10 C1 round 2 : un cache client périmé peut re-pousser (via toggle) un
        // coachId dont le coach a été supprimé depuis. Le PUT ne doit pas ressusciter ce
        // coach mort : il dé-attribue.
        $created = $this->post($this->payload(['weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(201);
        $ghost = '99999999-9999-4999-8999-999999999999'; // aucun coach de ce id

        $this->client->request('PUT', '/api/coach_wishes/' . $created['id'], [], [], $this->headers(), json_encode([
            'calendarEntryId' => $this->mother->getId(), 'weekStart' => '2026-02-16', 'teamId' => $this->team->getId(),
            'coachId' => $ghost, 'slotsWanted' => 2, 'unavailableDays' => [], 'comment' => null, 'done' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['coachId'], 'un coachId supprimé dé-attribue, ne ressuscite pas');
    }

    public function testDeletingTheMotherEntryPurgesItsWishes(): void
    {
        $created = $this->post($this->payload(['weekStart' => '2026-02-16']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/calendar_entries/' . $this->mother->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNull($this->em->getRepository(CoachWish::class)->find($created['id']), 'les doléances partent avec la période mère');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CW ' . $uid)->setSlug('cw-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('cw' . $uid . '@test.com')->setFirstName('C')->setLastName('W');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('SM1');
        $this->em->persist($this->team);
        $this->coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Maxime')->setLastName('Durand');
        $this->em->persist($this->coach);
        $this->em->flush();

        $this->mother = $this->holidayMother('2026-02-16', '2026-03-01');

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
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
            'weekStart' => '2026-02-16',
            'teamId' => $this->team->getId(),
            'coachId' => $this->coach->getId(),
            'slotsWanted' => 2,
            'unavailableDays' => [],
            'comment' => null,
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
        $this->client->request('POST', '/api/coach_wishes', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
