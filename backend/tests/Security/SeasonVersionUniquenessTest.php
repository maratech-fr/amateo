<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\State\Processor\ScheduleStateProcessor;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — P2-7, axe *planning lifecycle* : LE SOCLE EN VIGUEUR EST UNIQUE.
 *
 * Décision fondateur (P2-7) : tant que le plan SEASON POINTE une version choisie
 * (`chosen_schedule_id` non-null = « le planning de la saison est en vigueur »), on
 * refuse d'en préparer une autre. Le seul geste qui rouvre l'espace de travail est la
 * RÉOUVERTURE (dépointer). Sans cette garde, un POST /api/schedules — direct ou via
 * une régénération naïve — empilerait une seconde version « de saison » à côté du socle
 * arrêté, brouillant « quel est le planning de la saison ».
 *
 * La garde vit dans {@see ScheduleStateProcessor::processPost},
 * SOUS le verrou de plan-scope de la saison et DANS la transaction de création (un
 * `pg_advisory_xact_lock` pris hors transaction se relâcherait au statement suivant).
 * Elle ne s'arme QUE pour un POST « de saison » : pas de plan (→ plan SEASON par défaut)
 * OU un plan explicitement de type SEASON — jamais pour un overlay de période.
 *
 * PREUVE DE CHUTE (obligatoire, faite le 2026-07-30) : la garde
 * (bloc `if ($isSeasonPost) { … chosenOfSeasonPlan … ConflictHttpException }`) a été
 * COMMENTÉE, puis ce test relancé — les cas 1 et 2 rougissent (201 au lieu de 409 :
 * une seconde version de saison est acceptée). Garde remise, les quatre cas repassent
 * au vert. Un test qui ne peut pas échouer ne prouve rien.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonVersionUniquenessTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Cas 1 — socle pointé, POST SANS schedulePlanId (branche « plan SEASON par défaut ») → 409. */
    public function testPostingASeasonVersionWhileTheSocleIsInForceIsRefused(): void
    {
        [$user, , $season] = $this->seed();
        $this->settleSeasonPlan($season); // le plan SEASON pointe une version = en vigueur

        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'V2 saison', 'status' => 'DRAFT',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'un socle en vigueur refuse une nouvelle version de saison');
        self::assertStringContainsString('est en vigueur', (string) $this->client->getResponse()->getContent());
    }

    /** Cas 2 — socle pointé, POST AVEC le schedulePlanId du plan SEASON → 409 (même garde). */
    public function testPostingUnderTheSeasonPlanWhileTheSocleIsInForceIsRefused(): void
    {
        [$user, , $season] = $this->seed();
        $this->settleSeasonPlan($season);

        $seasonPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($season->getId());
        self::assertIsString($seasonPlanId);

        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'V2 saison', 'status' => 'DRAFT', 'schedulePlanId' => $seasonPlanId,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'nommer explicitement le plan SEASON ne contourne pas la garde');
        self::assertStringContainsString('est en vigueur', (string) $this->client->getResponse()->getContent());
    }

    /** Cas 3 — socle NON pointé (espace de travail) : le versionnage normal survit → 201. */
    public function testPostingASeasonVersionWhileTheSocleIsAWorkspaceSucceeds(): void
    {
        [$user] = $this->seed();
        // Aucun settleSeasonPlan : le plan SEASON est un espace de travail (chosen null).

        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'V1 saison', 'status' => 'DRAFT',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, 'sans socle pointé, la première/énième version de travail passe');
    }

    /** Cas 4 — socle pointé, POST d'un OVERLAY (plan de période) : la garde ne déborde pas → 201. */
    public function testPostingAPeriodOverlayWhileTheSocleIsInForceSucceeds(): void
    {
        [$user, , $season] = $this->seed();
        // inv. 13 : un overlay se bâtit justement SUR un socle pointé.
        $this->settleSeasonPlan($season);

        $entryId = $this->postClosurePeriod($user);
        $periodPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entryId);
        self::assertIsString($periodPlanId, 'la période née du geste porte un plan');

        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'V overlay', 'status' => 'DRAFT', 'schedulePlanId' => $periodPlanId,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, 'la garde d’unicité du socle ne concerne jamais un overlay de période');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** POST d'une période closure + geste « Adapter » : rend l'id de l'entrée (son plan existe). */
    private function postClosurePeriod(User $user): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Gymnase fermé',
            // Fenêtre lun→dim ALIGNÉE (2 semaines pleines = un seul segment) : « d'un bloc » permis
            // sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-01',
            'periodType' => 'closure',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsString($created['id']);

        // Le plan naît du geste Adapter (ADR-0002 amendé 2026-07-24).
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $created['id']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $created['id'];
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club unicité-socle');
        $club->setSlug('csvu-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CSVU' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('csvu-' . $uid . '@test.com');
        $user->setFirstName('Uni');
        $user->setLastName('Cite');
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
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
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
