<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Service\Basketball\FfbbExcelImporter;
use App\Service\ManagementAccessGuard;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Support\SubscribesClub;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * NR P1-3 PR B — LE modèle d'offres, gardé. Deux axes structurants (§7.1) :
 *
 *  · generation pipeline — le POOL DE CRÉDITS de sortie du plan Découverte : refus AVANT
 *    solve à l'épuisement, décompte au SUCCÈS, pool PAR CLUB (partagé entre gestionnaires,
 *    isolé entre clubs — même exigence que P4-45), un refus AMONT ne brûle rien, et
 *    payant/bêta/démo ne sont JAMAIS bridés ;
 *  · auth & memberships / périmètre — les CAPS PAYANTS aux portes de création d'équipes :
 *    Découverte crée librement (aucun cap), Essentiel refuse au-delà de 20 et Club au-delà de 34
 *    en nommant le palier supérieur, Grand club n'a aucun cap (grille 20 / 34 / 35+), l'import
 *    Excel au-delà du cap est refusé avec décompte.
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
    use SubscribesClub;
    use TenantGucTrait;

    /** Un id de planning bidon suffit : le refus se joue AVANT le contrôleur (comme ClubQuotaTest). */
    private const string DUMMY_SCHEDULE_ID = '00000000-0000-4000-8000-0000000000ff';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    private ?string $categoryId = null;

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

        self::assertNotSame(403, $this->post($ctx['user'], '/api/schedules/' . self::DUMMY_SCHEDULE_ID . '/regenerate-from'), 'charger une version n\'est jamais refusé par le mur des crédits');
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
        $ctx = $this->seedClub('essentiel', credits: 0, settleSocle: true);

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

        foreach (['essentiel', 'club', 'grand-club', 'beta'] as $code) {
            $paid = $this->seedClub($code, credits: 50);
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

    /** Essentiel (cap 20) : la 21ᵉ équipe est refusée en 422, message nommant le palier supérieur. */
    public function testEssentielRefusesTeamBeyondCapWithNamedTier(): void
    {
        $ctx = $this->seedClub('essentiel', credits: 0, teamCount: 20);

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T21', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(422, $status, 'au-delà du cap Essentiel, la création est refusée');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Essentiel', $body);
        self::assertStringContainsString('Club', $body, 'le message doit nommer le palier supérieur');
        // Phrase lue sur le corps ré-encodé : le corps brut de test porte une trace dont les numéros
        // de ligne pourraient contenir « 34 » par hasard.
        self::assertStringContainsString('l\'offre Club monte à 34.', $this->readableBody(), 'le message doit annoncer le cap du palier supérieur (Club = 34)');
    }

    /**
     * Grille 20 / 34 / 35+ : l'offre Club tient 34 équipes (la 34ᵉ passe) et refuse la 35ᵉ en
     * nommant Grand club, qui retire la limite.
     */
    public function testClubRefusesThe35thTeamNamingGrandClub(): void
    {
        $ctx = $this->seedClub('club', credits: 0, teamCount: 33);

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T34', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(201, $status, 'l\'offre Club tient 34 équipes : la 34ᵉ passe');

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T35', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(422, $status, 'au-delà de 34, l\'offre Club refuse la création');

        $message = $this->readableBody();
        self::assertStringContainsString('Votre offre Club permet 34 équipes', $message, 'le refus nomme l\'offre courante et son cap');
        self::assertStringContainsString('Grand club retire la limite', $message, 'le refus nomme le palier supérieur, sans limite');
    }

    /** Grand club (à partir de 35) n'a AUCUN cap : la 51ᵉ équipe passe, là où l'ancien cap était 50. */
    public function testGrandClubIsNeverCapped(): void
    {
        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);

        $ctx = $this->seedClub('grand-club', credits: 0, teamCount: 50);
        self::assertNull($entitlements->teamCap($ctx['club'], $ctx['season']), 'Grand club est illimité : aucun cap');

        $status = $this->postJson($ctx['user'], '/api/teams', ['name' => 'T51', 'sportCategoryId' => $this->categoryId, 'priorityTierId' => 1]);
        self::assertSame(201, $status, 'Grand club crée sa 51ᵉ équipe sans refus');
    }

    /** Import Excel au-delà du cap : refus tout-ou-rien avec DÉCOMPTE, rien écrit. */
    public function testExcelImportBeyondCapIsRefusedWithCount(): void
    {
        $ctx = $this->seedClub('essentiel', credits: 0, teamCount: 19, withFfbb: true); // reste 1 place
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

    /** Offre Club à 33/34 : un fichier de 3 équipes est refusé en annonçant la SEULE place restante. */
    public function testClubExcelImportCountsAgainstTheCapOf34(): void
    {
        $ctx = $this->seedClub('club', credits: 0, teamCount: 33, withFfbb: true); // reste 1 place sur 34
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
            self::fail('un import qui franchit le cap Club (34) doit être refusé');
        } catch (ImportRejectedException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertStringContainsString('3 équipe(s) à créer', $e->getMessage(), 'le décompte dit combien d\'équipes le fichier crée');
            self::assertStringContainsString('1 place(s) restante(s)', $e->getMessage(), '33 équipes sur un cap de 34 : une place reste');
        }

        self::assertSame(33, $this->teamCountOf($ctx['club']->getId(), $ctx['season']->getId()), 'rien n\'est écrit : l\'import est tout-ou-rien');
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
        $this->client->request('GET', '/api/subscription_plans/' . $this->planByCode('essentiel')->getId(), [], [], $headers);
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

        if (null !== $planCode) {
            // Offre attribuée ET saison courante réglée → l'offre effective est bien la payante.
            $this->subscribeClub($club->getId(), $year, $planCode);
        }

        if ($teamCount > 0) {
            $this->categoryId = $this->seedTeams($club->getId(), $season->getId(), $teamCount);
        }

        if ($settleSocle) {
            // Le placement des matchs est gardé par le SocleGuard (409 sans plan en vigueur),
            // ANTÉRIEUR à la porte crédit du contrôleur : pour atteindre le refus/l'acceptation
            // week-end, le plan de saison doit pointer une version (ADR-0002).
            $this->settleSeasonPlan($season);
        }

        return ['user' => $user, 'club' => $club, 'season' => $season];
    }

    private function seedTeams(string $clubId, string $seasonId, int $count): string
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
                ->setSessionsPerWeek(2)
                ->setIsActive(true);
            $this->em->persist($team);
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

    /** @param array<string, mixed> $payload */
    private function postJson(User $user, string $uri, array $payload): int
    {
        $this->client->request('POST', $uri, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
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
