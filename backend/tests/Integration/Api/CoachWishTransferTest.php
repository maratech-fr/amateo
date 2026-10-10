<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Controller\CoachWishTransferController;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamPeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ClubGenerationLock;
use App\Service\CoachWishTransferService;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-63 PR 5 — « Transférer au planning » ({@see CoachWishTransferService},
 * {@see CoachWishTransferController}).
 *
 * NR des axes §7.1 touchés :
 *  - planning lifecycle : {@see self::testTransferNeverBirthsAPlanNorTouchesValidatedSocle} —
 *    le transfert n'écrit que des objets de PÉRIODE, ne fait jamais naître un plan et ne
 *    touche ni le socle ni sa version choisie ;
 *  - constraint semantics (au niveau ÉCRITURE) : le transfert écrit la BONNE contrainte selon
 *    la règle Q-A (mono-coach → COACH_AVAILABILITY HARD ; co-coachée partielle → forbiddenDays
 *    TEAM HARD ; co-coachée tous répondants → COACH_AVAILABILITY HARD). Que le MOTEUR honore
 *    ces familles est gardé par ailleurs (ConstraintKeysAreHonouredByEngine,
 *    CoachUnavailabilityPayloadParity) ; le smoke moteur « transférer » vit en Behat.
 *
 * Tests d'ABUS (§8.4) : garde management FALSIFIÉE, doléance d'un autre club jamais convertie,
 * seules les cochées partent, idempotence par (source, occurrence), 409 si génération en cours.
 */
#[Group('phase1')]
final class CoachWishTransferTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    /** Mère des vacances — fenêtre lun 2026-02-16 → dim 2026-03-01, plan « bloc » ancré au 16/02. */
    private CalendarEntry $mother;

    private string $planId;

    private Team $teamS; // rang 1 (Fanion) — gardé actif par le seed

    private Coach $coach;

    public function testTransferConvertsSelectedWishIntoOverrideAndDatedConstraint(): void
    {
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 3, unavailableDays: [4, 5]);

        $recap = $this->transfer([$wish->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $recap['converted']);
        self::assertSame(0, $recap['ignored']);

        $override = $this->overrideFor($this->teamS->getId());
        self::assertInstanceOf(TeamPeriodOverride::class, $override);
        self::assertTrue($override->isActive());
        self::assertSame(3, $override->getSessionsPerWeek());
        self::assertSame(CoachWishTransferService::SOURCE, $override->getSource(), 'provenance « via doléances »');

        $constraints = $this->transferConstraintsFor($wish->getId());
        self::assertCount(1, $constraints);
        $constraint = $constraints[0];
        self::assertSame(ConstraintScope::COACH, $constraint->getScope());
        self::assertSame($this->coach->getId(), $constraint->getScopeTargetId());
        self::assertSame(ConstraintFamily::COACH_AVAILABILITY, $constraint->getFamily());
        self::assertSame(ConstraintRuleType::HARD, $constraint->getRuleType());
        self::assertSame(['unavailableDays' => [4, 5]], $constraint->getConfig());
        self::assertSame($this->mother->getId(), $constraint->getCalendarEntryId(), 'datée sur la mère (plan bloc)');
        self::assertSame(CoachWishTransferService::SOURCE, $constraint->getSource());
        self::assertSame($wish->getId(), $constraint->getSourceOccurrenceId());

        self::assertTrue($this->reload($wish)->isDone(), 'Q7 — une doléance transférée est traitée');
    }

    public function testOnlyExplicitlySelectedWishesConvert(): void
    {
        $picked = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2);
        $teamB = $this->team('U15', 3);
        $skipped = $this->createWish($teamB->getId(), null, slots: 1);

        $recap = $this->transfer([$picked->getId()]);
        self::assertSame(1, $recap['converted']);

        self::assertInstanceOf(TeamPeriodOverride::class, $this->overrideFor($this->teamS->getId()));
        self::assertFalse($this->reload($skipped)->isDone(), 'la doléance non cochée reste à traiter');
        // Son override NE porte PAS la provenance doléances (le seed a pu la désactiver, source nulle).
        $skippedOverride = $this->overrideFor($teamB->getId());
        if ($skippedOverride instanceof TeamPeriodOverride) {
            self::assertNotSame(CoachWishTransferService::SOURCE, $skippedOverride->getSource());
        }
    }

    public function testZeroSlotsDeactivatesTeam(): void
    {
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 0);

        $this->transfer([$wish->getId()]);

        $override = $this->overrideFor($this->teamS->getId());
        self::assertInstanceOf(TeamPeriodOverride::class, $override);
        self::assertFalse($override->isActive(), 'décision 12 — aucun créneau souhaité = équipe désactivée');
        self::assertSame(CoachWishTransferService::SOURCE, $override->getSource());
    }

    public function testNoCoachWritesForbiddenTeamDays(): void
    {
        // Q5/D-a — doléance sans coach : « l'équipe ne s'entraîne pas ces jours-là ».
        $wish = $this->createWish($this->teamS->getId(), null, slots: 2, unavailableDays: [4, 5]);

        $this->transfer([$wish->getId()]);

        $constraint = $this->transferConstraintsFor($wish->getId())[0];
        self::assertSame(ConstraintScope::TEAM, $constraint->getScope());
        self::assertSame($this->teamS->getId(), $constraint->getScopeTargetId());
        self::assertSame(ConstraintFamily::DAY, $constraint->getFamily());
        self::assertSame(ConstraintRuleType::HARD, $constraint->getRuleType());
        self::assertSame(['forbiddenDays' => [4, 5]], $constraint->getConfig());
    }

    public function testMultiCoachPartialResponseClosesTeamDayForTheTeam(): void
    {
        // Q-A — U11M1 co-coachée (Maxime + Julie), un SEUL a répondu : on ne suppose rien sur
        // l'autre, le jour déclaré est FERMÉ pour l'ÉQUIPE (forbiddenDays TEAM HARD).
        $team = $this->team('U11M1', 2);
        $julie = $this->coachNamed('Julie', 'Martin');
        $this->requireCoach($team->getId(), $this->coach->getId(), TeamCoachRole::MAIN);
        $this->requireCoach($team->getId(), $julie->getId(), TeamCoachRole::ASSISTANT);
        $this->campaignWithResponders([$this->coach->getId()]); // seul Maxime a répondu

        $wish = $this->createWish($team->getId(), $this->coach->getId(), slots: 2, unavailableDays: [4]);
        $this->transfer([$wish->getId()]);

        $constraint = $this->transferConstraintsFor($wish->getId())[0];
        self::assertSame(ConstraintScope::TEAM, $constraint->getScope());
        self::assertSame(ConstraintFamily::DAY, $constraint->getFamily());
        self::assertSame(['forbiddenDays' => [4]], $constraint->getConfig());
    }

    public function testMultiCoachAllRespondedWritesCoachAvailability(): void
    {
        // Q-A — co-coachée dont TOUS les coachs requis ont répondu : COACH_AVAILABILITY HARD du
        // coach de la doléance (le moteur applique « un coach suffit »).
        $team = $this->team('U11M2', 2);
        $julie = $this->coachNamed('Julie', 'Martin');
        $this->requireCoach($team->getId(), $this->coach->getId(), TeamCoachRole::MAIN);
        $this->requireCoach($team->getId(), $julie->getId(), TeamCoachRole::ASSISTANT);
        $this->campaignWithResponders([$this->coach->getId(), $julie->getId()]);

        $wish = $this->createWish($team->getId(), $this->coach->getId(), slots: 2, unavailableDays: [4]);
        $this->transfer([$wish->getId()]);

        $constraint = $this->transferConstraintsFor($wish->getId())[0];
        self::assertSame(ConstraintScope::COACH, $constraint->getScope());
        self::assertSame($this->coach->getId(), $constraint->getScopeTargetId());
        self::assertSame(ConstraintFamily::COACH_AVAILABILITY, $constraint->getFamily());
    }

    public function testWishedDaysWritePreferredTeamDays(): void
    {
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2, wishedDays: [1]);

        $this->transfer([$wish->getId()]);

        $constraint = $this->transferConstraintsFor($wish->getId())[0];
        self::assertSame(ConstraintScope::TEAM, $constraint->getScope());
        self::assertSame(ConstraintFamily::DAY, $constraint->getFamily());
        self::assertSame(ConstraintRuleType::PREFERRED, $constraint->getRuleType());
        self::assertSame(['preferredDays' => [1]], $constraint->getConfig());
    }

    public function testRetransferIsIdempotentBySourceOccurrence(): void
    {
        // Une contrainte MANUELLE du gestionnaire (source null, MÊME équipe, MÊME entrée) : elle
        // ne doit JAMAIS être emportée par le « efface puis recrée » du transfert (qui ne vise que
        // source=coach_wish). On la pose avant, on vérifie qu'elle survit au re-transfert.
        $manual = (new Constraint)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setName('Jours interdits (manuel)')
            ->setScope(ConstraintScope::TEAM)->setScopeTargetId($this->teamS->getId())
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['forbiddenDays' => [7]]);
        $this->em->persist($manual);
        $this->em->flush();
        $manualId = $manual->getId();

        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2, unavailableDays: [4]);
        $this->transfer([$wish->getId()]);
        self::assertCount(1, $this->transferConstraintsFor($wish->getId()));

        // Re-soumission du coach : jours changés, done remis à false (hors de ce test), puis
        // re-transfert → la contrainte est MISE À JOUR, jamais empilée.
        $reloaded = $this->reload($wish);
        $reloaded->setUnavailableDays([4, 6]);
        $this->em->flush();
        $this->em->clear();

        $this->transfer([$wish->getId()]);
        $constraints = $this->transferConstraintsFor($wish->getId());
        self::assertCount(1, $constraints, 'idempotence — une seule contrainte pour la doléance');
        self::assertSame(['unavailableDays' => [4, 6]], $constraints[0]->getConfig());
        self::assertInstanceOf(Constraint::class, $this->em->getRepository(Constraint::class)->find($manualId), 'la contrainte MANUELLE survit au re-transfert');
    }

    public function testResubmissionResetsDoneButNeverRetransfers(): void
    {
        // §8.4 — une re-soumission du coach remet « à traiter » (done=false) MAIS ne re-synchronise
        // JAMAIS le plan : seul un NOUVEAU transfert explicite recale (décision 8). On transfère,
        // puis on simule une re-soumission (done=false + jours changés) SANS re-transférer : le plan
        // garde l'override ET la contrainte du 1er transfert, inchangés.
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2, unavailableDays: [4]);
        $this->transfer([$wish->getId()]);
        self::assertTrue($this->reload($wish)->isDone());
        self::assertSame(['unavailableDays' => [4]], $this->transferConstraintsFor($wish->getId())[0]->getConfig());

        // Re-soumission (ce que fait CoachWishUpserter côté public) : done=false + jours changés.
        $reloaded = $this->reload($wish);
        $reloaded->setDone(false)->setUnavailableDays([6]);
        $this->em->flush();
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());

        // Le plan n'a PAS bougé : override toujours là, contrainte TOUJOURS sur [4] (pas [6]).
        $override = $this->overrideFor($this->teamS->getId());
        self::assertInstanceOf(TeamPeriodOverride::class, $override);
        self::assertTrue($override->isActive());
        self::assertSame(CoachWishTransferService::SOURCE, $override->getSource());
        self::assertSame(['unavailableDays' => [4]], $this->transferConstraintsFor($wish->getId())[0]->getConfig(), 'la re-soumission ne re-synchronise jamais le plan');
        self::assertFalse($this->reload($wish)->isDone(), 'la re-soumission remet « à traiter »');
    }

    public function testInvalidWishIdIsIgnoredWithAReason(): void
    {
        // B3 — un id non-UUID n'atteint jamais find() (il casserait la PK guid) : ignoré avec motif.
        $recap = $this->transfer(['pas-un-uuid']);
        self::assertSame(0, $recap['converted']);
        self::assertSame(1, $recap['ignored']);
        self::assertArrayHasKey('id_invalide', $recap['ignoredReasons']);
    }

    public function testAnOversizedBatchIsRejectedWith400(): void
    {
        // B3 — plafond dur : > 500 ids → 400 nommé, aucune transaction.
        $ids = array_fill(0, 501, '11111111-1111-4111-8111-111111111111');
        $this->client->request('POST', '/api/coach-wishes/transfer', [], [], $this->headers(), json_encode(['wishIds' => $ids], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testWishOfAnotherSeasonIsNeverConverted(): void
    {
        // B5 — une doléance d'une AUTRE saison que celle du gestionnaire (en-tête) est ignorée.
        $otherSeason = (new Season)->setClubId($this->club->getId())->setName('2024-2025')
            ->setStartDate(new DateTimeImmutable('2024-09-01'))->setEndDate(new DateTimeImmutable('2025-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($otherSeason);
        $this->em->flush();
        $wish = (new CoachWish)->setClubId($this->club->getId())->setSeasonId($otherSeason->getId())
            ->setCalendarEntryId($this->mother->getId())->setWeekStart(new DateTimeImmutable('2026-02-16'))
            ->setTeamId($this->teamS->getId())->setCoachId($this->coach->getId())->setSlotsWanted(2);
        $this->em->persist($wish);
        $this->em->flush();

        $recap = $this->transfer([$wish->getId()]);
        // But de sécurité B5 : une doléance d'une autre saison n'est JAMAIS convertie. Le MOTIF
        // peut être 'autre_saison' (garde du service) OU 'introuvable' si un season_filter l'a déjà
        // masquée au find() — les deux sont des refus corrects ; on vérifie surtout l'effet (0 écrit).
        self::assertSame(0, $recap['converted']);
        self::assertSame(1, $recap['ignored']);
        self::assertTrue(
            isset($recap['ignoredReasons']['autre_saison']) || isset($recap['ignoredReasons']['introuvable']),
            'une doléance d\'une autre saison est ignorée (autre_saison, ou introuvable si déjà masquée)',
        );
        self::assertNull($this->overrideFor($this->teamS->getId()), 'aucun réglage de plan écrit pour une doléance d\'une autre saison');
    }

    public function testMutualisationActivatedTeamSurvivesTheSeedOnTransfer(): void
    {
        // Bug corrigé — le seed S+A ne doit JAMAIS désactiver un override EXISTANT. Une équipe de
        // rang bas activée par mutualisation (source=mutualisation, isActive=true) sur un plan NON
        // initialisé doit rester active après un transfert (qui déclenche le seed serveur, D-e).
        $mutualized = $this->team('Loisirs', 5); // rang bas → le seed la désactiverait s'il osait
        $override = (new TeamPeriodOverride)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)->setTeamId($mutualized->getId())->setIsActive(true)->setSessionsPerWeek(1)
            ->setSource('mutualisation');
        $this->em->persist($override);
        $this->em->flush();

        // Un transfert d'une AUTRE équipe déclenche le seed (plan non initialisé) : il ne touche pas Loisirs.
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2);
        $this->transfer([$wish->getId()]);

        $survivor = $this->overrideFor($mutualized->getId());
        self::assertInstanceOf(TeamPeriodOverride::class, $survivor);
        self::assertTrue($survivor->isActive(), 'l\'activation par mutualisation survit au seed du transfert');
        self::assertSame('mutualisation', $survivor->getSource(), 'le seed n\'a pas réécrit la provenance');
    }

    public function testReactivatesSeededOffTeamWithProvenance(): void
    {
        // F + D-e — une équipe hors périmètre (rang D, désactivée par le seed S+A) revient au
        // plan via sa doléance, avec la trace « via doléances ».
        $veteran = $this->team('Vétérans', 5);
        $wish = $this->createWish($veteran->getId(), null, slots: 1);

        $this->transfer([$wish->getId()]);

        $override = $this->overrideFor($veteran->getId());
        self::assertInstanceOf(TeamPeriodOverride::class, $override);
        self::assertTrue($override->isActive());
        self::assertSame(CoachWishTransferService::SOURCE, $override->getSource());
        // D-e — le seed S+A a été matérialisé côté serveur AVANT la 1re écriture : le plan
        // porte désormais le drapeau d'initialisation (sinon un 2e geste ré-seederait).
        self::assertTrue($this->planInitialized(), 'le seed serveur a marqué le plan initialisé (D-e)');
    }

    public function testForeignClubWishIsNeverConverted(): void
    {
        // Abus §3bis #5 — une doléance d'un AUTRE club n'est jamais convertie (garde de club).
        [$otherWishId, $otherPlanId, $otherTeamId] = $this->foreignClubWish();

        $recap = $this->transfer([$otherWishId]);
        self::assertSame(0, $recap['converted']);
        self::assertSame(1, $recap['ignored']);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNull(
            $this->em->getRepository(TeamPeriodOverride::class)->findOneBy(['schedulePlanId' => $otherPlanId, 'teamId' => $otherTeamId]),
            'aucun override écrit dans le plan de l\'autre club',
        );
    }

    public function testTransferRequiresManagementRole(): void
    {
        // Abus §3bis #5 — garde SEC-07 FALSIFIÉE : un membre NON-gestionnaire est refusé 403,
        // et rien n'est écrit. (La retirer ferait passer ce 403 à 200.)
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2);
        $coachToken = $this->tokenForRole('coach');

        $this->client->request('POST', '/api/coach-wishes/transfer', [], [], [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $coachToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['wishIds' => [$wish->getId()]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->overrideFor($this->teamS->getId()), 'un non-gestionnaire n\'écrit rien');
    }

    public function testRefuses409WhenGenerationInProgress(): void
    {
        // Q-B — transférer pendant une génération écraserait son résultat : refus 409 nommé.
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2);
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($this->club->getId(), 60);

        try {
            $this->client->request('POST', '/api/coach-wishes/transfer', [], [], $this->headers(), json_encode(['wishIds' => [$wish->getId()]], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);
            self::assertStringContainsString('generation_in_progress', (string) $this->client->getResponse()->getContent());
            self::assertNull($this->overrideFor($this->teamS->getId()), 'rien n\'est écrit pendant une génération');
        } finally {
            if (\is_string($token)) {
                $lock->release($this->club->getId(), $token);
            }
        }
    }

    public function testTransferNeverBirthsAPlanNorTouchesValidatedSocle(): void
    {
        // NR planning lifecycle — une doléance sur une semaine SANS plan est IGNORÉE : le
        // transfert ne fait jamais naître un plan (décision 10) ni ne touche le socle.
        $plansBefore = $this->countPlans();
        $schedulesBefore = $this->countSchedules();

        // Une semaine (lundi) qui ne correspond à aucun plan de la période.
        $wish = $this->createWish($this->teamS->getId(), $this->coach->getId(), slots: 2, week: '2026-02-23');

        $recap = $this->transfer([$wish->getId()]);
        self::assertSame(0, $recap['converted']);
        self::assertSame(1, $recap['ignored']);
        self::assertArrayHasKey('semaine_sans_plan', $recap['ignoredReasons']);

        self::assertSame($plansBefore, $this->countPlans(), 'aucun plan n\'est né');
        self::assertSame($schedulesBefore, $this->countSchedules(), 'aucune version n\'est créée');
        // Aucune contrainte PERMANENTE (socle) n'a été écrite par le transfert.
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(
            [],
            $this->em->getRepository(Constraint::class)->findBy(['source' => CoachWishTransferService::SOURCE, 'calendarEntryId' => null]),
            'le transfert n\'écrit jamais de contrainte permanente (socle)',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CWT ' . $uid)->setSlug('cwt-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('cwt' . $uid . '@test.com')->setFirstName('C')->setLastName('T');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->teamS = $this->team('SM1', 1);
        $this->coach = $this->coachNamed('Maxime', 'Durand');
        $this->em->flush();

        $this->mother = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances de février')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->mother);
        $this->em->flush();
        $this->planId = $this->planIdOf($this->mother);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function team(string $name, int $tierId): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId($tierId)->setName($name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function coachNamed(string $first, string $last): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName($first)->setLastName($last);
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function requireCoach(string $teamId, string $coachId, TeamCoachRole $role): void
    {
        $link = (new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($teamId)->setCoachId($coachId)->setRole($role)->setIsRequired(true);
        $this->em->persist($link);
        $this->em->flush();
    }

    /** @param list<string> $respondedCoachIds */
    private function campaignWithResponders(array $respondedCoachIds): void
    {
        $campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setDeadline(new DateTimeImmutable('2026-02-10'));
        $this->em->persist($campaign);
        foreach ($respondedCoachIds as $coachId) {
            $token = (new CoachWishToken)->setClubId($this->club->getId())->setCampaignId($campaign->getId())->setCoachId($coachId);
            $token->markResponded(new DateTimeImmutable);
            $this->em->persist($token);
        }
        $this->em->flush();
    }

    /**
     * @param list<int> $unavailableDays
     * @param list<int> $wishedDays
     */
    private function createWish(string $teamId, ?string $coachId, int $slots = 2, array $unavailableDays = [], array $wishedDays = [], string $week = '2026-02-16'): CoachWish
    {
        $wish = (new CoachWish)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setWeekStart(new DateTimeImmutable($week))
            ->setTeamId($teamId)->setCoachId($coachId)->setSlotsWanted($slots)
            ->setUnavailableDays($unavailableDays)->setWishedDays($wishedDays);
        $this->em->persist($wish);
        $this->em->flush();

        return $wish;
    }

    /**
     * @param list<string> $wishIds
     *
     * @return array{converted: int, ignored: int, ignoredReasons: array<string, int>}
     */
    private function transfer(array $wishIds): array
    {
        $this->client->request('POST', '/api/coach-wishes/transfer', [], [], $this->headers(), json_encode(['wishIds' => $wishIds], \JSON_THROW_ON_ERROR));
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());

        /** @var array{converted: int, ignored: int, ignoredReasons: array<string, int>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true) ?? ['converted' => 0, 'ignored' => 0, 'ignoredReasons' => []];

        return $body;
    }

    private function overrideFor(string $teamId): ?TeamPeriodOverride
    {
        return $this->em->getRepository(TeamPeriodOverride::class)->findOneBy(['schedulePlanId' => $this->planId, 'teamId' => $teamId]);
    }

    /** @return list<Constraint> */
    private function transferConstraintsFor(string $wishId): array
    {
        return $this->em->getRepository(Constraint::class)->findBy([
            'source' => CoachWishTransferService::SOURCE,
            'sourceOccurrenceId' => $wishId,
        ]);
    }

    private function planInitialized(): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM schedule_plan WHERE id = :pid AND team_selection_initialized',
            ['pid' => $this->planId],
        );
    }

    private function reload(CoachWish $wish): CoachWish
    {
        $fresh = $this->em->getRepository(CoachWish::class)->find($wish->getId());
        self::assertInstanceOf(CoachWish::class, $fresh);

        return $fresh;
    }

    private function countPlans(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM schedule_plan WHERE club_id = :c', ['c' => $this->club->getId()]);
    }

    private function countSchedules(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM schedule WHERE club_id = :c', ['c' => $this->club->getId()]);
    }

    /**
     * Une doléance d'un AUTRE club, avec son propre plan de période — cible de l'abus « autre club ».
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function foreignClubWish(): array
    {
        $uid = uniqid('', true);
        $otherClub = (new Club)->setName('Other ' . $uid)->setSlug('other-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($otherClub);
        $this->em->flush();

        $this->scopeGucToClub($otherClub->getId());
        $otherSeason = (new Season)->setClubId($otherClub->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($otherSeason);
        $otherTeam = (new Team)->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('X1');
        $this->em->persist($otherTeam);
        $otherMother = (new CalendarEntry)->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('V')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($otherMother);
        $this->em->flush();
        $otherPlanId = $this->planIdOf($otherMother);

        $otherWish = (new CoachWish)->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setCalendarEntryId($otherMother->getId())->setWeekStart(new DateTimeImmutable('2026-02-16'))
            ->setTeamId($otherTeam->getId())->setCoachId(null)->setSlotsWanted(2);
        $this->em->persist($otherWish);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        return [$otherWish->getId(), $otherPlanId, $otherTeam->getId()];
    }

    private function tokenForRole(string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);
        $user = (new User)->setEmail('member' . $uid . '@test.com')->setFirstName('M')->setLastName('R');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole($role)->setIsActive(true));
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
