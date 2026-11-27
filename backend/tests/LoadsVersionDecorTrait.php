<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\PlacementStatus;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Plans\PlanComposition;
use App\Plans\PlanStates;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Le décor des tests de « Charger » (lot conception 5, PR 3) : un club, son gestionnaire, une saison en cours
 * (2026-08-01 → 2027-07-15, si bien qu'un plan de période daté de novembre 2026 est commencé et un plan de
 * Noël 2026 à venir), deux catégories, des équipes, des gymnases, des versions terminées qui portent la copie du
 * froid de leur plan prise par le vrai {@see PlanStates::capture}, et le geste `POST /api/schedules/{id}/load`.
 *
 * Le test qui `use` ce trait est un `WebTestCase` : il pose `$this->client` (le navigateur) et `$this->em` dans
 * son `setUp`, avant d'appeler {@see self::seedLoadClub()}.
 */
trait LoadsVersionDecorTrait
{
    use ChoosesPlanVersionTrait;
    use CreatesCompetitionsTrait;
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private string $clubId = '';

    private string $seasonId = '';

    private string $soclePlanId = '';

    private string $managerToken = '';

    private User $manager;

    /** @var array<string, string> nom → id de catégorie */
    private array $categoryIds = [];

    private function seedLoadClub(string $tag = 'charger'): void
    {
        $uid = uniqid('', true);
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $club = (new Club)->setName('Charger ' . $uid)->setSlug($tag . '-' . str_replace('.', '-', $uid))
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->manager = (new User)->setEmail($tag . $uid . '@test.com')->setFirstName('Gest')->setLastName('Ionnaire');
        $this->manager->setPasswordHash($hasher->hashPassword($this->manager, 'Password123!'));
        $this->em->persist($this->manager);
        $this->em->flush();
        $this->clubId = $club->getId();

        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new ClubUser)->setClubId($this->clubId)->setUserId($this->manager->getId())->setRole('admin')->setIsActive(true));
        $season = (new Season)->setClubId($this->clubId)->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->seasonId = $season->getId();
        $this->provisionSeasonPlan($season);
        $this->soclePlanId = SeasonPlanOf::id(static::getContainer(), $this->seasonId);

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . str_replace('.', '-', $uid))->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        foreach (['Seniors', 'U15', 'U17'] as $index => $name) {
            $category = (new SportCategory)->setClubId($this->clubId)->setSportId($sport->getId())->setName($name . ' ' . $uid)->setIsCustom(true)->setSortOrder($index);
            $this->em->persist($category);
            $this->em->flush();
            $this->categoryIds[$name] = $category->getId();
        }

        $this->managerToken = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->manager);
    }

    /** Une équipe de la saison, avec sa ligne dans chaque plan (la porte de la production). */
    private function loadTeam(string $name, string $category = 'Seniors', int $tier = 1, ?TeamLevel $level = TeamLevel::DEPARTEMENTAL, int $sessions = 2): Team
    {
        $this->scopeGucToClub($this->clubId);
        $team = (new Team)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setName($name)
            ->setSportCategoryId($this->categoryIds[$category])->setPriorityTierId($tier)->setLevel($level)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        static::getContainer()->get(PlanComposition::class)->ensureLines($this->seasonId, [$team->getId() => ['sessionsPerWeek' => $sessions]]);
        $this->em->flush();

        return $team;
    }

    private function loadVenue(string $name, bool $canSplit = false): Venue
    {
        $this->scopeGucToClub($this->clubId);
        $venue = $this->newVenue($this->clubId, $this->seasonId, $name)->setCanSplit($canSplit);
        $this->em->flush();
        static::getContainer()->get(PlanComposition::class)->ensureLines($this->seasonId);
        $this->em->flush();

        return $venue;
    }

    /** Une règle permanente d'équipe (froid du socle). */
    private function loadTeamRule(Team $team, string $minStart): Constraint
    {
        $this->scopeGucToClub($this->clubId);
        $rule = (new Constraint)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setName('Pas avant ' . $minStart)
            ->setScope(ConstraintScope::TEAM)->setScopeTargetId($team->getId())->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['minStartTime' => $minStart])->setIsActive(true);
        $this->em->persist($rule);
        $this->em->flush();
        static::getContainer()->get(PlanComposition::class)->ensureLines($this->seasonId);
        $this->em->flush();

        return $rule;
    }

    /** Un plan de période daté, né avec une ligne par équipe, gymnase et règle de sa saison. */
    private function loadPeriodPlan(string $name, string $start, string $end): string
    {
        $this->scopeGucToClub($this->clubId);
        $plan = (new SchedulePlan)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setType(SchedulePlanType::HOLIDAY)
            ->setName($name)->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($plan);
        $this->em->flush();
        static::getContainer()->get(PlanComposition::class)->ensureLines($this->seasonId);
        $this->em->flush();

        return $plan->getId();
    }

    /**
     * Une version terminée du plan, numérotée, qui porte la copie du froid de son plan prise MAINTENANT par le vrai
     * {@see PlanStates::capture} — l'état que la génération lui aurait laissé.
     */
    private function loadVersionOf(string $planId, ScheduleStatus $status = ScheduleStatus::COMPLETED, bool $withCopy = true): Schedule
    {
        $this->scopeGucToClub($this->clubId);
        $version = (new Schedule)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setName('V')->setStatus($status)->setSchedulePlanId($planId);
        $this->em->persist($version);
        static::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);
        $this->em->flush();
        if ($withCopy) {
            $states = static::getContainer()->get(PlanStates::class);
            $states->store($version, $states->capture($planId));
        }

        return $version;
    }

    /** La version chargée (★) du plan, posée comme le ferait la complétion d'une génération. */
    private function makeLoaded(Schedule $version): void
    {
        $this->scopeGucToClub($this->clubId);
        static::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($version->getSchedulePlanId(), $version->getId());
    }

    private function loadedOf(string $planId): ?string
    {
        $this->scopeGucToClub($this->clubId);

        return static::getContainer()->get(SchedulePlanProvisioner::class)->lockSourceScheduleIdOfPlan($planId);
    }

    /** Une équipe engagée en compétition : un match placé d'un championnat (un amical n'engage plus, lot 7 PR 4a). */
    private function engageTeam(Team $team): void
    {
        $this->scopeGucToClub($this->clubId);
        $competitionId = $this->competitionFor($team->getId(), 'Championnat ' . $team->getName())->getId();
        $this->em->persist((new Fixture)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setTeamId($team->getId())
            ->setCompetitionId($competitionId)->setMatchDate(new DateTimeImmutable('2026-12-05'))->setHomeAway(FixtureHomeAway::HOME)
            ->setOpponentLabel('AS Voisins')->setPlacement(PlacementStatus::PLACED));
        $this->em->flush();
    }

    /** Le geste « Charger cette version », par le gestionnaire. */
    private function loadVersion(string $versionId, ?string $token = null): void
    {
        $this->client->request('POST', '/api/schedules/' . $versionId . '/load', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->managerToken),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{}');
    }

    /** @return array<string, mixed> */
    private function loadResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** Une valeur lue en SQL sous le contexte du club (la base telle qu'elle est, hors UnitOfWork). */
    private function loadScalar(string $sql, array $parameters = []): mixed
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->clear();

        return $this->em->getConnection()->fetchOne($sql, $parameters);
    }

    private function assertLoadAccepted(string $what): void
    {
        Assert::assertSame(200, $this->client->getResponse()->getStatusCode(), $what . ' — corps : ' . $this->client->getResponse()->getContent());
    }
}
