<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Plans\PlanStates;
use App\Service\PeriodConstraintSelector;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ComposesPlanConstraintsTrait;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR d'axes *constraint semantics* et *planning lifecycle* (lot 5, PR 5, ligne 30 du plan ; avant : lot 4, PR 4, ligne
 * 32) : supprimer, élargir, restreindre ou dater une règle du planning de saison validé ne touche les lignes des plans
 * de période qu'à la validation du planning de saison. Avant : la ligne de la période et la sélection qui part au
 * moteur restent celles du planning en vigueur. À la validation : élargie, une règle d'équipe entre dans un plan de
 * vacances existant ; restreinte, une règle de club en sort ; supprimée ou datée, sa ligne de période part ; une coche
 * posée par le gestionnaire contre le défaut n'est jamais touchée (seules les lignes restées égales à l'ancien défaut
 * suivent le nouveau).
 *
 * « Valider » est le geste réel (`POST /api/schedules/{id}/validate`) sur une version de travail née après la
 * modification avec sa copie, chargée — l'état que lui aurait laissé « Régénérer ». Les deux plans de période sont
 * commencés (fermeture de février, vacances d'avril 2026) : la validation ne les supprime pas.
 *
 * Deux preuves par cas : la ligne du plan (`plan_constraint.applies`, le contrat) et la sélection qui part au moteur
 * ({@see PeriodConstraintSelector}). Jumeau Behat de la sélection par nature et par coche :
 * `plan-de-periode-en-overlay.feature`, « Une règle de saison s'applique à une période selon sa nature et ma coche ».
 *
 * Bloquant : step `Blocking suite` de `ci.yml` et `docs/testing/blocking-tests.md`.
 */
#[Group('phase1')]
#[Group('integration')]
final class ConstraintScopeChangeTest extends WebTestCase
{
    use ComposesPlanConstraintsTrait;
    use ComposesPlanTeamsTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    private Season $season;

    private string $clubId;

    private string $teamId;

    private string $closure;

    private string $holiday;

    /** @var array<string, string> plan => entrée de calendrier */
    private array $entryOf = [];

    /**
     * Élargir une règle d'équipe à tout le club : rien ne bouge dans les vacances tant que le planning de saison n'est
     * pas validé ; à la validation, elle y entre (elle y restait au défaut, décochée) ; dans la fermeture, elle reste
     * appliquée.
     */
    public function testWideningATeamRuleBringsItIntoAnExistingHolidayPlanAtValidation(): void
    {
        $ruleId = $this->postRule('TEAM');
        $this->validateTheSocle();
        self::assertSame([true, false], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'témoin : la fermeture l\'applique, les vacances non (défaut d\'une règle d\'équipe)');
        self::assertFalse($this->sentToTheEngine($this->holiday, $ruleId), 'témoin : elle ne part pas avec la demande des vacances');

        $this->changeScope($ruleId, 'CLUB');

        self::assertSame([true, false], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'élargie sans valider : les lignes des périodes sont celles du planning en vigueur');
        self::assertFalse($this->sentToTheEngine($this->holiday, $ruleId), 'élargie sans valider : elle ne part toujours pas avec la demande des vacances');

        $this->validateTheSocle();

        self::assertSame([true, true], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'validée, la règle élargie s\'applique dans les vacances, et toujours dans la fermeture');
        self::assertTrue($this->sentToTheEngine($this->holiday, $ruleId), 'elle part avec la demande des vacances');
    }

    /**
     * Élargir ne touche pas une coche posée contre le défaut, ni avant ni après la validation : décochée par le
     * gestionnaire pour la fermeture, la règle y reste décochée ; cochée pour les vacances, elle y reste cochée.
     */
    public function testWideningLeavesARuleTheManagerSetAgainstTheDefault(): void
    {
        $ruleId = $this->postRule('TEAM');
        $this->validateTheSocle();
        $this->publishRuleSetting($this->closure, $ruleId, false);
        $this->publishRuleSetting($this->holiday, $ruleId, true);
        self::assertSame([false, true], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'témoin : deux coches contre le défaut');

        $this->changeScope($ruleId, 'CLUB');
        $this->validateTheSocle();

        self::assertSame([false, true], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'les coches du gestionnaire ne bougent pas');
        self::assertFalse($this->sentToTheEngine($this->closure, $ruleId), 'décochée pour la fermeture, elle n\'en part toujours pas');
    }

    /** Restreindre une règle de club à une équipe : dans les vacances, où elle restait au défaut (cochée), elle sort à la validation, pas avant. */
    public function testNarrowingAClubRuleTakesItOutOfAnExistingHolidayPlanAtValidation(): void
    {
        $ruleId = $this->postRule('CLUB');
        $this->validateTheSocle();
        self::assertSame([true, true], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'témoin : une règle de club s\'applique aux deux périodes');
        self::assertTrue($this->sentToTheEngine($this->holiday, $ruleId), 'témoin : elle part avec la demande des vacances');

        $this->changeScope($ruleId, 'TEAM');

        self::assertSame([true, true], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'restreinte sans valider : les lignes des périodes sont celles du planning en vigueur');
        self::assertTrue($this->sentToTheEngine($this->holiday, $ruleId), 'restreinte sans valider : elle part toujours avec la demande des vacances');

        $this->validateTheSocle();

        self::assertSame([true, false], [$this->ruleLineRow($this->closure, $ruleId), $this->ruleLineRow($this->holiday, $ruleId)], 'validée, restreinte, elle sort des vacances et reste dans la fermeture');
        self::assertFalse($this->sentToTheEngine($this->holiday, $ruleId), 'elle ne part plus avec la demande des vacances');
    }

    /** Supprimer une règle du planning de saison validé : ses lignes de période restent jusqu'à la validation, puis partent. */
    public function testDeletingARuleRemovesItsPeriodLinesAtValidation(): void
    {
        $ruleId = $this->postRule('CLUB');
        $this->validateTheSocle();

        $this->client->request('DELETE', '/api/constraints/' . $ruleId, [], [], $this->headers());
        self::assertResponseStatusCodeSame(204, (string) $this->client->getResponse()->getContent());
        $this->em->clear();

        self::assertSame(2, $this->ruleLineCount($ruleId), 'supprimée sans valider : ses lignes de période sont celles du planning en vigueur');

        $this->validateTheSocle();

        self::assertSame(0, $this->ruleLineCount($ruleId), 'validée, la suppression emporte ses lignes de période');
    }

    /** Dater une règle du planning de saison validé (l'attacher à une période) : ses lignes de période restent jusqu'à la validation, puis partent. */
    public function testDatingARuleRemovesItsPeriodLinesAtValidation(): void
    {
        $ruleId = $this->postRule('CLUB');
        $this->validateTheSocle();

        $this->client->request('PUT', '/api/constraints/' . $ruleId, [], [], $this->headers(), json_encode([
            'name' => 'Pas le vendredi',
            'scope' => 'CLUB',
            'scopeTargetId' => null,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [5]],
            'calendarEntryId' => $this->entryOf[$this->closure],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->em->clear();

        self::assertSame(2, $this->ruleLineCount($ruleId), 'datée sans valider : ses lignes de période sont celles du planning en vigueur');

        $this->validateTheSocle();

        self::assertSame(0, $this->ruleLineCount($ruleId), 'validée, la règle datée n\'a plus de ligne de période');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $club = (new Club)->setName('Portée ' . $uid)->setSlug('portee-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->user = (new User)->setEmail('portee' . $uid . '@test.com')->setFirstName('P')->setLastName('Ortée');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();
        $this->clubId = $club->getId();

        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new ClubUser)->setClubId($this->clubId)->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->clubId)->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        // SM1, rang 1 : cochée dans les vacances par la pré-sélection — une règle d'équipe n'y est donc écartée que par
        // sa coche, jamais parce que son équipe ne joue pas.
        $team = (new Team)->setClubId($this->clubId)->setSeasonId($this->season->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
            ->setPriorityTierId(1)->setName('SM1')->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->teamId = $team->getId();
        $this->ensureProductionPriorityTiers();
        $this->setSocleTeam($this->season->getId(), $this->teamId, 2);
        $this->closure = $this->periodPlan(CalendarEntryPeriodType::CLOSURE, '2026-02-02', '2026-02-08');
        $this->holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2026-04-06', '2026-04-12');
        self::assertTrue($this->teamLine($this->holiday, $this->teamId)->isParticipating(), 'témoin : SM1 joue les vacances');
    }

    private function periodPlan(CalendarEntryPeriodType $type, string $start, string $end): string
    {
        $entry = (new CalendarEntry)->setClubId($this->clubId)->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType($type)->setTitle($type->value . ' ' . $start)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $planId = $this->planIdOf($entry);
        $this->entryOf[$planId] = $entry->getId();

        return $planId;
    }

    /** Le geste du gestionnaire : une règle « pas le vendredi » créée par l'API, de portée équipe (SM1) ou club. */
    private function postRule(string $scope): string
    {
        $this->client->request('POST', '/api/constraints', [], [], $this->headers(), json_encode([
            'name' => 'Pas le vendredi',
            'scope' => $scope,
            'scopeTargetId' => 'TEAM' === $scope ? $this->teamId : null,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [5]],
            'isActive' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $id = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** Le geste du formulaire de la règle : changer sa portée (équipe SM1 ou tout le club). */
    private function changeScope(string $ruleId, string $scope): void
    {
        $this->client->request('PUT', '/api/constraints/' . $ruleId, [], [], $this->headers(), json_encode([
            'name' => 'Pas le vendredi',
            'scope' => $scope,
            'scopeTargetId' => 'TEAM' === $scope ? $this->teamId : null,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [5]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->em->clear();
    }

    /** Le geste de l'étape Règles d'une période : cocher ou décocher la règle pour ce plan. */
    private function publishRuleSetting(string $planId, string $ruleId, bool $applies): void
    {
        $this->client->request('POST', '/api/constraint_period_overrides', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $planId,
            'constraintId' => $ruleId,
            'isActive' => $applies,
        ], \JSON_THROW_ON_ERROR));
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], 'la coche se publie : ' . $this->client->getResponse()->getContent());
        $this->em->clear();
    }

    /** Le nombre de lignes de la règle dans les deux plans de période (le plan de saison n'est pas compté). */
    private function ruleLineCount(string $ruleId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM plan_constraint WHERE constraint_id = ? AND schedule_plan_id IN (?, ?)',
            [$ruleId, $this->closure, $this->holiday],
        );
    }

    /**
     * Le planning de saison validé sur l'état du jour : une version de travail terminée, née maintenant avec la copie
     * de son plan (ce que lui aurait laissé « Régénérer »), chargée, puis validée par le geste réel.
     */
    private function validateTheSocle(): void
    {
        $this->scopeGucToClub($this->clubId);
        $planId = $this->seasonPlanIdOf($this->season);
        $version = (new Schedule)->setClubId($this->clubId)->setSeasonId($this->season->getId())->setName('V')->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($planId);
        $this->em->persist($version);
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $provisioner->linkSchedule($version);
        $this->em->flush();
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($version, $states->capture($planId));
        $provisioner->pointLockSource($planId, $version->getId());

        $this->client->request('POST', '/api/schedules/' . $version->getId() . '/validate', [], [], $this->headers(), json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->em->clear();
    }

    /** La règle fait-elle partie de la sélection qui part au moteur pour ce plan de période ? */
    private function sentToTheEngine(string $planId, string $ruleId): bool
    {
        $this->scopeGucToClub($this->clubId);
        $entry = $this->em->getRepository(CalendarEntry::class)->find($this->entryOf[$planId]);
        self::assertInstanceOf(CalendarEntry::class, $entry);
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)->selectForPlan($planId);
        foreach ($selection->kept as $constraint) {
            if ($ruleId === $constraint->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }
}
