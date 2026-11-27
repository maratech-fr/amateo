<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\GenerateScheduleController;
use App\Controller\RegenerateController;
use App\Controller\ValidateScheduleController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Plans\PlanStates;
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
 * NR bloquant — axe *planning lifecycle* (lot 5, PR 5, ligne 31 du plan) : LE SOCLE EN VIGUEUR RESTE EN VIGUEUR PENDANT
 * QU'ON LE RETRAVAILLE.
 *
 * Avec un planning de saison validé, générer, régénérer, charger une autre version et préparer une nouvelle version
 * sont permis : aucun ne touche au pointeur — la version en vigueur reste celle que lisent matchs, radar et plans de
 * période jusqu'à la prochaine validation. « Rouvrir » un plan de saison est refusé (409) : il n'y a plus de geste qui
 * vide le pointeur. (Un plan de période, lui, se rouvre comme avant : `un-planning-de-periode-commence-se-valide-seul.feature`.)
 *
 * Avant le lot 5, cinq gardes `SocleGuard::assertSeasonPlanNotChosen` refusaient ces gestes ({@see GenerateScheduleController},
 * {@see RegenerateController}, « Charger », {@see ScheduleStateProcessor}, l'import du parc) ; ce test prouve qu'elles
 * sont retirées sans que la version en vigueur bouge. L'import du parc a son étape Behat :
 * `l-import-du-parc-en-un-geste.feature`.
 *
 * Les états sont construits À LA MAIN (une version pointée qui porte sa copie + une sœur COMPLETED) pour que la
 * réponse dise le geste, jamais une garde antérieure (statut, version en vol). Valider une version supprime encore ses
 * sœurs ({@see ValidateScheduleController}) : premier cas, gardé.
 *
 * Bloquant (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`).
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonPlanInForceTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    private string $token;

    /**
     * Le cas central : après validation d'une version de saison, il n'en existe plus qu'UNE
     * pour cette saison (la validation supprime les sœurs). C'est la propriété émergente sur
     * laquelle repose l'invariant — si une future modif de la validation la casse, ce test
     * tombe et prévient que les portes se rouvrent.
     */
    public function testValidatingASeasonVersionLeavesExactlyOneSeasonVersion(): void
    {
        $this->seedSeasonVersion(ScheduleStatus::COMPLETED); // une sœur COMPLETED
        $chosen = $this->seedSeasonVersion(ScheduleStatus::COMPLETED); // la version chargée, seule validable

        $seasonPlanId = $this->seasonPlanId();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(2, $this->countSeasonVersions($seasonPlanId), 'avant validation : deux versions de saison coexistent');

        $this->request('POST', '/api/schedules/' . $chosen->getId() . '/validate');
        self::assertResponseStatusCodeSame(200, 'valider une version terminée réussit');

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(1, $this->countSeasonVersions($seasonPlanId), 'après validation : une seule version de saison subsiste');
    }

    /** Générer une version de travail, socle en vigueur : accepté (202), le planning en vigueur reste le même. */
    public function testGeneratingASiblingWhileTheSocleIsInForceIsAccepted(): void
    {
        $inForce = $this->seedInForceSocle();
        $sibling = $this->seedSeasonVersion(ScheduleStatus::DRAFT);

        $this->request('POST', '/api/schedules/' . $sibling->getId() . '/generate');

        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        $this->assertStillInForce($inForce);
    }

    /** Régénérer, socle en vigueur : accepté (202), le planning en vigueur reste le même. */
    public function testRegeneratingWhileTheSocleIsInForceIsAccepted(): void
    {
        [$inForce, $sibling] = $this->seedChosenSocleAndSibling();

        $this->request('POST', '/api/schedules/' . $sibling->getId() . '/regenerate');

        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        $this->assertStillInForce($inForce);
    }

    /** « Charger » une autre version, socle en vigueur : accepté (200), elle devient la chargée, l'en vigueur ne bouge pas. */
    public function testLoadingASiblingWhileTheSocleIsInForceIsAccepted(): void
    {
        [$inForce, $sibling] = $this->seedChosenSocleAndSibling();

        $this->request('POST', '/api/schedules/' . $sibling->getId() . '/load');

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame($sibling->getId(), self::getContainer()->get(SchedulePlanProvisioner::class)->lockSourceScheduleIdOfPlan($this->seasonPlanId()), 'la version chargée (★) est la sœur');
        $this->assertStillInForce($inForce);
    }

    /** Préparer une nouvelle version de saison (POST), socle en vigueur : acceptée (201), l'en vigueur ne bouge pas. */
    public function testPostingASeasonVersionWhileTheSocleIsInForceIsAccepted(): void
    {
        $inForce = $this->seedInForceSocle();

        $this->client->request('POST', '/api/schedules', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode(['status' => 'DRAFT'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $this->assertStillInForce($inForce);
    }

    /** « Rouvrir » un plan de saison est refusé (409) : le pointeur ne se vide plus. */
    public function testReopeningTheSeasonPlanIsRefused(): void
    {
        $inForce = $this->seedInForceSocle();

        $this->client->request('POST', '/api/schedules/' . $inForce->getId() . '/reopen', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, (string) $this->client->getResponse()->getContent());
        $this->assertStillInForce($inForce);
    }

    /** Non-régression : rien de pointé (espace de travail) → generate passe (202). */
    public function testGeneratingAWorkspaceVersionStillWorks(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::DRAFT);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/generate');

        self::assertResponseStatusCodeSame(202, 'sans socle pointé, la génération nominale survit');
    }

    /** Non-régression : rien de pointé → regenerate passe (202). */
    public function testRegeneratingAWorkspaceVersionStillWorks(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/regenerate');

        self::assertResponseStatusCodeSame(202, 'sans socle pointé, la régénération nominale survit');
    }

    /** Non-régression : rien de pointé → « Charger » passe (200). */
    public function testLoadingAWorkspaceVersionStillWorks(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSnapshot($version);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/load');

        self::assertResponseStatusCodeSame(200, 'sans socle pointé, le chargement d’une version nominal survit');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Club socle-en-vigueur')
            ->setSlug('cspf-' . $uid)
            ->setTimezone('Europe/Paris')
            ->setLocale('fr')
            ->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('cspf-' . $uid . '@test.com');
        $this->user->setFirstName('Soc');
        $this->user->setLastName('Le');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->em->persist((new ClubUser)
            ->setClubId($this->club->getId())
            ->setUserId($this->user->getId())
            ->setRole('admin')
            ->setIsActive(true));

        // Une vraie saison : ses versions vivent dans son plan SEASON, et le pointeur dit
        // « en vigueur ». Fabriquer un seasonId laisserait les versions sans plan.
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

    /**
     * Socle pointé et chargé (V1, avec sa copie) + une sœur COMPLETED non pointée (avec sa copie) — l'état des cas « porte ».
     *
     * @return array{0: Schedule, 1: Schedule} [en vigueur, sœur]
     */
    private function seedChosenSocleAndSibling(): array
    {
        $inForce = $this->seedInForceSocle();
        $sibling = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSnapshot($sibling); // pour que « Charger » franchisse la lecture de la copie

        return [$inForce, $sibling];
    }

    /** Le plan SEASON pointe une version terminée qui porte sa copie, et la charge : « le planning de saison est validé ». */
    private function seedInForceSocle(): Schedule
    {
        $inForce = $this->settleSeasonPlan($this->season);
        $this->seedSnapshot($inForce);
        self::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($this->seasonPlanId(), $inForce->getId());
        $this->em->flush();

        return $inForce;
    }

    /** Le planning de saison en vigueur est toujours celui d'avant le geste. */
    private function assertStillInForce(Schedule $inForce): void
    {
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame($inForce->getId(), $this->chosenPlanVersion($this->season), 'le planning de saison en vigueur n\'a pas bougé');
    }

    /** Une version de saison seedée par la couche entité, liée au plan SEASON puis numérotée. */
    private function seedSeasonVersion(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('V ' . $status->value)
            ->setStatus($status);
        // lot D : pas de persist/flush ici — linkSeededSchedule pose le plan AVANT de persister.
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** La copie du froid de son plan, comme toute version terminée, pour que « Charger » ne 409 pas faute de copie. */
    private function seedSnapshot(Schedule $schedule): void
    {
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($schedule, $states->capture($schedule->getSchedulePlanId()));
    }

    private function seasonPlanId(): string
    {
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($this->season->getId());
        self::assertIsString($planId);

        return $planId;
    }

    private function countSeasonVersions(string $seasonPlanId): int
    {
        return $this->em->getRepository(Schedule::class)->count([
            'clubId' => $this->club->getId(),
            'seasonId' => $this->season->getId(),
            'schedulePlanId' => $seasonPlanId,
        ]);
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ]);
    }
}
