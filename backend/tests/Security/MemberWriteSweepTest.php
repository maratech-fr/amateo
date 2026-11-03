<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\LockLevel;
use App\Tests\ChoosesPlanVersionTrait;
use App\Entity\User;
use App\Service\TenantConnectionContext;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\RouterInterface;

/**
 * NR BLOQUANT — axe auth & memberships : « un Membre lit tout, ne modifie RIEN ».
 *
 * Balayage du ROUTEUR, dans les deux sens, contre la table nominative `ROUTES` :
 *  - toute route d'écriture `^/api/` (POST/PUT/PATCH/DELETE) du routeur doit figurer dans la table
 *    (une route nouvelle sans décision rougit — c'est le but : personne n'ouvre une écriture au
 *    Membre sans le dire) ;
 *  - toute ligne de la table doit exister au routeur (une ligne morte rougit), SAUF les quatre
 *    routes de la demande « devenir gestionnaire », inscrites dès maintenant et tolérées absentes
 *    jusqu'à leur livraison (`ABSENT_UNTIL_MANAGER_REQUEST`) ;
 *  - chaque ligne `403` est JOUÉE par un Membre actif, aux ids RÉELS d'un club semé par l'API
 *    gestionnaire, corps valide : la réponse doit être 403 EXACT. Un 404/409/422 veut dire que la
 *    garde tire APRÈS une résolution ou une validation : le Membre apprend quelque chose, la garde
 *    se déplace (jamais la table) ;
 *  - les lignes `open:` (écritures personnelles ou gratuites, chacune avec SON test dédié) et
 *    `skip:` (hors club : auth, pages publiques à token, console) sont listées, jamais jouées.
 *
 * Après le balayage : empreinte des tables du club inchangée, aucun mail, aucun message en file
 * mémoire, seaux `api` (Membre) et `schedule_generation` (club) remis à zéro AVANT chaque appel par
 * `reset()` ciblé — jamais FLUSHALL.
 *
 * Falsification : retirer `assertManager()` d'un contrôleur d'écriture (ex. `ReorderTeamsController`)
 * rougit le balayage sur sa ligne ; retirer une ligne de la table rougit le sens routeur→table.
 */
#[Group('phase1')]
#[Group('integration')]
final class MemberWriteSweepTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private const string PASSWORD = 'Password123!';

    private const string DUMMY = '11111111-1111-4111-8111-111111111111';

    /**
     * Les quatre routes de la demande « devenir gestionnaire » (PR 3 du chantier) : leur décision
     * est déjà prise, elles sont tolérées ABSENTES du routeur jusqu'à leur livraison. Une fois
     * livrées, cette liste se vide et la table les garde comme les autres.
     */
    private const array ABSENT_UNTIL_MANAGER_REQUEST = [
        'POST /api/memberships/me/manager-request',
        'DELETE /api/memberships/me/manager-request',
        'POST /api/memberships/{id}/manager-request/accept',
        'POST /api/memberships/{id}/manager-request/refuse',
    ];

    /**
     * Préfixes hors club, jamais joués : console superadmin (firewall séparé, un JWT club ne la
     * franchit pas — `AdminRequestBoundaryTest`) et raccourcis de dev (absents en prod).
     */
    private const array SKIP_PREFIXES = ['/api/admin/', '/api/dev/'];

    /**
     * La table nominative : clé = `MÉTHODE chemin-du-routeur` (sans `.{_format}`), valeur =
     * [chemin à jouer (placeholders `{club}`, `{schedule}`… remplacés par les ids réels du décor),
     * corps JSON, décision]. Décision : `403`, `open:<raison — test dédié>` ou `skip:<raison>`.
     *
     * @var array<string, array{0: string, 1: string|null, 2: int|string}>
     */
    private const array ROUTES = [
        // ── Ouvertes au Membre : écritures PERSONNELLES ou lectures en POST, chacune son test.
        'PATCH /api/me' => ['/api/me', null, 'open:son propre profil — MemberRoleTest::testMemberKeepsSelfServiceProfileAndErasure'],
        'POST /api/me/password' => ['/api/me/password', null, 'open:son propre mot de passe — ProfileEndpointsTest'],
        'POST /api/me/email' => ['/api/me/email', null, 'open:sa propre adresse — UserSelfOnlyTest'],
        'DELETE /api/me/email' => ['/api/me/email', null, 'open:annuler son changement d\'adresse — UserSelfOnlyTest'],
        'POST /api/feedback' => ['/api/feedback', null, 'open:signaler un problème — FeedbackTest'],
        'POST /api/matches/module-visit' => ['/api/matches/module-visit', null, 'open:sa propre visite du module — MatchVisitDeltaParityTest'],
        'POST /api/release-notes/seen' => ['/api/release-notes/seen', null, 'open:ses nouveautés lues — ReleaseNotesTest'],
        'POST /api/fixtures/export-pdf' => ['/api/fixtures/export-pdf', null, 'open:export ouvert, compté sur le club — ExportAuthenticationTest + MemberReadsAreSideEffectFreeTest'],
        'POST /api/schedules/{id}/export-pdf' => ['/api/schedules/{schedule}/export-pdf', null, 'open:export ouvert, compté sur le club — ExportAuthenticationTest + MemberReadsAreSideEffectFreeTest'],
        'POST /api/schedules/{id}/export-xlsx' => ['/api/schedules/{schedule}/export-xlsx', null, 'open:export ouvert — ExportAuthenticationTest + MemberReadsAreSideEffectFreeTest'],
        'POST /api/constraints/validate' => ['/api/constraints/validate', null, 'open:lecture pure en POST — MemberReadsAreSideEffectFreeTest'],
        'POST /api/memberships/me/manager-request' => ['/api/memberships/me/manager-request', null, 'open:un Membre demande à devenir gestionnaire — ManagerRoleRequestTest'],
        'DELETE /api/memberships/me/manager-request' => ['/api/memberships/me/manager-request', null, 'open:un Membre annule sa demande — ManagerRoleRequestTest'],

        // ── Hors club : authentification, pages publiques à token (le token EST l'identité).
        'DELETE /api/me' => ['/api/me', null, 'skip:effacer son compte (RGPD) — MemberRoleTest, AccountErasureTest'],
        'POST /api/login' => ['/api/login', null, 'skip:authentification'],
        'POST /api/logout' => ['/api/logout', null, 'skip:authentification'],
        'POST /api/register' => ['/api/register', null, 'skip:inscription'],
        'POST /api/register/verify' => ['/api/register/verify', null, 'skip:inscription'],
        'POST /api/password/forgot' => ['/api/password/forgot', null, 'skip:mot de passe oublié'],
        'POST /api/password/reset' => ['/api/password/reset', null, 'skip:mot de passe oublié'],
        'POST /api/me/email/confirm' => ['/api/me/email/confirm', null, 'skip:lien de confirmation d\'adresse'],
        'POST /api/invitations/public/{token}/accept' => ['/api/invitations/public/{token}/accept', null, 'skip:page publique à token — ClubInvitationTest'],
        'POST /api/invitations/{token}/accept' => ['/api/invitations/{token}/accept', null, 'skip:invitation acceptée par son destinataire connecté (jeton + adresse) — ClubInvitationTest'],
        'POST /api/club-approvals/{token}' => ['/api/club-approvals/{token}', null, 'skip:page publique à token — ClubApprovalFlowTest'],
        'POST /api/coach-wishes/public/{token}' => ['/api/coach-wishes/public/{token}', null, 'skip:page publique à token — CoachWishPublicTest'],

        // ── Gestion des adhésions et invitations.
        'POST /api/memberships/{id}/approve' => ['/api/memberships/{membership}/approve', '{"role":"member"}', 403],
        'POST /api/memberships/{id}/deactivate' => ['/api/memberships/{membership}/deactivate', '{}', 403],
        'POST /api/memberships/{id}/reactivate' => ['/api/memberships/{membership}/reactivate', '{}', 403],
        'POST /api/memberships/{id}/reject' => ['/api/memberships/{membership}/reject', '{}', 403],
        'POST /api/memberships/{id}/role' => ['/api/memberships/{membership}/role', '{"role":"admin"}', 403],
        'POST /api/memberships/{id}/manager-request/accept' => ['/api/memberships/{membership}/manager-request/accept', '{}', 403],
        'POST /api/memberships/{id}/manager-request/refuse' => ['/api/memberships/{membership}/manager-request/refuse', '{}', 403],
        'POST /api/invitations' => ['/api/invitations', '{"email":"invite-sweep@test.fr","role":"admin"}', 403],
        'DELETE /api/invitations/{id}' => ['/api/invitations/{invitation}', null, 403],
        'POST /api/invitations/{id}/resend' => ['/api/invitations/{invitation}/resend', '{}', 403],

        // ── Club : identité, apparence, horloge, imports.
        'PUT /api/clubs/{id}' => ['/api/clubs/{club}', '@get', 403],
        'PATCH /api/club/appearance' => ['/api/club/appearance', '{"accentColor":"#123456"}', 403],
        'PATCH /api/club/short-name' => ['/api/club/short-name', '{"shortName":"MBR"}', 403],
        'PATCH /api/club/siege' => ['/api/club/siege', '{"venueId":"{venue}"}', 403],
        'POST /api/club/logo' => ['/api/club/logo', null, 403],
        'DELETE /api/club/logo' => ['/api/club/logo', null, 403],
        'POST /api/club/clock' => ['/api/club/clock', '{"today":null}', 403],
        'POST /api/club/ffbb-import' => ['/api/club/ffbb-import', '{}', 403],
        'POST /api/clubs/{id}/import-teams' => ['/api/clubs/{club}/import-teams', null, 403],
        'POST /api/clubs/{id}/import-teams/analyze' => ['/api/clubs/{club}/import-teams/analyze', null, 403],
        'POST /api/clubs/{id}/parc-import' => ['/api/clubs/{club}/parc-import', null, 403],
        'POST /api/clubs/{id}/parc-import/analyze' => ['/api/clubs/{club}/parc-import/analyze', null, 403],
        'DELETE /api/reset-season' => ['/api/reset-season', null, 403],
        'POST /api/seasons' => ['/api/seasons', '{"name":"Saison Membre","startDate":"2030-07-15","endDate":"2031-07-14"}', 403],
        'POST /api/seasons/{id}/transition' => ['/api/seasons/{season}/transition', '{}', 403],
        'POST /api/sport_categories' => ['/api/sport_categories', '{"name":"Catégorie Membre","sportId":"{sport}"}', 403],
        'PUT /api/sport_categories/{id}' => ['/api/sport_categories/{category}', '@get', 403],

        // ── Équipes, coachs.
        'POST /api/teams' => ['/api/teams', '{"name":"Membre","sportCategoryId":"{category}","priorityTierId":1}', 403],
        'PUT /api/teams/{id}' => ['/api/teams/{team}', '{"name":"Membre","sportCategoryId":"{category}","priorityTierId":1}', 403],
        'DELETE /api/teams/{id}' => ['/api/teams/{team}', null, 403],
        'POST /api/teams/reorder' => ['/api/teams/reorder', '{"teamIds":["{team2}","{team}"]}', 403],
        'POST /api/team_links' => ['/api/team_links', '{"teamAId":"{team}","teamBId":"{team3}","linkType":"NOT_SIMULTANEOUS"}', 403],
        'PUT /api/team_links/{id}' => ['/api/team_links/{team_link}', '@get', 403],
        'DELETE /api/team_links/{id}' => ['/api/team_links/{team_link}', null, 403],
        'POST /api/team_match_habits' => ['/api/team_match_habits', '{"teamId":"{team2}","dayOfWeek":6,"kickoffTime":"15:00"}', 403],
        'PUT /api/team_match_habits/{id}' => ['/api/team_match_habits/{team_match_habit}', '@get', 403],
        'DELETE /api/team_match_habits/{id}' => ['/api/team_match_habits/{team_match_habit}', null, 403],
        'POST /api/team_period_overrides' => ['/api/team_period_overrides', '{"schedulePlanId":"{period_plan}","teamId":"{team2}"}', 403],
        'PUT /api/team_period_overrides/{id}' => ['/api/team_period_overrides/{team_period_override}', '@get', 403],
        'DELETE /api/team_period_overrides/{id}' => ['/api/team_period_overrides/{team_period_override}', null, 403],
        'POST /api/coaches' => ['/api/coaches', '{"firstName":"Membre","isEmployee":true}', 403],
        'PUT /api/coaches/{id}' => ['/api/coaches/{coach}', '{"firstName":"Membre","isEmployee":true}', 403],
        'DELETE /api/coaches/{id}' => ['/api/coaches/{coach}', null, 403],
        'POST /api/team_coaches' => ['/api/team_coaches', '{"teamId":"{team}","coachId":"{coach}"}', 403],
        'DELETE /api/team_coaches/{id}' => ['/api/team_coaches/{team_coach}', null, 403],
        'POST /api/coach_player_memberships' => ['/api/coach_player_memberships', '{"coachId":"{coach}","teamId":"{team2}"}', 403],
        'DELETE /api/coach_player_memberships/{id}' => ['/api/coach_player_memberships/{coach_player_membership}', null, 403],

        // ── Gymnases, créneaux, réservations, trajets.
        'POST /api/venues' => ['/api/venues', '{"name":"Gym Membre","source":"manual"}', 403],
        'PUT /api/venues/{id}' => ['/api/venues/{venue}', '{"name":"Gym Membre","source":"manual"}', 403],
        'DELETE /api/venues/{id}' => ['/api/venues/{venue}', null, 403],
        'POST /api/venues/{id}/external-labels' => ['/api/venues/{venue}/external-labels', '{"label":"Salle Membre"}', 403],
        'DELETE /api/venues/{id}/external-labels/{label}' => ['/api/venues/{venue}/external-labels/salle-membre', null, 403],
        'POST /api/venue_training_slots' => ['/api/venue_training_slots', '{"venueId":"{venue}","dayOfWeek":2,"startTime":"18:00","durationMinutes":90,"capacity":1}', 403],
        'PUT /api/venue_training_slots/{id}' => ['/api/venue_training_slots/{slot}', '{"venueId":"{venue}","dayOfWeek":1,"startTime":"19:00","durationMinutes":90,"capacity":1}', 403],
        'DELETE /api/venue_training_slots/{id}' => ['/api/venue_training_slots/{slot}', null, 403],
        'POST /api/venue_unavailabilities' => ['/api/venue_unavailabilities', '{"venueId":"{venue}","startDate":"2030-01-10","endDate":"2030-01-11"}', 403],
        'DELETE /api/venue_unavailabilities/{id}' => ['/api/venue_unavailabilities/{venue_unavailability}', null, 403],
        'POST /api/venue_match_windows' => ['/api/venue_match_windows', '{"venueId":"{venue}","dayOfWeek":6,"startTime":"14:00","endTime":"18:00"}', 403],
        'DELETE /api/venue_match_windows/{id}' => ['/api/venue_match_windows/{venue_match_window}', null, 403],
        'POST /api/venue_period_overrides' => ['/api/venue_period_overrides', '{"schedulePlanId":"{period_plan}","venueId":"{venue2}","mode":"BLANK"}', 403],
        'PUT /api/venue_period_overrides/{id}' => ['/api/venue_period_overrides/{venue_period_override}', '@get', 403],
        'DELETE /api/venue_period_overrides/{id}' => ['/api/venue_period_overrides/{venue_period_override}', null, 403],
        'POST /api/venue_period_overrides/clear-grid' => ['/api/venue_period_overrides/clear-grid', '{"schedulePlanId":"{period_plan}","venueId":"{venue}"}', 403],
        'POST /api/venue_period_overrides/reset-grid' => ['/api/venue_period_overrides/reset-grid', '{"schedulePlanId":"{period_plan}","venueId":"{venue}"}', 403],
        'POST /api/reservations' => ['/api/reservations', '{"teamId":"{team2}","venueId":"{venue}","dayOfWeek":3,"startTime":"18:00","durationMinutes":90}', 403],
        'PATCH /api/reservations/{id}' => ['/api/reservations/{reservation}', '{"coachLateToleranceMinutes":15}', 403],
        'DELETE /api/reservations/{id}' => ['/api/reservations/{reservation}', null, 403],
        'POST /api/reservations/group' => ['/api/reservations/group', '{}', 403],
        'POST /api/venue_travel_times' => ['/api/venue_travel_times', '{"venueAId":"{venue2}","venueBId":"{venue3}","drivingMinutes":15}', 403],
        'PUT /api/venue_travel_times/{id}' => ['/api/venue_travel_times/{venue_travel_time}', '@get', 403],
        'POST /api/venue-travel-times/autofill' => ['/api/venue-travel-times/autofill', '{}', 403],
        'PUT /api/venue_travel_rule_settings/{ruleKey}' => ['/api/venue_travel_rule_settings/travelTime', '{"intensity":"MANDATORY"}', 403],

        // ── Contraintes, règles, calendrier.
        'POST /api/constraints' => ['/api/constraints', '{"name":"Membre","scope":"CLUB","family":"DAY","ruleType":"HARD","config":{"forbiddenDays":[5]},"isActive":true,"sortOrder":2}', 403],
        'PUT /api/constraints/{id}' => ['/api/constraints/{constraint}', '{"name":"Membre","scope":"CLUB","family":"DAY","ruleType":"HARD","config":{"forbiddenDays":[5]},"isActive":true,"sortOrder":1}', 403],
        'DELETE /api/constraints/{id}' => ['/api/constraints/{constraint}', null, 403],
        'POST /api/constraint_period_overrides' => ['/api/constraint_period_overrides', '{"schedulePlanId":"{holiday_plan}","constraintId":"{constraint}","isActive":false}', 403],
        'PUT /api/constraint_period_overrides/{id}' => ['/api/constraint_period_overrides/{constraint_period_override}', '@get', 403],
        'DELETE /api/constraint_period_overrides/{id}' => ['/api/constraint_period_overrides/{constraint_period_override}', null, 403],
        'PUT /api/implicit_rule_settings/{ruleKey}' => ['/api/implicit_rule_settings/{rule_key}', '{"intensity":"OFF"}', 403],
        'DELETE /api/implicit_rule_settings/{ruleKey}' => ['/api/implicit_rule_settings/{rule_key}', null, 403],
        'POST /api/shared_training_blocks' => ['/api/shared_training_blocks', '{"teamIds":["{team2}","{team3}"],"commonSessions":1,"schedulePlanId":null,"label":"Membre"}', 403],
        'PUT /api/shared_training_blocks/{id}' => ['/api/shared_training_blocks/{shared_training_block}', '@get', 403],
        'DELETE /api/shared_training_blocks/{id}' => ['/api/shared_training_blocks/{shared_training_block}', null, 403],
        'POST /api/calendar_entries' => ['/api/calendar_entries', '{"kind":"event","title":"Membre","startDate":"2027-01-10","endDate":"2027-01-10"}', 403],
        'PUT /api/calendar_entries/{id}' => ['/api/calendar_entries/{calendar_entry}', '{"kind":"event","title":"Membre","startDate":"2027-01-10","endDate":"2027-01-10"}', 403],
        'DELETE /api/calendar_entries/{id}' => ['/api/calendar_entries/{calendar_entry}', null, 403],
        'POST /api/calendar_entries/{id}/redate-preview' => ['/api/calendar_entries/{calendar_entry}/redate-preview', '{}', 403],
        'POST /api/club_league_windows' => ['/api/club_league_windows', '{"category":"Seniors","level":"REGIONAL","gender":null,"dayOfWeek":7,"kickoffMin":"10:00","kickoffMax":"12:00"}', 403],
        'PUT /api/club_league_windows/{id}' => ['/api/club_league_windows/{club_league_window}', '@get', 403],
        'DELETE /api/club_league_windows/{id}' => ['/api/club_league_windows/{club_league_window}', null, 403],
        'POST /api/league-window-suggestions/apply' => ['/api/league-window-suggestions/apply', '{"combinations":[]}', 403],

        // ── Planning : versions, génération, retouches.
        'POST /api/schedules' => ['/api/schedules', '{"name":"Membre","status":"DRAFT"}', 403],
        'DELETE /api/schedules/{id}' => ['/api/schedules/{schedule}', null, 403],
        'POST /api/schedules/{id}/generate' => ['/api/schedules/{schedule}/generate', null, 403],
        'POST /api/schedules/{id}/regenerate' => ['/api/schedules/{schedule}/regenerate', null, 403],
        'POST /api/schedules/{id}/regenerate-from' => ['/api/schedules/{schedule}/regenerate-from', '{}', 403],
        'POST /api/schedules/{id}/fill' => ['/api/schedules/{schedule}/fill', null, 403],
        'POST /api/schedules/{id}/validate' => ['/api/schedules/{schedule}/validate', null, 403],
        'POST /api/schedules/{id}/reopen' => ['/api/schedules/{schedule}/reopen', null, 403],
        'POST /api/schedules/{id}/load-locks' => ['/api/schedules/{schedule}/load-locks', null, 403],
        'POST /api/schedules/{id}/place-slot' => ['/api/schedules/{schedule}/place-slot', '{}', 403],
        'POST /api/schedule_plans' => ['/api/schedule_plans', '{"calendarEntryId":"{unplanned_period}"}', 403],
        'PUT /api/schedule_plans/{id}' => ['/api/schedule_plans/{period_plan}', '@get', 403],
        'POST /api/schedule_plans/{id}/transcribe-from-socle' => ['/api/schedule_plans/{schedule_plan}/transcribe-from-socle', null, 403],
        'POST /api/schedule_plans/{id}/window' => ['/api/schedule_plans/{schedule_plan}/window', '{}', 403],
        'POST /api/schedule_plans/{id}/window-preview' => ['/api/schedule_plans/{schedule_plan}/window-preview', '{}', 403],
        'POST /api/schedule-slots/{id}/manual-edit/lock' => ['/api/schedule-slots/{schedule_slot}/manual-edit/lock', '{}', 403],
        'POST /api/schedule-slots/{id}/move' => ['/api/schedule-slots/{schedule_slot}/move', '{}', 403],
        'POST /api/schedule-slots/{id}/mutualize' => ['/api/schedule-slots/{schedule_slot}/mutualize', '{}', 403],
        'POST /api/schedule-slots/{id}/remove' => ['/api/schedule-slots/{schedule_slot}/remove', null, 403],
        'POST /api/schedule-slots/move-group' => ['/api/schedule-slots/move-group', '{"scheduleId":"{schedule}"}', 403],
        'POST /api/schedule-slots/mutualize-at-case' => ['/api/schedule-slots/mutualize-at-case', '{}', 403],
        'POST /api/schedule-slots/remove-group' => ['/api/schedule-slots/remove-group', '{"scheduleId":"{schedule}"}', 403],
        'POST /api/schedule-slots/swap' => ['/api/schedule-slots/swap', '{}', 403],

        // ── Doléances.
        'POST /api/coach_wishes' => ['/api/coach_wishes', '{"calendarEntryId":"{holiday}","weekStart":"{holiday_monday}","teamId":"{team2}","coachId":"{coach}","slotsWanted":1}', 403],
        'PUT /api/coach_wishes/{id}' => ['/api/coach_wishes/{coach_wish}', '@get', 403],
        'DELETE /api/coach_wishes/{id}' => ['/api/coach_wishes/{coach_wish}', null, 403],
        'POST /api/coach-wishes/transfer' => ['/api/coach-wishes/transfer', '{}', 403],
        'POST /api/coach_wish_campaigns' => ['/api/coach_wish_campaigns', '{"calendarEntryId":"{holiday}","deadline":"{campaign_deadline}","weeks":["{holiday_monday}"],"teamIds":["{team2}"]}', 403],
        'PUT /api/coach_wish_campaigns/{id}' => ['/api/coach_wish_campaigns/{coach_wish_campaign}', '@get', 403],
        'POST /api/coach_wish_campaigns/{id}/recipient' => ['/api/coach_wish_campaigns/{coach_wish_campaign}/recipient', '{}', 403],
        'POST /api/coach_wish_campaigns/{id}/remind' => ['/api/coach_wish_campaigns/{coach_wish_campaign}/remind', '{}', 403],
        'POST /api/coach_wish_campaigns/{id}/send-links' => ['/api/coach_wish_campaigns/{coach_wish_campaign}/send-links', '{}', 403],
        'POST /api/coach_wish_mutualizations' => ['/api/coach_wish_mutualizations', '{"calendarEntryId":"{holiday}","teamId":"{team2}","coachId":"{coach}","partnerTeamIds":["{team3}"],"sharedSlots":1,"done":false}', 403],
        'PUT /api/coach_wish_mutualizations/{id}' => ['/api/coach_wish_mutualizations/{coach_wish_mutualization}', '@get', 403],
        'DELETE /api/coach_wish_mutualizations/{id}' => ['/api/coach_wish_mutualizations/{coach_wish_mutualization}', null, 403],

        // ── Matchs : rencontres, compétitions, adversaires, FFBB.
        'POST /api/fixtures' => ['/api/fixtures', '{"teamId":"{team}","matchDate":"{match_date}","homeAway":"HOME","opponentLabel":"Membre"}', 403],
        'PUT /api/fixtures/{id}' => ['/api/fixtures/{fixture}', '{"teamId":"{team}","matchDate":"{match_date}","homeAway":"AWAY","opponentLabel":"Membre"}', 403],
        'DELETE /api/fixtures/{id}' => ['/api/fixtures/{fixture}', null, 403],
        'POST /api/fixtures/place' => ['/api/fixtures/place', '{}', 403],
        'POST /api/fixtures/import' => ['/api/fixtures/import', null, 403],
        'POST /api/fixtures/import/analyze' => ['/api/fixtures/import/analyze', null, 403],
        'POST /api/fixtures/review' => ['/api/fixtures/review', '{}', 403],
        'POST /api/fixtures/review/deviations' => ['/api/fixtures/review/deviations', '{}', 403],
        'POST /api/fixtures/league-validation' => ['/api/fixtures/league-validation', '{}', 403],
        'PUT /api/fixtures/conflicts/{fingerprint}/resolution' => ['/api/fixtures/conflicts/VENUE_OVERLAP:ab12/resolution', '{}', 403],
        'DELETE /api/fixtures/conflicts/{fingerprint}/resolution' => ['/api/fixtures/conflicts/VENUE_OVERLAP:ab12/resolution', null, 403],
        'POST /api/fixtures/fbi-corrections/{id}/close' => ['/api/fixtures/fbi-corrections/{fixture}/close', '{}', 403],
        'POST /api/fixtures/fbi-corrections/{id}/reopen' => ['/api/fixtures/fbi-corrections/{fixture}/reopen', '{}', 403],
        'POST /api/competitions' => ['/api/competitions', '{"teamId":"{team}","name":"Compétition Membre","competitionType":"CHAMPIONSHIP"}', 403],
        'POST /api/competitions/entry-deadlines' => ['/api/competitions/entry-deadlines', '{"competitionIds":["{dummy}"],"deadline":null}', 403],
        'POST /api/match_constraints' => ['/api/match_constraints', '{"ruleType":"PREFERRED","daysOfWeek":[7],"kickoffMax":"20:00"}', 403],
        'PUT /api/match_constraints/{id}' => ['/api/match_constraints/{match_constraint}', '@get', 403],
        'DELETE /api/match_constraints/{id}' => ['/api/match_constraints/{match_constraint}', null, 403],
        'POST /api/opponents/refresh' => ['/api/opponents/refresh', '{}', 403],
        'POST /api/opponents/resolve' => ['/api/opponents/resolve', '{}', 403],
        'POST /api/opponents/travel/resolve' => ['/api/opponents/travel/resolve', '{}', 403],
        'POST /api/opponents/{code}/venues' => ['/api/opponents/ARA0069001/venues', '{}', 403],
        'POST /api/opponents/{code}/venue-links' => ['/api/opponents/ARA0069001/venue-links', '{}', 403],
        'PUT /api/opponents/venue-links/{id}' => ['/api/opponents/venue-links/{opponent_venue_link}', '{}', 403],
        'DELETE /api/opponents/venue-links/{id}' => ['/api/opponents/venue-links/{opponent_venue_link}', null, 403],
        'POST /api/ffbb/engagements/confirm' => ['/api/ffbb/engagements/confirm', '{}', 403],
        'POST /api/ffbb/rencontres/apply' => ['/api/ffbb/rencontres/apply', '{}', 403],
    ];

    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $ids = [];

    private string $memberToken = '';

    private string $memberUserId = '';

    private string $clubId = '';

    private string $managerUserId = '';

    private string $managerToken = '';

    public function testEveryRouterWriteIsDecidedInTheTableAndEveryRowExists(): void
    {
        $router = $this->routerWrites();
        $table = array_keys(self::ROUTES);

        $undecided = array_values(array_diff($router, $table));
        self::assertSame([], $undecided, "Écritures du routeur sans décision dans MemberWriteSweepTest::ROUTES :\n  - " . implode("\n  - ", $undecided));

        $dead = array_values(array_diff($table, $router, self::ABSENT_UNTIL_MANAGER_REQUEST));
        self::assertSame([], $dead, "Lignes de la table sans route au routeur :\n  - " . implode("\n  - ", $dead));
    }

    public function testMemberIsRefused403OnEveryManagementWriteAndNothingMoves(): void
    {
        $this->seedClubAndMember();
        $before = $this->fingerprint();
        $sentBefore = $this->sentCounts();

        $router = $this->routerWrites();
        $wrong = [];
        $played = 0;
        foreach (self::ROUTES as $key => [$path, $body, $decision]) {
            if (403 !== $decision || !\in_array($key, $router, true)) {
                continue; // `open:`/`skip:` jamais jouées ; une route encore absente (PR 3) non plus
            }
            [$method] = explode(' ', $key, 2);
            if ('@get' === $body) {
                // PUT : le corps est la ressource telle que le gestionnaire la lit — valide par construction.
                $body = json_encode($this->api($this->managerToken, 'GET', $this->fill($path)), \JSON_THROW_ON_ERROR);
            }
            $this->resetBuckets();
            $this->startFreshBrowserSession($this->client);
            $this->client->request($method, $this->fill($path), [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->memberToken,
                'CONTENT_TYPE' => 'PATCH' === $method && !str_starts_with($path, '/api/club/') && !str_starts_with($path, '/api/me')
                    ? 'application/merge-patch+json' : 'application/json',
            ], null === $body ? null : $this->fill($body));
            ++$played;
            $status = $this->client->getResponse()->getStatusCode();
            if (403 !== $status) {
                $wrong[] = \sprintf('%s → %d %s', $key, $status, mb_substr((string) $this->client->getResponse()->getContent(), 0, 700));
            }
        }

        // Témoin positif : un balayage qui ne jouerait rien serait vert pour rien.
        self::assertGreaterThan(100, $played, 'le balayage n\'a presque rien joué');
        self::assertSame([], $wrong, "Écritures qu'un Membre n'a pas reçues en 403 EXACT :\n  - " . implode("\n  - ", $wrong));
        self::assertSame($before, $this->fingerprint(), 'les tables du club ont bougé sous un Membre');
        self::assertSame($sentBefore, $this->sentCounts(), 'un Membre a fait partir un mail ou un message');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** @return list<string> `MÉTHODE chemin` des écritures `^/api/` du routeur. */
    private function routerWrites(): array
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $out = [];
        foreach ($router->getRouteCollection() as $route) {
            $path = str_replace('.{_format}', '', $route->getPath());
            if (!str_starts_with($path, '/api/')) {
                continue;
            }
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }
            $methods = $route->getMethods();
            if ([] === $methods) {
                $out[] = 'ANY ' . $path;
                continue;
            }
            foreach ($methods as $method) {
                if (\in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $out[] = $method . ' ' . $path;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    private function seedClubAndMember(): void
    {
        $managerToken = $this->registerManager();
        $this->managerToken = $managerToken;

        $categoryId = $this->api($managerToken, 'GET', '/api/sport_categories')['member'][0]['id'];
        $this->ids['category'] = $categoryId;
        $this->ids['team'] = $this->create($managerToken, '/api/teams', ['name' => 'SW1', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        $this->ids['team2'] = $this->create($managerToken, '/api/teams', ['name' => 'SW2', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        $this->ids['venue'] = $this->create($managerToken, '/api/venues', ['name' => 'Gym SW', 'source' => 'manual']);
        $this->ids['slot'] = $this->create($managerToken, '/api/venue_training_slots', ['venueId' => $this->ids['venue'], 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]);
        $this->ids['coach'] = $this->create($managerToken, '/api/coaches', ['firstName' => 'Jean', 'isEmployee' => true]);
        $this->ids['constraint'] = $this->create($managerToken, '/api/constraints', ['name' => 'SW', 'scope' => 'CLUB', 'family' => 'DAY', 'ruleType' => 'HARD', 'config' => ['forbiddenDays' => [6]], 'isActive' => true, 'sortOrder' => 1]);
        $this->ids['draft_schedule'] = $this->create($managerToken, '/api/schedules', ['name' => 'Planning SW', 'status' => 'DRAFT']);
        $this->pointValidatedSeasonPlan();
        $this->ids['dummy'] = self::DUMMY;
        $this->ids['match_date'] = new DateTimeImmutable('+10 days')->format('Y-m-d');
        $this->ids['fixture'] = $this->create($managerToken, '/api/fixtures', [
            'teamId' => $this->ids['team'], 'matchDate' => new DateTimeImmutable('+10 days')->format('Y-m-d'),
            'homeAway' => 'HOME', 'opponentLabel' => 'Amical SW',
        ]);

        $this->seedEveryEditableResource($managerToken);

        [$this->memberToken, $this->memberUserId, $this->ids['membership']] = $this->makeMember($this->clubId);
    }

    /**
     * Une ligne réelle par ressource éditable, créée par l'API gestionnaire : une écriture d'item
     * jouée sur un id bidon tomberait en 404 au provider, AVANT la garde — elle ne prouverait rien.
     */
    private function seedEveryEditableResource(string $token): void
    {
        $monday = new DateTimeImmutable('monday next week +4 weeks');
        $this->ids['venue2'] = $this->create($token, '/api/venues', ['name' => 'Gym SW 2', 'source' => 'manual']);
        $this->ids['venue3'] = $this->create($token, '/api/venues', ['name' => 'Gym SW 3', 'source' => 'manual']);
        $this->ids['team3'] = $this->create($token, '/api/teams', ['name' => 'SW3', 'sportCategoryId' => $this->ids['category'], 'priorityTierId' => 1]);
        $this->ids['sport'] = (string) $this->api($token, 'GET', '/api/sport_categories/' . $this->ids['category'])['sportId'];
        $this->ids['team_link'] = $this->create($token, '/api/team_links', ['teamAId' => $this->ids['team'], 'teamBId' => $this->ids['team2'], 'linkType' => 'NOT_SIMULTANEOUS']);
        $this->ids['team_match_habit'] = $this->create($token, '/api/team_match_habits', ['teamId' => $this->ids['team'], 'dayOfWeek' => 7, 'kickoffTime' => '17:30']);
        $this->ids['team_coach'] = $this->create($token, '/api/team_coaches', ['teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'role' => 'MAIN']);
        $this->ids['coach_player_membership'] = $this->create($token, '/api/coach_player_memberships', ['coachId' => $this->ids['coach'], 'teamId' => $this->ids['team2'], 'isActive' => true]);
        $this->ids['venue_unavailability'] = $this->create($token, '/api/venue_unavailabilities', ['venueId' => $this->ids['venue'], 'startDate' => $monday->modify('+1 day')->format('Y-m-d'), 'endDate' => $monday->modify('+2 days')->format('Y-m-d')]);
        $this->ids['venue_match_window'] = $this->create($token, '/api/venue_match_windows', ['venueId' => $this->ids['venue'], 'openingDate' => $monday->modify('+5 days')->format('Y-m-d'), 'startTime' => '14:00', 'endTime' => '20:00', 'label' => 'Tournoi SW']);
        $this->ids['venue_travel_time'] = $this->create($token, '/api/venue_travel_times', ['venueAId' => $this->ids['venue'], 'venueBId' => $this->ids['venue2'], 'drivingMinutes' => 15]);
        $this->ids['reservation'] = $this->create($token, '/api/reservations', ['teamId' => $this->ids['team'], 'venueId' => $this->ids['venue'], 'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90]);
        $this->ids['shared_training_block'] = $this->create($token, '/api/shared_training_blocks', ['teamIds' => [$this->ids['team'], $this->ids['team2']], 'commonSessions' => 1, 'schedulePlanId' => null, 'label' => 'Groupe SW']);
        $this->ids['club_league_window'] = $this->create($token, '/api/club_league_windows', ['category' => 'Seniors', 'level' => 'REGIONAL', 'gender' => null, 'dayOfWeek' => 6, 'kickoffMin' => '14:00', 'kickoffMax' => '18:00']);
        $this->ids['match_constraint'] = $this->create($token, '/api/match_constraints', ['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMax' => '21:00']);
        $this->ids['calendar_entry'] = $this->create($token, '/api/calendar_entries', ['kind' => 'event', 'title' => 'Événement SW', 'startDate' => $monday->format('Y-m-d'), 'endDate' => $monday->format('Y-m-d')]);

        // Une période et son plan : les surcharges de période en dépendent.
        $this->ids['period'] = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Fermeture SW', 'startDate' => $monday->format('Y-m-d'), 'endDate' => $monday->modify('+6 days')->format('Y-m-d')]);
        $this->ids['period_plan'] = $this->create($token, '/api/schedule_plans', ['calendarEntryId' => $this->ids['period']]);
        $this->ids['team_period_override'] = $this->create($token, '/api/team_period_overrides', ['schedulePlanId' => $this->ids['period_plan'], 'teamId' => $this->ids['team']]);
        $this->ids['venue_period_override'] = $this->create($token, '/api/venue_period_overrides', ['schedulePlanId' => $this->ids['period_plan'], 'venueId' => $this->ids['venue'], 'mode' => 'BLANK']);
        $this->ids['constraint_period_override'] = $this->create($token, '/api/constraint_period_overrides', ['schedulePlanId' => $this->ids['period_plan'], 'constraintId' => $this->ids['constraint'], 'isActive' => false]);
        // Les doléances ne concernent que les vacances : une seconde période, de type vacances.
        $this->ids['holiday'] = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'holiday', 'title' => 'Vacances SW', 'startDate' => $monday->modify('+14 days')->format('Y-m-d'), 'endDate' => $monday->modify('+20 days')->format('Y-m-d')]);
        $holidayMonday = $monday->modify('+14 days')->format('Y-m-d');
        $this->ids['holiday_monday'] = $holidayMonday;
        $this->ids['campaign_deadline'] = $monday->modify('+7 days')->format('Y-m-d');
        $this->ids['unplanned_period'] = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'custom', 'title' => 'Période SW sans plan', 'startDate' => $monday->modify('+28 days')->format('Y-m-d'), 'endDate' => $monday->modify('+30 days')->format('Y-m-d')]);
        $this->ids['coach_wish'] = $this->create($token, '/api/coach_wishes', ['calendarEntryId' => $this->ids['holiday'], 'weekStart' => $holidayMonday, 'teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'slotsWanted' => 1]);
        $this->ids['coach_wish_mutualization'] = $this->create($token, '/api/coach_wish_mutualizations', ['calendarEntryId' => $this->ids['holiday'], 'teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'partnerTeamIds' => [$this->ids['team2']], 'sharedSlots' => 1, 'done' => false]);
        $this->ids['holiday_plan'] = $this->create($token, '/api/schedule_plans', ['calendarEntryId' => $this->ids['holiday']]);
        $this->ids['coach_wish_campaign'] = $this->create($token, '/api/coach_wish_campaigns', ['calendarEntryId' => $this->ids['holiday'], 'deadline' => $monday->modify('+7 days')->format('Y-m-d'), 'weeks' => [$holidayMonday], 'teamIds' => [$this->ids['team']]]);
        $this->ids['rule_key'] = 'coachRestDay';
        $this->ids['season'] = $this->ids['season'] ?? self::DUMMY;
    }

    /**
     * Pointage (SQL autorisé par la charte) : valider un plan de saison exige une génération moteur ;
     * le décor pointe donc une version du plan SEASON (patron `ChoosesPlanVersionTrait`, comme les
     * tests du module matchs) et y pose une séance — ce qui ouvre les rencontres et donne des ids
     * réels aux retouches de planning.
     */
    private function pointValidatedSeasonPlan(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $this->clubId], ['startDate' => 'DESC']);
        self::assertInstanceOf(Season::class, $season);
        $this->ids['season'] = $season->getId();
        $schedule = $this->settleSeasonPlan($season);
        $this->ids['schedule'] = $schedule->getId();
        $this->ids['schedule_plan'] = (string) $schedule->getSchedulePlanId();
        $session = (new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($season->getId())->setScheduleId($schedule->getId())
            ->setTeamId($this->ids['team'])->setVenueId($this->ids['venue'])->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setLockLevel(LockLevel::NONE);
        $em->persist($session);
        $em->flush();
        $this->ids['schedule_slot'] = $session->getId();
    }

    private function registerManager(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'sw' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => self::PASSWORD,
            'firstName' => 'M', 'lastName' => 'Sweep', 'ara' => strtoupper($suffix),
            'club_name' => 'Club Sweep', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);
        $this->startFreshBrowserSession($this->client);
        $me = $this->api($token, 'GET', '/api/me');
        $this->clubId = (string) $me['club']['id'];
        $this->managerUserId = (string) $me['id'];
        $this->ids['club'] = $this->clubId;

        return $token;
    }

    /** @return array{0: string, 1: string, 2: string} [token, userId, membershipId] */
    private function makeMember(string $clubId): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = new User;
        $user->setEmail('membre' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $user->setFirstName('Paul');
        $user->setLastName('Membre');
        $user->setPasswordHash($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole('member');
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return [$container->get(JWTTokenManagerInterface::class)->create($user), $user->getId(), $membership->getId()];
    }

    /** @param array<string, mixed> $payload */
    private function create(string $token, string $url, array $payload): string
    {
        $body = $this->api($token, 'POST', $url, $payload);
        self::assertResponseStatusCodeSame(201, 'décor : ' . $url . ' ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return (string) $body['id'];
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function api(string $token, string $method, string $url, ?array $payload = null): array
    {
        if ('' !== $this->managerUserId) {
            // Le décor dépasse la borne `api` de test (30/min) : seau du gestionnaire remis à zéro.
            $limiter = self::getContainer()->get('limiter.api');
            \assert($limiter instanceof RateLimiterFactory);
            $limiter->create('api-user-' . $this->managerUserId)->reset();
        }
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function fill(string $path): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $this->ids[$m[1]] ?? self::DUMMY, $path);
    }

    /** Remet à zéro les seaux que le balayage traverse : `api` du Membre, `schedule_generation` du club. */
    private function resetBuckets(): void
    {
        $container = self::getContainer();
        $api = $container->get('limiter.api');
        \assert($api instanceof RateLimiterFactory);
        $api->create('api-user-' . $this->memberUserId)->reset();
        $gen = $container->get('limiter.schedule_generation');
        \assert($gen instanceof RateLimiterFactory);
        $gen->create('club-' . $this->clubId)->reset();
    }

    /**
     * Empreinte des tables du club : pour chaque table portant `club_id`, `count(*)` et, si elle a
     * `updated_at`, son max. Lue sous la GUC du club (RLS active).
     *
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $container = self::getContainer();
        $container->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $conn = $container->get(EntityManagerInterface::class)->getConnection();
        \assert($conn instanceof Connection);
        $tables = $conn->fetchAllAssociative(
            "SELECT c.table_name, bool_or(u.column_name IS NOT NULL) AS has_updated
               FROM information_schema.columns c
               LEFT JOIN information_schema.columns u
                 ON u.table_schema = c.table_schema AND u.table_name = c.table_name AND u.column_name = 'updated_at'
              WHERE c.table_schema = 'public' AND c.column_name = 'club_id'
              GROUP BY c.table_name ORDER BY c.table_name",
        );
        $print = [];
        foreach ($tables as $t) {
            $name = (string) $t['table_name'];
            $select = $t['has_updated'] ? 'count(*)::text || \'@\' || coalesce(max(updated_at)::text, \'-\')' : 'count(*)::text';
            $print[$name] = (string) $conn->fetchOne(\sprintf('SELECT %s FROM "%s" WHERE club_id = ?', $select, $name), [$this->clubId]);
        }

        return $print;
    }

    /** @return array<string, int> messages déjà passés par chaque transport mémoire (mails compris). */
    private function sentCounts(): array
    {
        $counts = [];
        foreach (['mailer_in_memory', 'travel_in_memory', 'placement_in_memory', 'ffbb_in_memory', 'reset_in_memory'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.' . $name);
            \assert($transport instanceof InMemoryTransport);
            $counts[$name] = \count($transport->getSent());
        }

        return $counts;
    }
}
