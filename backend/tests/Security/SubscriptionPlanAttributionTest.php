<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\PlanEntitlements;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P1-3 PR A — socle d'offres par statut. Garde le modèle (6 offres seedées, codes
 * uniques), l'exposition (bêta cachée, aucun montant), l'attribution superadmin
 * (la commande écrit la FK), l'offre EFFECTIVE calculée à la lecture (expiration →
 * Découverte ; démo → droits pleins) et le bloc `entitlements` de /api/me.
 *
 * ⚠ Groupe phase1 mais PAS un step du gate `blocking-tests` (§4) : tourne dans
 * `backend-tests` (dossier entier). L'enforcement (débit crédits, cap équipes) est la
 * PR B — ce test ne vérifie QUE le socle.
 */
#[Group('phase1')]
#[Group('integration')]
final class SubscriptionPlanAttributionTest extends WebTestCase
{
    use TenantGucTrait;

    private const string ESSENTIEL_ID = '00000000-0000-4000-8000-000000000002';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testTheSixOffersAreSeededWithUniqueCodes(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT code FROM subscription_plan ORDER BY code');
        $codes = array_map(static fn (array $r): string => (string) $r['code'], $rows);

        self::assertSame(
            ['beta', 'club', 'decouverte', 'essentiel', 'grand-club', 'sans-limite'],
            $codes,
            'les 6 offres sont seedées avec des codes uniques',
        );
    }

    public function testBetaIsHiddenFromThePublicCollection(): void
    {
        [$user] = $this->seedClub();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/subscription_plans');
        self::assertResponseIsSuccessful();

        $codes = array_map(
            static fn (array $row): string => (string) $row['code'],
            json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['member'],
        );

        self::assertNotContains('beta', $codes, 'l\'offre bêta est superadmin-only : jamais dans le catalogue public');
        self::assertContains('decouverte', $codes);
        self::assertContains('essentiel', $codes);
    }

    public function testNoPriceFieldIsExposed(): void
    {
        [$user] = $this->seedClub();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/subscription_plans');
        self::assertResponseIsSuccessful();

        $member = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['member'];
        self::assertNotEmpty($member);
        $first = $member[0];

        self::assertArrayHasKey('code', $first);
        self::assertArrayHasKey('maxTeams', $first);
        self::assertArrayHasKey('maxGenerations', $first);
        self::assertArrayNotHasKey('monthlyPrice', $first, 'aucun montant n\'est exposé (P1-3)');
        self::assertArrayNotHasKey('annualPrice', $first);
    }

    public function testSetPlanCommandWritesTheForeignKey(): void
    {
        [, $club] = $this->seedClub();
        $clubId = $club->getId();

        $tester = new CommandTester(new Application(self::$kernel)->find('app:clubs:set-plan'));
        $exit = $tester->execute(['--club' => $clubId, '--plan' => 'essentiel']);
        self::assertSame(0, $exit, $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Club::class)->find($clubId);
        self::assertSame(self::ESSENTIEL_ID, $reloaded?->getPlanId(), 'l\'attribution écrit la FK offre');
    }

    public function testExpiredPaidOfferFallsBackToDecouverte(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        $season = (new Season)->setStartDate(new DateTimeImmutable('2026-09-01'));
        $club = (new Club)
            ->setPlanId(self::ESSENTIEL_ID)
            ->setPaidSeasonYear(2024); // < seasonYear(2026-09-01) = 2026 → périmé

        $result = $entitlements->forClub($club, $season);

        self::assertSame('decouverte', $result['planCode'], 'une offre payante périmée retombe sur Découverte');
        self::assertSame(10, $result['creditsMax'], 'le pool Découverte est réactivé (10 crédits)');
        self::assertFalse($result['seasonTransition'], 'la bascule de saison est fermée en Découverte effective');
        self::assertTrue($result['canGenerate'], 'crédits neufs → sortie possible');
    }

    public function testDemoClubAlwaysHasFullRights(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        $season = (new Season)->setStartDate(new DateTimeImmutable('2026-09-01'));
        // Démo SANS offre payante : l'exemption vient de isDemo, pas d'un plan.
        $club = (new Club)->setIsDemo(true);

        $result = $entitlements->forClub($club, $season);

        self::assertTrue($result['canGenerate']);
        self::assertTrue($result['canPlaceMatches']);
        self::assertTrue($result['canExportPdf']);
        self::assertNull($result['creditsMax'], 'droits pleins → crédits illimités');
        self::assertTrue($result['seasonTransition']);
    }

    public function testApiMeExposesTheEntitlementsBlock(): void
    {
        [$user] = $this->seedClub();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('entitlements', $body['club']);
        $entitlements = $body['club']['entitlements'];

        foreach (['planCode', 'planName', 'maxTeams', 'teamsUsed', 'creditsMax', 'creditsUsed', 'canGenerate', 'canPlaceMatches', 'canExportPdf', 'seasonTransition'] as $key) {
            self::assertArrayHasKey($key, $entitlements, "entitlements.$key manque");
        }

        // Club fraîchement seedé (planId null) → offre Découverte par défaut.
        self::assertSame('decouverte', $entitlements['planCode']);
        self::assertSame(10, $entitlements['creditsMax']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Club} */
    private function seedClub(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $club = new Club;
        $club->setName('Offre ' . $uid);
        $club->setSlug('offre-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('OFFR' . strtoupper(substr(md5($uid), 0, 7)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('offre-' . $uid . '@test.com');
        $user->setFirstName('O');
        $user->setLastName('Ffre');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2026-2027');
        $season->setStartDate(new DateTimeImmutable('2026-09-01'));
        $season->setEndDate(new DateTimeImmutable('2027-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return [$user, $club];
    }
}
