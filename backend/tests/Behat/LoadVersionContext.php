<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : « Charger » une version de saison remet la grille, les réservations, les règles et les
 * séances de chaque équipe telles qu'elles étaient à sa génération (lot conception 5, PR 1 : le filet de
 * ce qui revient DÉJÀ, avant que la PR 3 ne donne à « Charger » une seule définition).
 *
 * Décor : le petit club de {@see SmallClubFixtureTrait}, inscrit et saisi par l'API. Avant la version 1,
 * SF1 se réserve une case et une règle interdit à SM1 de s'entraîner avant 19h00. Entre les deux
 * versions, par les gestes de l'API : un créneau du vendredi est ouvert au Gymnase Centre et réservé à
 * SM1, la réservation de SF1 est retirée, la règle de SM1 passe à 19h30, une règle interdit le lundi aux
 * U15M1, Loisir prend une séance de plus. Deux solves réels (palier 60 s).
 *
 * Lecture : un pointage SQL du plan de saison du club, sur le CONTENU (noms, jours, heures) et jamais sur
 * les identifiants — un chargement qui met à jour les lignes présentes au lieu de les réinsérer tient la
 * même promesse. Témoin positif : avant le chargement, chacune des quatre familles diffère de la
 * version 1 ; sans lui, « égal à la version 1 » serait vrai d'un décor où rien n'a bougé.
 *
 * Route : `POST /api/schedules/{id}/load`, la seule porte de « Charger » depuis la PR 3 du lot 5.
 *
 * Second scénario (PR 3, lignes 11 et 12 du plan) : tout le froid du planning de saison revient — passerelle,
 * règle, groupe mutualisé, bien-être, règle de trajet, coach principal, coach-joueur, gymnase imposé — et rien de
 * ce qui est libre ou vit hors du planning ne bouge : durée de match, catégories, noms, adjoints, libellés ; aucune
 * équipe, aucun gymnase, aucun coach, aucune catégorie ne naît ni ne part. Les séances de la version 1 — réservée,
 * libre, de groupe — retrouvent ce qui les tient. Même témoin : avant le chargement, chaque famille diffère. Les cas
 * nombreux (références disparues, autres plans, équipe engagée) sont prouvés par `Integration/Plans/LoadVersionTest`.
 */
final class LoadVersionContext extends BaseContext
{
    use SmallClubFixtureTrait;

    /** Les quatre familles comparées, dans l'ordre des messages. */
    private const array FAMILIES = ['grille', 'réservations', 'règles', 'séances par équipe'];

    private string $versionOneId = '';

    /** @var array<string, string> famille → état du plan de saison à la génération de la version 1 */
    private array $versionOneState = [];

    private string $rulePreferenceId = '';

    private string $linkId = '';

    private string $blockId = '';

    private string $playerId = '';

    /** @var array<string, string> famille du froid du socle → son état à la génération de la version 1 */
    private array $colderOfVersionOne = [];

    /** @var array<string, string> ce qui est libre ou vit hors du planning, juste avant le chargement */
    private array $freeBeforeLoad = [];

    /** @var array<string, string> séances de la version 1 par sorte (réservée, libre, de groupe), juste avant le chargement */
    private array $versionOneSessions = [];

    #[Given('un planning de saison en version 2')]
    public function unPlanningDeSaisonEnVersion2(): void
    {
        $this->buildSmallClub();
        $sf1Reservation = $this->reserve('SF1', 'Gymnase Centre', 1, '18:00');
        $this->rulePreferenceId = $this->addRule('SM1 pas avant 19h00', 'SM1', 'TIME', ['minStartTime' => '19:00']);
        $this->versionOneId = $this->generateSmallClubSeason();
        $this->versionOneState = $this->planState();

        // Entre les deux versions : les gestes de l'API, une famille après l'autre.
        $slot = $this->apiPost('venue_training_slots', [
            'venueId' => $this->smallClubVenueIds['Gymnase Centre'],
            'dayOfWeek' => 5,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'capacity' => 1,
        ], $this->smallClubToken);
        $this->expectStatus($slot, [200, 201], 'l\'ouverture du créneau du vendredi');
        $removed = $this->apiDelete(\sprintf('reservations/%s', $sf1Reservation), $this->smallClubToken);
        $this->expectStatus($removed, [200, 204], 'le retrait de la réservation de SF1');
        $this->reserve('SM1', 'Gymnase Centre', 5, '18:00');
        $this->putBack('constraints', $this->rulePreferenceId, ['config' => ['minStartTime' => '19:30']]);
        $this->addRule('U15M1 pas le lundi', 'U15M1', 'DAY', ['forbiddenDays' => [1]]);
        $this->putBack('teams', $this->smallClubTeamIds['Loisir'], ['sessionsPerWeek' => $this->smallClubTeamPlan()['Loisir']['sessionsPerWeek'] + 1]);
        $this->generateSmallClubSeason();

        $now = $this->planState();
        foreach (self::FAMILIES as $family) {
            if ($now[$family] === $this->versionOneState[$family]) {
                throw new RuntimeException(\sprintf('témoin : %s de la version 2 devrait différer de la version 1 (« %s »)', $family, $now[$family]));
            }
        }
    }

    #[When('je charge la version 1')]
    public function jeChargeLaVersion1(): void
    {
        $this->freeBeforeLoad = $this->freeState();
        $this->versionOneSessions = $this->versionOneSessionsByKind();
        foreach ('' === $this->blockId ? [] : $this->versionOneSessions as $kind => $sessions) {
            if ('' === $sessions) {
                throw new RuntimeException(\sprintf('témoin : la version 1 n\'a aucune séance %s', $kind));
            }
        }
        $loaded = $this->apiPost(\sprintf('schedules/%s/load', $this->versionOneId), [], $this->smallClubToken);
        $this->expectStatus($loaded, [200], '« Charger » la version 1');
    }

    #[Given('un planning de saison généré avec une passerelle, une règle, un groupe mutualisé, une règle de bien-être, une règle de trajet, un coach principal, un coach-joueur et un gymnase imposé')]
    public function unPlanningDeSaisonGenereAvecToutLeFroid(): void
    {
        $this->buildSmallClub();
        $this->reserve('SF1', 'Gymnase Centre', 1, '18:00');
        $this->linkId = $this->createdId($this->apiPost('team_links', [
            'teamAId' => $this->smallClubTeamIds['SM1'], 'teamBId' => $this->smallClubTeamIds['U20M1'], 'linkType' => 'NOT_SIMULTANEOUS', 'trainingIntensity' => 'PREFERRED',
        ], $this->smallClubToken), 'la passerelle SM1 – U20M1');
        $this->rulePreferenceId = $this->addRule('U15F1 pas le mardi', 'U15F1', 'DAY', ['forbiddenDays' => [2]]);
        $this->blockId = $this->createdId($this->apiPost('shared_training_blocks', [
            'teamIds' => [$this->smallClubTeamIds['U17F1'], $this->smallClubTeamIds['U15F1']], 'commonSessions' => 1, 'schedulePlanId' => null,
        ], $this->smallClubToken), 'le groupe U17F1 + U15F1');
        $this->expectStatus($this->apiPut('implicit_rule_settings/ageAscending', ['intensity' => 'OFF'], $this->smallClubToken), [200], 'la règle de bien-être');
        $this->expectStatus($this->apiPut('venue_travel_rule_settings/travelTime', ['intensity' => 'MANDATORY', 'toleranceMinutes' => 15, 'defaultMinutes' => 25], $this->smallClubToken), [200], 'la règle de trajet');
        $this->playerId = $this->createdId($this->apiPost('coach_player_memberships', [
            'coachId' => $this->smallClubCoachIds['Dominique'], 'teamId' => $this->smallClubTeamIds['U20M1'], 'isActive' => true,
        ], $this->smallClubToken), 'Dominique joue en U20M1');
        $this->putBack('teams', $this->smallClubTeamIds['U15M1'], ['forcedVenueId' => $this->smallClubVenueIds['Gymnase Nord']]);
        $this->versionOneId = $this->generateSmallClubSeason();
        $this->colderOfVersionOne = $this->socleColdState();
    }

    #[Given('une version 2 générée après avoir changé chacun d\'eux')]
    public function uneVersion2GenereeApresAvoirChangeChacunDEux(): void
    {
        $this->expectStatus($this->apiDelete(\sprintf('team_links/%s', $this->linkId), $this->smallClubToken), [200, 204], 'le retrait de la passerelle');
        $this->putBack('constraints', $this->rulePreferenceId, ['config' => ['forbiddenDays' => [2, 4]], 'ruleType' => 'HARD']);
        $this->putBack('shared_training_blocks', $this->blockId, ['commonSessions' => 2]);
        $this->expectStatus($this->apiPut('implicit_rule_settings/ageAscending', ['intensity' => 'PREFERRED'], $this->smallClubToken), [200], 'la règle de bien-être changée');
        $this->expectStatus($this->apiPut('venue_travel_rule_settings/travelTime', ['intensity' => 'PREFERRED', 'toleranceMinutes' => 20, 'defaultMinutes' => 20], $this->smallClubToken), [200], 'la règle de trajet changée');
        $sacha = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM team_coach WHERE team_id = \'%s\' AND coach_id = \'%s\'', $this->smallClubTeamIds['U17M1'], $this->smallClubCoachIds['Sacha']), admin: true);
        $this->expectStatus($this->apiDelete(\sprintf('team_coaches/%s', $sacha), $this->smallClubToken), [200, 204], 'Sacha quitte les U17M1');
        $this->assignSmallClubCoach('Alex', 'U17M1');
        $this->expectStatus($this->apiDelete(\sprintf('coach_player_memberships/%s', $this->playerId), $this->smallClubToken), [200, 204], 'Dominique ne joue plus');
        // L'API n'efface pas un gymnase imposé (absent = gardé) : on en impose un autre.
        $this->putBack('teams', $this->smallClubTeamIds['U15M1'], ['forcedVenueId' => $this->smallClubVenueIds['Gymnase Sud']]);
        $this->generateSmallClubSeason();

        $now = $this->socleColdState();
        foreach ($this->colderOfVersionOne as $family => $state) {
            if ($now[$family] === $state) {
                throw new RuntimeException(\sprintf('témoin : %s de la version 2 devrait différer de la version 1 (« %s »)', $family, $state));
            }
        }
    }

    #[Given('une durée de match modifiée, une catégorie créée, une équipe renommée, un adjoint ajouté et un groupe renommé après la version 1')]
    public function desChangementsLibresApresLaVersion1(): void
    {
        $category = $this->dbalScalar(\sprintf('SELECT sport_category_id AS behatval FROM team WHERE id = \'%s\'', $this->smallClubTeamIds['SM1']), admin: true);
        $this->putBack('sport_categories', $category, ['matchMinutes' => 75]);
        $sport = $this->dbalScalar(\sprintf('SELECT sport_id AS behatval FROM sport_category WHERE id = \'%s\'', $category), admin: true);
        $this->createdId($this->apiPost('sport_categories', ['sportId' => $sport, 'name' => 'Vétérans', 'isCustom' => true], $this->smallClubToken), 'la catégorie Vétérans');
        $this->putBack('teams', $this->smallClubTeamIds['U13M1'], ['name' => 'U13M1 Les Lions']);
        $this->createdId($this->apiPost('team_coaches', ['teamId' => $this->smallClubTeamIds['SM1'], 'coachId' => $this->smallClubCoachIds['Sacha'], 'role' => 'ASSISTANT'], $this->smallClubToken), 'Sacha adjoint des SM1');
        $this->putBack('shared_training_blocks', $this->blockId, ['label' => 'Groupe des filles']);
    }

    #[Then('les passerelles, les règles, les groupes mutualisés, le bien-être, la règle de trajet, les coachs principaux, les coachs-joueurs et les gymnases imposés sont ceux de la version 1')]
    public function toutLeFroidEstCeluiDeLaVersion1(): void
    {
        $now = $this->socleColdState();
        foreach ($this->colderOfVersionOne as $family => $state) {
            if ($now[$family] !== $state) {
                throw new RuntimeException(\sprintf("%s après chargement ≠ version 1\n  attendu : %s\n  obtenu  : %s", $family, $state, $now[$family]));
            }
        }
    }

    #[Then('la durée de match, les catégories, les noms, les adjoints et les libellés sont inchangés')]
    public function leLibreEstInchange(): void
    {
        $now = $this->freeState();
        if ($now !== $this->freeBeforeLoad) {
            throw new RuntimeException(\sprintf("le chargement a touché ce qui est libre\n  avant : %s\n  après : %s", json_encode($this->freeBeforeLoad, \JSON_UNESCAPED_UNICODE), json_encode($now, \JSON_UNESCAPED_UNICODE)));
        }
        if (!str_contains($now['noms'], 'U13M1 Les Lions') || !str_contains($now['adjoints'], 'Sacha') || !str_contains($now['catégories'], 'Vétérans 0') || !str_contains($now['libellés'], 'Groupe des filles')) {
            throw new RuntimeException('témoin : les changements libres du décor ne sont pas en base ' . json_encode($now, \JSON_UNESCAPED_UNICODE));
        }
    }

    #[Then('aucune équipe, aucun gymnase, aucun coach ni aucune catégorie n\'est créé ni supprimé')]
    public function rienNeNaitNiNePart(): void
    {
        $counts = $this->identityCounts();
        if ($counts !== $this->freeBeforeLoad['identités']) {
            throw new RuntimeException(\sprintf('le chargement a créé ou supprimé une équipe, un gymnase, un coach ou une catégorie : avant %s, après %s', $this->freeBeforeLoad['identités'], $counts));
        }
    }

    /** @param TableNode<array<string, string>> $kinds */
    #[Then('les séances de la version 1 retrouvent ce qui les tient :')]
    public function lesSeancesDeLaVersion1RetrouventCeQuiLesTient(TableNode $kinds): void
    {
        $now = $this->versionOneSessionsByKind();
        foreach ($kinds->getColumnsHash() as $row) {
            $kind = $row['séance'];
            if ('' === ($this->versionOneSessions[$kind] ?? '')) {
                throw new RuntimeException(\sprintf('témoin : la version 1 n\'a aucune séance %s', $kind));
            }
            if (($now[$kind] ?? '') !== $this->versionOneSessions[$kind]) {
                throw new RuntimeException(\sprintf("séance %s : la version 1 ne la retrouve pas\n  avant : %s\n  après : %s", $kind, $this->versionOneSessions[$kind], $now[$kind] ?? ''));
            }
        }
        $held = $this->heldSessions();
        foreach (['réservée' => 'reservation', 'd\'un groupe mutualisé' => 'block'] as $kind => $hold) {
            if (\in_array($kind, array_column($kinds->getColumnsHash(), 'séance'), true) && '0' !== $held[$hold]) {
                throw new RuntimeException(\sprintf('%s séance(s) %s de la version 1 sans ce qui la tient après le chargement', $held[$hold], $kind));
            }
        }
    }

    #[Then('la grille, les réservations et les règles sont celles de la version 1')]
    public function laGrilleLesReservationsEtLesReglesSontCellesDeLaVersion1(): void
    {
        $this->assertFamiliesAreThoseOfVersionOne(['grille', 'réservations', 'règles']);
    }

    #[Then('chaque équipe a les séances de la version 1')]
    public function chaqueEquipeALesSeancesDeLaVersion1(): void
    {
        $this->assertFamiliesAreThoseOfVersionOne(['séances par équipe']);
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @param list<string> $families */
    private function assertFamiliesAreThoseOfVersionOne(array $families): void
    {
        $now = $this->planState();
        foreach ($families as $family) {
            if ($now[$family] !== $this->versionOneState[$family]) {
                throw new RuntimeException(\sprintf("%s après chargement ≠ version 1\n  attendu : %s\n  obtenu  : %s", $family, $this->versionOneState[$family], $now[$family]));
            }
        }
    }

    /**
     * L'état du plan de saison du club, famille par famille, en texte trié (pointage SQL).
     *
     * @return array<string, string>
     */
    private function planState(): array
    {
        $plan = \sprintf('(SELECT id FROM schedule_plan WHERE club_id = \'%s\' AND type = \'SEASON\')', $this->smallClubId);

        return [
            'grille' => $this->dbalScalar(\sprintf(
                'SELECT COALESCE(string_agg(v.name || \' \' || s.day_of_week || \' \' || to_char(s.start_time, \'HH24:MI\') || \' \' || s.duration_minutes || \' \' || s.capacity, \' ; \' ORDER BY v.name, s.day_of_week, s.start_time), \'aucun\') AS behatval'
                . ' FROM venue_training_slot s JOIN venue v ON v.id = s.venue_id WHERE s.schedule_plan_id = %s',
                $plan,
            ), admin: true),
            'réservations' => $this->dbalScalar(\sprintf(
                'SELECT COALESCE(string_agg(COALESCE(t.name, \'libre\') || \' \' || v.name || \' \' || r.day_of_week || \' \' || to_char(r.start_time, \'HH24:MI\') || \' \' || r.duration_minutes, \' ; \' ORDER BY v.name, r.day_of_week, r.start_time), \'aucune\') AS behatval'
                . ' FROM reservation r JOIN venue v ON v.id = r.venue_id LEFT JOIN team t ON t.id = r.team_id WHERE r.schedule_plan_id = %s',
                $plan,
            ), admin: true),
            'règles' => $this->dbalScalar(\sprintf(
                'SELECT COALESCE(string_agg(c.name || \' \' || c.scope || \' \' || c.family || \' \' || c.rule_type || \' \' || c.config::text || \' \' || c.is_active, \' ; \' ORDER BY c.name), \'aucune\') AS behatval'
                . ' FROM "constraint" c WHERE c.club_id = \'%s\' AND c.calendar_entry_id IS NULL',
                $this->smallClubId,
            ), admin: true),
            'séances par équipe' => $this->dbalScalar(\sprintf(
                'SELECT COALESCE(string_agg(t.name || \' \' || l.sessions_per_week || \' \' || l.max_sessions_per_day, \' ; \' ORDER BY t.name), \'aucune\') AS behatval'
                . ' FROM plan_team l JOIN team t ON t.id = l.team_id WHERE l.schedule_plan_id = %s',
                $plan,
            ), admin: true),
        ];
    }

    /**
     * Le froid du socle, famille par famille, en texte trié par identifiants (un nom est libre : il ne doit pas
     * faire différer deux états froids).
     *
     * @return array<string, string>
     */
    private function socleColdState(): array
    {
        $club = $this->smallClubId;
        $plan = \sprintf('(SELECT id FROM schedule_plan WHERE club_id = \'%s\' AND type = \'SEASON\')', $club);
        $queries = [
            'passerelles' => \sprintf('SELECT COALESCE(string_agg(team_a_id || \'-\' || team_b_id || \' \' || link_type || \' \' || training_intensity, \' ; \' ORDER BY team_a_id, team_b_id), \'aucune\') AS behatval FROM team_link WHERE club_id = \'%s\'', $club),
            'règles' => \sprintf('SELECT COALESCE(string_agg(scope || \' \' || COALESCE(scope_target_id::text, \'\') || \' \' || family || \' \' || rule_type || \' \' || config::text || \' \' || is_active, \' ; \' ORDER BY id), \'aucune\') AS behatval FROM "constraint" WHERE club_id = \'%s\' AND calendar_entry_id IS NULL', $club),
            'groupes mutualisés' => \sprintf('SELECT COALESCE(string_agg(b.common_sessions || \' \' || (SELECT string_agg(m.team_id::text, \'+\' ORDER BY m.team_id) FROM shared_training_block_team m WHERE m.block_id = b.id), \' ; \' ORDER BY b.id), \'aucun\') AS behatval FROM shared_training_block b WHERE b.schedule_plan_id = %s', $plan),
            'bien-être' => \sprintf('SELECT COALESCE(string_agg(rule_key || \' \' || intensity || \' \' || COALESCE(params::text, \'\'), \' ; \' ORDER BY rule_key), \'aucune\') AS behatval FROM implicit_rule_setting WHERE schedule_plan_id = %s', $plan),
            'règle de trajet' => \sprintf('SELECT COALESCE(string_agg(intensity || \' \' || tolerance_minutes || \' \' || default_minutes, \' ; \'), \'défauts\') AS behatval FROM venue_travel_rule_setting WHERE club_id = \'%s\'', $club),
            'coachs principaux' => \sprintf('SELECT COALESCE(string_agg(team_id || \' \' || coach_id, \' ; \' ORDER BY team_id, coach_id), \'aucun\') AS behatval FROM team_coach WHERE club_id = \'%s\' AND role = \'MAIN\'', $club),
            'coachs-joueurs' => \sprintf('SELECT COALESCE(string_agg(coach_id || \' \' || team_id || \' \' || is_active || \' \' || COALESCE(position, \'\'), \' ; \' ORDER BY coach_id, team_id), \'aucun\') AS behatval FROM coach_player_membership WHERE club_id = \'%s\'', $club),
            'gymnases imposés' => \sprintf('SELECT COALESCE(string_agg(id || \' \' || forced_venue_id, \' ; \' ORDER BY id), \'aucun\') AS behatval FROM team WHERE club_id = \'%s\' AND forced_venue_id IS NOT NULL', $club),
        ];

        return array_map(fn (string $sql): string => $this->dbalScalar($sql, admin: true), $queries);
    }

    /**
     * Ce qui est libre ou vit hors du planning : durée de match et catégories, noms d'équipe, adjoints, libellés
     * (réservation, groupe, nom de règle), et le compte des identités du club.
     *
     * @return array<string, string>
     */
    private function freeState(): array
    {
        $club = $this->smallClubId;

        return [
            'durée de match' => $this->dbalScalar(\sprintf('SELECT string_agg(name || \' \' || COALESCE(match_minutes::text, \'défaut\'), \' ; \' ORDER BY name) AS behatval FROM sport_category WHERE club_id = \'%s\'', $club), admin: true),
            'catégories' => $this->dbalScalar(\sprintf('SELECT string_agg(c.name || \' \' || (SELECT COUNT(*) FROM team t WHERE t.sport_category_id = c.id), \' ; \' ORDER BY c.name) AS behatval FROM sport_category c WHERE c.club_id = \'%s\'', $club), admin: true),
            'noms' => $this->dbalScalar(\sprintf('SELECT string_agg(name, \' ; \' ORDER BY id) AS behatval FROM team WHERE club_id = \'%s\'', $club), admin: true),
            'adjoints' => $this->dbalScalar(\sprintf('SELECT COALESCE(string_agg(tc.team_id || \' \' || p.first_name, \' ; \' ORDER BY tc.team_id), \'aucun\') AS behatval FROM team_coach tc JOIN coach c ON c.id = tc.coach_id JOIN person p ON p.id = c.person_id WHERE tc.club_id = \'%s\' AND tc.role = \'ASSISTANT\'', $club), admin: true),
            'libellés' => $this->dbalScalar(\sprintf(
                'SELECT COALESCE((SELECT string_agg(COALESCE(label, \'-\'), \' ; \' ORDER BY id) FROM reservation WHERE club_id = \'%1$s\'), \'\') || \' | \' || COALESCE((SELECT string_agg(COALESCE(label, \'-\'), \' ; \' ORDER BY id) FROM shared_training_block WHERE club_id = \'%1$s\'), \'\') || \' | \' || COALESCE((SELECT string_agg(name, \' ; \' ORDER BY id) FROM "constraint" WHERE club_id = \'%1$s\'), \'\') AS behatval',
                $club,
            ), admin: true),
            'identités' => $this->identityCounts(),
        ];
    }

    /** Équipes, gymnases, coachs, catégories du club : leurs nombres. */
    private function identityCounts(): string
    {
        $club = $this->smallClubId;

        return $this->dbalScalar(\sprintf(
            'SELECT (SELECT COUNT(*) FROM team WHERE club_id = \'%1$s\') || \' équipes, \' || (SELECT COUNT(*) FROM venue WHERE club_id = \'%1$s\') || \' gymnases, \' || (SELECT COUNT(*) FROM coach WHERE club_id = \'%1$s\') || \' coachs, \' || (SELECT COUNT(*) FROM sport_category WHERE club_id = \'%1$s\') || \' catégories\' AS behatval',
            $club,
        ), admin: true);
    }

    /**
     * Les séances de la version 1, par sorte : réservée (épinglée par une réservation), libre (sans verrou), de
     * groupe (liée à un groupe mutualisé). Équipe, gymnase, jour, heure, verrou et groupe, en texte trié.
     *
     * @return array<string, string>
     */
    private function versionOneSessionsByKind(): array
    {
        // Une séance de groupe : celle d'une équipe du groupe, à la même case qu'une autre équipe du groupe
        // (le solveur pose la séance commune sans la lier au bloc).
        $group = \sprintf(
            'team_id IN (\'%1$s\', \'%2$s\') AND EXISTS (SELECT 1 FROM schedule_slot_template o WHERE o.schedule_id = schedule_slot_template.schedule_id AND o.team_id IN (\'%1$s\', \'%2$s\') AND o.team_id <> schedule_slot_template.team_id AND o.venue_id = schedule_slot_template.venue_id AND o.day_of_week = schedule_slot_template.day_of_week AND o.start_time = schedule_slot_template.start_time)',
            $this->smallClubTeamIds['U17F1'] ?? '',
            $this->smallClubTeamIds['U15F1'] ?? '',
        );
        $kinds = ['réservée' => 'lock_origin = \'RESERVATION\'', 'libre' => 'lock_level = \'NONE\'', 'd\'un groupe mutualisé' => $group];
        $sessions = [];
        foreach ($kinds as $kind => $where) {
            $sessions[$kind] = $this->dbalScalar(\sprintf(
                'SELECT COALESCE(string_agg(team_id || \' \' || venue_id || \' \' || day_of_week || \' \' || to_char(start_time, \'HH24:MI\') || \' \' || lock_level || \' \' || COALESCE(shared_training_block_id::text, \'-\'), \' ; \' ORDER BY team_id, day_of_week, start_time), \'\') AS behatval FROM schedule_slot_template WHERE schedule_id = \'%s\' AND %s',
                $this->versionOneId,
                $where,
            ), admin: true);
        }

        return $sessions;
    }

    /**
     * Les séances de la version 1 qui ont perdu ce qui les tient : une séance réservée sans réservation de son équipe
     * à sa case, une séance de groupe dont l'équipe n'est plus membre de son groupe.
     *
     * @return array{reservation: string, block: string}
     */
    private function heldSessions(): array
    {
        $plan = \sprintf('(SELECT id FROM schedule_plan WHERE club_id = \'%s\' AND type = \'SEASON\')', $this->smallClubId);

        return [
            'reservation' => $this->dbalScalar(\sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template s WHERE s.schedule_id = \'%s\' AND s.lock_origin = \'RESERVATION\' AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.schedule_plan_id = %s AND r.team_id = s.team_id AND r.venue_id = s.venue_id AND r.day_of_week = s.day_of_week AND r.start_time = s.start_time)',
                $this->versionOneId,
                $plan,
            ), admin: true),
            'block' => $this->dbalScalar(\sprintf(
                'SELECT 2 - COUNT(*) AS behatval FROM shared_training_block_team m JOIN shared_training_block b ON b.id = m.block_id WHERE b.id = \'%s\' AND b.common_sessions = 1 AND m.team_id IN (\'%s\', \'%s\')',
                $this->blockId,
                $this->smallClubTeamIds['U17F1'] ?? '',
                $this->smallClubTeamIds['U15F1'] ?? '',
            ), admin: true),
        ];
    }

    /** @param array{status: int, json: array<mixed>} $response */
    private function createdId(array $response, string $what): string
    {
        $this->expectStatus($response, [200, 201], $what);

        return $this->smallClubStringField($response['json'], 'id');
    }

    /** Réserve à une équipe une case du plan de saison ; rend l'id de la réservation. */
    private function reserve(string $team, string $venue, int $day, string $start): string
    {
        $created = $this->apiPost('reservations', [
            'teamId' => $this->smallClubTeamIds[$team],
            'venueId' => $this->smallClubVenueIds[$venue],
            'dayOfWeek' => $day,
            'startTime' => $start,
            'durationMinutes' => 90,
            'schedulePlanId' => null,
        ], $this->smallClubToken);
        $this->expectStatus($created, [200, 201], \sprintf('la réservation de %s', $team));

        return $this->smallClubStringField($created['json'], 'id');
    }

    /**
     * Une règle d'équipe souhaitée ; rend son id.
     *
     * @param array<string, mixed> $config
     */
    private function addRule(string $name, string $team, string $family, array $config): string
    {
        $created = $this->apiPost('constraints', [
            'name' => $name,
            'scope' => 'TEAM',
            'scopeTargetId' => $this->smallClubTeamIds[$team],
            'family' => $family,
            'ruleType' => 'PREFERRED',
            'config' => $config,
            'isActive' => true,
        ], $this->smallClubToken);
        $this->expectStatus($created, [200, 201], \sprintf('la règle « %s »', $name));

        return $this->smallClubStringField($created['json'], 'id');
    }

    /**
     * Le geste de l'écran d'édition : relire la ressource, changer un champ, tout renvoyer (PUT).
     *
     * @param array<string, mixed> $changes
     */
    private function putBack(string $resource, string $id, array $changes): void
    {
        $current = $this->apiGet(\sprintf('%s/%s', $resource, $id), $this->smallClubToken);
        $this->expectStatus($current, [200], \sprintf('la lecture de %s/%s', $resource, $id));
        $this->expectStatus($this->apiPut(\sprintf('%s/%s', $resource, $id), [...$current['json'], ...$changes], $this->smallClubToken), [200], \sprintf('la modification de %s/%s', $resource, $id));
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     * @param list<int>                              $expected
     */
    private function expectStatus(array $response, array $expected, string $gesture): void
    {
        if (!\in_array($response['status'], $expected, true)) {
            throw new RuntimeException(\sprintf('%s a répondu %d — corps : %s', $gesture, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }
    }
}
