<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\PlanEntitlements;
use App\Tests\Support\SubscribesClub;
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
 * P1-3 PR A, lot 8 PR 2 — socle d'offres. Garde le modèle (trois offres, l'offre complète et
 * ses trois paliers), l'exposition (bêta cachée, paliers servis, aucun montant — ni sur l'offre
 * ni sur le palier), l'attribution superadmin (la commande écrit l'offre et le palier), l'offre
 * EFFECTIVE calculée à la lecture (expiration → Découverte ; démo → droits pleins) et le bloc
 * `entitlements` de /api/me (palier compris).
 *
 * ⚠ Groupe phase1 mais PAS un step du gate `blocking-tests` (§4) : tourne dans
 * `backend-tests` (dossier entier). L'enforcement (débit crédits, cap équipes) est la
 * PR B — ce test ne vérifie QUE le socle.
 */
#[Group('phase1')]
#[Group('integration')]
final class SubscriptionPlanAttributionTest extends WebTestCase
{
    use SubscribesClub;
    use TenantGucTrait;

    /** L'ancienne ligne Essentiel, devenue l'offre payante unique (lot 8, PR 2). */
    private const string COMPLETE_ID = '00000000-0000-4000-8000-000000000002';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Lot 8, PR 2 — trois offres (Découverte, l'offre complète, Bêta) ; l'offre complète porte trois paliers 20 / 34 / illimité. */
    public function testThreeOffersAreSeededAndTheCompleteOfferCarriesThreeTiers(): void
    {
        $connection = $this->em->getConnection();
        self::assertSame(
            ['beta', 'complete', 'decouverte'],
            array_map(strval(...), $connection->fetchFirstColumn('SELECT code FROM subscription_plan ORDER BY code')),
            'trois offres : Club et Grand club sont devenus des paliers de l\'offre complète',
        );
        self::assertSame('Offre complète', $connection->fetchOne('SELECT name FROM subscription_plan WHERE id = ?', [self::COMPLETE_ID]), 'l\'ancienne ligne Essentiel porte l\'offre complète');

        /** @var list<array{code: string, position: int|string, max_teams: int|string, plan_id: string}> $tiers */
        $tiers = $connection->fetchAllAssociative('SELECT code, position, max_teams, plan_id::text AS plan_id FROM subscription_plan_tier ORDER BY position');
        self::assertSame(
            [
                ['code' => 'palier-1', 'position' => 1, 'max_teams' => 20, 'plan_id' => self::COMPLETE_ID],
                ['code' => 'palier-2', 'position' => 2, 'max_teams' => 34, 'plan_id' => self::COMPLETE_ID],
                ['code' => 'palier-3', 'position' => 3, 'max_teams' => 0, 'plan_id' => self::COMPLETE_ID],
            ],
            array_map(static fn (array $tier): array => ['code' => $tier['code'], 'position' => (int) $tier['position'], 'max_teams' => (int) $tier['max_teams'], 'plan_id' => $tier['plan_id']], $tiers),
            'trois paliers de l\'offre complète : 20, 34, illimité (0)',
        );
    }

    /**
     * Le catalogue public sert Découverte et l'offre complète — jamais la bêta — ; chaque offre porte
     * ses paliers (`code`, `position`, `maxTeams`), Découverte n'en a aucun, et l'offre ne sert plus
     * de `maxTeams` (le plafond vit sur le palier).
     */
    public function testThePublicCatalogueServesTheTiersOfTheCompleteOffer(): void
    {
        [$user] = $this->seedClub();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/subscription_plans');
        self::assertResponseIsSuccessful();

        $tiersByCode = [];
        foreach (json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['member'] as $row) {
            self::assertArrayNotHasKey('maxTeams', $row, \sprintf('l\'offre %s ne sert plus de plafond : il vit sur le palier', (string) $row['code']));
            $tiersByCode[(string) $row['code']] = $row['tiers'];
        }
        ksort($tiersByCode);

        self::assertSame(
            [
                'complete' => [
                    ['code' => 'palier-1', 'position' => 1, 'maxTeams' => 20],
                    ['code' => 'palier-2', 'position' => 2, 'maxTeams' => 34],
                    ['code' => 'palier-3', 'position' => 3, 'maxTeams' => 0],
                ],
                'decouverte' => [],
            ],
            $tiersByCode,
            'catalogue servi : Découverte sans palier, l\'offre complète et ses trois paliers dans l\'ordre, aucune bêta',
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
        self::assertContains('complete', $codes);
    }

    /**
     * Aucun montant n'est servi (P1-3), ni sur l'offre ni sur un palier : le prix d'un palier
     * (`priceCents`, lot 8 PR 2) existe en base mais ne sort jamais de l'API — l'écran dit « Sur
     * demande ». Témoin : la base porte bien la colonne, sinon son absence ne prouverait rien.
     */
    public function testNoPriceFieldIsExposed(): void
    {
        [$user] = $this->seedClub();
        $this->client->loginUser($user);

        self::assertNotFalse(
            $this->em->getConnection()->fetchOne('SELECT 1 FROM information_schema.columns WHERE table_name = \'subscription_plan_tier\' AND column_name = \'price_cents\''),
            'témoin : le prix d\'un palier a sa place en base',
        );

        $this->client->request('GET', '/api/subscription_plans');
        self::assertResponseIsSuccessful();

        $member = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['member'];
        self::assertNotEmpty($member);
        $tierCount = 0;
        foreach ($member as $offer) {
            self::assertArrayHasKey('code', $offer);
            self::assertArrayHasKey('maxGenerations', $offer);
            self::assertArrayNotHasKey('monthlyPrice', $offer, 'aucun montant n\'est exposé (P1-3)');
            self::assertArrayNotHasKey('annualPrice', $offer);
            foreach ($offer['tiers'] as $tier) {
                ++$tierCount;
                self::assertSame(['code', 'maxTeams', 'position'], self::sortedKeys($tier), \sprintf('le palier %s ne sert que code, position et plafond — jamais son prix', (string) $tier['code']));
            }
        }
        self::assertSame(3, $tierCount, 'témoin : les trois paliers sont servis, la boucle a bien lu chacun');
    }

    /** Le geste « Offre » écrit l'offre ET le palier sur la ligne de la saison. */
    public function testSetPlanCommandWritesTheOfferAndTheTier(): void
    {
        [, $club] = $this->seedClub();
        $clubId = $club->getId();

        $tester = new CommandTester(new Application(self::$kernel)->find('app:clubs:set-plan'));
        $exit = $tester->execute(['--club' => $clubId, '--plan' => 'complete', '--tier' => 'palier-2', '--paid-season' => 'current']);
        self::assertSame(0, $exit, $tester->getDisplay());

        self::assertSame(
            ['plan_id' => self::COMPLETE_ID, 'tier' => 'palier-2'],
            $this->em->getConnection()->fetchAssociative('SELECT l.plan_id::text AS plan_id, t.code AS tier FROM club_subscription l LEFT JOIN subscription_plan_tier t ON t.id = l.tier_id WHERE l.club_id = ?', [$clubId]),
            'l\'attribution écrit l\'offre complète et son palier',
        );
    }

    public function testExpiredPaidOfferFallsBackToDecouverte(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        $season = (new Season)->setStartDate(new DateTimeImmutable('2026-09-01'));
        [, $club] = $this->seedClub();
        $this->subscribeClub($club->getId(), 2024, 'complete', 'palier-1'); // < seasonYear(2026-09-01) = 2026 → périmé

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

        foreach (['planCode', 'planName', 'tierCode', 'maxTeams', 'teamsUsed', 'creditsMax', 'creditsUsed', 'canGenerate', 'canPlaceMatches', 'canExportPdf', 'seasonTransition'] as $key) {
            self::assertArrayHasKey($key, $entitlements, "entitlements.$key manque");
        }

        // Club fraîchement seedé (aucune ligne d'abonnement) → offre Découverte par défaut, sans palier.
        self::assertSame('decouverte', $entitlements['planCode']);
        self::assertNull($entitlements['tierCode'], 'Découverte n\'a pas de palier');
        self::assertSame(10, $entitlements['creditsMax']);
    }

    /**
     * Lot 8, PR 2 — `/api/me` dit le palier de la saison lue (`tierCode`) et sert son plafond par le
     * seul `maxTeams` existant ; la Bêta n'a pas de palier.
     */
    public function testApiMeServesTheTierOfTheSeasonAndItsCap(): void
    {
        foreach ([['complete', 'palier-2', 'Offre complète', 34], ['beta', null, 'Bêta', null]] as [$plan, $tier, $name, $cap]) {
            [$user, $club] = $this->seedClub();
            $this->subscribeClub($club->getId(), 2026, $plan, $tier);
            $this->client->loginUser($user);

            $this->client->request('GET', '/api/me');
            self::assertResponseIsSuccessful();
            $entitlements = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['club']['entitlements'];

            self::assertSame(
                ['planCode' => $plan, 'planName' => $name, 'tierCode' => $tier, 'maxTeams' => $cap],
                ['planCode' => $entitlements['planCode'], 'planName' => $entitlements['planName'], 'tierCode' => \array_key_exists('tierCode', $entitlements) ? $entitlements['tierCode'] : 'absent','maxTeams' => $entitlements['maxTeams']],
                \sprintf('%s : offre, palier et plafond servis pour la saison lue', $plan),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return list<string>
     */
    private static function sortedKeys(array $row): array
    {
        $keys = array_map(strval(...), array_keys($row));
        sort($keys);

        return $keys;
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
