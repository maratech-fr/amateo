<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Campagne de collecte (feature #10, lot C2) : l'API management (SEC-07) crée une campagne
 * par période, synchronise UN token par coach du périmètre (jamais supprimé), reflète le
 * périmètre COURANT dans ses compteurs, et à la suppression tue les tokens sans toucher la
 * todo-list C1 (les CoachWish survivent).
 */
#[Group('phase1')]
final class CoachWishCampaignApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $jwt;

    /** Vacances mère, fenêtre lun 2026-02-16 → dim 2026-03-01 (2 semaines). */
    private CalendarEntry $mother;

    private Team $teamA;

    private Team $teamB;

    private Coach $coachA;

    private Coach $coachB;

    public function testPostCreatesCampaignAndOneTokenPerCoachOfRetainedTeams(): void
    {
        // teamA (coachA) retenue ; teamB (coachB) NON → un seul token, celui de coachA.
        $body = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $body['coaches']);
        self::assertSame($this->coachA->getId(), $body['coaches'][0]['coachId']);
        self::assertSame('Maxime', $body['coaches'][0]['firstName']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['coaches'][0]['token'], 'token 64 hex en clair');
        self::assertNull($body['coaches'][0]['respondedAt']);
        self::assertSame(1, $body['totalCoachCount']);
        self::assertSame(0, $body['respondedCoachCount']);
        self::assertSame(0, $body['openWishCount']);

        // Un vrai token en base, unique (campagne, coach).
        $this->scopeGucToClub($this->club->getId());
        $tokens = $this->em->getRepository(CoachWishToken::class)->findBy(['campaignId' => $body['id']]);
        self::assertCount(1, $tokens);
    }

    public function testDuplicateCampaignForSamePeriodIsRejectedWith422(): void
    {
        $this->post($this->payload());
        self::assertResponseStatusCodeSame(201);
        $this->post($this->payload());
        self::assertResponseStatusCodeSame(422);
        // P4-126 — le motif voyage jusque dans le corps (un 422 muet rendrait `violations: []`).
        self::assertStringContainsString('Une collecte existe déjà pour cette période — modifiez-la.', (string) $this->client->getResponse()->getContent());
    }

    public function testCampaignOnANonHolidayPeriodIsRejectedWith422(): void
    {
        $closure = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-22'));
        $this->em->persist($closure);
        $this->em->flush();

        $this->post($this->payload(['calendarEntryId' => $closure->getId()]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testWeekOutsideTheHolidayWindowIsRejectedWith422(): void
    {
        $this->post($this->payload(['weeks' => ['2026-03-30']])); // lundi bien après la fenêtre
        self::assertResponseStatusCodeSame(422);
    }

    public function testPutAddingATeamAddsAMissingTokenWithoutTouchingExistingOnes(): void
    {
        $created = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);
        $tokenA = $created['coaches'][0]['token'];

        // On élargit à teamB → token de coachB ajouté, celui de coachA INCHANGÉ.
        $this->client->request('PUT', '/api/coach_wish_campaigns/' . $created['id'], [], [], $this->headers(), json_encode($this->payload([
            'teamIds' => [$this->teamA->getId(), $this->teamB->getId()],
        ]), \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $body['totalCoachCount']);

        $byCoach = [];
        foreach ($body['coaches'] as $c) {
            $byCoach[$c['coachId']] = $c['token'];
        }
        self::assertSame($tokenA, $byCoach[$this->coachA->getId()], 'le token déjà émis ne change jamais');
        self::assertArrayHasKey($this->coachB->getId(), $byCoach);
    }

    public function testOpenWishCountReflectsUntreatedWishesOfThePeriod(): void
    {
        $this->seedWish($this->teamA->getId(), '2026-02-16', false); // à traiter
        $this->seedWish($this->teamA->getId(), '2026-02-23', true);  // déjà traité

        $body = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $body['openWishCount'], 'seules les doléances done=false comptent');
    }

    public function testRespondedCoachCountReflectsTokenRespondedAt(): void
    {
        $created = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);

        // On simule une réponse : respondedAt posé sur le token.
        $this->scopeGucToClub($this->club->getId());
        $token = $this->em->getRepository(CoachWishToken::class)->findOneBy(['campaignId' => $created['id']]);
        self::assertNotNull($token);
        $token->markResponded(new DateTimeImmutable);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/api/coach_wish_campaigns/' . $created['id'], [], [], $this->headers());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['respondedCoachCount']);
        self::assertNotNull($body['coaches'][0]['respondedAt']);
    }

    public function testDeleteRemovesTokensButKeepsCoachWishes(): void
    {
        $wishId = $this->seedWish($this->teamA->getId(), '2026-02-16', false);
        $created = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/coach_wish_campaigns/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertCount(0, $this->em->getRepository(CoachWishToken::class)->findBy(['campaignId' => $created['id']]), 'les tokens partent avec la campagne');
        self::assertNull($this->em->getRepository(CoachWishCampaign::class)->find($created['id']));
        self::assertNotNull($this->em->getRepository(CoachWish::class)->find($wishId), 'la todo-list C1 survit à la suppression de la campagne');
    }

    public function testDeletingTheMotherEntryCascadesToCampaignAndTokens(): void
    {
        $created = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/calendar_entries/' . $this->mother->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNull($this->em->getRepository(CoachWishCampaign::class)->find($created['id']), 'la campagne part avec la période mère');
        self::assertCount(0, $this->em->getRepository(CoachWishToken::class)->findBy(['campaignId' => $created['id']]));
    }

    public function testNonManagementMemberCannotReadCampaignsAndTheirTokens(): void
    {
        // Revue #10 C2 : la ressource expose coaches[].token (secret du lien public). La
        // LECTURE est management-only — sinon un simple membre lirait les tokens et usurperait.
        $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);

        $jwt = $this->memberJwt('editor'); // rôle non-management
        $this->client->request('GET', '/api/coach_wish_campaigns', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            'CONTENT_TYPE' => 'application/ld+json',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOpenWishCountIsScopedToTheCampaignTeams(): void
    {
        // Revue #10 C2 : « N à traiter » ne compte QUE les équipes de la campagne — une saisie
        // « au nom de » sur une équipe hors campagne ne doit pas gonfler le badge.
        $this->seedWish($this->teamA->getId(), '2026-02-16', false); // dans la campagne
        $this->seedWish($this->teamB->getId(), '2026-02-16', false); // HORS campagne (teamA seule)

        $body = $this->post($this->payload(['teamIds' => [$this->teamA->getId()]]));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $body['openWishCount'], 'seule la doléance d’une équipe de la campagne compte');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CWC ' . $uid)->setSlug('cwc-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('cwc' . $uid . '@test.com')->setFirstName('C')->setLastName('W');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->teamA = $this->newTeam('SM1');
        $this->teamB = $this->newTeam('SF1');
        $this->coachA = $this->newCoach('Maxime', 'Durand');
        $this->coachB = $this->newCoach('Mara', 'Petit');
        $this->em->flush();

        // coachA → teamA (MAIN) · coachB → teamB (MAIN).
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($this->teamA->getId())->setCoachId($this->coachA->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($this->teamB->getId())->setCoachId($this->coachB->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->flush();

        $this->mother = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->mother);
        $this->em->flush();

        $this->jwt = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function memberJwt(string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);
        $user = (new User)->setEmail('m' . $uid . '@test.com')->setFirstName('M')->setLastName('R');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole($role)->setIsActive(true));
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function newTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName($name);
        $this->em->persist($team);

        return $team;
    }

    private function newCoach(string $first, string $last): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName($first)->setLastName($last);
        $this->em->persist($coach);

        return $coach;
    }

    private function seedWish(string $teamId, string $weekStart, bool $done): string
    {
        $wish = (new CoachWish)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setTeamId($teamId)->setCoachId($this->coachA->getId())
            ->setWeekStart(new DateTimeImmutable($weekStart . ' 00:00:00'))->setSlotsWanted(2)->setUnavailableDays([])->setComment(null)->setDone($done);
        $this->em->persist($wish);
        $this->em->flush();

        return $wish->getId();
    }

    /**
     * Envoie le corps donné en POST et renvoie la réponse décodée.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        $this->client->request('POST', '/api/coach_wish_campaigns', [], [], $this->headers(), json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
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
            'deadline' => '2027-06-30',
            'weeks' => ['2026-02-16', '2026-02-23'],
            'teamIds' => [$this->teamA->getId(), $this->teamB->getId()],
        ], $over);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
