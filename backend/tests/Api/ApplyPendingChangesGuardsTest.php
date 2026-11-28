<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\TeamCoachRole;
use App\Plans\PlanStates;
use App\Service\EngineClient;
use App\Service\RequestIdContext;
use App\Service\SchedulePlanProvisioner;
use App\Tests\LoadsVersionDecorTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Promesse (lot conception 5, PR 6, ligne 49 du plan) : « Valider ces modifications »
 * (`POST /api/schedule_plans/{id}/apply-pending-changes`), seul guichet qui écrit la version en vigueur, ne s'ouvre
 * qu'au gestionnaire du club, sur un plan de saison en vigueur dont du froid attend, hors génération, et sur le jeton de
 * la liste vue. Le plan d'un autre club répond le même 404, octet pour octet, qu'un plan inconnu ; l'annonce
 * (`GET …/pending-impact`) aussi. Chaque refus n'écrit rien : la copie de la version en vigueur et ses séances restent.
 *
 * Le Membre lit l'annonce sans que le moteur travaille (le contrôle d'incohérences est réservé au gestionnaire), cas
 * ci-dessous. Son 403 exact au geste est joué par `Security/MemberWriteSweepTest`, sa ligne de table ; le jeton d'une liste qui a
 * changé depuis l'annonce, par `Api/ValidateChangesConfirmationTest`. Angles du sensible (isolation, abus) : la porte de
 * l'en vigueur ne rend jamais l'état d'un autre club, `Security/PlanStateTenantIsolationTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class ApplyPendingChangesGuardsTest extends WebTestCase
{
    use LoadsVersionDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Les demandes reçues par le moteur simulé (le contrôle d'incohérences en fait une par lot de 50 séances). */
    private int $engineCalls = 0;

    /**
     * Un Membre lit l'annonce sans jamais faire travailler le moteur : `checkPerformed` faux, listes du contrôle vides,
     * aucune demande au moteur ; ce qui change et son jeton restent lus. Le gestionnaire, lui, a le contrôle. Un plan de
     * période n'a pas de contrôle, pour personne.
     */
    public function testOnlyAManagerMakesTheAnnouncementCallTheEngine(): void
    {
        $inForce = $this->inForceWithAPendingCoachChange();
        // Une séance de l'équipe dont le coach change : le contrôle a de quoi soumettre au moteur.
        $this->scopeGucToClub($this->clubId);
        $link = $this->em->getRepository(TeamCoach::class)->findOneBy(['clubId' => $this->clubId, 'role' => TeamCoachRole::MAIN]);
        self::assertInstanceOf(TeamCoach::class, $link);
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setScheduleId($inForce->getId())
            ->setTeamId($link->getTeamId())->setVenueId($this->loadVenue('Gymnase contrôlé')->getId())->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setLockLevel(LockLevel::NONE));
        $this->em->flush();
        $periodPlan = $this->loadPeriodPlan('Vacances de Noël', '2026-12-19', '2027-01-03');
        $memberToken = $this->memberToken();

        foreach ([$periodPlan, $this->soclePlanId] as $planId) {
            $this->engineCalls = 0;
            $this->call('GET', '/api/schedule_plans/' . $planId . '/pending-impact', token: $memberToken);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $body = $this->decoded();
            self::assertFalse($body['checkPerformed'] ?? null, 'Membre : le contrôle n\'est pas fait');
            self::assertSame([[], [], false], [$body['incoherences'] ?? null, $body['missingSessions'] ?? null, $body['checkInconclusive'] ?? null], 'Membre : les listes du contrôle sont vides');
            self::assertSame(0, $this->engineCalls, 'Membre : aucune demande au moteur');
        }
        self::assertNotSame([], $this->decoded()['changes'] ?? [], 'témoin : le Membre lit ce qui change');

        $this->engineCalls = 0;
        $this->call('GET', '/api/schedule_plans/' . $periodPlan . '/pending-impact');
        self::assertFalse($this->decoded()['checkPerformed'] ?? null, 'plan de période : pas de contrôle, même pour le gestionnaire');
        self::assertSame(0, $this->engineCalls, 'plan de période : aucune demande au moteur');

        $this->call('GET', '/api/schedule_plans/' . $this->soclePlanId . '/pending-impact');
        self::assertTrue($this->decoded()['checkPerformed'] ?? null, 'gestionnaire : le contrôle est fait');
        self::assertGreaterThan(0, $this->engineCalls, 'gestionnaire : le contrôle demande son verdict au moteur');
    }

    /** Le plan d'un autre club : même 404, même corps, qu'un plan inconnu — au geste comme à l'annonce ; rien n'est écrit chez lui. */
    public function testAnotherClubsPlanAnswersTheSame404AsAnUnknownOne(): void
    {
        $theirs = $this->inForceWithAPendingCoachChange();
        $theirPlan = $this->soclePlanId;
        $theirCopy = $this->copyOf($theirs);
        $this->seedLoadClub('autre-club');

        foreach (['POST' => 'apply-pending-changes', 'GET' => 'pending-impact'] as $method => $route) {
            $this->call($method, '/api/schedule_plans/' . $theirPlan . '/' . $route, ['confirmChanges' => 'jeton']);
            $foreign = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];
            $this->call($method, '/api/schedule_plans/00000000-0000-4000-8000-0000000000aa/' . $route, ['confirmChanges' => 'jeton']);
            $unknown = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

            self::assertSame(404, $foreign[0], $route . ' : le plan d\'un autre club est introuvable');
            self::assertSame($unknown, $foreign, $route . ' : même statut, même corps qu\'un plan inconnu');
        }
        self::assertSame($theirCopy, $this->copyOf($theirs), 'la copie en vigueur de l\'autre club n\'est pas écrite');
    }

    /** Sans jeton, ou sur un jeton qui n'est celui d'aucune liste : 409 `changes_to_confirm`, rien n'est écrit. */
    public function testWithoutTheTokenOfTheListSeenNothingIsWritten(): void
    {
        $inForce = $this->inForceWithAPendingCoachChange();
        $copy = $this->copyOf($inForce);
        $slots = $this->slotsOf($inForce);

        foreach ([[], ['confirmChanges' => 'jeton-perime']] as $body) {
            $this->call('POST', '/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', $body);
            self::assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            self::assertSame('changes_to_confirm', $this->decoded()['code'] ?? null);
            self::assertIsString($this->decoded()['changesToken'] ?? null, 'le refus porte la liste et son jeton, comme l\'annonce');
        }
        self::assertSame($copy, $this->copyOf($inForce), 'la copie de la version en vigueur n\'est pas écrite');
        self::assertSame($slots, $this->slotsOf($inForce), 'ses séances non plus');
    }

    /** Le plan d'une période : 409, le geste ne vaut que pour le plan de saison. */
    public function testAPeriodPlanIsRefused(): void
    {
        $this->inForceWithAPendingCoachChange();
        $periodPlan = $this->loadPeriodPlan('Vacances de Noël', '2026-12-19', '2027-01-03');

        $this->call('POST', '/api/schedule_plans/' . $periodPlan . '/apply-pending-changes', ['confirmChanges' => 'jeton']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /** Rien n'attend la validation : 409, aucune écriture. */
    public function testNothingPendingIsRefused(): void
    {
        $inForce = $this->inForce();
        $copy = $this->copyOf($inForce);

        $this->call('POST', '/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', ['confirmChanges' => 'jeton']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($copy, $this->copyOf($inForce));
    }

    /** Un plan de saison sans version en vigueur : 409 (rien n'attend une validation qui n'a jamais eu lieu). */
    public function testASeasonPlanWithoutAVersionInForceIsRefused(): void
    {
        $this->loadTeam('SM1');
        $this->loadVersionOf($this->soclePlanId);

        $this->call('POST', '/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', ['confirmChanges' => 'jeton']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /** Une génération court dans la saison : 409, la copie en vigueur reste. */
    public function testRefusedWhileAGenerationRuns(): void
    {
        $inForce = $this->inForceWithAPendingCoachChange();
        $token = $this->tokenOfTheAnnouncement();
        $this->loadVersionOf($this->soclePlanId, ScheduleStatus::GENERATING, withCopy: false);
        $copy = $this->copyOf($inForce);

        $this->call('POST', '/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', ['confirmChanges' => $token]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($copy, $this->copyOf($inForce));
    }

    /** Témoin du décor : le gestionnaire du club, jeton de l'annonce, rien en vol — le geste passe (200, la version en vigueur). */
    public function testTheManagerWithTheTokenOfTheAnnouncementPasses(): void
    {
        $inForce = $this->inForceWithAPendingCoachChange();

        $this->call('POST', '/api/schedule_plans/' . $this->soclePlanId . '/apply-pending-changes', ['confirmChanges' => $this->tokenOfTheAnnouncement()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($inForce->getId(), $this->decoded()['id'] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(EngineClient::class, new EngineClient(new MockHttpClient(function (): MockResponse {
            ++$this->engineCalls;

            return new MockResponse(
                '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}',
                ['http_code' => 200],
            );
        }), new RequestIdContext));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLoadClub('appliquer');
    }

    /** Une équipe tenue par un coach principal ; la version 1, pointée et chargée, avec sa copie : le socle en vigueur. */
    private function inForce(): Schedule
    {
        $team = $this->loadTeam('SM1');
        $coach = $this->newCoach($this->clubId, $this->seasonId, 'Alix', 'Ancien');
        $this->em->persist($coach);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->flush();
        $version = $this->loadVersionOf($this->soclePlanId);
        $this->scopeGucToClub($this->clubId);
        self::assertTrue(self::getContainer()->get(SchedulePlanProvisioner::class)->choose($version), 'témoin : la version 1 se pointe');
        $this->makeLoaded($version);
        $this->em->flush();

        return $version;
    }

    /** Le socle en vigueur, puis le coach principal de l'équipe confié à un autre, sans valider : du froid attend. */
    private function inForceWithAPendingCoachChange(): Schedule
    {
        $version = $this->inForce();
        $this->scopeGucToClub($this->clubId);
        $link = $this->em->getRepository(TeamCoach::class)->findOneBy(['clubId' => $this->clubId, 'role' => TeamCoachRole::MAIN]);
        self::assertInstanceOf(TeamCoach::class, $link);
        $teamId = $link->getTeamId();
        $this->em->remove($link);
        $other = $this->newCoach($this->clubId, $this->seasonId, 'Bao', 'Nouveau');
        $this->em->persist($other);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setTeamId($teamId)->setCoachId($other->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->flush();

        return $version;
    }

    private function tokenOfTheAnnouncement(): string
    {
        $this->call('GET', '/api/schedule_plans/' . $this->soclePlanId . '/pending-impact');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $token = $this->decoded()['changesToken'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @return array<string, mixed> */
    private function copyOf(Schedule $version): array
    {
        $this->scopeGucToClub($version->getClubId());
        $this->em->clear();

        return self::getContainer()->get(PlanStates::class)->of($version->getId())->toArray();
    }

    /** @return list<array<string, mixed>> */
    private function slotsOf(Schedule $version): array
    {
        $this->scopeGucToClub($version->getClubId());

        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM schedule_slot_template WHERE schedule_id = ? ORDER BY id', [$version->getId()]);
    }

    /** Un Membre actif du club (adhésion `member`), et son jeton. */
    private function memberToken(): string
    {
        $member = (new User)->setEmail('membre' . uniqid('', true) . '@test.com')->setFirstName('Paul')->setLastName('Membre');
        $member->setPasswordHash('x');
        $this->em->persist($member);
        $this->em->flush();
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new ClubUser)->setClubId($this->clubId)->setUserId($member->getId())->setRole('member')->setIsActive(true));
        $this->em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($member);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $url, array $body = [], ?string $token = null): void
    {
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->managerToken),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], 'GET' === $method ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : [];
    }
}
