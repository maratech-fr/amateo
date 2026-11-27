<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Access\DemoRegime;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Plans\PlanComposition;
use App\Repository\ClubSubscriptionRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionPlanTierRepository;
use App\Service\Basketball\FfbbExcelImporter;
use App\Service\BrandAssets;
use App\Service\ManagementAccessGuard;
use App\Service\PdfGenerator;
use App\Service\PlanEntitlements;
use App\Service\ProductIdentity;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesCompetitionsTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\Support\SubscribesClub;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * NR P1-3 PR B — LE modèle d'offres, gardé. Deux axes structurants (§7.1) :
 *
 *  · generation pipeline — le POOL DE CRÉDITS de sortie du plan Découverte : refus AVANT
 *    solve à l'épuisement, décompte au SUCCÈS, pool PAR CLUB (partagé entre gestionnaires,
 *    isolé entre clubs — même exigence que P4-45), un refus AMONT ne brûle rien, et
 *    payant/bêta/démo ne sont JAMAIS bridés ;
 *  · auth & memberships / périmètre — le PLAFOND D'ÉQUIPES du palier de l'offre complète, aux
 *    portes de création (lot 8, PR 2) : Découverte et Bêta n'ont pas de palier (aucun plafond),
 *    le palier 1 refuse au-delà de 20 et le palier 2 au-delà de 34 en annonçant le palier suivant,
 *    le palier 3 n'a aucun plafond ; les équipes désactivées comptent ; l'import Excel au-delà du
 *    palier est refusé avec décompte ;
 *  · au-dessus du palier, plus de sorties (lot 8, PR 3) : les sept fonctions à sortie refusées 403
 *    avec le message du dépassement, rien en file ; le rôle et le module fermé répondent avant le
 *    palier ; consulter, charger une version et télécharger un PDF produit restent ouverts ; la
 *    saison qui juge est celle de la requête (abus de saison). L'enchaînement de bout en bout :
 *    `features/au-dessus-du-palier-plus-de-sorties.feature`.
 *
 * Plus l'invisibilité de la bêta (superadmin-only) jusqu'en GET item (finding revue sécu).
 *
 * Le refus « crédits épuisés » vit dans le contrôle central (`ClubAccessListener`, kernel.request
 * priorité 4, APRÈS le rôle) : il s'observe par HTTP réel (il précède le contrôleur, AUCUN moteur
 * n'est touché). Le décompte à la RÉPONSE ne vaut que pour les sorties synchrones (tableur, PDF des
 * matchs) : il s'observe en envoyant une réponse synthétique à TOUS les écouteurs de
 * `kernel.response` (le 2xx réel toucherait le worker de rendu). Une sortie asynchrone (solve,
 * placement, PDF du planning) se décompte à l'obtention, dans le worker :
 * `MessageHandler/GenerationCreditIsPartOfTheResultTest`, `PlacementCreditIsPartOfTheResultTest`,
 * `PdfCreditIsTakenOnDeliveryTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class PlanEntitlementsTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesCompetitionsTrait;
    use SubscribesClub;
    use TenantGucTrait;

    /** Un id de planning bidon suffit : le refus se joue AVANT le contrôleur (comme ClubQuotaTest). */
    private const string DUMMY_SCHEDULE_ID = '00000000-0000-4000-8000-0000000000ff';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    private ?string $categoryId = null;

    /** @var list<string> le HTML reçu par le double du worker de rendu, dans l'ordre */
    private array $renderedHtml = [];

    // ─────────────────────────── Pool de crédits — refus (HTTP) ───────────────────────────

    /** Découverte à 10/10 : les TROIS sortes de sortie sont refusées AVANT le solve, avec message. */
    public function testDecouverteExhaustedRefusesEveryOutputRoute(): void
    {
        // Découverte, pool = maxGenerations = 10. Socle en vigueur : sans lui, le module Matchs fermé
        // répondrait le premier au placement (409, ordre du contrôle central — ClubAccessListenerTest).
        $ctx = $this->seedClub(credits: 10, settleSocle: true);

        $generate = $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate');
        self::assertSame(403, $generate, 'générer sans crédit doit être refusé (403) avant le solve');
        self::assertStringContainsString('gratuites', (string) $this->client->getResponse()->getContent(), 'le refus doit porter un message de conversion');

        self::assertSame(403, $this->post($ctx['user'], '/api/fixtures/place'), 'placer les matchs sans crédit doit être refusé');
        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/export-pdf'), 'exporter un PDF sans crédit doit être refusé');
        // Revue sécu PR B : sans cette route dans le pool, le mur PDF se contournait
        // par le second bouton d'export (même livrable en tableur).
        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/export-xlsx'), 'exporter un XLSX sans crédit doit être refusé');
        // Même livrable de sortie : sans cette route dans le pool, l'export des matchs contournerait le mur.
        self::assertSame(403, $this->post($ctx['user'], '/api/fixtures/export-pdf'), 'exporter les matchs sans crédit doit être refusé');
        // Le comblement d'une période lance un solve : c'est une sortie payante, refusée avant la file.
        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/fill'), 'combler sans crédit doit être refusé avant la mise en file');
        self::assertStringContainsString('gratuites', (string) $this->client->getResponse()->getContent(), 'le refus du comblement porte le message des crédits');
        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/regenerate'), 'régénérer sans crédit doit être refusé');
    }

    /**
     * « Charger une version » ne lance aucun calcul : ce n'est pas une sortie, un club à sec la garde
     * (décision fondateur Q13). Le refus éventuel vient du contrôleur (404 sur un id inconnu), jamais
     * du mur des crédits.
     */
    public function testLoadingAVersionIsNotAnOutput(): void
    {
        $ctx = $this->seedClub(credits: 10);

        self::assertNotSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/load'), 'charger une version n\'est jamais refusé par le mur des crédits');
    }

    /**
     * E10 — un Membre d'un club Découverte À SEC qui appelle une sortie réservée au gestionnaire
     * reçoit le refus du RÔLE, pas celui des crédits : le rôle se juge d'abord (avant le lot 2, le
     * mur des crédits répondait le premier).
     */
    public function testAMemberOfAnExhaustedClubIsRefusedForTheRoleNotTheCredits(): void
    {
        $ctx = $this->seedClub(credits: 10);
        $member = $this->addActiveMember($ctx['club']->getId());

        foreach (['/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate', '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/fill', '/api/fixtures/place'] as $uri) {
            self::assertSame(403, $this->post($member, $uri), $uri . ' : un Membre est refusé');
            $body = $this->readableBody();
            self::assertStringContainsString(ManagementAccessGuard::REFUSAL, $body, $uri . ' : le refus nomme le rôle');
            self::assertStringNotContainsString('gratuites', $body, $uri . ' : le refus ne parle pas des crédits');
        }
    }

    /**
     * Un Membre qui lance le comblement est refusé (403) sans rien entamer : ni crédit du club, ni
     * une unité du plafond horaire de génération du club.
     */
    public function testAMemberLaunchingTheFillTakesNeitherACreditNorTheHourlyCap(): void
    {
        $ctx = $this->seedClub(credits: 0);
        $member = $this->addActiveMember($ctx['club']->getId());
        $bucket = 'club-' . $ctx['club']->getId();

        self::assertSame(403, $this->post($member, '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/fill'), 'combler est réservé au gestionnaire');
        self::assertStringContainsString(ManagementAccessGuard::REFUSAL, $this->readableBody());
        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), 'le refus d\'un Membre ne prend aucun crédit');
        $limiter = self::getContainer()->get('limiter.schedule_generation');
        \assert($limiter instanceof RateLimiterFactory);
        self::assertSame(5, $limiter->create($bucket)->consume(0)->getRemainingTokens(), 'le refus d\'un Membre n\'entame pas le plafond du club');
    }

    /** L'export des matchs consomme le pool commun au succès seulement : un refus 422 ne brûle rien. */
    public function testMatchesExportDecrementsOnSuccessOnly(): void
    {
        $ctx = $this->seedClub(credits: 0);

        $this->fireResponse($ctx, 'api_fixtures_export_pdf', 422);
        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), 'une plage refusée ne décompte rien');

        $this->fireResponse($ctx, 'api_fixtures_export_pdf', 502);
        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), 'une panne du worker ne décompte rien');

        $this->fireResponse($ctx, 'api_fixtures_export_pdf', 200);
        self::assertSame(1, $this->creditsOf($ctx['club']->getId()), 'le PDF livré décompte un crédit');
    }

    /** Pool partagé : le dernier crédit pris par un export de matchs ferme aussi la génération. */
    public function testMatchesExportDrawsFromTheSharedPool(): void
    {
        $ctx = $this->seedClub(credits: 9);

        $this->fireResponse($ctx, 'api_fixtures_export_pdf', 200);

        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'le crédit consommé par l\'export manque à la génération');
    }

    /**
     * Le pool est PARTAGÉ : un crédit consommé par un gestionnaire ferme la porte à SON
     * collègue du même club — sinon inviter quelqu'un rouvrirait le budget.
     */
    public function testPoolIsSharedBetweenTwoManagersOfTheSameClub(): void
    {
        $ctx = $this->seedClub(credits: 9);
        $second = $this->addActiveManager($ctx['club']->getId());

        // Une sortie RÉUSSIE d'un premier gestionnaire consomme le dernier crédit.
        $this->consumeOneCredit($ctx['club']->getId(), $ctx['season']->getId());
        self::assertSame(10, $this->creditsOf($ctx['club']->getId()), 'la sortie réussie décompte le pool du CLUB');

        self::assertSame(403, $this->post($second, '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'un collègue du même club ne doit pas rouvrir le budget');
    }

    /** …et ISOLÉ : le compteur d'un club ne bouge pas quand un autre club génère. */
    public function testPoolIsIsolatedBetweenClubs(): void
    {
        $exhausted = $this->seedClub(credits: 10);
        $neighbour = $this->seedClub(credits: 0);

        // Le voisin n'est PAS bloqué (403) par la consommation de l'autre club.
        self::assertNotSame(403, $this->post($neighbour['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'le pool est PAR club : un voisin à sec ne doit jamais bloquer un autre club');

        // Et une sortie du voisin ne touche pas le compteur du club épuisé.
        $this->consumeOneCredit($neighbour['club']->getId(), $neighbour['season']->getId());
        self::assertSame(1, $this->creditsOf($neighbour['club']->getId()));
        self::assertSame(10, $this->creditsOf($exhausted['club']->getId()), 'le compteur d\'un club ne bouge pas quand un AUTRE club consomme');
    }

    /**
     * Isolation (lot 8, PR 1) : la ligne d'abonnement d'un club ne donne rien à un autre, même pour la
     * même année de saison. Témoin : la même ligne lève bien la bride du club qu'elle vise — sinon le
     * refus du voisin ne prouverait rien.
     */
    public function testOneClubsSubscriptionLineGivesNothingToAnotherClub(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);
        $subscribed = $this->seedClub(credits: 10);
        $other = $this->seedClub(credits: 10);
        $this->subscribeClub($subscribed['club']->getId(), SeasonResolver::seasonYear($subscribed['season']->getStartDate()), 'beta');

        // La ligne se lit sous le locataire du club lu (RLS), comme dans toute requête.
        $this->scopeGucToClub($subscribed['club']->getId());
        self::assertFalse($entitlements->outputBudget($subscribed['club'], $subscribed['season'])['restricted'], 'témoin : la ligne Bêta du club lève sa bride');
        self::assertNotSame(403, $this->post($subscribed['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'témoin : le club réglé n\'est pas refusé business');

        $this->scopeGucToClub($other['club']->getId());
        self::assertTrue($entitlements->outputBudget($other['club'], $other['season'])['restricted'], 'la ligne d\'un AUTRE club, même saison, ne lève pas la bride');
        self::assertSame(403, $this->post($other['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'un club sans ligne reste en Découverte, crédits épuisés : refusé');
    }

    /**
     * Isolation, second angle (lot 8, PR 1) : la lecture de la ligne NOMME le club lu, en plus de la
     * RLS — sous le locataire du club réglé, la ligne d'un autre club ne se lit pas. Le jumeau
     * {@see self::testOneClubsSubscriptionLineGivesNothingToAnotherClub} lit chaque club sous son
     * propre locataire : il reste vert tant que la RLS tient seule (falsifié : clause `club_id` de
     * `ClubSubscriptionRepository::forSeasonYear` coupée, il passe) ; celui-ci rougit. La couche RLS
     * a sa garde structurelle : `RlsIsolationTest::testEveryClubIdTableIsUnderForcedRls`.
     */
    public function testTheSubscriptionReadNamesTheClubEvenUnderAnotherClubsTenant(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);
        $subscribed = $this->seedClub(credits: 0);
        $other = $this->seedClub(credits: 0);
        $year = SeasonResolver::seasonYear($subscribed['season']->getStartDate());
        $this->subscribeClub($subscribed['club']->getId(), $year, 'beta');

        // Le locataire posé est celui du club réglé : sa ligne est visible de la RLS.
        $this->scopeGucToClub($subscribed['club']->getId());
        self::assertTrue($entitlements->isSeasonPaid($subscribed['club']->getId(), $year), 'témoin : la ligne Bêta du club réglé se lit sous son locataire');
        self::assertFalse($entitlements->isSeasonPaid($other['club']->getId(), $year), 'la ligne visible sous ce locataire est celle d\'un AUTRE club : elle ne règle rien pour le club lu');
        self::assertTrue($entitlements->outputBudget($other['club'], $other['season'])['restricted'], 'l\'autre club reste bridé (Découverte), même lu sous le locataire du club réglé');
    }

    // ─────────── Placement week-end par week-end en mode restreint (P4-240 ④) ───────────

    /**
     * En Découverte bridée, le placement AUTOMATIQUE se fait week-end par week-end : un appel
     * SANS fenêtre est refusé 403 (défense serveur derrière le bouton global désactivé). Le club
     * a du crédit (0/10) — le refus ne vient donc PAS du pool épuisé mais bien de la règle fenêtre.
     */
    public function testRestrictedRefusesPlacementWithoutWindow(): void
    {
        $ctx = $this->seedClub(credits: 0, settleSocle: true);

        self::assertSame(403, $this->post($ctx['user'], '/api/fixtures/place'), 'placer sans fenêtre en Découverte doit être refusé');
        self::assertStringContainsString('week-end par week-end', (string) $this->client->getResponse()->getContent(), 'le refus doit expliquer la règle week-end');
    }

    /** Une fenêtre qui dépasse une semaine contournerait la règle : refusée 403, même message. */
    public function testRestrictedRefusesWindowWiderThanOneWeek(): void
    {
        $ctx = $this->seedClub(credits: 0, settleSocle: true);

        // 16 jours (du 5 au 20) : au-delà de la borne Lun→Dim (7 jours).
        self::assertSame(403, $this->postJson($ctx['user'], '/api/fixtures/place', ['from' => '2026-10-05', 'to' => '2026-10-20']), 'une fenêtre > 7 jours en Découverte doit être refusée');
        self::assertStringContainsString('week-end par week-end', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Une fenêtre Lun→Dim (7 jours) PASSE la porte crédit (pas de 403). Sans match à placer le
     * contrôleur répond 200 « Aucun match à placer » : aucun run enfilé, donc AUCUN crédit — le
     * placement est désormais asynchrone et le crédit se décompte AU SUCCÈS dans le worker
     * (couvert par PlaceMatchesHandlerTest), jamais sur le 202 ni sur un « rien à placer ».
     */
    public function testRestrictedAllowsAWeekWindowAndNothingToPlaceDoesNotDecrement(): void
    {
        $ctx = $this->seedClub(credits: 0, settleSocle: true);

        // Lun 5 → Dim 11 oct. : 7 jours calendaires, la borne exacte acceptée.
        self::assertSame(200, $this->postJson($ctx['user'], '/api/fixtures/place', ['from' => '2026-10-05', 'to' => '2026-10-11']), 'une fenêtre d\'une semaine doit passer en Découverte');
        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), '« rien à placer » ne crée pas de run et ne décompte aucun crédit');
    }

    /** Hors mode restreint (offre payante), un placement SANS fenêtre reste libre (aucun 403). */
    public function testUnrestrictedAllowsPlacementWithoutWindow(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, settleSocle: true, tierCode: 'palier-1');

        self::assertSame(200, $this->post($ctx['user'], '/api/fixtures/place'), 'une offre payante place sans fenêtre, sans porte crédit');
        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), 'une offre non bridée ne décompte jamais');
    }

    // ──────────────────────── Pool de crédits — décompte (subscriber) ────────────────────────

    /**
     * Le crédit se prend quand la sortie est OBTENUE : une demande acceptée (202 mise en file) ne
     * décompte rien — génération, régénération, comblement, placement, PDF du planning se décomptent
     * dans le worker, à l'obtention. Seules les sorties synchrones (tableur, PDF des matchs) se
     * décomptent à la réponse, au 2xx seulement ; un refus amont (409, 422) ne brûle rien.
     */
    public function testAcceptedDispatchDecrementsButUpstreamRefusalDoesNot(): void
    {
        $ctx = $this->seedClub(credits: 0);

        foreach (['generate_schedule', 'api_schedule_regenerate', 'api_schedule_fill', 'api_fixtures_place', 'export_pdf'] as $queued) {
            $this->fireResponse($ctx, $queued, 202);
            self::assertSame(0, $this->creditsOf($ctx['club']->getId()), $queued . ' : une demande mise en file (202) ne décompte rien, le crédit se prend à l\'obtention');
        }

        $this->fireResponse($ctx, 'export_xlsx', 409);
        $this->fireResponse($ctx, 'export_xlsx', 422);
        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), 'un refus amont d\'une sortie synchrone ne décompte rien');

        $this->fireResponse($ctx, 'export_xlsx', 200);
        self::assertSame(1, $this->creditsOf($ctx['club']->getId()), 'le tableur servi décompte à la réponse');
        $this->fireResponse($ctx, 'api_fixtures_export_pdf', 200);
        self::assertSame(2, $this->creditsOf($ctx['club']->getId()), 'le PDF des matchs servi décompte à la réponse');
    }

    /** Payant, bêta et démo : jamais bridés (outputBudget), jamais décomptés, jamais refusés business. */
    public function testPaidBetaAndDemoAreNeverRestricted(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        $decouverte = $this->seedClub(credits: 3);
        self::assertTrue($entitlements->outputBudget($decouverte['club'], $decouverte['season'])['restricted'], 'Découverte est le SEUL régime bridé');

        foreach ([['complete', 'palier-1'], ['complete', 'palier-2'], ['complete', 'palier-3'], ['beta', null]] as [$code, $tier]) {
            $paid = $this->seedClub($code, credits: 50, tierCode: $tier);
            $code .= null === $tier ? '' : ' ' . $tier;
            self::assertFalse($entitlements->outputBudget($paid['club'], $paid['season'])['restricted'], \sprintf('l\'offre %s n\'est jamais bridée', $code));
            // Une sortie servie ne décompte pas une offre non bridée.
            $this->fireResponse($paid, 'export_xlsx', 200);
            self::assertSame(50, $this->creditsOf($paid['club']->getId()), \sprintf('%s ne décompte jamais', $code));
            // Et la porte HTTP n'oppose pas de refus business (403) à un plein compteur.
            self::assertNotSame(403, $this->post($paid['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), \sprintf('%s ne doit jamais être refusé business', $code));
        }

        $demo = $this->seedClub(credits: 99, isDemo: true);
        self::assertFalse($entitlements->outputBudget($demo['club'], $demo['season'])['restricted'], 'un club démo a les droits pleins, toujours');
        self::assertNotSame(403, $this->post($demo['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'un club démo n\'est jamais refusé');
    }

    /** Reset superadmin : remet le pool à zéro et rouvre les sorties. */
    public function testResetCreditsReopensOutput(): void
    {
        $ctx = $this->seedClub(credits: 10);
        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'épuisé = refusé avant reset');

        $tester = new CommandTester(new Application(self::$kernel)->find('app:clubs:reset-credits'));
        self::assertSame(0, $tester->execute(['--club' => $ctx['club']->getId()]));
        $this->em->clear(); // le reset est un UPDATE SQL brut — relire depuis la base (cf. fireResponseFor)

        self::assertSame(0, $this->creditsOf($ctx['club']->getId()), 'le reset remet le pool à zéro');
        self::assertNotSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'le reset rouvre les sorties');
    }

    // ─────────────────────────── Caps payants — création d'équipes ───────────────────────────

    /** NR inversé : Découverte crée sa 25ᵉ équipe SANS aucun refus (aucun cap en gratuit). */
    public function testDecouverteCreatesTeamsFreely(): void
    {
        $ctx = $this->seedClub(credits: 0, teamCount: 24);

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T25', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(201, $status, 'Découverte n\'a AUCUN cap d\'équipes : la 25ᵉ passe');
    }

    /**
     * Lot 8, PR 2 — le plafond de création suit le PALIER de la ligne de la saison : au palier 1
     * (20 équipes), la 21ᵉ est refusée en 422 et le message annonce le palier suivant (34). Les
     * équipes désactivées comptent (décision Q2) : une des 20 équipes semées l'est.
     */
    public function testPalier1RefusesThe21stTeamAnnouncingTheNextTier(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 20, tierCode: 'palier-1', inactiveTeams: 1);

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T21', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(422, $status, 'au-delà du palier 1 (20 équipes, une désactivée comprise), la création est refusée');
        // Phrase lue sur le corps ré-encodé : le corps brut de test porte une trace dont les numéros
        // de ligne pourraient contenir « 34 » par hasard.
        self::assertStringContainsString('Votre palier permet 20 équipes — le palier suivant monte à 34.', $this->readableBody(), 'le refus dit le plafond du palier et celui du palier suivant');
    }

    /**
     * Palier 2 : il tient 34 équipes (la 34ᵉ passe) et refuse la 35ᵉ en annonçant que le palier
     * suivant retire la limite.
     */
    public function testPalier2RefusesThe35thTeamAnnouncingNoLimitAbove(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 33, tierCode: 'palier-2');

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T34', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(201, $status, 'le palier 2 tient 34 équipes : la 34ᵉ passe');

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T35', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(422, $status, 'au-delà de 34, le palier 2 refuse la création');
        self::assertStringContainsString('Votre palier permet 34 équipes — le palier suivant retire la limite.', $this->readableBody(), 'le refus dit le plafond du palier et que le suivant n\'a pas de limite');
    }

    /**
     * Le palier 3 n'a AUCUN plafond, et la Bêta n'a pas de palier : ni l'un ni l'autre ne
     * plafonne (la 51ᵉ équipe passe). Témoin du même décor : le palier 1 plafonne à 20 — sans
     * lui, un `teamCap` toujours nul passerait ce test.
     */
    public function testPalier3AndBetaAreNeverCapped(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        $witness = $this->seedClub('complete', credits: 0, tierCode: 'palier-1');
        self::assertSame(20, $entitlements->teamCap($witness['club'], $witness['season']), 'témoin : le palier 1 plafonne à 20');

        foreach ([['complete', 'palier-3'], ['beta', null]] as [$code, $tier]) {
            $ctx = $this->seedClub($code, credits: 0, teamCount: 50, tierCode: $tier);
            self::assertNull($entitlements->teamCap($ctx['club'], $ctx['season']), \sprintf('%s %s : aucun plafond', $code, $tier ?? '(sans palier)'));

            $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T51', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
            self::assertSame(201, $status, \sprintf('%s %s crée sa 51ᵉ équipe sans refus', $code, $tier ?? '(sans palier)'));
        }
    }

    /**
     * Lot 8, PR 2 (revue de sécurité) — défaut FERMÉ : une ligne réglée sur l'offre complète dont le
     * palier manque (palier vide, ou palier introuvable) se lit Découverte — crédits bridés, bascule
     * fermée, saison non réglée —, jamais « offre complète sans plafond ». Le palier 3 réel (0 = illimité)
     * reste, lui, illimité et non bridé.
     *
     * La base refuse une telle ligne (`Integration/Service/ClubSubscriptionWriterTest::testTheDatabaseRefusesALineWhoseTierDoesNotBelongToItsOffer`) :
     * ce test prouve la seconde ceinture, le code. La ligne est donc forgée sur la connexion
     * propriétaire, ses contraintes neutralisées dans la transaction du test (CHECK retirés,
     * déclencheurs et clés étrangères suspendus par `session_replication_role`), tout annulé à la fin ;
     * `PlanEntitlements` y lit par un gestionnaire d'entités ouvert sur cette même connexion (la
     * connexion applicative ne verrait pas la ligne non validée). Témoins, par le même montage : les
     * paliers 1 et 3 se lisent (sans eux, « aucune ligne lue » passerait pour Découverte).
     */
    public function testATieredOfferLineWithoutItsTierReadsAsDecouverteNeverUnlimited(): void
    {
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($admin instanceof Connection);
        $admin->beginTransaction();
        try {
            $admin->executeStatement('SET LOCAL lock_timeout = \'5s\'');
            foreach ($admin->fetchFirstColumn('SELECT conname FROM pg_constraint WHERE conrelid = \'club_subscription\'::regclass AND contype = \'c\'') as $check) {
                $admin->executeStatement(\sprintf('ALTER TABLE club_subscription DROP CONSTRAINT %s', $admin->quoteSingleIdentifier((string) $check)));
            }
            $admin->executeStatement('SET LOCAL session_replication_role = replica');

            $adminEm = new EntityManager($admin, $this->em->getConfiguration());
            $registry = $this->createStub(ManagerRegistry::class);
            $registry->method('getManagerForClass')->willReturn($adminEm);
            $plans = new SubscriptionPlanRepository($registry);
            $entitlements = new PlanEntitlements($plans, $adminEm, new DemoRegime, new ClubSubscriptionRepository($registry), new SubscriptionPlanTierRepository($registry));

            $decouverte = $plans->findOneBy(['code' => 'decouverte']);
            self::assertInstanceOf(SubscriptionPlan::class, $decouverte);
            self::assertGreaterThan(0, $decouverte->getMaxGenerations(), 'témoin : Découverte a un pool de crédits, donc une bride');

            $forge = static function (string $tierSql) use ($admin, $adminEm): array {
                $clubId = (string) $admin->fetchOne('SELECT gen_random_uuid()::text');
                $suffix = bin2hex(random_bytes(4));
                $admin->executeStatement(
                    'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, false)',
                    [$clubId, 'Sans palier ' . $suffix, 'sans-palier-' . $suffix],
                );
                $season = new Season()->setStartDate(new DateTimeImmutable('2030-09-01'));
                $admin->executeStatement(
                    'INSERT INTO club_subscription (id, club_id, season_year, plan_id, tier_id, paid_at, origin, created_at, updated_at)'
                    . ' VALUES (gen_random_uuid(), ?, ?, (SELECT id FROM subscription_plan WHERE code = \'complete\'), ' . $tierSql . ', now(), \'support\', now(), now())',
                    [$clubId, SeasonResolver::seasonYear($season->getStartDate())],
                );
                $club = $adminEm->find(Club::class, $clubId);
                self::assertInstanceOf(Club::class, $club);

                return [$club, $season];
            };

            // Témoins : les paliers réels se lisent — palier 1 plafonné à 20, palier 3 illimité et non bridé.
            [$club, $season] = $forge('(SELECT id FROM subscription_plan_tier WHERE code = \'palier-1\')');
            self::assertSame(20, $entitlements->teamCap($club, $season), 'témoin : la ligne forgée est lue, le palier 1 plafonne à 20');
            [$club, $season] = $forge('(SELECT id FROM subscription_plan_tier WHERE code = \'palier-3\')');
            $read = $entitlements->forClub($club, $season);
            self::assertSame(['complete', 'palier-3', null], [$read['planCode'], $read['tierCode'], $read['maxTeams']], 'le palier 3 réel reste l\'offre complète, sans plafond');
            self::assertFalse($entitlements->outputBudget($club, $season)['restricted'], 'le palier 3 réel n\'est pas bridé');

            foreach (['palier vide' => 'NULL', 'palier introuvable' => 'gen_random_uuid()'] as $case => $tierSql) {
                [$club, $season] = $forge($tierSql);
                $read = $entitlements->forClub($club, $season);
                self::assertSame('decouverte', $read['planCode'], $case . ' : la ligne se lit Découverte, jamais l\'offre complète');
                self::assertNull($read['tierCode'], $case . ' : aucun palier lu');
                self::assertSame($decouverte->getMaxGenerations(), $read['creditsMax'], $case . ' : les crédits sont ceux de Découverte, bornés');
                self::assertFalse($read['seasonTransition'], $case . ' : la bascule reste fermée');
                self::assertTrue($entitlements->outputBudget($club, $season)['restricted'], $case . ' : les sorties sont bridées comme en Découverte');
                self::assertFalse($entitlements->isSeasonPaid($club->getId(), SeasonResolver::seasonYear($season->getStartDate())), $case . ' : la saison ne compte pas comme réglée');
            }
        } finally {
            $admin->rollBack();
        }
    }

    /** Import Excel au-delà du palier : refus tout-ou-rien avec DÉCOMPTE, rien écrit. */
    public function testExcelImportBeyondCapIsRefusedWithCount(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 19, withFfbb: true, tierCode: 'palier-1'); // reste 1 place
        $this->ensureActiveSport();
        $code = (string) $ctx['club']->getFfbbClubCode();

        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', $code . ' - MON CLUB'],
            ['SM2', 'Seniors', '2', $code . ' - MON CLUB'],
            ['SM3', 'Seniors', '3', $code . ' - MON CLUB'],
        ]);

        $importer = self::getContainer()->get(FfbbExcelImporter::class);
        \assert($importer instanceof FfbbExcelImporter);

        try {
            $importer->import($file, $ctx['club']->getId(), $ctx['season']->getId());
            self::fail('un import qui franchit le cap payant doit être refusé');
        } catch (ImportRejectedException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertStringContainsString('3', $e->getMessage(), 'le décompte doit dire combien d\'équipes le fichier crée');
            self::assertStringContainsString('1 place', $e->getMessage(), 'le décompte doit dire combien de places restent');
        }

        self::assertSame(19, $this->teamCountOf($ctx['club']->getId(), $ctx['season']->getId()), 'rien n\'est écrit : l\'import est tout-ou-rien');
    }

    /** Palier 2 à 33/34 : un fichier de 3 équipes est refusé en annonçant la SEULE place restante sur le palier. */
    public function testPalier2ExcelImportCountsAgainstTheCapOf34(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 33, withFfbb: true, tierCode: 'palier-2'); // reste 1 place sur 34
        $this->ensureActiveSport();
        $code = (string) $ctx['club']->getFfbbClubCode();

        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', $code . ' - MON CLUB'],
            ['SM2', 'Seniors', '2', $code . ' - MON CLUB'],
            ['SM3', 'Seniors', '3', $code . ' - MON CLUB'],
        ]);

        $importer = self::getContainer()->get(FfbbExcelImporter::class);
        \assert($importer instanceof FfbbExcelImporter);

        try {
            $importer->import($file, $ctx['club']->getId(), $ctx['season']->getId());
            self::fail('un import qui franchit le palier 2 (34) doit être refusé');
        } catch (ImportRejectedException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('3 équipe(s) à créer dans le fichier, 1 place(s) restante(s) sur votre palier. Retirez des lignes ou passez au palier supérieur.', $e->getMessage(), '33 équipes sur un palier de 34 : le décompte dit les équipes du fichier et la place restante sur le palier');
        }

        self::assertSame(33, $this->teamCountOf($ctx['club']->getId(), $ctx['season']->getId()), 'rien n\'est écrit : l\'import est tout-ou-rien');
    }

    // ──────────────── Au-dessus du palier, plus de sorties (lot 8, PR 3) ────────────────

    /**
     * Au palier 1 avec 21 équipes (dont 3 désactivées, elles comptent — décision Q2), les SEPT
     * fonctions à sortie sont refusées 403 avec le message du dépassement, et rien ne part : le
     * brouillon visé par « Générer » reste DRAFT, aucune version, aucun run de placement, aucun
     * message de placement en file. Le tableur passe en premier : tant que le refus manque, c'est lui
     * qui rougit, sans rien envoyer au moteur.
     */
    public function testAboveItsTierEveryOutputIsRefusedAndNothingIsQueued(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 21, settleSocle: true, tierCode: 'palier-1', inactiveTeams: 3);
        $draft = $this->seedSchedule($ctx['season'], ScheduleStatus::DRAFT);
        $schedulesBefore = $this->countForClub('schedule', $ctx['club']->getId());

        foreach ($this->outputRequests($draft->getId(), $ctx['season']) as $output => [$uri, $body]) {
            self::assertSame(403, $this->postJson($ctx['user'], $uri, $body), $output . ' : au-dessus du palier, la sortie est refusée');
            self::assertSame($this->overrunMessage(21, 20), $this->refusalDetail(), $output . ' : le refus porte le message du dépassement');
            if ('placer les matchs' === $output) {
                $transport = self::getContainer()->get('messenger.transport.placement_in_memory');
                \assert($transport instanceof InMemoryTransport);
                self::assertCount(0, $transport->getSent(), 'un placement refusé ne part pas en file');
            }
        }

        self::assertSame('DRAFT', $this->em->getConnection()->fetchOne('SELECT status FROM schedule WHERE id = :id', ['id' => $draft->getId()]), 'la génération refusée ne passe pas le brouillon en file (PENDING)');
        self::assertSame($schedulesBefore, $this->countForClub('schedule', $ctx['club']->getId()), 'aucune version ne naît d\'un refus (régénérer, combler)');
        self::assertSame(0, $this->countForClub('match_placement_run', $ctx['club']->getId()), 'aucun run de placement ne naît d\'un refus');
    }

    /**
     * Le message du dépassement, que `/api/me` sert à chaque écran : il dit le compte et le plafond
     * (21 sur 20, 35 sur 34) ; à la limite exacte du palier (20 au palier 1, 34 au palier 2) il n'y a
     * pas de dépassement ; Découverte, la Bêta, le palier 3 et la démonstration ne sont jamais
     * concernés, même à 21 équipes.
     */
    public function testTheOverrunMessageNamesCountAndCapAndNeverConcernsAnUncappedClub(): void
    {
        $over = $this->seedClub('complete', credits: 0, teamCount: 21, tierCode: 'palier-1');
        self::assertSame($this->overrunMessage(21, 20), $this->teamCapMessageOutsideRequest($over['club'], $over['season']), 'palier 1, 21 équipes : dépassement');
        self::assertSame($this->overrunMessage(21, 20), $this->meEntitlements($over['user'])['teamCapMessage'] ?? null, '/api/me sert le message du dépassement');
        $over2 = $this->seedClub('complete', credits: 0, teamCount: 35, tierCode: 'palier-2');
        self::assertSame($this->overrunMessage(35, 34), $this->teamCapMessageOutsideRequest($over2['club'], $over2['season']), 'palier 2, 35 équipes : dépassement');

        $atCap = $this->seedClub('complete', credits: 0, teamCount: 20, tierCode: 'palier-1', inactiveTeams: 2);
        self::assertNull($this->teamCapMessageOutsideRequest($atCap['club'], $atCap['season']), 'palier 1, 20 équipes : pas de dépassement');
        $me = $this->meEntitlements($atCap['user']);
        self::assertTrue(\array_key_exists('teamCapMessage', $me) && null === $me['teamCapMessage'], '/api/me sert teamCapMessage: null sans dépassement');
        self::assertNotSame(403, $this->post($atCap['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), 'à la limite du palier, les sorties restent ouvertes');
        $atCap2 = $this->seedClub('complete', credits: 0, teamCount: 34, tierCode: 'palier-2');
        self::assertNull($this->teamCapMessageOutsideRequest($atCap2['club'], $atCap2['season']), 'palier 2, 34 équipes : pas de dépassement');

        foreach (['palier 3' => ['complete', 'palier-3', false], 'Bêta' => ['beta', null, false], 'Découverte' => [null, null, false], 'démonstration au palier 1' => ['complete', 'palier-1', true]] as $case => [$plan, $tier, $demo]) {
            $ctx = $this->seedClub($plan, credits: 0, teamCount: 21, isDemo: $demo, tierCode: $tier);
            self::assertNull($this->teamCapMessageOutsideRequest($ctx['club'], $ctx['season']), $case . ', 21 équipes : jamais concerné');
            self::assertNotSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/generate'), $case . ' : jamais refusé pour le palier');
        }
    }

    /**
     * Ordre du contrôle central : un Membre reçoit le refus du RÔLE sur les sorties de gestionnaire
     * (générer, régénérer, combler, placer) ; les exports lui sont ouverts, donc le palier les lui
     * refuse comme à tous ; et `/api/me` lui sert le message, pour le bandeau de chaque membre.
     */
    public function testAMemberAboveTheTierGetsTheRoleRefusalOnManagingAndTheTierRefusalOnExports(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 21, settleSocle: true, tierCode: 'palier-1');
        $member = $this->addActiveMember($ctx['club']->getId());

        self::assertSame($this->overrunMessage(21, 20), $this->meEntitlements($member)['teamCapMessage'] ?? null, 'un Membre est prévenu comme le gestionnaire');
        foreach ($this->outputRequests(self::DUMMY_SCHEDULE_ID, $ctx['season']) as $output => [$uri, $body]) {
            self::assertSame(403, $this->postJson($member, $uri, $body), $output . ' : refusé à un Membre au-dessus du palier');
            $expected = \in_array($output, ['générer le planning', 'régénérer le planning', 'combler le planning', 'placer les matchs'], true)
                ? ManagementAccessGuard::REFUSAL
                : $this->overrunMessage(21, 20);
            self::assertSame($expected, $this->refusalDetail(), $output . ' : le rôle se juge avant le palier');
        }
    }

    /**
     * Le module fermé répond avant le palier : sans planning de saison en vigueur, placer les matchs
     * au-dessus du palier rend le 409 du socle, pas le refus du palier.
     */
    public function testAClosedModuleAnswersBeforeTheTier(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 21, tierCode: 'palier-1');

        self::assertSame(409, $this->post($ctx['user'], '/api/fixtures/place'), 'module fermé : 409 avant le palier');
        self::assertStringContainsString(SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE, $this->readableBody(), 'le refus est celui du socle');
    }

    /**
     * Au-dessus du palier, ce qui n'est pas une sortie reste ouvert : consulter, charger une version
     * (aucun calcul : le refus éventuel vient du contrôleur, jamais du palier), télécharger un PDF
     * déjà produit.
     */
    public function testConsultingLoadingAndDownloadingAProducedPdfStayOpenAboveTheTier(): void
    {
        $ctx = $this->seedClub('complete', credits: 0, teamCount: 21, tierCode: 'palier-1');
        $schedule = $this->seedSchedule($ctx['season'], ScheduleStatus::COMPLETED);
        $filename = \sprintf('schedule-%s-all.pdf', $schedule->getId());
        $this->writeRender($filename);
        $headers = $this->authHeaders($ctx['user']);

        $this->client->request('GET', '/api/schedules', [], [], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'consulter reste ouvert');
        self::assertNotSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/load'), 'charger une version n\'est jamais refusé par le palier');
        $this->client->request('GET', \sprintf('/api/schedules/%s/export-pdf/%s', $schedule->getId(), $filename), [], [], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'un PDF déjà produit se télécharge au-dessus du palier');

        // Témoin : le même club, au même instant, est bien refusé sur une sortie.
        self::assertSame(403, $this->post($ctx['user'], '/api/schedules/' . $schedule->getId() . '/export-xlsx'), 'témoin : le club est au-dessus de son palier');
    }

    /**
     * Abus de saison : la saison qui juge est celle de la requête, et la sortie produite est celle de
     * la même saison. Club en Bêta sur sa saison en cours (A), au palier 1 avec 21 équipes sur la
     * saison suivante préparée (B). L'en-tête de A ne sert jamais une sortie de B : un planning de B
     * nommé rend 404 (filtre de saison), le placement ne prend aucun match de B, le PDF des matchs ne
     * contient que ceux de A ; A garde ses sorties. Lève le « non vérifié » du plan (placement et PDF
     * des matchs bornés à la saison de la requête). Témoin : sous l'en-tête de B, les sept sorties sont
     * refusées pour le palier.
     */
    public function testAnEarlierSeasonHeaderNeverServesAnOutputOfTheSeasonAboveItsTier(): void
    {
        // Le double du worker de rendu doit survivre d'une requête à l'autre.
        $this->client->disableReboot();
        $this->renderMatchesPdfInMemory();

        $ctx = $this->seedClub('beta', credits: 0, settleSocle: true);
        $clubId = $ctx['club']->getId();
        $seasonA = $ctx['season'];
        $year = SeasonResolver::seasonYear($seasonA->getStartDate());
        $seasonB = new Season()
            ->setClubId($clubId)
            ->setName((string) ($year + 1))
            ->setStartDate(new DateTimeImmutable(($year + 1) . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 2) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)
            ->setTransitionData([]);
        $this->em->persist($seasonB);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $seasonB->getId());
        $this->subscribeClub($clubId, $year + 1, 'complete', 'palier-1');
        $this->seedTeams($clubId, $seasonB->getId(), 21);
        $this->settleSeasonPlan($seasonB);
        $scheduleA = $this->seedSchedule($seasonA, ScheduleStatus::COMPLETED);
        $scheduleB = $this->seedSchedule($seasonB, ScheduleStatus::DRAFT);
        $this->seedMatch($clubId, $seasonA, 'Adversaire de la saison en cours', FixtureHomeAway::AWAY);
        $this->seedMatch($clubId, $seasonB, 'Adversaire de la saison suivante', FixtureHomeAway::HOME);
        $schedulesBefore = $this->countForClub('schedule', $clubId);
        $headerA = ['HTTP_X_SEASON_ID' => $seasonA->getId()];

        foreach ($this->outputRequests($scheduleB->getId(), $seasonA) as $output => [$uri, $body]) {
            // Sans reboot, le gestionnaire d'entités est partagé : vidé, il relit comme en production
            // (une entité semée restée en mémoire passerait à côté du filtre de saison).
            $this->em->clear();
            $status = $this->postJson($ctx['user'], $uri, $body, $headerA);
            if ('placer les matchs' === $output) {
                self::assertSame(200, $status, 'placer sous l\'en-tête de A ne prend aucun match de B');
                self::assertSame('Aucun match à placer.', $this->json()['message'] ?? null, 'le seul match à placer est celui de B : il n\'entre pas dans la sortie de A');
            } elseif ('exporter les matchs en PDF' === $output) {
                self::assertSame(200, $status, 'A (Bêta) garde ses sorties : le PDF de ses matchs est servi');
                self::assertStringContainsString('Adversaire de la saison en cours', $this->lastRenderedHtml(), 'le PDF contient les matchs de A');
                self::assertStringNotContainsString('Adversaire de la saison suivante', $this->lastRenderedHtml(), 'le PDF ne contient aucun match de B');
            } else {
                self::assertSame(404, $status, $output . ' : un planning de B nommé sous l\'en-tête de A est introuvable');
            }
        }
        self::assertSame(0, $this->countForClub('match_placement_run', $clubId), 'aucun run de placement sous l\'en-tête de A');
        self::assertSame($schedulesBefore, $this->countForClub('schedule', $clubId), 'aucune version née sous l\'en-tête de A');
        self::assertSame('DRAFT', $this->em->getConnection()->fetchOne('SELECT status FROM schedule WHERE id = :id', ['id' => $scheduleB->getId()]), 'le planning de B n\'est pas parti au calcul');
        $this->em->clear();
        self::assertSame(200, $this->postJson($ctx['user'], '/api/schedules/' . $scheduleA->getId() . '/export-xlsx', [], $headerA), 'A garde ses sorties : son tableur est servi');

        // Témoin : sous l'en-tête de B, la saison qui dépasse son palier, chaque sortie est refusée.
        // Le plafond horaire des solves (5/h par club en test) passe avant le contrôle central : les
        // trois solves demandés sous l'en-tête de A l'ont entamé ; on remet le seau DU club à zéro.
        $limiter = self::getContainer()->get('limiter.schedule_generation');
        \assert($limiter instanceof RateLimiterFactory);
        $limiter->create('club-' . $clubId)->reset();
        foreach ($this->outputRequests($scheduleB->getId(), $seasonB) as $output => [$uri, $body]) {
            $this->em->clear();
            self::assertSame(403, $this->postJson($ctx['user'], $uri, $body, ['HTTP_X_SEASON_ID' => $seasonB->getId()]), $output . ' : sous l\'en-tête de B, refusé pour le palier');
            self::assertSame($this->overrunMessage(21, 20), $this->refusalDetail(), $output . ' : le refus porte le message du dépassement de B');
        }
    }

    // ─────────────────────────────── Bêta invisible en GET item ───────────────────────────────

    /** La bêta est superadmin-only : 404 en GET item (pas seulement masquée de la collection). */
    public function testBetaOfferIsHiddenOnGetItem(): void
    {
        $ctx = $this->seedClub(credits: 0);
        $headers = $this->authHeaders($ctx['user']);

        $this->client->request('GET', '/api/subscription_plans/' . $this->planByCode('beta')->getId(), [], [], $headers);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'la bêta ne doit pas être servie par id');

        // Témoin : une offre publique reste bien lisible par id (le 404 est SPÉCIFIQUE à la bêta).
        $this->client->request('GET', '/api/subscription_plans/' . $this->planByCode('complete')->getId(), [], [], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'une offre publique reste lisible par id');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** Le message du dépassement de palier, tel que le contrat du lot 8 (PR 3) l'écrit. */
    private function overrunMessage(int $teams, int $cap): string
    {
        return \sprintf('Votre club compte %d équipes, votre palier en permet %d. Les générations, le placement des matchs et les exports sont suspendus : supprimez des équipes qui n\'ont pas de match, ou changez de palier.', $teams, $cap);
    }

    /**
     * @return array{user: User, club: Club, season: Season}
     */
    private function seedClub(
        ?string $planCode = null,
        int $credits = 0,
        int $teamCount = 0,
        bool $isDemo = false,
        bool $withFfbb = false,
        bool $settleSocle = false,
        ?string $tierCode = null,
        int $inactiveTeams = 0,
    ): array {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('PE ' . $uid);
        $club->setSlug('pe-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo($isDemo);
        $club->setOutputCreditsUsed($credits);
        if ($withFfbb) {
            $club->setFfbbClubCode('PE' . strtoupper(substr(md5($uid), 0, 9)));
        }
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('pe-' . $uid . '@test.com');
        $user->setFirstName('Plan');
        $user->setLastName('Entitle');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Une saison naît avec son plan de saison (lot 4) : ses équipes y ont leur ligne. L'entité est passée telle
        // quelle — après une requête, le filtre de club du conteneur vise le club de cette requête.
        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        if (null !== $planCode) {
            // Offre (et palier) attribués ET saison courante réglée → l'offre effective est bien la payante.
            $this->subscribeClub($club->getId(), $year, $planCode, $tierCode);
        }

        if ($teamCount > 0) {
            $this->categoryId = $this->seedTeams($club->getId(), $season->getId(), $teamCount, $inactiveTeams);
        }

        if ($settleSocle) {
            // Le placement des matchs est gardé par le SocleGuard (409 sans plan en vigueur),
            // ANTÉRIEUR à la porte crédit du contrôleur : pour atteindre le refus/l'acceptation
            // week-end, le plan de saison doit pointer une version (ADR-0002).
            $this->settleSeasonPlan($season);
        }

        return ['user' => $user, 'club' => $club, 'season' => $season];
    }

    /** $inactive : combien des $count équipes sont désactivées (elles comptent au plafond, Q2). */
    private function seedTeams(string $clubId, string $seasonId, int $count, int $inactive = 0): string
    {
        $sport = $this->ensureActiveSport();
        $category = (new SportCategory)
            ->setClubId($clubId)
            ->setSportId($sport->getId())
            ->setName('Seniors')
            ->setAgeMin(22)
            ->setAgeMax(99)
            ->setSortOrder(1);
        $this->em->persist($category);

        for ($i = 0; $i < $count; ++$i) {
            $team = (new Team)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSportCategoryId($category->getId())
                ->setPriorityTierId(1)
                ->setName('Seed ' . $i)
                ->setIsActive($i >= $inactive);
            $this->em->persist($team);
            $this->em->flush();
            self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
        }
        $this->em->flush();

        return $category->getId();
    }

    private function ensureActiveSport(): Sport
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if ($sport instanceof Sport) {
            return $sport;
        }
        $uid = uniqid('', true);
        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        return $sport;
    }

    private function addActiveManager(string $clubId): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $user = new User;
        $user->setEmail('pe2-' . $uid . '@test.com');
        $user->setFirstName('Second');
        $user->setLastName('Manager');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /** Une sortie synchrone servie (le tableur) : elle décompte à la réponse. */
    private function consumeOneCredit(string $clubId, string $seasonId): void
    {
        $this->fireResponseFor($clubId, $seasonId, 'export_xlsx', 200);
    }

    /** Un Membre ACTIF (rôle hors gestion) du club, avec son compte. */
    private function addActiveMember(string $clubId): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $user = new User;
        $user->setEmail('pe-member-' . $uid . '@test.com');
        $user->setFirstName('Membre');
        $user->setLastName('Club');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole(ClubRole::MEMBER->value);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /** @param array{user: User, club: Club, season: Season} $ctx */
    private function fireResponse(array $ctx, string $route, int $status): void
    {
        $this->fireResponseFor($ctx['club']->getId(), $ctx['season']->getId(), $route, $status);
    }

    /**
     * Une réponse synthétique de la route nommée, envoyée à TOUS les écouteurs de `kernel.response`
     * (quel que soit celui qui décompte) : la requête porte ce que le routeur et le locataire y
     * posent (`_route`, `_controller`, la ressource, `_club_id`, `_season_id`).
     */
    private function fireResponseFor(string $clubId, string $seasonId, string $route, int $status): void
    {
        $this->scopeGucToClub($clubId); // l'écouteur lit la Season (RLS)
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $compiled = $router->getRouteCollection()->get($route);
        self::assertNotNull($compiled, \sprintf('la route %s existe', $route));
        $path = $router->generate($route, str_contains($compiled->getPath(), '{id}') ? ['id' => self::DUMMY_SCHEDULE_ID] : []);

        $request = Request::create($path, 'POST');
        $request->attributes->add($router->matchRequest($request));
        $request->attributes->set('_club_id', $clubId);
        $request->attributes->set('_season_id', $seasonId);

        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->dispatch(new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', $status)), KernelEvents::RESPONSE);

        // L'incrément est un UPDATE SQL brut : il court-circuite l'EM. En PROD chaque requête
        // a un EM neuf ; ici le kernel de test est partagé entre seed et requête HTTP, donc on
        // vide la map d'identité pour qu'une requête HTTP suivante relise la vérité en base.
        $this->em->clear();
    }

    private function creditsOf(string $clubId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $clubId]);
    }

    private function teamCountOf(string $clubId, string $seasonId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM team WHERE club_id = :club AND season_id = :season',
            ['club' => $clubId, 'season' => $seasonId],
        );
    }

    private function planByCode(string $code): SubscriptionPlan
    {
        $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $code]);
        \assert($plan instanceof SubscriptionPlan);

        return $plan;
    }

    private function post(User $user, string $uri): int
    {
        $this->client->request('POST', $uri, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], '{}');

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers en-têtes serveur de plus (ex. `HTTP_X_SEASON_ID`)
     */
    private function postJson(User $user, string $uri, array $payload, array $headers = []): int
    {
        $this->client->request('POST', $uri, [], [], $this->authHeaders($user) + $headers + ['CONTENT_TYPE' => 'application/json'], [] === $payload ? '{}' : json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Les sept fonctions à sortie, sur le planning nommé. Le tableur en premier : tant que le refus
     * du palier manque, c'est lui qui rougit, sans qu'une génération parte. Le PDF des matchs couvre
     * novembre de la saison donnée.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function outputRequests(string $scheduleId, Season $season): array
    {
        $year = SeasonResolver::seasonYear($season->getStartDate());

        return [
            'exporter le planning en tableur' => ['/api/schedules/' . $scheduleId . '/export-xlsx', []],
            'exporter le planning en PDF' => ['/api/schedules/' . $scheduleId . '/export-pdf', []],
            'exporter les matchs en PDF' => ['/api/fixtures/export-pdf', ['from' => $year . '-11-01', 'to' => $year . '-11-30', 'teamId' => null]],
            'placer les matchs' => ['/api/fixtures/place', []],
            'combler le planning' => ['/api/schedules/' . $scheduleId . '/fill', []],
            'régénérer le planning' => ['/api/schedules/' . $scheduleId . '/regenerate', []],
            'générer le planning' => ['/api/schedules/' . $scheduleId . '/generate', []],
        ];
    }

    /**
     * `teamCapMessage` lu hors requête, comme le lit un appel neuf en production. Le noyau de test est
     * partagé : une requête précédente laisse le filtre de locataire et le filtre de saison actifs sur
     * son gestionnaire d'entités, et le compte d'équipes d'un autre club y vaudrait 0 (un « null »
     * qui ne prouverait rien). On les coupe, puis on lit sous le locataire du club (RLS).
     */
    private function teamCapMessageOutsideRequest(Club $club, Season $season): ?string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        foreach (['tenant_filter', 'season_filter'] as $filter) {
            if ($em->getFilters()->isEnabled($filter)) {
                $em->getFilters()->disable($filter);
            }
        }
        $this->scopeGucToClub($club->getId());
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        return $entitlements->teamCapMessage($club, $season);
    }

    /** Le `detail` du dernier refus (problème JSON), ou '' s'il n'y en a pas. */
    private function refusalDetail(): string
    {
        $detail = $this->json()['detail'] ?? '';

        return \is_string($detail) ? $detail : '';
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<mixed> les droits de l'offre que `/api/me` sert à cet utilisateur */
    private function meEntitlements(User $user): array
    {
        $this->client->request('GET', '/api/me', [], [], $this->authHeaders($user));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), '/api/me répond');
        $club = $this->json()['club'] ?? null;
        $entitlements = \is_array($club) ? ($club['entitlements'] ?? null) : null;
        self::assertIsArray($entitlements, '/api/me sert les droits de l\'offre');

        return $entitlements;
    }

    private function countForClub(string $table, string $clubId): int
    {
        return (int) $this->em->getConnection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE club_id = :club', $table), ['club' => $clubId]);
    }

    /** Une version de la saison, liée à son plan comme la production au POST. */
    private function seedSchedule(Season $season, ScheduleStatus $status): Schedule
    {
        $this->scopeGucToClub($season->getClubId());
        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('Version ' . $status->value)
            ->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** Un match de championnat d'une équipe de la saison (créée si la saison n'en a pas), en novembre de l'année de la saison en cours. */
    private function seedMatch(string $clubId, Season $season, string $opponent, FixtureHomeAway $homeAway): void
    {
        $this->scopeGucToClub($clubId);
        $teamId = $this->em->getConnection()->fetchOne('SELECT id FROM team WHERE club_id = :club AND season_id = :season ORDER BY name LIMIT 1', ['club' => $clubId, 'season' => $season->getId()]);
        if (!\is_string($teamId)) {
            $this->seedTeams($clubId, $season->getId(), 1);
            $teamId = (string) $this->em->getConnection()->fetchOne('SELECT id FROM team WHERE club_id = :club AND season_id = :season', ['club' => $clubId, 'season' => $season->getId()]);
        }
        $competition = $this->competitionFor($teamId, 'Championnat', CompetitionType::CHAMPIONSHIP);

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable(SeasonResolver::seasonYear(new DateTimeImmutable('today')) . '-11-14'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponent);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    /** Écrit un rendu minimal mais valide (entête %PDF) dans le répertoire réel des exports. */
    private function writeRender(string $filename): void
    {
        if (!is_dir(PdfGenerator::outputDir())) {
            @mkdir(PdfGenerator::outputDir(), 0o775, true);
        }
        $path = PdfGenerator::outputDir() . '/' . $filename;
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->tempFiles[] = $path;
    }

    /**
     * Le worker de rendu remplacé par un double qui écrit un PDF factice et garde le HTML reçu
     * (patron `Integration/Controller/ExportMatchesTest`) : le PDF des matchs se lit sans navigateur.
     */
    private function renderMatchesPdfInMemory(): void
    {
        $reflection = new ReflectionClass(PdfGenerator::class);
        $pdf = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($pdf, new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode((string) $options['body'], true);
            \assert(\is_array($payload));
            $this->renderedHtml[] = (string) ($payload['html'] ?? '');
            if (!is_dir(PdfGenerator::outputDir())) {
                @mkdir(PdfGenerator::outputDir(), 0o775, true);
            }
            file_put_contents(PdfGenerator::outputDir() . '/' . $payload['filename'], '%PDF-1.4 test');

            return new MockResponse((string) json_encode(['success' => true]));
        }));
        $reflection->getProperty('brandAssets')->setValue($pdf, new BrandAssets);
        $reflection->getProperty('productIdentity')->setValue($pdf, new ProductIdentity(productName: 'Zephyr'));
        self::getContainer()->set(PdfGenerator::class, $pdf);
    }

    private function lastRenderedHtml(): string
    {
        return [] === $this->renderedHtml ? '' : $this->renderedHtml[array_key_last($this->renderedHtml)];
    }

    /** Le corps de la dernière réponse, ré-encodé lisible (accents et apostrophes non échappés). */
    private function readableBody(): string
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return (string) json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows
     */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Nom', 'Catégorie', 'Numéro', 'Organisme'], ...$rows]);
        $file = tempnam(sys_get_temp_dir(), 'pe-xlsx-');
        \assert(\is_string($file));
        new Xlsx($spreadsheet)->save($file);
        $this->tempFiles[] = $file;

        return $file;
    }
}
