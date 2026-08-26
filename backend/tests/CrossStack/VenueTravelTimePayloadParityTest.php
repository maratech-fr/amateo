<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\VenueTravelRuleSetting;
use App\Entity\VenueTravelTime;
use App\Enum\SeasonStatus;
use App\Enum\TeamLinkIntensity;
use App\Enum\VenueTravelTimeSource;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + pipeline de génération (§7.1). P2-53 RMM-8 PR-2.
 *
 * Ce que le club STOCKE (la matrice de trajet {venueAId, venueBId, drivingMinutes, walkingMinutes},
 * STRUCTURE de club+saison) doit être EXACTEMENT le bloc `venueTravelTimes` que le payload émet au
 * solveur, TRIÉ par couple. Et sa présence (≥1 ligne) — et ELLE SEULE — active la règle implicite
 * `travelTime` (PREFERRED + défaut 20) : « l'activation au premier geste sur la matrice », jamais
 * un changement silencieux chez un club qui n'a rien touché (opt-in P2-42).
 *
 * Falsifié dans les DEUX sens :
 *  - une ligne stockée DOIT apparaître, triée (un builder émettant [] échoue) ; le statut véhiculé
 *    d'un coach DOIT voyager ;
 *  - une ligne d'un AUTRE club/saison ne fuit PAS ; un club SANS matrice émet [] ET n'active PAS la
 *    règle ET son payload est par ailleurs identique à avant (l'anti-régression).
 */
#[Group('phase1')]
#[Group('integration')]
final class VenueTravelTimePayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    /**
     * Sens 1 — le socle émet EXACTEMENT ses lignes stockées, TRIÉES par (venueAId, venueBId), et
     * active la règle `travelTime` (PREFERRED + défaut 20). Un builder émettant [] échoue.
     */
    public function testStoredMatrixIsEmittedSortedAndActivatesTheRule(): void
    {
        [$club, $season] = $this->seed();
        // Deux couples déposés dans le DÉSORDRE : le payload doit les trier (aaa.. avant bbb..).
        $vBbb = 'bbbbbbbb-0000-4000-8000-000000000001';
        $vAaa = 'aaaaaaaa-0000-4000-8000-000000000001';
        $vCcc = 'cccccccc-0000-4000-8000-000000000001';
        $this->travelTime($club, $season, $vBbb, $vCcc, 12, 40);
        $this->travelTime($club, $season, $vAaa, $vBbb, null, 25);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(
            [
                ['venueAId' => $vAaa, 'venueBId' => $vBbb, 'drivingMinutes' => null, 'walkingMinutes' => 25],
                ['venueAId' => $vBbb, 'venueBId' => $vCcc, 'drivingMinutes' => 12, 'walkingMinutes' => 40],
            ],
            $payload['venueTravelTimes'],
            'le socle émet EXACTEMENT la matrice stockée, triée par couple, colonnes nulles comprises',
        );

        self::assertArrayHasKey('travelTime', $payload['implicitRules'], 'la présence de matrice active la règle');
        self::assertSame(
            ['intensity' => 'PREFERRED', 'defaultMinutes' => 20],
            $payload['implicitRules']['travelTime'],
            'la règle naît PREFERRED avec le défaut 20 (l’écran PR-3 réglera le cran/seuil)',
        );
    }

    /**
     * PR-4 — le LEVIER Obligatoire : une intensité stockée MANDATORY est ÉMISE MANDATORY (le
     * défaut 20 reste). Falsification sens 1 : un builder qui garderait PREFERRED en dur échoue.
     */
    public function testStoredMandatoryIntensityIsEmitted(): void
    {
        [$club, $season] = $this->seed();
        $this->travelTime($club, $season, 'aaaaaaaa-0000-4000-8000-00000000000a', 'bbbbbbbb-0000-4000-8000-00000000000a', 10, 20);
        $this->travelRuleSetting($club, $season, TeamLinkIntensity::MANDATORY);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(
            ['intensity' => 'MANDATORY', 'defaultMinutes' => 20],
            $payload['implicitRules']['travelTime'],
            'l’intensité stockée MANDATORY est émise MANDATORY (le levier Obligatoire), défaut 20 conservé',
        );
    }

    /**
     * PR-4 — falsification sens 2 : un réglage stocké PREFERRED (le défaut, explicitement posé)
     * émet bien PREFERRED. Un builder qui émettrait toujours MANDATORY dès qu'une ligne existe
     * échouerait ici. Combiné à `testClubWithoutMatrixEmitsEmptyBlockAndNoRule` (rien de stocké
     * ⇒ PREFERRED, payload par ailleurs inchangé), le levier est falsifié dans les DEUX sens.
     */
    public function testStoredPreferredIntensityIsEmitted(): void
    {
        [$club, $season] = $this->seed();
        $this->travelTime($club, $season, 'aaaaaaaa-0000-4000-8000-00000000000b', 'bbbbbbbb-0000-4000-8000-00000000000b', 10, 20);
        $this->travelRuleSetting($club, $season, TeamLinkIntensity::PREFERRED);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame(
            ['intensity' => 'PREFERRED', 'defaultMinutes' => 20],
            $payload['implicitRules']['travelTime'],
            'un réglage PREFERRED stocké émet PREFERRED (le builder LIT le réglage, ne devine pas)',
        );
    }

    /**
     * Le statut véhiculé d'un coach VOYAGE dans le bloc `coaches` (champ `isVehicled`).
     */
    public function testCoachVehicledStatusIsEmitted(): void
    {
        [$club, $season] = $this->seed();
        $this->coach($club, $season, vehicled: true);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertArrayHasKey('isVehicled', $payload['coaches'][0]);
        self::assertTrue($payload['coaches'][0]['isVehicled'], 'un coach véhiculé émet isVehicled=true');
    }

    /**
     * Sens 2 — une ligne d'un AUTRE club/saison ne fuit pas dans le payload de ce club.
     */
    public function testMatrixOfAnotherClubDoesNotLeak(): void
    {
        [$club, $season] = $this->seed();
        [$other, $otherSeason] = $this->seed();
        $this->travelTime($other, $otherSeason, 'aaaaaaaa-0000-4000-8000-000000000009', 'bbbbbbbb-0000-4000-8000-000000000009', 10, 20);
        $this->em->flush();

        // Repositionne le GUC sur le club observé (le dernier seed l'avait porté sur `other`).
        $this->scopeGucToClub($club->getId());
        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame([], $payload['venueTravelTimes'], 'la matrice d’un autre club ne fuit pas');
        self::assertArrayNotHasKey('travelTime', $payload['implicitRules'], 'la règle reste inactive sans matrice PROPRE');
    }

    /**
     * Sens 2 — un club SANS matrice émet un bloc VIDE, N'active PAS la règle, et son payload est
     * par ailleurs INCHANGÉ (anti-régression : la fonctionnalité est silencieuse tant qu'on n'a
     * pas touché la matrice). Un builder qui activerait la règle par défaut échoue ici.
     */
    public function testClubWithoutMatrixEmitsEmptyBlockAndNoRule(): void
    {
        [$club, $season] = $this->seed();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertSame([], $payload['venueTravelTimes'], 'aucune matrice ⇒ bloc vide (byte-identique côté moteur)');
        self::assertArrayNotHasKey(
            'travelTime',
            $payload['implicitRules'],
            'aucune matrice ⇒ règle INACTIVE : jamais un changement silencieux (opt-in)',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    private function travelTime(Club $club, Season $season, string $vA, string $vB, ?int $driving, ?int $walking): VenueTravelTime
    {
        // Normalise venueAId < venueBId comme le processor.
        if (strcasecmp($vA, $vB) > 0) {
            [$vA, $vB] = [$vB, $vA];
        }
        $row = new VenueTravelTime;
        $row->setClubId($club->getId());
        $row->setSeasonId($season->getId());
        $row->setVenueAId($vA);
        $row->setVenueBId($vB);
        $row->setDrivingMinutes($driving);
        $row->setWalkingMinutes($walking);
        if (null !== $driving) {
            $row->setDrivingSource(VenueTravelTimeSource::MANUAL);
        }
        if (null !== $walking) {
            $row->setWalkingSource(VenueTravelTimeSource::MANUAL);
        }
        $this->em->persist($row);

        return $row;
    }

    private function travelRuleSetting(Club $club, Season $season, TeamLinkIntensity $intensity): VenueTravelRuleSetting
    {
        $setting = new VenueTravelRuleSetting;
        $setting->setClubId($club->getId());
        $setting->setSeasonId($season->getId());
        $setting->setIntensity($intensity);
        $this->em->persist($setting);

        return $setting;
    }

    private function coach(Club $club, Season $season, bool $vehicled): Coach
    {
        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName('C');
        $coach->setLastName(substr($this->uuid(), 0, 6));
        $coach->setIsActive(true);
        $coach->setIsVehicled($vehicled);
        $this->em->persist($coach);

        return $coach;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Travel Parity Club');
        $club->setSlug('travel-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TVP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('travel-parity-' . $uid . '@test.com');
        $user->setFirstName('T');
        $user->setLastName('P');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

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
