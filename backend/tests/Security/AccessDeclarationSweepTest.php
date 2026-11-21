<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Access\AccessDeclarationResolver;
use App\Access\AccountFunction;
use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\ClubResource;
use App\Access\Module;
use App\Access\Output;
use App\Controller\CoachWishCampaignActionController;
use App\Controller\CoachWishRecipientController;
use App\Controller\ExportMatchesPdfController;
use App\Controller\ExportPdfController;
use App\Controller\ExportXlsxController;
use App\Controller\FillPeriodPlanController;
use App\Controller\GenerateScheduleController;
use App\Controller\ImportController;
use App\Controller\ImportFixturesAnalyzeController;
use App\Controller\ImportFixturesController;
use App\Controller\ImportTeamsAnalyzeController;
use App\Controller\ParcImportAnalyzeController;
use App\Controller\ParcImportExecuteController;
use App\Controller\PlaceMatchesController;
use App\Controller\RegenerateController;
use App\Controller\RegenerateFromVersionController;
use App\Controller\VenuePeriodGridActionController;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Security\PublicApiRouteCatalog;
use App\Service\ClubInvitationManager;
use App\Service\PlanEntitlements;
use App\Service\TenantConnectionContext;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\MemberReadOnlyDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * NR BLOQUANT — axe auth & memberships : « chaque route de l'API dit à qui elle est ouverte, et ce
 * qu'elle dit est ce qu'un Membre rencontre aujourd'hui ».
 *
 * Balayage du ROUTEUR, contre les gardes à la main (qui décident encore : aucun écouteur ne lit les
 * déclarations à ce stade) :
 *  1. CLASSEMENT — chaque route `^/api/` (et chaque méthode, HEAD exclue) tombe dans UNE famille :
 *     console (`/api/admin/`), dev (`/api/dev/`), cadre d'API Platform (dix routes nommées ici),
 *     publique (`PublicApiRouteCatalog::matches`, plus `api_login`, porte du pare-feu `login` qui n'a
 *     pas de contrôleur), compte (`#[AccountFunction]`), club (`#[ClubFunction]`/`#[ClubResource]`).
 *     Les quatre premières se recouvrent sur deux routes (`api_doc` est aussi publique,
 *     `dev_demo_register` aussi) : l'ordre ci-dessus tranche. Une route des quatre premières ne
 *     résout AUCUNE déclaration ; une route hors d'elles en résout une, obligatoirement.
 *  2. CARACTÉRISATION — chaque route de club est jouée par un Membre actif, aux ids RÉELS d'un club
 *     PAYANT, NON démo, socle EN VIGUEUR (sinon un 403/409 viendrait de l'offre, de la démonstration
 *     ou du socle, et une lecture ouverte passerait pour « gestionnaire ») : « gestionnaire » ⇔ 403
 *     EXACT. Une lecture qui répond 404 doit être nommée (`READS_WITHOUT_ROW`) : un id bidon ne
 *     prouve rien.
 *  3. ORACLE DES ÉCRITURES — chaque ligne `403` de `MemberWriteSweepTest::ROUTES` est déclarée
 *     « gestionnaire », chaque ligne `open:` « membre » ou « compte », aucune ligne `skip:` n'est de club.
 *  4. ORACLE DES LECTURES — `MANAGER_ONLY_READS`, écrite à la main : toute lecture de club déclarée
 *     « gestionnaire » y figure, et inversement.
 *  5. RÉSOLUTION — une route d'API Platform sert la déclaration de sa ressource ; les quatorze
 *     opérations à contrôleur maison servent celle du CONTRÔLEUR (jamais celle de la ressource) ;
 *     les opérations non exposées sont nommées et non jouées.
 *  6. COMPTE SANS CLUB — chaque route « compte » est jouée par un compte sans aucune adhésion et
 *     répond le statut écrit dans `ACCOUNT_FUNCTIONS_WITHOUT_CLUB` (jamais le 404 « pas de club »).
 *  7. UN SEUL PUBLIC PAR CIBLE — aucune classe ni méthode ne porte une déclaration de club ET
 *     `#[AccountFunction]`.
 *  8. MODULES (lot 2, PR 5) — chaque classe déclarante est dans le module que `MODULE_OF` lui donne.
 *  9. MODULE FERMÉ — seules les huit fonctions de `REFUSED_WHEN_MODULE_CLOSED` le déclarent.
 * 10. DÉMONSTRATION — les envois à un tiers sont exactement `THIRD_PARTY`, les gestes de compte
 *     protégés exactement `PROTECTED_IN_DEMO` ; et, joués dans un club passé en démonstration,
 *     chaque envoi à un tiers est refusé sans qu'aucun message ne parte (abus).
 * 11. ENVOIS STRUCTURELS — toute route d'un contrôleur de club qui injecte de quoi envoyer est dans
 *     `THIRD_PARTY` ou dans `MAIL_EXEMPTIONS` (raison écrite).
 * 12. DÉCLARATIONS SCINDÉES — dans une classe à drapeau de démonstration ou de module fermé, chaque
 *     déclaration de méthode est dans `SPLIT_DECLARATIONS`.
 * Les tables de ce test sont écrites à la main, jamais dérivées des déclarations ; une ligne sans
 * route au routeur rougit.
 *
 * Seaux de débit remis à zéro avant chaque appel par `reset()` ciblé — jamais FLUSHALL.
 * Angle jumeau, cité : `MemberWriteSweepTest` (les écritures, 403 exact, aucun effet) et
 * `MemberReadsAreSideEffectFreeTest` (les lectures ouvertes n'agissent pas) — auth & memberships
 * est un point sensible : ici la déclaration, là l'effet.
 */
#[Group('phase1')]
#[Group('integration')]
final class AccessDeclarationSweepTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use MemberReadOnlyDecorTrait;
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    /** Les cinq routes du cadre sans classe de ressource, nommées par le contrat du lecteur. */
    private const array FRAMEWORK_ROUTES_WITHOUT_RESOURCE = ['api_doc', 'api_genid', 'api_validation_errors', 'api_entrypoint', 'api_jsonld_context'];

    /** La famille 4 attendue, exactement : les cinq ci-dessus et les cinq dont la ressource est sous `ApiPlatform\`. */
    private const array FRAMEWORK_FAMILY = [
        '_api_errors', '_api_validation_errors_hydra', '_api_validation_errors_jsonapi', '_api_validation_errors_problem',
        '_api_validation_errors_xml', 'api_doc', 'api_entrypoint', 'api_genid', 'api_jsonld_context', 'api_validation_errors',
    ];

    /** Routes publiques absentes du catalogue, nommées avec leur raison. */
    private const array PUBLIC_OUTSIDE_CATALOG = [
        'api_login' => 'porte json_login de son propre pare-feu `login` : aucun contrôleur, exclue du catalogue par construction (PublicApiRouteCatalog, docblock)',
    ];

    /**
     * Les routes sous un motif de `PublicApiRouteCatalog`, à la main (`MÉTHODE chemin`, sans
     * `.{_format}`) => pourquoi elle est ouverte à un anonyme, jamais contrôlée par le rôle.
     */
    private const array PUBLIC_ROUTES = [
        'GET /api/club-approvals/{token}' => 'page publique à token : validation d\'un club',
        'POST /api/club-approvals/{token}' => 'page publique à token : validation d\'un club',
        'GET /api/coach-wishes/public/{token}' => 'page publique à token : doléances d\'un coach',
        'POST /api/coach-wishes/public/{token}' => 'page publique à token : doléances d\'un coach',
        'GET /api/invitations/public/{token}' => 'page publique à token : invitation',
        'POST /api/invitations/public/{token}/accept' => 'page publique à token : invitation',
        'GET /api/clubs/{clubId}/logo' => 'logo du club, servi aux pages publiques et aux mails',
        'GET /api/ffbb-logos/{scope}/{code}' => 'logos FFBB stockés, servis aux pages publiques',
        'GET /api/docs' => 'documentation de l\'API',
        'GET /api/health' => 'sonde de santé',
        'GET /api/register/club-lookup' => 'inscription',
        'GET /api/register/config' => 'inscription',
        'POST /api/register' => 'inscription',
        'POST /api/register/verify' => 'inscription',
        'POST /api/dev/demo-register' => 'inscription de démonstration',
        'POST /api/password/forgot' => 'mot de passe oublié',
        'POST /api/password/reset' => 'mot de passe oublié',
        'POST /api/me/email/confirm' => 'confirmation d\'adresse par lien',
        'POST /api/logout' => 'déconnexion, même d\'une session morte',
    ];

    /** Les quatorze opérations d'API servies par un contrôleur maison : nom de route => contrôleur. */
    private const array CUSTOM_CONTROLLER_OPERATIONS = [
        'import_teams' => ImportController::class,
        'import_teams_analyze' => ImportTeamsAnalyzeController::class,
        'parc_import_analyze' => ParcImportAnalyzeController::class,
        'parc_import' => ParcImportExecuteController::class,
        'analyze_fixtures_import' => ImportFixturesAnalyzeController::class,
        'import_fixtures' => ImportFixturesController::class,
        'send_links_coach_wish_campaign' => CoachWishCampaignActionController::class,
        'remind_coach_wish_campaign' => CoachWishCampaignActionController::class,
        'set_recipient_coach_wish_campaign' => CoachWishRecipientController::class,
        'reset_venue_period_grid' => VenuePeriodGridActionController::class,
        'clear_venue_period_grid' => VenuePeriodGridActionController::class,
        'export_pdf' => ExportPdfController::class,
        'export_xlsx' => ExportXlsxController::class,
        'generate_schedule' => GenerateScheduleController::class,
    ];

    /** Les opérations `api_platform.action.not_exposed` : 404 pour tous, nommées, jamais jouées. */
    private const array NOT_EXPOSED = [
        '_api_/venue_closures/{id}{._format}_get' => 'App\ApiResource\VenueClosureResource',
        '_api_/team_solo_budgets/{teamId}{._format}_get' => 'App\ApiResource\TeamSoloBudgetResource',
        '_api_/team_tag_assignments/{id}{._format}_get' => 'App\ApiResource\TeamTagAssignmentResource',
    ];

    /**
     * L'ORACLE DES LECTURES, à la main : `GET chemin` (sans `.{_format}`) => pourquoi elle est
     * réservée au gestionnaire. Partie de `MemberReadsAreSideEffectFreeTest` (sept lectures
     * sortantes, deux aperçus de mail), complétée par ce que le Membre rencontre aujourd'hui (403
     * exact, constaté le 2026-11-20 sur ce décor).
     */
    private const array MANAGER_ONLY_READS = [
        'GET /api/ffbb/engagements' => 'appel sortant FFBB',
        'GET /api/ffbb/rencontres' => 'appel sortant FFBB',
        'GET /api/ffbb/salles' => 'appel sortant FFBB',
        'GET /api/ffbb/salles-proches' => 'appel sortant FFBB',
        'GET /api/geocode' => 'appel sortant de géocodage',
        'GET /api/geocode/reverse' => 'appel sortant de géocodage',
        'GET /api/venues/geo-check' => 'vérification géographique sortante',
        'GET /api/coach_wish_campaigns/{id}/email-preview' => 'aperçu du mail aux coachs',
        'GET /api/fixtures/conflicts/mail-coaches/preview' => 'aperçu de l\'envoi aux coachs : noms et adresses',
        'GET /api/fixtures/league-validation' => 'domiciles à valider auprès de la ligue',
        'GET /api/opponents/{code}/venue-suggestions' => 'gymnases suggérés pour un adversaire (appariement, geste de gestion)',
        'GET /api/invitations' => 'invitations en cours (adresses)',
        'GET /api/memberships' => 'membres du club et leurs rôles',
        'GET /api/memberships/pending' => 'adhésions en attente',
        'GET /api/club/export' => 'export RGPD des données du club',
    ];

    /**
     * Lectures dont le chemin de jeu n'est pas le chemin du routeur aux ids remplis : paramètres de
     * requête ou identifiant d'un autre genre. `GET chemin` => chemin joué (placeholders du décor).
     */
    private const array READ_PLAY = [
        'GET /api/coach_wish_campaigns/{id}/preview' => '/api/coach_wish_campaigns/{coach_wish_campaign}/preview?coachId={coach}',
        'GET /api/opponents/{code}/logo' => '/api/opponents/ARA0069001/logo',
        'GET /api/opponents/{code}/venue-suggestions' => '/api/opponents/ARA0069001/venue-suggestions',
        'GET /api/schedules/{id}/export-pdf/{filename}' => '/api/schedules/{schedule}/export-pdf/schedule-{schedule}-season.pdf',
        'GET /api/ffbb/salles' => '/api/ffbb/salles?q=gymnase',
        'GET /api/ffbb/salles-proches' => '/api/ffbb/salles-proches?lat=45.75&lon=4.85',
        'GET /api/geocode' => '/api/geocode?q=gymnase',
        'GET /api/geocode/reverse' => '/api/geocode/reverse?lat=45.75&lon=4.85',
        'GET /api/planned-windows' => '/api/planned-windows?start={match_date}&end={match_date}',
        'GET /api/venue-closure-impact' => '/api/venue-closure-impact?venueId={venue}&startDate={match_date}&endDate={match_date}',
        'GET /api/calendar-entries/{id}/conflicts' => '/api/calendar-entries/{period}/conflicts',
        'GET /api/calendar-entries/{id}/impact' => '/api/calendar-entries/{period}/impact',
    ];

    /** Lectures où un 404 est la réponse attendue sur ce décor, avec la raison. */
    private const array READS_WITHOUT_ROW = [
        'GET /api/opponents/{code}/logo' => 'logo absent du stockage (le cas stocké : MemberReadsAreSideEffectFreeTest)',
        'GET /api/schedules/{id}/export-pdf/{filename}' => 'aucun PDF rendu sur ce décor',
        'GET /api/mailbox/{id}' => 'aucun message dans la boîte du club sur ce décor',
        'GET /api/schedule_diagnostics/{id}' => 'un diagnostic naît d\'une génération en échec, absente de ce décor',
        'GET /api/priority_tiers/{id}' => 'table de référence globale, vide sur la base de test (lecture sans garde de rôle)',
    ];

    /** Premier segment d'un chemin de ressource => placeholder du décor qui remplit son `{id}`. */
    private const array SEGMENT_IDS = [
        'calendar_entries' => 'calendar_entry', 'club_league_windows' => 'club_league_window', 'clubs' => 'club',
        'coach_player_memberships' => 'coach_player_membership', 'coaches' => 'coach', 'coach_wish_campaigns' => 'coach_wish_campaign',
        'coach_wish_mutualizations' => 'coach_wish_mutualization', 'coach_wishes' => 'coach_wish', 'competitions' => 'competition',
        'constraint_period_overrides' => 'constraint_period_override', 'constraints' => 'constraint', 'fixtures' => 'fixture',
        'implicit_rule_settings' => 'rule_key', 'match_constraints' => 'match_constraint', 'priority_tiers' => 'priority_tier',
        'reservations' => 'reservation', 'schedule_plans' => 'schedule_plan', 'schedules' => 'schedule',
        'schedule_slot_templates' => 'schedule_slot', 'seasons' => 'season', 'shared_training_blocks' => 'shared_training_block',
        'sport_categories' => 'category', 'sports' => 'sport', 'subscription_plans' => 'subscription_plan',
        'team_coaches' => 'team_coach', 'team_links' => 'team_link', 'team_match_habits' => 'team_match_habit',
        'team_period_overrides' => 'team_period_override', 'team_tag_assignments' => 'team_tag_assignment', 'team_tags' => 'team_tag',
        'teams' => 'team', 'users' => 'member_user', 'venue_match_windows' => 'venue_match_window',
        'venue_occupancies' => 'venue_occupancy', 'venue_period_overrides' => 'venue_period_override', 'venues' => 'venue',
        'venue_training_slots' => 'slot', 'venue_travel_rule_settings' => 'travel_rule', 'venue_travel_times' => 'venue_travel_time',
    ];

    /**
     * Ce qu'une fonction « compte » répond à un compte SANS AUCUNE adhésion, à la main :
     * `MÉTHODE chemin` => [chemin joué, corps, statut attendu]. Jouées dans cet ordre (le mot de
     * passe change avant l'effacement, qui clôt). L'acceptation d'invitation est jouée par un
     * second compte sans club, à l'adresse invitée (`{invitation_token}`). Une route déclarée
     * `#[AccountFunction]` absente d'ici rougit : une fonction de compte se prouve indépendante du club.
     */
    private const array ACCOUNT_FUNCTIONS_WITHOUT_CLUB = [
        'GET /api/me' => ['/api/me', null, 200],
        'PATCH /api/me' => ['/api/me', '{"firstName":"Sans"}', 200],
        'GET /api/me/export' => ['/api/me/export', null, 200],
        'GET /api/release-notes' => ['/api/release-notes', null, 200],
        'POST /api/release-notes/seen' => ['/api/release-notes/seen', null, 204],
        'GET /api/deployment-notice' => ['/api/deployment-notice', null, 200],
        'POST /api/me/email' => ['/api/me/email', '{"currentPassword":"Password123!","email":"{new_email}"}', 200],
        'DELETE /api/me/email' => ['/api/me/email', null, 200],
        'POST /api/me/password' => ['/api/me/password', '{"currentPassword":"Password123!","newPassword":"Password456!Sans"}', 200],
        'POST /api/invitations/{token}/accept' => ['/api/invitations/{invitation_token}/accept', null, 200],
        'DELETE /api/me' => ['/api/me', '{"password":"Password456!Sans"}', 200],
    ];

    /**
     * L'ORACLE DES MODULES, à la main : chaque classe qui déclare une fonction de club (contrôleur
     * maison, ou ressource d'API Platform pour ses opérations sans contrôleur maison), rangée dans son
     * module, sous `App\`. Une classe nouvelle sans ligne rougit : elle se classe consciemment.
     * Choix écrits (lot 2, PR 5) : les imports d'équipes et de parc sont du Planning, comme les
     * équipes et les gymnases qu'ils alimentent (`TeamResource`, `VenueResource`) ; l'impact d'une
     * fermeture de gymnase (`VenueClosureImpactController`, `VenueClosureResource`) et l'impact d'une
     * suppression (`DeletionImpactController`) restent du Planning — ce sont des gestes du Planning
     * dont la réponse COMPTE aussi les matchs touchés ; les ranger sous Matchs priverait de cet
     * avertissement un club dont le module Matchs serait fermé.
     */
    private const array MODULE_OF = [
        'club' => [
            'ApiResource\ClubResource', 'ApiResource\SeasonResource', 'ApiResource\SportCategoryResource', 'ApiResource\SportResource',
            'ApiResource\SubscriptionPlanResource', 'ApiResource\UserResource',
            'Controller\Basketball\FfbbImportController', 'Controller\ClubAppearanceController', 'Controller\ClubClockController',
            'Controller\ClubInvitationController', 'Controller\ClubLogoController', 'Controller\ClubShortNameController',
            'Controller\ClubSiegeController', 'Controller\FeedbackController', 'Controller\MailboxController',
            'Controller\MembershipController', 'Controller\MercureAuthController', 'Controller\ResetSeasonController',
            'Controller\RgpdExportController', 'Controller\SeasonTransitionController',
        ],
        'matches' => [
            'ApiResource\ClubLeagueWindowResource', 'ApiResource\CompetitionResource', 'ApiResource\FixtureResource',
            'ApiResource\MatchConstraintResource', 'ApiResource\TeamMatchHabitResource', 'ApiResource\VenueMatchWindowResource',
            'Controller\Basketball\FfbbEngagementsController', 'Controller\Basketball\FfbbRencontresController',
            'Controller\Basketball\OpponentLogoController', 'Controller\ClubRuleCoherenceController',
            'Controller\CompetitionEntryDeadlinesController', 'Controller\ConflictCoachMailController',
            'Controller\EntryDeadlineOutlookController', 'Controller\ExportMatchesPdfController', 'Controller\FbiCorrectionController',
            'Controller\FbiIngestionFreshnessController', 'Controller\FixtureConflictsController',
            'Controller\ImportFixturesAnalyzeController', 'Controller\ImportFixturesController', 'Controller\LeagueMatchWindowsController',
            'Controller\LeagueValidatedFixturesController', 'Controller\LeagueWindowSuggestionsController',
            'Controller\MatchModuleVisitController', 'Controller\OpponentRefreshController', 'Controller\OpponentResolveController',
            'Controller\OpponentTravelController', 'Controller\PlaceMatchesController', 'Controller\PlacementRunController',
            'Controller\ReviewFixtureDeviationController', 'Controller\ReviewFixturesController', 'Controller\VenueExternalLabelController',
        ],
        'planning' => [
            'ApiResource\CalendarEntryResource', 'ApiResource\CoachPlayerMembershipResource', 'ApiResource\CoachResource',
            'ApiResource\CoachWishCampaignResource', 'ApiResource\CoachWishMutualizationResource', 'ApiResource\CoachWishResource',
            'ApiResource\ConstraintPeriodOverrideResource', 'ApiResource\ConstraintResource', 'ApiResource\ImplicitRuleSettingResource',
            'ApiResource\PriorityTierResource', 'ApiResource\ReservationResource', 'ApiResource\ScheduleDiagnosticResource',
            'ApiResource\SchedulePlanResource', 'ApiResource\ScheduleResource', 'ApiResource\ScheduleSlotTemplateResource',
            'ApiResource\SharedTrainingBlockResource', 'ApiResource\TeamCoachResource', 'ApiResource\TeamLinkResource',
            'ApiResource\TeamPeriodOverrideResource', 'ApiResource\TeamResource', 'ApiResource\TeamSoloBudgetResource',
            'ApiResource\TeamTagAssignmentResource', 'ApiResource\TeamTagResource', 'ApiResource\VenueClosureResource',
            'ApiResource\VenueOccupancyResource', 'ApiResource\VenuePeriodOverrideResource', 'ApiResource\VenueResource',
            'ApiResource\VenueTrainingSlotResource', 'ApiResource\VenueTravelRuleSettingResource', 'ApiResource\VenueTravelTimeResource',
            'Controller\Basketball\FfbbSallesController', 'Controller\CalendarEntryConflictsController',
            'Controller\ClosureWindowApplyController', 'Controller\ClosureWindowPreviewController',
            'Controller\CoachWishCampaignActionController', 'Controller\CoachWishCampaignPreviewController',
            'Controller\CoachWishEmailPreviewController', 'Controller\CoachWishRecipientController', 'Controller\CoachWishTransferController',
            'Controller\DeletionImpactController', 'Controller\ExportPdfController', 'Controller\ExportPdfDownloadController',
            'Controller\ExportXlsxController', 'Controller\FillPeriodPlanController', 'Controller\GenerateScheduleController',
            'Controller\GeocodeController', 'Controller\GroupReservationController', 'Controller\ImportController',
            'Controller\ImportTeamsAnalyzeController', 'Controller\LoadLocksController', 'Controller\ManualEditController',
            'Controller\ParcImportAnalyzeController', 'Controller\ParcImportExecuteController', 'Controller\PlannedWindowsController',
            'Controller\PublicHolidaysController', 'Controller\RedatePreviewController', 'Controller\RegenerateController',
            'Controller\RegenerateFromVersionController', 'Controller\ReopenScheduleController', 'Controller\ReorderTeamsController',
            'Controller\SchedulePlanAlertSeenController', 'Controller\SchedulePlanStructureHashController',
            'Controller\SchoolHolidaysController', 'Controller\SocleDeviationController', 'Controller\TrainingPlacedConflictsController',
            'Controller\TranscribePeriodPlanController', 'Controller\ValidateConstraintsController', 'Controller\ValidateImpactController',
            'Controller\ValidateScheduleController', 'Controller\VenueClosureImpactController', 'Controller\VenueGeoCheckController',
            'Controller\VenuePeriodGridActionController', 'Controller\VenueTravelTimeAutofillController',
            'Controller\VenueUsageStatsController',
        ],
    ];

    /**
     * Les fonctions refusées module Matchs fermé (aucun planning de saison en vigueur), à la main :
     * les huit fonctions de la décision Q9 (a) — huit appels au garde du socle retirés des contrôleurs,
     * neuf routes (l'envoi des conflits et son aperçu partagent leur garde). Aucune lecture nouvelle
     * n'est fermée : la liste des engagements, les rencontres, la visite du module ne le sont pas.
     */
    private const array REFUSED_WHEN_MODULE_CLOSED = [
        'POST /api/ffbb/engagements/confirm', 'GET /api/ffbb/rencontres', 'POST /api/ffbb/rencontres/apply',
        'POST /api/fixtures/conflicts/mail-coaches', 'GET /api/fixtures/conflicts/mail-coaches/preview',
        'GET /api/fixtures/league-validation', 'POST /api/fixtures/place', 'POST /api/fixtures/review',
        'POST /api/fixtures/review/deviations',
    ];

    /**
     * Les fonctions qui envoient à un tiers, à la main : `MÉTHODE chemin` => [chemin joué, corps,
     * texte du refus en démonstration] (trois textes gardés, décision Q12). L'aperçu de l'envoi aux
     * coachs n'envoie rien mais partage le refus de l'envoi (comportement d'avant la PR 5, gardé).
     */
    private const array THIRD_PARTY = [
        'POST /api/fixtures/conflicts/mail-coaches' => ['/api/fixtures/conflicts/mail-coaches', [], 'Envoi d\'e-mails indisponible en démonstration.'],
        'GET /api/fixtures/conflicts/mail-coaches/preview' => ['/api/fixtures/conflicts/mail-coaches/preview', null, 'Envoi d\'e-mails indisponible en démonstration.'],
        'POST /api/coach_wish_campaigns/{id}/send-links' => ['/api/coach_wish_campaigns/{coach_wish_campaign}/send-links', [], 'Envoi d\'e-mails indisponible en démonstration.'],
        'POST /api/coach_wish_campaigns/{id}/remind' => ['/api/coach_wish_campaigns/{coach_wish_campaign}/remind', [], 'Envoi d\'e-mails indisponible en démonstration.'],
        'POST /api/invitations' => ['/api/invitations', ['email' => '{invitee}', 'role' => 'member'], 'Un compte de démonstration ne peut pas inviter.'],
        'POST /api/invitations/{id}/resend' => ['/api/invitations/{invitation}/resend', [], 'Un compte de démonstration ne peut pas inviter.'],
    ];

    /**
     * Les fonctions de compte protégées en démonstration, à la main (« Ce compte de démonstration ne
     * peut pas être modifié. ») ; jouées par `DemoWindowTest::testDemoAccountCannotMutateItsProfileNorDeleteItself`.
     * Annuler un changement d'adresse en attente ne l'est pas (comportement d'avant la PR 5).
     */
    private const array PROTECTED_IN_DEMO = ['PATCH /api/me', 'POST /api/me/password', 'POST /api/me/email', 'DELETE /api/me'];

    /**
     * Les routes d'un contrôleur de club QUI PEUT ENVOYER (il injecte `MailerInterface`, un
     * `*Notifier`, un `*Mailer` ou un `*Mailing`) et qui ne sont pas dans `THIRD_PARTY`, chacune avec
     * sa raison écrite à la main. Un envoi ajouté à un contrôleur de club sans passer par l'une des
     * deux listes rougit `testEveryClubControllerThatCanSendIsDeclaredOrExempted`. Portée : l'injection
     * DIRECTE dans le contrôleur ; un envoi caché derrière un service tiers lui échappe.
     */
    private const array MAIL_EXEMPTIONS = [
        'GET /api/invitations' => 'lecture, n\'envoie rien',
        'DELETE /api/invitations/{id}' => 'révocation, n\'envoie rien',
        'POST /api/feedback' => 'le retour part à l\'éditeur du produit, jamais à un tiers du club',
        'GET /api/fixtures/conflicts' => 'lecture : `ConflictCoachMailing` n\'y sert qu\'à compter',
        'PUT /api/fixtures/conflicts/{fingerprint}/resolution' => '`ConflictCoachMailing` n\'y sert qu\'à compter, n\'envoie rien',
        'DELETE /api/fixtures/conflicts/{fingerprint}/resolution' => '`ConflictCoachMailing` n\'y sert qu\'à compter, n\'envoie rien',
        'GET /api/memberships' => 'lecture, n\'envoie rien',
        'GET /api/memberships/pending' => 'lecture, n\'envoie rien',
        'POST /api/memberships/{id}/approve' => 'pré-existant, comportement démo à trancher (mail d\'acceptation au membre)',
        'POST /api/memberships/{id}/reject' => 'pré-existant, comportement démo à trancher (mail de refus au demandeur)',
        'POST /api/memberships/{id}/role' => 'pré-existant, comportement démo à trancher (mail « rôle accordé » au membre)',
        'POST /api/memberships/{id}/deactivate' => 'pré-existant, comportement démo à trancher (mail « accès retiré » au membre)',
        'POST /api/memberships/{id}/manager-request/refuse' => 'pré-existant, comportement démo à trancher (mail de refus de la demande de rôle)',
        'POST /api/memberships/me/manager-request' => 'un club de démonstration est refusé 409 par ManagerRoleRequestService (DemoRegime) avant tout envoi',
        'DELETE /api/memberships/me/manager-request' => 'annulation, n\'envoie rien',
        'POST /api/memberships/{id}/reactivate' => 'réactivation, n\'envoie rien (annule un envoi programmé)',
    ];

    /**
     * Une déclaration de MÉTHODE remplace celle de la classe, sans rien en hériter
     * (`AccessDeclarationResolver::declarationOn`) : dans une classe où `thirdParty` ou
     * `refusedWhenModuleClosed` est déclaré, chaque `#[ClubFunction]` de méthode est écrit ici, à la
     * main, avec sa raison — un drapeau perdu par une méthode nouvelle rougit.
     */
    private const array SPLIT_DECLARATIONS = [
        'App\Controller\Basketball\FfbbEngagementsController::confirm' => 'seule la confirmation se ferme module fermé ; la liste des engagements reste ouverte',
        'App\Controller\ClubInvitationController::create' => 'inviter envoie à un tiers ; lister et révoquer n\'envoient rien',
        'App\Controller\ClubInvitationController::resend' => 'renvoyer envoie à un tiers ; lister et révoquer n\'envoient rien',
    ];

    private const string MAIN_CONTROLLER = 'api_platform.symfony.main_controller';

    private const string NOT_EXPOSED_CONTROLLER = 'api_platform.action.not_exposed';

    private KernelBrowser $client;

    public function testTheContractIsTheOneThePlanNames(): void
    {
        self::assertSame(['club', 'planning', 'matches'], array_map(static fn (Module $m): string => $m->value, Module::cases()), 'trois modules : Club, Planning (doléances comprises), Matchs');
        self::assertSame(['MEMBER', 'MANAGER'], array_map(static fn (Audience $a): string => $a->name, Audience::cases()));
        self::assertSame(self::FRAMEWORK_ROUTES_WITHOUT_RESOURCE, AccessDeclarationResolver::FRAMEWORK_ROUTES);

        // Public obligatoire, aucun défaut tiré de la méthode HTTP : module et public n'ont pas de
        // valeur par défaut. Ce qu'une fonction FAIT EN PLUS se dit seulement quand c'est vrai : la
        // sortie payante (`output`, nulle par défaut), l'envoi à un tiers (`thirdParty`) et le refus
        // module fermé (`refusedWhenModuleClosed`), faux par défaut — une fonction qui ne fait rien de
        // tel ne le dit pas. Une fonction de compte n'est protégée en démonstration que si elle le dit.
        $defaults = ['output' => null, 'thirdParty' => false, 'refusedWhenModuleClosed' => false, 'protectedInDemo' => false];
        foreach ([
            ClubFunction::class => ['module', 'audience', 'output', 'thirdParty', 'refusedWhenModuleClosed'],
            ClubResource::class => ['module', 'read', 'write'],
            AccountFunction::class => ['protectedInDemo'],
        ] as $attribute => $expected) {
            $params = new ReflectionClass($attribute)->getConstructor()?->getParameters() ?? [];
            self::assertSame($expected, array_map(static fn (ReflectionParameter $p): string => $p->getName(), $params), $attribute);
            foreach ($params as $param) {
                if (\array_key_exists($param->getName(), $defaults)) {
                    self::assertTrue($param->isOptional() && $defaults[$param->getName()] === $param->getDefaultValue(), \sprintf('%s::$%s vaut %s par défaut', $attribute, $param->getName(), var_export($defaults[$param->getName()], true)));

                    continue;
                }
                self::assertFalse($param->isOptional(), \sprintf('%s::$%s a une valeur par défaut', $attribute, $param->getName()));
            }
        }

        // Le module dit sa condition : seul le module Matchs exige le socle en vigueur.
        $requires = [];
        foreach (Module::cases() as $module) {
            $requires[$module->value] = new ReflectionMethod(Module::class, 'requiresSocleInForce')->invoke($module);
        }
        self::assertSame(['club' => false, 'planning' => false, 'matches' => true], $requires, 'Module::requiresSocleInForce() : seul Matchs se ferme sans socle en vigueur');
    }

    /**
     * Les sorties payantes se DÉCLARENT sur la fonction, et la déclaration est cohérente : les cinq
     * sorties du contrat, deux seulement réglées à la réponse (tableur, PDF des matchs — rendus dans
     * la requête), les trois autres dans le worker, à l'obtention ; exactement les sept fonctions qui
     * produisent quelque chose en portent une, chacune la sienne, et « Charger une version » n'en
     * porte pas (aucun calcul, décision fondateur Q13). Une sortie se déclare sur une écriture de
     * club : jamais sur une lecture, une ressource ou une fonction de compte.
     */
    public function testOutputsAreDeclaredOnExactlyTheFunctionsThatProduceOne(): void
    {
        self::assertSame(['SOLVE', 'MATCH_PLACEMENT', 'PLANNING_PDF', 'PLANNING_XLSX', 'MATCHES_PDF'], array_map(static fn (Output $o): string => $o->name, Output::cases()), 'les cinq sorties du contrat');
        $settled = array_values(array_map(static fn (Output $o): string => $o->name, array_filter(Output::cases(), static fn (Output $o): bool => $o->settledAtResponse())));
        self::assertSame(['PLANNING_XLSX', 'MATCHES_PDF'], $settled, 'seules les sorties rendues dans la requête se règlent à la réponse');

        $declared = [];
        $wrong = [];
        $resolver = $this->resolver();
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                $output = null === $declaration ? null : (get_object_vars($declaration)['output'] ?? null);
                if (!$output instanceof Output) {
                    continue;
                }
                $controller = $this->controllerClass((string) $route->getDefault('_controller'));
                $declared[$controller] = $output->name;
                if (!$declaration instanceof ClubFunction || \in_array($method, ['GET', 'HEAD'], true)) {
                    $wrong[] = \sprintf('%s %s (%s) : une sortie se déclare sur une écriture de club', $method, $route->getPath(), $name);
                }
            }
        }
        ksort($declared);

        $expected = [
            ExportMatchesPdfController::class => 'MATCHES_PDF',
            ExportPdfController::class => 'PLANNING_PDF',
            ExportXlsxController::class => 'PLANNING_XLSX',
            FillPeriodPlanController::class => 'SOLVE',
            GenerateScheduleController::class => 'SOLVE',
            PlaceMatchesController::class => 'MATCH_PLACEMENT',
            RegenerateController::class => 'SOLVE',
        ];
        ksort($expected);

        self::assertSame([], $wrong, "Sorties mal placées :\n  - " . implode("\n  - ", $wrong));
        self::assertSame($expected, $declared, 'les fonctions qui produisent une sortie, et la sortie de chacune');
        self::assertArrayNotHasKey(RegenerateFromVersionController::class, $declared, '« Charger une version » ne lance aucun calcul : ce n\'est pas une sortie');
    }

    public function testEveryApiRouteFallsInExactlyOneFamily(): void
    {
        $resolver = $this->resolver();
        $wrong = [];
        $framework = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                $request = $this->requestFor($name, $route, $method);
                $family = $this->outsideFamily($name, $route, $method);
                if ('framework' === $family) {
                    $framework[$name] = true;
                }
                if (('framework' === $family) !== $resolver->isFrameworkRoute($request)) {
                    $wrong[] = \sprintf('%s %s (%s) : isFrameworkRoute dit l\'inverse de la famille %s', $method, $route->getPath(), $name, $family ?? 'compte/club');
                }
                $declaration = $resolver->for($request);
                if (null !== $family && null !== $declaration) {
                    $wrong[] = \sprintf('%s %s (%s) : famille %s ET déclarée %s', $method, $route->getPath(), $name, $family, $declaration::class);
                }
                if (null === $family && null === $declaration) {
                    $wrong[] = \sprintf('%s %s (%s) : aucune famille — ni console, ni dev, ni cadre, ni publique, ni déclarée', $method, $route->getPath(), $name);
                }
            }
        }

        $framework = array_keys($framework);
        sort($framework);
        self::assertSame(self::FRAMEWORK_FAMILY, $framework, 'la famille du cadre d\'API Platform a changé : une route du cadre inconnue est à classer');
        self::assertSame([], $wrong, "Routes hors d'une famille unique :\n  - " . implode("\n  - ", $wrong));
        self::assertSame([], $this->deadRows(), "Lignes des tables sans route au routeur :\n  - " . implode("\n  - ", $this->deadRows()));
    }

    public function testEachRouteResolvesTheDeclarationThePlanRuleDesignates(): void
    {
        $resolver = $this->resolver();
        $wrong = [];
        $custom = [];
        $notExposed = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (null !== $this->outsideFamily($name, $route, $method)) {
                    continue;
                }
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                $controller = (string) $route->getDefault('_controller');
                $resource = (string) $route->getDefault('_api_resource_class');
                if (str_starts_with($resource, 'App\\') && \in_array($controller, [self::MAIN_CONTROLLER, self::NOT_EXPOSED_CONTROLLER], true)) {
                    if (self::NOT_EXPOSED_CONTROLLER === $controller) {
                        $notExposed[$name] = $resource;
                    }
                    $expected = $this->classAttribute($resource, ClubResource::class);
                    if (!$expected instanceof ClubResource || !$this->sameDeclaration($declaration, $expected)) {
                        $wrong[] = \sprintf('%s %s (%s) : la déclaration de la ressource %s est attendue', $method, $route->getPath(), $name, $resource);
                    }
                    continue;
                }
                if (str_starts_with($resource, 'App\\')) {
                    $custom[$name] = $this->controllerClass($controller);
                }
                $expected = $this->controllerDeclaration($controller);
                if (null === $expected) {
                    $wrong[] = \sprintf('%s %s (%s) : le contrôleur %s ne porte aucune déclaration (obligatoire)', $method, $route->getPath(), $name, $controller);
                } elseif ($declaration instanceof ClubResource || !$this->sameDeclaration($declaration, $expected)) {
                    $wrong[] = \sprintf('%s %s (%s) : la déclaration du contrôleur %s est attendue, jamais celle de la ressource', $method, $route->getPath(), $name, $controller);
                }
            }
        }

        ksort($custom);
        $expectedCustom = self::CUSTOM_CONTROLLER_OPERATIONS;
        ksort($expectedCustom);
        self::assertSame($expectedCustom, $custom, 'les opérations d\'API servies par un contrôleur maison ont changé');
        ksort($notExposed);
        $expectedNotExposed = self::NOT_EXPOSED;
        ksort($expectedNotExposed);
        self::assertSame($expectedNotExposed, $notExposed, 'les opérations non exposées ont changé');
        self::assertSame([], $wrong, "Résolutions fausses :\n  - " . implode("\n  - ", $wrong));
    }

    public function testEveryWriteIsDeclaredAsTheWriteSweepDecides(): void
    {
        $resolver = $this->resolver();
        $byKey = $this->routesByKey();
        $wrong = [];
        foreach ($this->writeSweepRows() as $key => [, , $decision]) {
            if (!isset($byKey[$key])) {
                continue; // une ligne morte rougit déjà dans MemberWriteSweepTest
            }
            [$name, $route] = $byKey[$key];
            $declaration = $resolver->for($this->requestFor($name, $route, explode(' ', $key, 2)[0]));
            $audience = $this->audienceOf($declaration, explode(' ', $key, 2)[0]);
            $said = $declaration instanceof AccountFunction ? 'compte' : ($audience?->name ?? 'aucune');
            if (403 === $decision && Audience::MANAGER !== $audience) {
                $wrong[] = \sprintf('%s : 403 au balayage des écritures, déclarée %s', $key, $said);
            } elseif (\is_string($decision) && str_starts_with($decision, 'open:') && !$declaration instanceof AccountFunction && Audience::MEMBER !== $audience) {
                $wrong[] = \sprintf('%s : ouverte au Membre au balayage des écritures, déclarée %s', $key, $said);
            } elseif (\is_string($decision) && str_starts_with($decision, 'skip:') && ($declaration instanceof ClubFunction || $declaration instanceof ClubResource)) {
                $wrong[] = \sprintf('%s : hors club au balayage des écritures, déclarée de club', $key);
            }
        }

        self::assertSame([], $wrong, "Écritures déclarées contre MemberWriteSweepTest::ROUTES :\n  - " . implode("\n  - ", $wrong));
    }

    public function testManagerOnlyReadsAreExactlyTheHandOracle(): void
    {
        $resolver = $this->resolver();
        $declaredManager = [];
        $clubReads = [];
        foreach ($this->apiRoutes() as $name => $route) {
            if (!\in_array('GET', $this->methods($route), true) || null !== $this->outsideFamily($name, $route, 'GET')) {
                continue;
            }
            $declaration = $resolver->for($this->requestFor($name, $route, 'GET'));
            if ($declaration instanceof AccountFunction) {
                continue;
            }
            $key = 'GET ' . $this->bare($route);
            $clubReads[$key] = true;
            if (Audience::MANAGER === $this->audienceOf($declaration, 'GET')) {
                $declaredManager[] = $key;
            }
        }
        sort($declaredManager);
        $oracle = array_keys(self::MANAGER_ONLY_READS);
        sort($oracle);

        self::assertSame($oracle, $declaredManager, 'lectures déclarées « gestionnaire » ≠ MANAGER_ONLY_READS (à gauche l\'oracle, à droite les déclarations)');
        foreach ($oracle as $key) {
            self::assertArrayHasKey($key, $clubReads, \sprintf('%s : ligne de l\'oracle qui n\'est pas une lecture de club', $key));
        }
    }

    /**
     * Chaque fonction de club est rangée dans le module que `MODULE_OF` (à la main) lui donne : la
     * table est la vérité, jamais dérivée des déclarations ; une classe absente ou en trop rougit.
     */
    public function testEveryClubFunctionSitsInTheModuleTheHandTableNames(): void
    {
        $resolver = $this->resolver();
        $declared = [];
        $split = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (null !== $this->outsideFamily($name, $route, $method)) {
                    continue;
                }
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                if (!$declaration instanceof ClubFunction && !$declaration instanceof ClubResource) {
                    continue;
                }
                $class = substr($this->declaringClass($route), \strlen('App\\'));
                if (isset($declared[$class]) && $declared[$class] !== $declaration->module->value) {
                    $split[] = \sprintf('%s : deux modules (%s, %s)', $class, $declared[$class], $declaration->module->value);
                }
                $declared[$class] = $declaration->module->value;
            }
        }
        ksort($declared);
        $expected = [];
        foreach (self::MODULE_OF as $module => $classes) {
            foreach ($classes as $class) {
                $expected[$class] = $module;
            }
        }
        ksort($expected);

        self::assertSame([], $split, "Une classe, un module :\n  - " . implode("\n  - ", $split));
        self::assertSame($expected, $declared, 'modules déclarés ≠ MODULE_OF (à gauche la table écrite à la main, à droite les déclarations)');
    }

    /**
     * Le module Matchs fermé (aucun planning de saison en vigueur) refuse EXACTEMENT les huit
     * fonctions de `REFUSED_WHEN_MODULE_CLOSED` (neuf routes) : la déclaration le dit, explicitement,
     * sur une fonction du module Matchs ; aucune autre fonction ne le dit.
     */
    public function testOnlyTheEightMatchFunctionsAreRefusedWhenTheModuleIsClosed(): void
    {
        $resolver = $this->resolver();
        $refused = [];
        $wrong = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (null !== $this->outsideFamily($name, $route, $method)) {
                    continue;
                }
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                if (true !== (null === $declaration ? null : (get_object_vars($declaration)['refusedWhenModuleClosed'] ?? null))) {
                    continue;
                }
                $key = $method . ' ' . $this->bare($route);
                $refused[] = $key;
                if (!$declaration instanceof ClubFunction || Module::MATCHES !== $declaration->module) {
                    $wrong[] = $key . ' : le refus module fermé se déclare sur une fonction du module Matchs';
                }
            }
        }
        $refused = array_values(array_unique($refused));
        sort($refused);
        $expected = self::REFUSED_WHEN_MODULE_CLOSED;
        sort($expected);

        self::assertSame([], $wrong, "Refus module fermé mal placés :\n  - " . implode("\n  - ", $wrong));
        self::assertSame($expected, $refused, 'fonctions refusées module fermé ≠ REFUSED_WHEN_MODULE_CLOSED (à gauche la table, à droite les déclarations)');
    }

    /**
     * Ce qui sort vers un tiers et ce qui modifie un compte se déclarent : les fonctions de club
     * `thirdParty` sont EXACTEMENT `THIRD_PARTY`, les fonctions de compte `protectedInDemo`
     * EXACTEMENT `PROTECTED_IN_DEMO` — les deux tables écrites à la main.
     */
    public function testThirdPartySendsAndDemoProtectedAccountGesturesAreExactlyTheHandTables(): void
    {
        $resolver = $this->resolver();
        $thirdParty = [];
        $protected = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (null !== $this->outsideFamily($name, $route, $method)) {
                    continue;
                }
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                $said = null === $declaration ? [] : get_object_vars($declaration);
                $key = $method . ' ' . $this->bare($route);
                if (true === ($said['thirdParty'] ?? null)) {
                    $thirdParty[] = $key;
                }
                if (true === ($said['protectedInDemo'] ?? null)) {
                    $protected[] = $key;
                }
            }
        }
        $thirdParty = array_values(array_unique($thirdParty));
        sort($thirdParty);
        $protected = array_values(array_unique($protected));
        sort($protected);
        $expectedThirdParty = array_keys(self::THIRD_PARTY);
        sort($expectedThirdParty);
        $expectedProtected = self::PROTECTED_IN_DEMO;
        sort($expectedProtected);

        self::assertSame($expectedThirdParty, $thirdParty, 'fonctions déclarées « envoi à un tiers » ≠ THIRD_PARTY');
        self::assertSame($expectedProtected, $protected, 'fonctions de compte protégées en démonstration ≠ PROTECTED_IN_DEMO');
    }

    /**
     * ABUS — en démonstration, rien ne part vers un tiers. Le club du balayage (payant, socle en
     * vigueur, un coach joignable par e-mail, une collecte de doléances ouverte, une invitation en
     * attente) passe en démonstration avec son gestionnaire ; chaque fonction de `THIRD_PARTY`, jouée
     * par ce gestionnaire aux ids réels et au corps valide, répond 403 avec son texte et ne fait
     * partir aucun message. Témoin positif, avant la bascule : la même invitation part (201, un mail
     * vu par le transport). Le texte se lit en `detail` ou en `error` (l'écran lit l'un ou l'autre).
     * Angle jumeau, cité : `features/le-club-de-demonstration-n-envoie-rien-a-un-tiers.feature`
     * (le webmail de test) — l'abus de démonstration est un point sensible.
     */
    public function testInDemoNoThirdPartyFunctionLetsAMessageLeave(): void
    {
        $this->seedPayingClubWithMember();
        $coach = $this->api($this->managerToken, 'GET', '/api/coaches/' . $this->ids['coach']);
        $this->api($this->managerToken, 'PUT', '/api/coaches/' . $this->ids['coach'], ['email' => 'coach-demo-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr'] + $coach);
        self::assertResponseIsSuccessful('décor : le coach reçoit une adresse');

        $this->ids['invitee'] = 'invite-demo-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr';
        $pending = $this->api($this->managerToken, 'POST', '/api/invitations', ['email' => 'attente-' . $this->ids['invitee'], 'role' => 'member']);
        self::assertResponseStatusCodeSame(201, 'témoin : un club réel invite');
        self::assertNotSame([], $this->messagesSentByLastRequest(), 'témoin : le transport voit le mail d\'invitation d\'un club réel');
        $this->ids['invitation'] = (string) $pending['id'];

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $club = $em->find(Club::class, $this->clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setIsDemo(true);
        $manager = $em->find(User::class, $this->managerUserId);
        self::assertInstanceOf(User::class, $manager);
        $manager->setIsDemo(true);
        $manager->setDemoActiveUntil(new DateTimeImmutable('+1 day'));
        $em->flush();
        self::getContainer()->get(TenantConnectionContext::class)->clear();

        $wrong = [];
        foreach (self::THIRD_PARTY as $key => [$path, $body, $message]) {
            [$method] = explode(' ', $key, 2);
            $this->resetClubBuckets();
            $this->resetBucket('limiter.invitation_send', $this->managerUserId);
            $served = $this->api($this->managerToken, $method, $this->fill($path), null === $body ? null : array_map(fn (mixed $v): mixed => \is_string($v) ? $this->fill($v) : $v, $body));
            $status = $this->client->getResponse()->getStatusCode();
            $text = $served['detail'] ?? $served['error'] ?? null;
            $sent = $this->messagesSentByLastRequest();
            if (403 !== $status || $message !== $text || [] !== $sent) {
                $wrong[] = \sprintf('%s → %d « %s », %d message(s) parti(s) (attendu 403 « %s », aucun)', $key, $status, \is_string($text) ? $text : '', \count($sent), $message);
            }
        }

        self::assertSame([], $wrong, "En démonstration, une fonction qui envoie à un tiers n'est pas refusée :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * CONTRÔLE STRUCTUREL de l'abus « en démonstration, rien ne part vers un tiers » : la liste
     * `THIRD_PARTY` est écrite à la main, donc un envoi nouveau pourrait l'éviter. Toute route d'un
     * contrôleur de club qui injecte de quoi envoyer (`MailerInterface`, `*Notifier`, `*Mailer`,
     * `*Mailing`) est soit dans `THIRD_PARTY` (refusée en démonstration), soit dans `MAIL_EXEMPTIONS`
     * avec sa raison ; une exemption qui ne désigne plus une telle route rougit aussi.
     */
    public function testEveryClubControllerThatCanSendIsDeclaredOrExempted(): void
    {
        $resolver = $this->resolver();
        $sending = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (null !== $this->outsideFamily($name, $route, $method)) {
                    continue;
                }
                if (!$resolver->for($this->requestFor($name, $route, $method)) instanceof ClubFunction) {
                    continue;
                }
                $class = $this->declaringClass($route);
                if (class_exists($class) && $this->canSend($class)) {
                    $sending[] = $method . ' ' . $this->bare($route);
                }
            }
        }
        $sending = array_values(array_unique($sending));
        sort($sending);

        $undeclared = array_values(array_diff($sending, array_keys(self::THIRD_PARTY), array_keys(self::MAIL_EXEMPTIONS)));
        $staleExemptions = array_values(array_diff(array_keys(self::MAIL_EXEMPTIONS), $sending));

        self::assertNotSame([], $sending, 'témoin : le balayage trouve des contrôleurs de club qui envoient');
        self::assertSame([], $undeclared, "Routes d'un contrôleur de club qui peut envoyer, ni dans THIRD_PARTY ni dans MAIL_EXEMPTIONS :\n  - " . implode("\n  - ", $undeclared));
        self::assertSame([], $staleExemptions, "Exemptions qui ne désignent plus une route d'un contrôleur qui envoie :\n  - " . implode("\n  - ", $staleExemptions));
    }

    /**
     * Une déclaration de méthode ne garde rien de celle de la classe : dans une classe qui déclare
     * `thirdParty` ou `refusedWhenModuleClosed` (sur elle-même ou sur une méthode), chaque
     * `#[ClubFunction]` de méthode est exactement une ligne de `SPLIT_DECLARATIONS`.
     */
    public function testNoMethodDeclarationSilentlyDropsADemoOrClosedModuleFlag(): void
    {
        $split = [];
        $finder = new Finder()->files()->in(\dirname(__DIR__, 2) . '/src/Controller')->name('*.php');
        foreach ($finder as $file) {
            $class = 'App\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $methods = [];
            $flagged = false;
            foreach ([$reflection, ...$reflection->getMethods()] as $target) {
                if ($target instanceof ReflectionMethod && $target->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($target->getAttributes(ClubFunction::class) as $attribute) {
                    $declaration = $attribute->newInstance();
                    $flagged = $flagged || $declaration->thirdParty || $declaration->refusedWhenModuleClosed;
                    if ($target instanceof ReflectionMethod) {
                        $methods[] = $class . '::' . $target->getName();
                    }
                }
            }
            if ($flagged) {
                array_push($split, ...$methods);
            }
        }
        sort($split);
        $expected = array_keys(self::SPLIT_DECLARATIONS);
        sort($expected);

        self::assertSame($expected, $split, 'déclarations de méthode dans une classe qui porte un drapeau de démonstration ou de module fermé ≠ SPLIT_DECLARATIONS (à gauche la table, à droite le code)');
    }

    public function testTheMemberMeetsEveryClubRouteAsItIsDeclared(): void
    {
        $this->seedPayingClubWithMember();
        $resolver = $this->resolver();
        $writes = $this->writeSweepRows();
        $wrong = [];
        $played = 0;
        $calls = [];
        foreach ($this->apiRoutes() as $name => $route) {
            if (isset(self::NOT_EXPOSED[$name])) {
                continue;
            }
            foreach ($this->methods($route) as $method) {
                if (null !== $this->outsideFamily($name, $route, $method)) {
                    continue;
                }
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                if ($declaration instanceof AccountFunction || null === $declaration) {
                    continue; // compte : hors du club ; non déclarée : rougit au classement
                }
                $key = $method . ' ' . $this->bare($route);
                if ('GET' === $method) {
                    $calls[] = [$key, $this->readPlayPath($key), null, $this->audienceOf($declaration, $method)];
                    continue;
                }
                if (!isset($writes[$key])) {
                    $wrong[] = $key . ' : écriture de club absente de MemberWriteSweepTest::ROUTES';
                    continue;
                }
                [$path, $body] = $writes[$key];
                $calls[] = [$key, $path, $body, $this->audienceOf($declaration, $method)];
            }
        }

        foreach ($calls as [$key, $path, $body, $audience]) {
            [$method] = explode(' ', $key, 2);
            if ('@get' === $body) {
                // PUT : le corps est la ressource telle que le gestionnaire la lit — valide par construction.
                $body = json_encode($this->api($this->managerToken, 'GET', $this->fill($path)), \JSON_THROW_ON_ERROR);
            }
            $this->resetClubBuckets();
            $this->asMember($method, $this->fill($path), null === $body ? null : $this->fill($body));
            ++$played;
            $status = $this->client->getResponse()->getStatusCode();
            $excerpt = mb_substr((string) $this->client->getResponse()->getContent(), 0, 300);
            if (Audience::MANAGER === $audience && 403 !== $status) {
                $wrong[] = \sprintf('%s déclarée « gestionnaire » → %d pour un Membre %s', $key, $status, $excerpt);
            } elseif (Audience::MEMBER === $audience && 403 === $status) {
                $wrong[] = \sprintf('%s déclarée « membre » → 403 pour un Membre %s', $key, $excerpt);
            } elseif ('GET' === $method && 404 === $status && !isset(self::READS_WITHOUT_ROW[$key])) {
                $wrong[] = \sprintf('%s → 404 : lecture jouée sur un id sans ligne, elle ne prouve rien (%s)', $key, $this->fill($path));
            }
        }

        // Témoin positif : un balayage qui ne jouerait presque rien serait vert pour rien.
        self::assertGreaterThan(200, $played, 'le balayage n\'a presque rien joué');
        self::assertSame([], $wrong, "Routes de club où le Membre ne rencontre pas la déclaration :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * Une fonction déclarée « compte » ne dépend d'aucun club : un compte sans aucune adhésion la
     * joue et obtient le statut écrit à la main (jamais le 404 « pas de club »). Le sens inverse
     * (déclarée « club » mais jouable sans club) n'est pas une faute.
     */
    public function testAnAccountFunctionAnswersAnAccountWithoutAnyClub(): void
    {
        $this->registerManager('acc');
        $resolver = $this->resolver();
        $declared = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (null === $this->outsideFamily($name, $route, $method) && $resolver->for($this->requestFor($name, $route, $method)) instanceof AccountFunction) {
                    $declared[] = $method . ' ' . $this->bare($route);
                }
            }
        }

        [$soloId, $soloToken] = $this->accountWithoutClub();
        [$invitedId, $invitedToken, $invitedEmail] = $this->accountWithoutClub();
        $this->ids['new_email'] = 'nouvelle' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr';
        $manager = self::getContainer()->get(ClubInvitationManager::class);
        \assert($manager instanceof ClubInvitationManager);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $this->ids['invitation_token'] = $manager->issue($this->clubId, $invitedEmail, ClubRole::MEMBER, $this->managerUserId);
        $em->flush();
        self::getContainer()->get(TenantConnectionContext::class)->clear();

        $wrong = [];
        foreach (array_diff($declared, array_keys(self::ACCOUNT_FUNCTIONS_WITHOUT_CLUB)) as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $this->asAccount($soloId, $soloToken, $method, $this->fill($path), '{}');
            $wrong[] = \sprintf('%s : déclarée « compte », absente de la liste des fonctions de compte — sans club elle répond %d %s', $key, $this->client->getResponse()->getStatusCode(), mb_substr((string) $this->client->getResponse()->getContent(), 0, 200));
        }
        foreach (array_diff(array_keys(self::ACCOUNT_FUNCTIONS_WITHOUT_CLUB), $declared) as $key) {
            $wrong[] = $key . ' : ligne de la liste qui n\'est pas une route déclarée « compte »';
        }
        foreach (self::ACCOUNT_FUNCTIONS_WITHOUT_CLUB as $key => [$path, $body, $expected]) {
            if (!\in_array($key, $declared, true)) {
                continue;
            }
            [$method] = explode(' ', $key, 2);
            $invited = str_contains($path, '{invitation_token}');
            $this->asAccount($invited ? $invitedId : $soloId, $invited ? $invitedToken : $soloToken, $method, $this->fill($path), null === $body ? null : $this->fill($body));
            $status = $this->client->getResponse()->getStatusCode();
            if ($expected !== $status) {
                $wrong[] = \sprintf('%s → %d sans club, %d attendu %s', $key, $status, $expected, mb_substr((string) $this->client->getResponse()->getContent(), 0, 200));
            }
        }

        self::assertSame([], $wrong, "Fonctions « compte » qui dépendent d'un club :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * Une cible (classe ou méthode) ne porte qu'UN public : jamais une déclaration de club
     * (`ClubFunction`/`ClubResource`) ET `AccountFunction` ensemble — le lecteur prendrait la
     * première en silence. Une méthode qui surcharge sa classe reste permise.
     */
    public function testNoTargetCarriesBothAClubAndAnAccountDeclaration(): void
    {
        $both = [];
        $seen = 0;
        $finder = new Finder()->files()->in(\dirname(__DIR__, 2) . '/src')->name('*.php');
        foreach ($finder as $file) {
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            foreach ([$reflection, ...$reflection->getMethods()] as $target) {
                if ($target instanceof ReflectionMethod && $target->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $club = \count($target->getAttributes(ClubFunction::class)) + \count($target->getAttributes(ClubResource::class));
                $account = \count($target->getAttributes(AccountFunction::class));
                $seen += $club + $account;
                if ($club + $account > 1) {
                    $both[] = $target instanceof ReflectionMethod ? $class . '::' . $target->getName() : $class;
                }
            }
        }

        self::assertGreaterThan(50, $seen, 'témoin : le balayage lit des déclarations');
        self::assertSame([], $both, "Cibles à deux publics :\n  - " . implode("\n  - ", $both));
    }

    /**
     * Aucune route de club ni de compte ne se cache sous un motif public : une route qui tombe sous
     * `PublicApiRouteCatalog` (miroir des `PUBLIC_ACCESS` de `security.yaml`, motifs larges
     * `^/api/password`, `^/api/register`, `^/api/docs`, `^/api/health`…) est sautée par le contrôle
     * central ET ouverte à un anonyme par le pare-feu — plus aucune garde à la main ne la rattraperait.
     * Mesuré sur le chemin de chaque route du routeur, méthode par méthode (comme le pare-feu) :
     * (1) aucune route publique ne résout de déclaration `ClubFunction`/`ClubResource`/`AccountFunction` ;
     * (2) les routes publiques sont EXACTEMENT `PUBLIC_ROUTES`, écrite à la main — une route nouvelle
     * sous un préfixe public, déclarée ou non, rougit et se classe consciemment.
     * Le cas (1) recoupe la ligne « famille ET déclarée » de
     * `testEveryApiRouteFallsInExactlyOneFamily` ; il est nommé ici pour la revue de sécurité de la
     * PR 3b, le cas (2) n'existe que là.
     */
    public function testNoClubOrAccountRouteHidesUnderAPublicPattern(): void
    {
        $resolver = $this->resolver();
        $declared = [];
        $public = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                if (!PublicApiRouteCatalog::matches($this->samplePath($route), $method)) {
                    continue;
                }
                $key = $method . ' ' . $this->bare($route);
                $public[] = $key;
                $declaration = $resolver->for($this->requestFor($name, $route, $method));
                if (null !== $declaration) {
                    $declared[] = \sprintf('%s (%s) : sous un motif public, déclarée %s', $key, $name, $declaration::class);
                }
            }
        }
        sort($public);
        $expected = array_keys(self::PUBLIC_ROUTES);
        sort($expected);

        self::assertSame([], $declared, "Routes de club ou de compte sous un motif public (sautées par le contrôle central, ouvertes à un anonyme) :\n  - " . implode("\n  - ", $declared));
        self::assertSame($expected, $public, 'les routes sous un motif public ont changé : à gauche PUBLIC_ROUTES, à droite le routeur');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Un compte vérifié, sans AUCUNE adhésion, et son jeton.
     *
     * @return array{0: string, 1: string, 2: string} [id, jeton, adresse]
     */
    private function accountWithoutClub(): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $user = new User;
        $user->setEmail('sansclub' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $user->setFirstName('Lou');
        $user->setLastName('Sansclub');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $em->persist($user);
        $em->flush();
        $token = $container->get(JWTTokenManagerInterface::class)->create($user);

        return [$user->getId(), $token, $user->getEmail()];
    }

    private function asAccount(string $userId, string $token, string $method, string $url, ?string $body): void
    {
        $this->resetBucket('limiter.api', 'api-user-' . $userId);
        $this->resetBucket('limiter.feedback', $userId);
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * Le décor du balayage, puis le club rendu PAYANT (offre « grand-club » réglée pour la saison
     * courante — pointage SQL autorisé par la charte : l'offre payante ne s'achète pas par l'API),
     * un Membre actif et les ids que les tables lisent. Témoins : pas démo, pas bridé, socle en
     * vigueur (le gestionnaire lit une route gardée par le socle).
     */
    private function seedPayingClubWithMember(): void
    {
        $token = $this->seedSweepClub('ad');
        $this->ids['competition'] = $this->create($token, '/api/competitions', ['teamId' => $this->ids['team'], 'name' => 'Compétition AD', 'competitionType' => 'CHAMPIONSHIP']);
        foreach (['priority_tier' => '/api/priority_tiers', 'subscription_plan' => '/api/subscription_plans', 'team_tag' => '/api/team_tags', 'team_tag_assignment' => '/api/team_tag_assignments'] as $placeholder => $collection) {
            $first = $this->api($token, 'GET', $collection)['member'][0]['id'] ?? null;
            if (null !== $first) {
                $this->ids[$placeholder] = (string) $first;
            }
        }
        $this->ids['travel_rule'] = 'travelTime';

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $club = $em->find(Club::class, $this->clubId);
        self::assertInstanceOf(Club::class, $club);
        $plan = $em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'grand-club']);
        self::assertInstanceOf(SubscriptionPlan::class, $plan);
        $season = $em->find(Season::class, $this->ids['season']);
        self::assertInstanceOf(Season::class, $season);
        $club->setPlanId($plan->getId());
        $club->setPaidSeasonYear((int) $season->getStartDate()->format('Y') + 1);
        $em->flush();

        $entitlements = self::getContainer()->get(PlanEntitlements::class);
        \assert($entitlements instanceof PlanEntitlements);
        self::assertFalse($club->isDemo(), 'témoin : le club du balayage n\'est pas un club démo');
        self::assertFalse($entitlements->outputBudget($club, $season)['restricted'], 'témoin : le club du balayage est payant, jamais bridé');

        $this->addActiveMember();
        $this->ids['member_user'] = $this->memberUserId;
        self::getContainer()->get(TenantConnectionContext::class)->clear();

        $this->api($token, 'GET', '/api/fixtures/league-validation');
        self::assertResponseStatusCodeSame(200, 'témoin : socle en vigueur — le gestionnaire lit une route gardée par le socle');
    }

    private function resetClubBuckets(): void
    {
        foreach (['limiter.schedule_generation', 'limiter.schedule_pdf_export', 'limiter.match_placement'] as $limiter) {
            $this->resetBucket($limiter, 'club-' . $this->clubId);
        }
    }

    /** Le lecteur du conteneur s'il y est (un service sans consommateur en est retiré), sinon construit. */
    private function resolver(): AccessDeclarationResolver
    {
        $container = self::getContainer();
        if (!$container->has(AccessDeclarationResolver::class)) {
            return new AccessDeclarationResolver;
        }
        $resolver = $container->get(AccessDeclarationResolver::class);
        \assert($resolver instanceof AccessDeclarationResolver);

        return $resolver;
    }

    /** Deux déclarations sont la même : même attribut, mêmes valeurs (les cas d'enum sont uniques). */
    private function sameDeclaration(ClubFunction|ClubResource|AccountFunction|null $a, ClubFunction|ClubResource|AccountFunction $b): bool
    {
        return null !== $a && $a::class === $b::class && get_object_vars($a) === get_object_vars($b);
    }

    /** @return array<string, Route> les routes `^/api/` du routeur, par nom */
    private function apiRoutes(): array
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $out = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/')) {
                $out[$name] = $route;
            }
        }

        return $out;
    }

    /** @return list<string> les méthodes jouables d'une route (HEAD suit GET, jamais classée seule) */
    private function methods(Route $route): array
    {
        $methods = [] === $route->getMethods() ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] : $route->getMethods();

        return array_values(array_filter($methods, static fn (string $m): bool => 'HEAD' !== $m));
    }

    private function bare(Route $route): string
    {
        return str_replace('.{_format}', '', $route->getPath());
    }

    /** Familles 1 à 4, dans l'ordre qui tranche leurs deux recouvrements ; null = compte ou club. */
    private function outsideFamily(string $name, Route $route, string $method): ?string
    {
        $path = $this->bare($route);
        $resource = (string) $route->getDefault('_api_resource_class');

        return match (true) {
            str_starts_with($path, '/api/admin/') => 'console',
            str_starts_with($path, '/api/dev/') => 'dev',
            \in_array($name, self::FRAMEWORK_ROUTES_WITHOUT_RESOURCE, true), str_starts_with($resource, 'ApiPlatform\\') => 'framework',
            PublicApiRouteCatalog::matches($this->samplePath($route), $method), isset(self::PUBLIC_OUTSIDE_CATALOG[$name]) => 'public',
            default => null,
        };
    }

    private function samplePath(Route $route): string
    {
        return (string) preg_replace('/\{[^}]+\}/', 'x', $this->bare($route));
    }

    /** La requête telle que le routeur la laisse : défauts de la route, nom, méthode. */
    private function requestFor(string $name, Route $route, string $method): Request
    {
        $request = Request::create($this->samplePath($route), $method);
        $request->attributes->add($route->getDefaults());
        $request->attributes->set('_route', $name);

        return $request;
    }

    private function audienceOf(ClubFunction|ClubResource|AccountFunction|null $declaration, string $method): ?Audience
    {
        return match (true) {
            $declaration instanceof ClubFunction => $declaration->audience,
            $declaration instanceof ClubResource => 'GET' === $method ? $declaration->read : $declaration->write,
            default => null,
        };
    }

    /**
     * La déclaration d'un contrôleur maison, lue par réflexion : celle de la méthode l'emporte sur
     * celle de la classe.
     */
    private function controllerDeclaration(string $controller): ClubFunction|AccountFunction|null
    {
        $class = $this->controllerClass($controller);
        if (!class_exists($class)) {
            return null;
        }
        $method = str_contains($controller, '::') ? explode('::', $controller, 2)[1] : '__invoke';
        $reflection = new ReflectionClass($class);

        return ($reflection->hasMethod($method) ? $this->declarationOn($reflection->getMethod($method)) : null)
            ?? $this->declarationOn($reflection);
    }

    /** @param ReflectionClass<object>|ReflectionMethod $target */
    private function declarationOn(ReflectionClass|ReflectionMethod $target): ClubFunction|AccountFunction|null
    {
        $club = $target->getAttributes(ClubFunction::class);
        if ([] !== $club) {
            return $club[0]->newInstance();
        }
        $account = $target->getAttributes(AccountFunction::class);

        return [] === $account ? null : $account[0]->newInstance();
    }

    /** Le constructeur du contrôleur injecte-t-il de quoi envoyer un message ? */
    private function canSend(string $class): bool
    {
        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : '';
            if (MailerInterface::class === $name || 1 === preg_match('/(Notifier|Mailer|Mailing)$/', $name)) {
                return true;
            }
        }

        return false;
    }

    /** La classe dont la déclaration vaut pour la route : la ressource d'API Platform, ou le contrôleur maison. */
    private function declaringClass(Route $route): string
    {
        $controller = (string) $route->getDefault('_controller');
        $resource = (string) $route->getDefault('_api_resource_class');
        if (str_starts_with($resource, 'App\\') && \in_array($controller, [self::MAIN_CONTROLLER, self::NOT_EXPOSED_CONTROLLER], true)) {
            return $resource;
        }

        return $this->controllerClass($controller);
    }

    private function controllerClass(string $controller): string
    {
        return explode('::', $controller, 2)[0];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return T|null
     */
    private function classAttribute(string $class, string $attribute): ?object
    {
        if (!class_exists($class)) {
            return null;
        }
        $found = new ReflectionClass($class)->getAttributes($attribute);

        return [] === $found ? null : $found[0]->newInstance();
    }

    private function readPlayPath(string $key): string
    {
        if (isset(self::READ_PLAY[$key])) {
            return self::READ_PLAY[$key];
        }
        $path = explode(' ', $key, 2)[1];
        $segment = explode('/', $path)[2] ?? '';
        $placeholder = self::SEGMENT_IDS[$segment] ?? 'dummy';

        return (string) preg_replace('/\{\w+\}/', '{' . $placeholder . '}', $path);
    }

    /**
     * La table de `MemberWriteSweepTest` — l'oracle des écritures, lu tel quel.
     *
     * @return array<string, array{0: string, 1: string|null, 2: int|string}>
     */
    private function writeSweepRows(): array
    {
        $rows = new ReflectionClassConstant(MemberWriteSweepTest::class, 'ROUTES')->getValue();
        \assert(\is_array($rows));

        /* @var array<string, array{0: string, 1: string|null, 2: int|string}> $rows */
        return $rows;
    }

    /** @return array<string, array{0: string, 1: Route}> `MÉTHODE chemin` => [nom, route] */
    private function routesByKey(): array
    {
        $out = [];
        foreach ($this->apiRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                $out[$method . ' ' . $this->bare($route)] ??= [$name, $route];
            }
        }

        return $out;
    }

    /** @return list<string> les lignes des tables écrites à la main qui ne désignent plus aucune route */
    private function deadRows(): array
    {
        $routes = $this->apiRoutes();
        $keys = $this->routesByKey();
        $dead = [];
        foreach ([...array_keys(self::MANAGER_ONLY_READS), ...array_keys(self::READ_PLAY), ...array_keys(self::READS_WITHOUT_ROW), ...self::REFUSED_WHEN_MODULE_CLOSED, ...array_keys(self::THIRD_PARTY), ...self::PROTECTED_IN_DEMO] as $key) {
            if (!isset($keys[$key])) {
                $dead[] = $key;
            }
        }
        foreach ([...array_keys(self::CUSTOM_CONTROLLER_OPERATIONS), ...array_keys(self::NOT_EXPOSED), ...array_keys(self::PUBLIC_OUTSIDE_CATALOG), ...self::FRAMEWORK_FAMILY] as $name) {
            if (!isset($routes[$name])) {
                $dead[] = $name;
            }
        }

        return array_values(array_unique($dead));
    }
}
