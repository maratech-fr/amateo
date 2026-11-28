<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\ScheduleStatus;
use App\Tests\LoadsVersionDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot conception 5, PR 3, lignes 17 et 19 du plan) : « Charger » a une seule porte,
 * `POST /api/schedules/{id}/load`, pour tout plan, et ses refus sont nommés. La version d'un autre club répond le
 * même 404 qu'une version inconnue ; une version non terminée, sans copie, ou pendant une génération répond 409 ; une
 * version d'un plan de période validé reste refusée ; sous un socle en vigueur, une autre version se charge (PR 5). La capacité
 * `canLoad` vaut le verdict. Les deux anciennes routes, `regenerate-from` et `load-locks`, n'existent plus.
 *
 * Le Membre (403) est joué par `Security/MemberWriteSweepTest`, la saison archivée par `Security/SeasonReadonlyTest` ;
 * une copie qui cite l'équipe d'un autre club, par `Security/PlanStateTenantIsolationTest` (angles du sensible :
 * isolation et abus).
 */
#[Group('integration')]
final class LoadVersionGuardsTest extends WebTestCase
{
    use LoadsVersionDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Une version terminée, avec copie, rien en vol : la capacité l'offre et le geste aboutit ; l'étoile servie
     * (`isLiveContext`) passe de l'ancienne version chargée à celle-ci (reprend `RegenerateFromVersionTest::testScheduleApiExposesIsLiveContext`).
     */
    public function testALoadableVersionIsOfferedAndLoads(): void
    {
        $this->loadTeam('SM1');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);
        self::assertTrue($this->served($v2->getId())['isLiveContext'] ?? null, 'témoin : l\'étoile est sur la version 2');

        self::assertTrue($this->capabilitiesOf($v1->getId())['canLoad'] ?? null, 'la capacité offre « Charger »');
        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version terminée');
        self::assertSame(['id' => $v1->getId(), 'status' => 'COMPLETED'], array_intersect_key($this->loadResponse(), ['id' => 1, 'status' => 1]));
        self::assertTrue($this->served($v1->getId())['isLiveContext'] ?? null, 'l\'étoile servie passe sur la version chargée');
        self::assertFalse($this->served($v2->getId())['isLiveContext'] ?? null, 'et quitte l\'ancienne');
    }

    /** La version d'un autre club répond le même 404, octet pour octet, qu'une version qui n'existe pas. */
    public function testAnotherClubsVersionAnswersTheSame404AsAnUnknownOne(): void
    {
        $this->loadTeam('SM1');
        $theirs = $this->loadVersionOf($this->soclePlanId);
        $theirClub = $this->clubId;
        $this->seedLoadClub('autre-club');
        self::assertNotSame($theirClub, $this->clubId, 'témoin : deux clubs');

        $this->loadVersion($theirs->getId());
        $foreign = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];
        $this->loadVersion('00000000-0000-4000-8000-0000000000aa');
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

        self::assertSame(404, $foreign[0], 'la version d\'un autre club est introuvable');
        self::assertSame($unknown, $foreign, 'même statut, même corps qu\'une version inconnue');
    }

    /** Une version qui n'est pas terminée ne se charge pas ; la capacité le dit. */
    #[TestWith([ScheduleStatus::FAILED])]
    #[TestWith([ScheduleStatus::DRAFT])]
    public function testAVersionThatIsNotCompletedIsRefused(ScheduleStatus $status): void
    {
        $this->loadTeam('SM1');
        $version = $this->loadVersionOf($this->soclePlanId, $status, withCopy: false);

        $this->assertRefusedAndAnnounced($version->getId(), 'une version ' . $status->value);
    }

    /** Une version terminée sans copie (née avant le lot, ni validée ni chargée) ne se charge pas. */
    public function testAVersionWithoutACopyIsRefused(): void
    {
        $this->loadTeam('SM1');
        $version = $this->loadVersionOf($this->soclePlanId, withCopy: false);
        $this->makeLoaded($this->loadVersionOf($this->soclePlanId));

        $this->assertRefusedAndAnnounced($version->getId(), 'une version sans copie', withReason: true);
    }

    /** Pendant qu'une version du plan se calcule, rien ne se charge. */
    public function testLoadingIsRefusedWhileAGenerationRuns(): void
    {
        $this->loadTeam('SM1');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->loadVersionOf($this->soclePlanId, ScheduleStatus::GENERATING, withCopy: false);

        $this->assertRefusedAndAnnounced($v1->getId(), 'charger pendant une génération');
    }

    /**
     * Depuis la PR 5 (ligne 31) : sous un socle en vigueur, une autre version s'offre et se charge ; l'en vigueur ne
     * bouge pas. Le 200 seul est aussi joué par `Security/SeasonPlanInForceTest::testLoadingASiblingWhileTheSocleIsInForceIsAccepted` ;
     * ici, la capacité dit le même verdict que le geste.
     */
    public function testUnderASocleInForceAnotherVersionIsOfferedAndLoads(): void
    {
        $this->loadTeam('SM1');
        $inForce = $this->loadVersionOf($this->soclePlanId);
        $this->choosePlanVersion($inForce);
        // L'état réel après validation : l'étoile reste sur la version en vigueur (sans pointeur, le repli prendrait
        // la dernière terminée, la sœur, qui passerait pour déjà chargée).
        $this->makeLoaded($inForce);
        $sibling = $this->loadVersionOf($this->soclePlanId);
        self::assertSame($inForce->getId(), $this->loadedOf($this->soclePlanId), 'témoin : l\'étoile est sur la version en vigueur');

        self::assertTrue($this->capabilitiesOf($sibling->getId())['canLoad'] ?? null, 'la capacité offre « Charger » sous un socle en vigueur');
        $this->loadVersion($sibling->getId());

        $this->assertLoadAccepted('charger une autre version sous un socle en vigueur');
        self::assertSame($sibling->getId(), $this->loadedOf($this->soclePlanId), 'l\'étoile passe sur la version chargée');
        self::assertSame($inForce->getId(), $this->loadScalar('SELECT chosen_schedule_id FROM schedule_plan WHERE id = ?', [$this->soclePlanId]), 'la version en vigueur le reste');
    }

    /** Jusqu'à la PR 5 : une version d'un plan de période validé reste refusée. */
    public function testAVersionOfAValidatedPeriodPlanIsStillRefused(): void
    {
        $this->loadTeam('SM1');
        $noel = $this->loadPeriodPlan('Noël', '2026-12-21', '2027-01-03');
        $older = $this->loadVersionOf($noel);
        $validated = $this->loadVersionOf($noel);
        $this->choosePlanVersion($validated);

        $this->assertRefusedAndAnnounced($older->getId(), 'charger une version d\'un plan de période validé');
    }

    /** Les deux anciennes routes de « Charger » n'existent plus. */
    #[TestWith(['regenerate-from'])]
    #[TestWith(['load-locks'])]
    public function testTheOldLoadRoutesAreGone(string $route): void
    {
        $this->loadTeam('SM1');
        $version = $this->loadVersionOf($this->soclePlanId);

        $this->client->request('POST', '/api/schedules/' . $version->getId() . '/' . $route, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], '{}');

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'la route ' . $route . ' n\'est plus servie');
        self::assertStringContainsString('No route found', (string) $this->client->getResponse()->getContent(), 'introuvable au routeur, pas un refus métier');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLoadClub();
    }

    /** 409, l'étoile ne bouge pas, la capacité ne l'offre pas (avec son motif quand la version reste consultable). */
    private function assertRefusedAndAnnounced(string $versionId, string $what, bool $withReason = false): void
    {
        $this->scopeGucToClub($this->clubId);
        $planId = (string) $this->em->getConnection()->fetchOne('SELECT schedule_plan_id FROM schedule WHERE id = ?', [$versionId]);
        $loadedBefore = $this->loadedOf($planId);
        $capabilities = $this->capabilitiesOf($versionId);

        $this->loadVersion($versionId);

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), $what . ' est refusé — corps : ' . $this->client->getResponse()->getContent());
        self::assertSame($loadedBefore, $this->loadedOf($planId), $what . ' : l\'étoile ne bouge pas');
        self::assertFalse($capabilities['canLoad'] ?? null, $what . ' : la capacité ne l\'offre pas');
        if ($withReason) {
            self::assertIsString($capabilities['loadBlockedReason'] ?? null, $what . ' : la capacité dit pourquoi, la version restant consultable');
        }
    }

    /** @return array<string, mixed> la version telle que l'API la sert */
    private function served(string $versionId): array
    {
        $this->client->request('GET', '/api/schedules/' . $versionId, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken, 'HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        return $this->loadResponse();
    }

    /** @return array<string, mixed> */
    private function capabilitiesOf(string $versionId): array
    {
        $capabilities = $this->served($versionId)['capabilities'] ?? null;
        self::assertIsArray($capabilities, 'la version porte ses capacités');

        return $capabilities;
    }
}
