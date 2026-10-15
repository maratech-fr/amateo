<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SeasonStatus;
use App\Service\ImplicitRuleResolver;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + sémantique de contrainte (§7.1).
 *
 * Les 4 règles implicites « bien-être » sont réglables PAR PORTÉE (ADR-0002 inv. 5 — intensité
 * HARD/PREFERRED + seuils, contrat 2.7). Ce que le gestionnaire STOCKE doit être EXACTEMENT le
 * bloc `implicitRules` que le payload émet au solveur — DÉFAUTS RÉSOLUS compris. Le résolveur
 * (`ImplicitRuleResolver`) est la maison unique dont DÉRIVENT la collection GET et le payload.
 *
 * ⚠ INVARIANT RETOURNÉ (bien-être PAR PÉRIODE) — ce test gardait l'inverse (« l'overlay émet le
 * MÊME bloc season-scopé que la base »). Il l'ÉPINGLE désormais dans l'autre sens :
 *  - chemin BASE (plan null) : le payload émet la portée SAISON résolue ;
 *  - un plan né APRÈS la fonctionnalité émet SA COPIE — une modification de la SAISON postérieure
 *    à la naissance NE REDESCEND PAS dans son payload (la copie est matérialisée à la naissance) ;
 *  - un plan LEGACY (zéro ligne) émet le bloc SAISON — le repli vivant est ÉPINGLÉ ici.
 *
 * Falsifié dans les deux sens : un réglage stocké DOIT apparaître (un builder qui émettrait
 * toujours les défauts échoue), une règle non stockée DOIT valoir le défaut (un builder qui
 * inventerait échoue), la copie de période NE DOIT PAS suivre la saison (un builder resté
 * season-scopé pour les périodes échoue), et un plan legacy DOIT suivre la saison (un builder
 * qui lirait aveuglément les lignes du plan émettrait des défauts et échoue).
 */
#[Group('phase1')]
#[Group('integration')]
final class ImplicitRulePayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ImplicitRuleResolver $resolver;

    /**
     * Chemin base : le bloc émis == le bloc résolu, réglages stockés compris, défauts pour le reste.
     */
    public function testClubSeasonPayloadEmitsTheResolvedBlockStoredOverDefaults(): void
    {
        [$club, $season] = $this->seed();

        // Deux règles DÉVIÉES du défaut : un cran (salarie → PREFERRED) et un cran + seuil
        // (coachRestDay → PREFERRED, minRestDays 3). Les deux autres restent au défaut (absentes).
        $this->store($club, $season, ImplicitRuleKey::COACH_REST_DAY, ImplicitRuleIntensity::PREFERRED, ['minRestDays' => 3]);
        $this->store($club, $season, ImplicitRuleKey::SALARIE_DISTRIBUTION, ImplicitRuleIntensity::PREFERRED, null);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        // Parité stricte : le payload émet EXACTEMENT ce que le résolveur résout — maison unique.
        self::assertSame(
            $this->resolver->resolve($club->getId(), $season->getId()),
            $payload['implicitRules'],
            'le bloc implicitRules émis doit être EXACTEMENT le bloc résolu',
        );

        // Sens 1 — le stocké est REFLÉTÉ (un builder qui émettrait toujours les défauts échoue ici).
        self::assertSame(['intensity' => 'PREFERRED', 'minRestDays' => 3], $payload['implicitRules']['coachRestDay']);
        self::assertSame(['intensity' => 'PREFERRED'], $payload['implicitRules']['salarieDistribution']);

        // Sens 2 — le NON stocké vaut le DÉFAUT (un builder qui inventerait une valeur échoue ici).
        self::assertSame(['intensity' => 'HARD', 'maxConsecutive' => 3], $payload['implicitRules']['maxConsecutiveSessions']);
        self::assertSame(['intensity' => 'HARD'], $payload['implicitRules']['ageAscending']);
    }

    /**
     * Chemin overlay de période : le plan né APRÈS la fonctionnalité émet SA COPIE, matérialisée
     * à la naissance — une modification de la SAISON postérieure NE REDESCEND PAS.
     */
    public function testPeriodPlanEmitsItsOwnCopyAndASeasonChangeDoesNotCascade(): void
    {
        [$club, $season] = $this->seed();

        // Réglage SAISON au moment de la naissance du plan : PREFERRED, maxConsecutive 5.
        $this->store($club, $season, ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS, ImplicitRuleIntensity::PREFERRED, ['maxConsecutive' => 5]);
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season);
        // planIdOf → provisionPeriodPlan → matérialise les 4 lignes de CE plan (copie de la saison
        // à cet instant : maxConsecutive PREFERRED 5, le reste au défaut).
        $planId = $this->planIdOf($entry);

        // APRÈS la naissance, la SAISON change : maxConsecutive redevient HARD, seuil par défaut.
        $seasonRow = $this->em->getRepository(ImplicitRuleSetting::class)->findOneBy([
            'clubId' => $club->getId(),
            'seasonId' => $season->getId(),
            'schedulePlanId' => null,
            'ruleKey' => ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS,
        ]);
        self::assertInstanceOf(ImplicitRuleSetting::class, $seasonRow);
        $seasonRow->setIntensity(ImplicitRuleIntensity::HARD);
        $seasonRow->setParams(null);
        $this->em->flush();
        $this->em->clear();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        // Le payload de période garde la COPIE de naissance…
        self::assertSame(['intensity' => 'PREFERRED', 'maxConsecutive' => 5], $payload['implicitRules']['maxConsecutiveSessions']);
        self::assertSame(
            $this->resolver->resolveForPlan($club->getId(), $season->getId(), $planId),
            $payload['implicitRules'],
            'le payload de période émet EXACTEMENT la copie résolue de son plan',
        );
        // …et ne suit PAS la saison, qui vaut désormais le défaut HARD.
        self::assertNotSame(
            $this->resolver->resolve($club->getId(), $season->getId())['maxConsecutiveSessions'],
            $payload['implicitRules']['maxConsecutiveSessions'],
            'une modification de la saison postérieure à la naissance du plan ne redescend pas dans sa copie',
        );
    }

    /**
     * Un plan LEGACY (zéro ligne, né avant la fonctionnalité) émet le bloc SAISON — repli vivant.
     */
    public function testALegacyPlanWithoutCopyEmitsTheSeasonBlock(): void
    {
        [$club, $season] = $this->seed();

        $this->store($club, $season, ImplicitRuleKey::COACH_REST_DAY, ImplicitRuleIntensity::PREFERRED, ['minRestDays' => 3]);
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);

        // On simule un plan d'AVANT la fonctionnalité : ses 4 lignes copiées sont supprimées.
        foreach ($this->em->getRepository(ImplicitRuleSetting::class)->findBy(['schedulePlanId' => $planId]) as $copied) {
            $this->em->remove($copied);
        }
        $this->em->flush();
        $this->em->clear();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertSame(
            $this->resolver->resolve($club->getId(), $season->getId()),
            $payload['implicitRules'],
            'un plan legacy (zéro ligne) retombe sur la portée saison — repli vivant, byte-identique à l\'avant-fonctionnalité',
        );
        self::assertSame(['intensity' => 'PREFERRED', 'minRestDays' => 3], $payload['implicitRules']['coachRestDay']);
    }

    /**
     * Absence totale de réglage = payload aux DÉFAUTS (tout HARD, seuils historiques) — le payload
     * d'absence de PR 1, byte-identique.
     */
    public function testNoStoredSettingsEmitsAllDefaults(): void
    {
        [$club, $season] = $this->seed();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(ImplicitRuleResolver::defaults(), $payload['implicitRules']);
    }

    /**
     * P4-340 — axe sémantique de contrainte + contrat backend↔engine (§7.1).
     *
     * Promesse : un club NEUF génère un plan de PÉRIODE. Sa règle opt-in `maxConsecutiveDays`
     * naît OFF — la copie matérialisée à la naissance du plan ({@see ImplicitRuleSettingRepository::
     * materializeForPlan}) la porte à OFF —, et le payload overlay doit alors l'OMETTRE, EXACTEMENT
     * comme il omet une opt-in ABSENTE (`testNoStoredSettingsEmitsAllDefaults`) : le moteur
     * n'accepte que HARD/PREFERRED (`engine/app/schemas/input_schema.py`) et refuse
     * `intensity:"OFF"` (422 Pydantic). Sans cette omission, un club neuf ne peut pas générer
     * un plan de période.
     *
     * Falsifié : un résolveur qui SÉRIALISE l'opt-in stockée OFF (via `entryFrom`) au lieu de la
     * SAUTER émet la clé — c'est l'état d'avant le correctif, et le test rougit.
     */
    public function testPeriodPlanOfAFreshClubOmitsTheOptInRuleMaterializedOff(): void
    {
        [$club, $season] = $this->seed();

        // Club NEUF, zéro réglage : à la naissance du plan, les 4 règles sont matérialisées —
        // l'opt-in maxConsecutiveDays à OFF (les autres au défaut HARD).
        $entry = $this->holidayPeriod($club, $season);
        $planId = $this->planIdOf($entry);

        // Précondition EXPLICITE : la copie de plan porte bien l'opt-in à OFF (sinon le test
        // serait vacant — il faut que le foyer ait quelque chose d'OFF à sauter).
        $planRow = $this->em->getRepository(ImplicitRuleSetting::class)->findOneBy([
            'schedulePlanId' => $planId,
            'ruleKey' => ImplicitRuleKey::MAX_CONSECUTIVE_DAYS,
        ]);
        self::assertInstanceOf(ImplicitRuleSetting::class, $planRow);
        self::assertSame(ImplicitRuleIntensity::OFF, $planRow->getIntensity(), 'précondition : le plan d\'un club neuf matérialise l\'opt-in à OFF');

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertArrayNotHasKey(
            'maxConsecutiveDays',
            $payload['implicitRules'],
            'une opt-in matérialisée OFF sur le plan NE DOIT PAS entrer dans le payload overlay (le moteur refuse OFF)',
        );
        // Témoin anti-vide : les règles non opt-in restent présentes.
        self::assertArrayHasKey('maxConsecutiveSessions', $payload['implicitRules']);
        self::assertArrayHasKey('ageAscending', $payload['implicitRules']);
        // Parité maison unique : le payload émet EXACTEMENT ce que le résolveur de plan résout.
        self::assertSame(
            $this->resolver->resolveForPlan($club->getId(), $season->getId(), $planId),
            $payload['implicitRules'],
            'le payload de période émet EXACTEMENT la copie résolue de son plan',
        );
    }

    /**
     * P4-340 — même promesse côté SAISON : une opt-in STOCKÉE OFF (le gestionnaire active
     * « jours consécutifs » au wizard puis la repasse à « Inactive ») doit être OMISE du payload
     * de saison, sinon la génération de SAISON part en 422 moteur.
     *
     * Falsifié : un résolveur qui sérialise l'opt-in stockée OFF émet la clé → rouge.
     */
    public function testSeasonPayloadOmitsAnOptInRuleStoredOff(): void
    {
        [$club, $season] = $this->seed();

        $this->store($club, $season, ImplicitRuleKey::MAX_CONSECUTIVE_DAYS, ImplicitRuleIntensity::OFF, null);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertArrayNotHasKey(
            'maxConsecutiveDays',
            $payload['implicitRules'],
            'une opt-in STOCKÉE OFF en saison NE DOIT PAS entrer dans le payload (comme une opt-in absente)',
        );
        self::assertArrayHasKey('ageAscending', $payload['implicitRules']);
        self::assertSame(
            $this->resolver->resolve($club->getId(), $season->getId()),
            $payload['implicitRules'],
            'le bloc implicitRules émis doit être EXACTEMENT le bloc résolu',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $this->resolver = self::getContainer()->get(ImplicitRuleResolver::class);
    }

    /** @param array<string, mixed>|null $params */
    private function store(Club $club, Season $season, ImplicitRuleKey $ruleKey, ImplicitRuleIntensity $intensity, ?array $params): void
    {
        $setting = new ImplicitRuleSetting;
        $setting->setClubId($club->getId());
        $setting->setSeasonId($season->getId());
        $setting->setRuleKey($ruleKey);
        $setting->setIntensity($intensity);
        $setting->setParams($params);
        $this->em->persist($setting);
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Implicit Parity Club');
        $club->setSlug('impl-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('IPC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('impl-parity-' . $uid . '@test.com');
        $user->setFirstName('I');
        $user->setLastName('P');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
