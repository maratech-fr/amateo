<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\GenerateScheduleController;
use App\Controller\RegenerateController;
use App\Controller\RegenerateFromVersionController;
use App\Controller\ValidateScheduleController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
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
 * NR — P2-9bis, axe *planning lifecycle* : LE SOCLE EN VIGUEUR EST SOUVERAIN.
 *
 * Invariant : tant que le plan SEASON POINTE une version choisie (« le planning de la
 * saison est en vigueur »), aucune AUTRE version de saison n'est créée ni relancée — le
 * seul geste qui rouvre l'espace de travail est la RÉOUVERTURE (dépointer).
 *
 * Cet invariant est aujourd'hui tenu par une PROPRIÉTÉ ÉMERGENTE : valider une version
 * SUPPRIME ses sœurs ({@see ValidateScheduleController}) et P2-7 a fermé le POST direct
 * d'une seconde version ({@see ScheduleStateProcessor}). Résultat :
 * quand un plan pointe, il n'existe qu'UNE version de saison — donc aucun défaut
 * atteignable. Ce test épingle cette propriété ET pose la défense en profondeur : si une
 * future modification de la validation cesse de supprimer les sœurs, les trois portes de
 * (re)génération se rouvriraient en silence. Elles portent désormais une garde explicite
 * ({@see SocleGuard::assertSeasonPlanNotChosen}), sous le verrou de plan-scope
 * de la saison (une garde qui lit hors verrou n'est qu'un TOCTOU) :
 *   - {@see GenerateScheduleController}
 *   - {@see RegenerateController}
 *   - {@see RegenerateFromVersionController}
 *
 * Les états sont construits À LA MAIN (une version pointée + une sœur COMPLETED seedée par
 * la couche entité) pour que ce soit BIEN la nouvelle assertion qui morde, jamais une garde
 * antérieure (statut, isSeasonSchedule, isChosen, version en vol) — d'où l'assertion sur le
 * message « est en vigueur ». La sœur reçoit une photo de structure pour que
 * regenerate-from franchisse readSnapshot et atteigne l'assertion.
 *
 * PREUVE DE CHUTE (obligatoire, faite le 2026-07-31) : les trois assertions
 * `assertSeasonPlanNotChosen` ont été COMMENTÉES dans les trois contrôleurs, puis ce test
 * relancé — les trois cas de porte rougissent (la sœur est acceptée au lieu du 409 attendu).
 * Assertions remises, tous les cas repassent au vert. Un test qui ne peut pas échouer ne
 * prouve rien.
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

    /** Porte generate — socle pointé + sœur COMPLETED : générer la sœur → 409 « est en vigueur ». */
    public function testGeneratingASiblingWhileTheSocleIsInForceIsRefused(): void
    {
        $sibling = $this->seedChosenSocleAndSibling();

        $this->request('POST', '/api/schedules/' . $sibling->getId() . '/generate');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('est en vigueur', (string) $this->client->getResponse()->getContent());
    }

    /** Porte regenerate — socle pointé + sœur COMPLETED : régénérer la sœur → 409 « est en vigueur ». */
    public function testRegeneratingASiblingWhileTheSocleIsInForceIsRefused(): void
    {
        $sibling = $this->seedChosenSocleAndSibling();

        $this->request('POST', '/api/schedules/' . $sibling->getId() . '/regenerate');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('est en vigueur', (string) $this->client->getResponse()->getContent());
    }

    /** Porte regenerate-from — socle pointé + sœur COMPLETED (avec photo) : charger la sœur → 409 « est en vigueur ». */
    public function testRegeneratingFromASiblingWhileTheSocleIsInForceIsRefused(): void
    {
        $sibling = $this->seedChosenSocleAndSibling();

        $this->request('POST', '/api/schedules/' . $sibling->getId() . '/regenerate-from');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('est en vigueur', (string) $this->client->getResponse()->getContent());
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

    /** Non-régression : rien de pointé → regenerate-from passe (200). */
    public function testRegeneratingFromAWorkspaceVersionStillWorks(): void
    {
        $version = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSnapshot($version);

        $this->request('POST', '/api/schedules/' . $version->getId() . '/regenerate-from');

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

    /** Socle pointé (V1) + une sœur COMPLETED non pointée (avec photo) — l'état des cas « porte ». */
    private function seedChosenSocleAndSibling(): Schedule
    {
        $this->settleSeasonPlan($this->season); // le plan SEASON pointe une version = en vigueur
        $sibling = $this->seedSeasonVersion(ScheduleStatus::COMPLETED);
        $this->seedSnapshot($sibling); // pour que regenerate-from franchisse readSnapshot

        return $sibling;
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

    /** Une photo de structure (vide) pour que regenerate-from ne 409 pas sur readSnapshot. */
    private function seedSnapshot(Schedule $schedule): void
    {
        $snapshot = (new ScheduleStructureSnapshot)
            ->setClubId($this->club->getId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setData([]);
        $this->em->persist($snapshot);
        $this->em->flush();
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
