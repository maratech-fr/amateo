<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-07 non-regression: the cockpit write endpoints
 * (validate/reopen/generate/reorder/appearance/manual-edit) require
 * a management membership (owner/admin), not merely an authenticated active
 * member. Benign today (every member is admin) but the boundary that keeps the
 * cockpit closed to the forthcoming non-management `coach` role.
 *
 * The ManagementAccessGuard fires before any entity lookup, so a dummy id is
 * enough: a non-management member is rejected with 403 up front, and a manager
 * falls through to the normal not-found/validation path (never 403).
 */
#[Group('phase1')]
#[Group('integration')]
final class ManagementRoleTest extends WebTestCase
{
    use VerifiesRegistration;

    private const DUMMY_ID = '00000000-0000-0000-0000-000000000000';

    private KernelBrowser $client;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function managementEndpoints(): array
    {
        return [
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/validate'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/reopen'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/regenerate'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/generate'],
            ['POST', '/api/teams/reorder'],
            ['PATCH', '/api/club/appearance'],
            // Lot B: FFBB club-info write (same management surface as appearance).
            // Lot C: FFBB import (fills institutional data — management-only).
            ['POST', '/api/club/ffbb-import'],
            // SEC-12: the pre-solve constraint check is part of the cockpit flow.
            ['POST', '/api/constraints/validate'],
            // The manual-edit lock action carries its own guard call.
            ['POST', '/api/schedule-slots/' . self::DUMMY_ID . '/manual-edit/lock'],
            // Club branding writes (same surface as /club/appearance).
            ['POST', '/api/club/logo'],
            ['DELETE', '/api/club/logo'],
            // P2-53 RMM-8 — géocodage et autofill de la matrice : assertManager() tire AVANT
            // tout travail, un id/corps bidon suffit à atteindre le 403.
            ['GET', '/api/geocode?q=test'],
            ['POST', '/api/venue-travel-times/autofill'],
        ];
    }

    /**
     * The generic API Platform write lane (review finding: the guard on the
     * custom controllers is a door next to an open wall without this). POST
     * bodies are minimal-valid so the request reaches the processor, where the
     * requiresManagementRole() hook must 403 a non-management member.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function apiPlatformWriteEndpoints(): array
    {
        return [
            ['POST', '/api/schedules', '{"name":"t","status":"DRAFT"}'],
            // #10 — les doléances coachs sont management-only (le guard tire AVANT tout
            // lookup, donc un corps minimal-valide suffit à atteindre le processor).
            // UUID valide-en-forme (le nil-uuid échoue la validation Uuid stricte AVANT le
            // processor) : le corps passe la validation, atteint le guard, qui 403.
            ['POST', '/api/coach_wishes', '{"calendarEntryId":"11111111-1111-4111-8111-111111111111","weekStart":"2026-02-16","teamId":"11111111-1111-4111-8111-111111111111","coachId":"11111111-1111-4111-8111-111111111111","slotsWanted":1}'],
            // #10 C2 — la campagne de collecte est management-only (SEC-07), même patron : le
            // guard tire AVANT tout lookup, un corps valide-en-forme atteint le processor.
            ['POST', '/api/coach_wish_campaigns', '{"calendarEntryId":"11111111-1111-4111-8111-111111111111","deadline":"2027-06-30","weeks":["2026-02-16"],"teamIds":["11111111-1111-4111-8111-111111111111"]}'],
            // #10 C3 — actions d'envoi : assertManager() tire AVANT le lookup → un id bidon suffit.
            ['POST', '/api/coach_wish_campaigns/' . self::DUMMY_ID . '/send-links', '{}'],
            ['POST', '/api/coach_wish_campaigns/' . self::DUMMY_ID . '/remind', '{}'],
            // RMM-6 — la saisie bulk des échéances ligue/comité est management-only : assertManager()
            // tire AVANT tout lookup de compétition, un corps valide-en-forme atteint le 403.
            ['POST', '/api/competitions/entry-deadlines', '{"competitionIds":["11111111-1111-4111-8111-111111111111"],"deadline":null}'],
        ];
    }

    /**
     * P1-1 (PR A) — le cœur de la bascule : `requiresManagementRole()` passe à
     * `true` PAR DÉFAUT dans AbstractStateProcessor. Les familles d'écriture de
     * l'atelier (équipes, gymnases, contraintes, entrées calendaires, saisons,
     * périodes), jusque-là ouvertes à tout membre actif, se ferment au management.
     *
     * Corps valides EN FORME : le guard tire dans le processor, APRÈS la
     * validation API Platform — un corps invalide 422-erait avant d'atteindre le
     * 403 (même idiome que apiPlatformWriteEndpoints : UUID non-nil, jamais le
     * nil-uuid qui échoue la validation Uuid stricte en amont). Le seul champ
     * requis à la validation suffit ; la garde précède toute résolution de FK.
     *
     * Familles couvertes via POST (création) : la garde tire avant le persist,
     * donc un corps valide-en-forme atteint le 403 sans dépendre d'une ligne
     * existante — contrairement à un PUT dont la lecture d'item renverrait 404
     * sur un id bidon avant même le processor.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function newlyClosedApiWriteEndpoints(): array
    {
        $uuid = '11111111-1111-4111-8111-111111111111';

        return [
            ['POST', '/api/teams', '{"name":"NR","sportCategoryId":"' . $uuid . '"}'],
            ['POST', '/api/venues', '{"name":"NR Gym","source":"manual"}'],
            ['POST', '/api/constraints', '{"name":"NR","scope":"CLUB","family":"DAY","ruleType":"HARD","config":{"forbiddenDays":[6]},"isActive":true,"sortOrder":1}'],
            ['POST', '/api/calendar_entries', '{"kind":"event","title":"NR","startDate":"2027-01-10","endDate":"2027-01-10"}'],
            ['POST', '/api/seasons', '{"name":"NR Saison","startDate":"2030-07-15","endDate":"2031-07-14"}'],
            ['POST', '/api/team_period_overrides', '{"schedulePlanId":"' . $uuid . '","teamId":"' . $uuid . '"}'],
            // P2-53 RMM-8 — le CRUD de la matrice de trajet passe au data-provider géré (deux
            // uuids valides-en-forme atteignent le processor, qui 403 un non-management).
            ['POST', '/api/venue_travel_times', '{"venueAId":"' . $uuid . '","venueBId":"22222222-2222-4222-8222-222222222222","drivingMinutes":15}'],
        ];
    }

    /**
     * Contre-test de lecture : la bascule ne ferme QUE les écritures. Un membre
     * non-management garde l'accès en lecture aux mêmes familles (le cockpit
     * reste consultable) — sinon le 403 ne prouverait pas un gate de rôle mais
     * un blocage global.
     *
     * @return list<array{0: string}>
     */
    public static function readableCollections(): array
    {
        return [
            ['/api/teams'],
            ['/api/venues'],
            ['/api/constraints'],
            ['/api/calendar_entries'],
        ];
    }

    /**
     * A5/A6 puis P4-103 : la ressource générique `ClubUser` n'existe PLUS DU TOUT.
     *
     * Les écritures avaient d'abord été retirées (fermant l'auto-promotion `POST role=owner`,
     * la rétrogradation du dernier propriétaire et le contournement d'approbation) en laissant
     * `GetCollection`/`Get`. L'audit des routes non consommées (2026-08-20) a montré que
     * personne ne lisait cette liste : le front passe par `/api/memberships/*`. Une surface qui
     * énumère `userId`/`role`/`isActive` d'un club sans qu'aucun écran ne s'en serve est une
     * surface qu'on retire.
     *
     * ⚑ L'assertion a CHANGÉ DE NATURE, et c'est délibéré. Elle disait « ces verbes rendent
     * 405 » — un 405 n'a de sens que tant qu'un GET existe à ce chemin. Elle dit maintenant
     * « ce chemin n'existe pas », ce qui est plus fort et reste vrai. La garantie métier
     * (« un membre ne fabrique pas une adhésion ») est portée par les VRAIES routes, gardées
     * par `MemberRoleTest` (bloquant lui aussi) : `/role`, `/deactivate`, `/reactivate`,
     * `/approve`. On n'a donc rien perdu en supprimant — on a cessé de garder un mort.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function clubUserWriteVerbs(): array
    {
        return [
            ['GET', '/api/club_users'],
            ['GET', '/api/club_users/' . self::DUMMY_ID],
            ['POST', '/api/club_users'],
            ['PUT', '/api/club_users/' . self::DUMMY_ID],
            ['DELETE', '/api/club_users/' . self::DUMMY_ID],
        ];
    }

    public function testNonManagementMemberCannotDeleteSchedule(): void
    {
        // DELETE resolves the item through the provider before the processor
        // runs, so a real schedule (same club) is needed to reach the guard.
        [$adminToken, , $clubA] = $this->register('MGE');
        $this->client->request('POST', '/api/schedules', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"name":"todelete","status":"DRAFT"}');
        self::assertResponseStatusCodeSame(201);
        $scheduleId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? '';
        self::assertNotSame('', $scheduleId);

        $editorToken = $this->addActiveMember($clubA, 'editor');
        $this->client->request('DELETE', '/api/schedules/' . $scheduleId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'schedule DELETE must be management-only');
    }

    #[DataProvider('apiPlatformWriteEndpoints')]
    public function testNonManagementMemberIsForbiddenOnApiPlatformWrites(string $method, string $url, string $body): void
    {
        [, , $clubA] = $this->register('MGC');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], $body);

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s must be management-only', $method, $url));
    }

    #[DataProvider('newlyClosedApiWriteEndpoints')]
    public function testNonManagementMemberIsForbiddenOnNewlyClosedWrites(string $method, string $url, string $body): void
    {
        // 'member' = la future valeur d'enum non-management (PR B). Aujourd'hui
        // toute adhésion est 'admin', donc la bascule est inerte en prod ; ici on
        // fabrique le premier membre non-management pour prouver la fermeture.
        [, , $clubA] = $this->register('MGN');
        $memberToken = $this->addActiveMember($clubA, 'member');

        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], $body);

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s doit devenir management-only (bascule P1-1)', $method, $url));
    }

    #[DataProvider('readableCollections')]
    public function testNonManagementMemberCanStillRead(string $url): void
    {
        [, , $clubA] = $this->register('MGR');
        $memberToken = $this->addActiveMember($clubA, 'member');

        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken]);

        self::assertResponseIsSuccessful(); // 200 : la lecture reste ouverte
    }

    public function testManagerStillWritesNewlyClosedFamily(): void
    {
        // Contre-preuve : le défaut à true ne bloque pas le management — un admin
        // crée toujours dans une famille nouvellement fermée (sinon le 403
        // ci-dessus témoignerait d'un blocage global, pas d'un gate de rôle).
        [$adminToken] = $this->register('MGW');

        $this->client->request('POST', '/api/venues', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"name":"NR Gym","source":"manual"}');

        self::assertResponseStatusCodeSame(201, 'un manager écrit toujours dans les familles fermées');
    }

    public function testNonManagementMemberIsForbiddenOnImplicitRuleWrites(): void
    {
        // Régler une règle implicite (bien-être) est du management. PUT (upsert) et DELETE
        // (réinitialiser) par ruleKey : le provider rend le résolu du défaut (pas de 404 sur une
        // clé valide), donc la requête atteint le processor, qui 403 un membre non-management.
        [, , $clubA] = $this->register('MGI');
        $memberToken = $this->addActiveMember($clubA, 'member');

        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"intensity":"PREFERRED"}');
        self::assertResponseStatusCodeSame(403, 'régler une règle implicite doit être management-only');

        $this->client->request('DELETE', '/api/implicit_rule_settings/coachRestDay', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'réinitialiser une règle implicite doit être management-only');
    }

    public function testNonManagementMemberCanEditOwnProfile(): void
    {
        // Opt-out P1-1 : éditer SON profil n'est pas une action de management
        // (UserStateProcessor::requiresManagementRole() = false, self-only gardé
        // par SEC-02/UserSelfOnlyTest). Un membre non-management doit pouvoir
        // modifier son propre User malgré le défaut à true.
        [, , $clubA] = $this->register('MGS');
        [$memberToken, $memberId] = $this->addActiveMemberReturningId($clubA, 'member');

        $this->client->request('PUT', '/api/users/' . $memberId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"firstName":"Renommé","lastName":"Member"}');

        self::assertResponseIsSuccessful(); // 200 : l'opt-out laisse passer le self-edit
    }

    #[DataProvider('clubUserWriteVerbs')]
    public function testTheGenericClubUserSurfaceDoesNotExist(string $method, string $url): void
    {
        // Même un membre de gestion ne trouve RIEN ici : ni écriture, ni lecture.
        [$adminToken] = $this->register('MGF');
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"userId":"' . self::DUMMY_ID . '","role":"owner","isActive":true}');

        self::assertResponseStatusCodeSame(404, \sprintf('%s %s ne doit exister sous AUCUN verbe (surface retirée, P4-103)', $method, $url));
    }

    /**
     * ADR-0002: renommer LE planning de la saison est la seule écriture cliente
     * qu'ouvre la bascule, et elle remplace `PUT /api/seasons` (planningName) qui
     * n'était PAS gaté management. Elle ne peut pas s'épingler dans le provider
     * statique : un id bidon rend 404 (la lecture précède le processor), donc la
     * garde ne se prouve que sur un plan qui existe — celui du club du membre.
     */
    public function testRenamingTheSeasonPlanIsManagementOnly(): void
    {
        [$adminToken, , $clubA] = $this->register('MGE');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        // Le plan de la saison, tel que le frontend le lit.
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $planId = $me['seasonPlan']['id'];
        self::assertNotNull($planId, 'un club neuf possède le plan de sa saison');

        $rename = static fn (string $token): array => [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];

        // Un membre actif NON-management voit le plan mais ne peut pas le renommer.
        $this->client->request('PUT', '/api/schedule_plans/' . $planId, [], [], $rename($editorToken), '{"name":"forgé"}');
        self::assertResponseStatusCodeSame(403, 'renommer le planning est réservé au management');

        // Le manager, lui, passe — sinon le 403 ci-dessus ne prouverait rien.
        $this->client->request('PUT', '/api/schedule_plans/' . $planId, [], [], $rename($adminToken), '{"name":"Saison de la montée"}');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Saison de la montée', $me['seasonPlan']['name']);
    }

    public function testScheduleCannotBeCreatedWithNonDraftStatus(): void
    {
        // Review finding: POST accepted any status — fabricating a finished plan
        // without ever generating. Even a manager gets 409 for non-DRAFT.
        // COMPLETED is the forge that matters now: it is what the solver's own
        // answer looks like, and choosing a version only ever picks a COMPLETED one.
        [$adminToken] = $this->register('MGD');

        $this->client->request('POST', '/api/schedules', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"name":"forged","status":"COMPLETED"}');

        self::assertResponseStatusCodeSame(409, 'a schedule must be created as DRAFT only');
    }

    #[DataProvider('managementEndpoints')]
    public function testNonManagementMemberIsForbidden(string $method, string $url): void
    {
        [, , $clubA] = $this->register('MGA');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s must be management-only', $method, $url));
    }

    public function testManagerPassesTheGuard(): void
    {
        // Admin (management) → guard passes, falls through to 404 (schedule not
        // found), proving the gate is a role check and not a blanket block.
        [$adminToken] = $this->register('MGB');

        $this->client->request('POST', '/api/schedules/' . self::DUMMY_ID . '/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ]);

        self::assertResponseStatusCodeSame(404, 'a manager must pass the role guard');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        return $this->addActiveMemberReturningId($clubId, $role)[0];
    }

    /**
     * @return array{0: string, 1: string} [token, userId]
     */
    private function addActiveMemberReturningId(string $clubId, string $role): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return [$container->get(JWTTokenManagerInterface::class)->create($user), $user->getId()];
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'M', 'lastName' => 'Role', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
