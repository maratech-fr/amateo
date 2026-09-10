<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Les conflits d'un match disent la vérité, sur la stack qui tourne (axes planning lifecycle +
 * constraint semantics, P4-188 & P4-191).
 *
 * Cas Matéo : une fermeture racine (large, sans plan) découpée en milieu · fin, la racine
 * et le « milieu » PARTAGEANT leur date de départ. `CalendarEntryRepository::findActivePeriodsOrdered`
 * trie par startDate puis id (UUIDv4 aléatoire) : la racine peut donc précéder son enfant. Le plan
 * du « milieu » pointe une version portant un entraînement le jeudi à 20h45 ; un match à domicile
 * est posé ce même jeudi avec coup d'envoi à 20h45 (l'échauffement empiète sur la séance). Le radar
 * doit voir le conflit d'entraînement (la
 * période la plus ÉTROITE gagne — jamais un repli silencieux sur la racine sans plan, faux négatif
 * P4-188), et sa borne de début doit être l'heure murale du club, sans décalage (P4-191).
 *
 * Autosuffisant : équipe, coach, gymnase JETABLES (POST API) ; pointeur de socle posé s'il manque
 * puis restauré (à NULL) ; la suppression de la racine cascade ses enfants, leurs plans et leurs
 * versions. Aucune génération moteur (la version du « milieu » est peuplée à la main). La grille du
 * club réel n'est jamais touchée.
 */
final class ConflictTruthContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    /** Catégorie DISTINCTIVE (aucune vraie équipe ne la porte → zéro collision d'enveloppe). */
    private const string CUP_CATEGORY_NAME = 'BEHAT Coupe P4-194';

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $coachId = '';

    private string $venueId = '';

    private string $teamCoachId = '';

    private string $rootId = '';

    private string $pointedChildId = '';

    private string $debutPlanId = '';

    private string $versionId = '';

    private string $matchThursday = '';

    private string $fixtureId = '';

    private string $competitionId = '';

    private string $championshipFixtureId = '';

    private string $friendlyId = '';

    private string $cupCategoryId = '';

    private string $cupWindowId = '';

    private string $cupCompetitionId = '';

    private string $cupFixtureId = '';

    private string $cupSaturday = '';

    /** @var list<mixed> Conflits rendus par GET /api/fixtures/conflicts. */
    private array $conflicts = [];

    #[Given('le club de démonstration, connecté, dont le planning de saison est en vigueur')]
    public function leClubConnecteAvecSocleEnVigueur(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // Poser un match exige que le socle pointe une version (SocleGuard, 409 sinon).
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM schedule WHERE club_id=\'%s\' AND status=\'COMPLETED\' AND schedule_plan_id=(SELECT id FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\') ORDER BY created_at DESC LIMIT 1', $this->clubId, $this->clubId),
                admin: true,
            );
            if ('' === $completed) {
                throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
            }
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=\'%s\' WHERE club_id=\'%s\' AND type=\'SEASON\'', $completed, $this->clubId),
                admin: true,
            );
            $this->pointerSetBySelf = true;
        }
    }

    #[Given('une équipe, un coach et un gymnase jetables')]
    public function uneEquipeUnCoachUnGymnaseJetables(): void
    {
        // Cloner une catégorie et un palier de priorité valides du club (FK NOT NULL de team).
        $clone = $this->dbalScalar(
            \sprintf('SELECT sport_category_id || \'|\' || priority_tier_id || \'|\' || COALESCE(tier_order::text, \'0\') AS behatval FROM team WHERE club_id=\'%s\' LIMIT 1', $this->clubId),
            admin: true,
        );
        $parts = '' === $clone ? [] : explode('|', $clone);
        if (3 !== \count($parts)) {
            throw new RuntimeException('impossible de cloner une catégorie/palier valide (le club a-t-il des équipes seedées ?)');
        }
        [$sportCategoryId, $priorityTierId, $tierOrder] = $parts;

        $team = $this->apiPost('teams', [
            'name' => 'Équipe jetable (vérité conflits)',
            'sportCategoryId' => $sportCategoryId,
            'priorityTierId' => (int) $priorityTierId,
            'tierOrder' => (int) $tierOrder,
        ], $this->token);
        $this->teamId = $this->idOf($team, 'équipe jetable');

        $coach = $this->apiPost('coaches', [
            'firstName' => 'Coach',
            'lastName' => 'Jetable',
        ], $this->token);
        $this->coachId = $this->idOf($coach, 'coach jetable');

        $venue = $this->apiPost('venues', [
            'name' => 'Gymnase jetable (vérité conflits)',
            'source' => 'manual',
        ], $this->token);
        $this->venueId = $this->idOf($venue, 'gymnase jetable');

        $teamCoach = $this->apiPost('team_coaches', [
            'teamId' => $this->teamId,
            'coachId' => $this->coachId,
            'role' => 'MAIN',
        ], $this->token);
        $this->teamCoachId = $this->idOf($teamCoach, 'affectation coach↔équipe');
    }

    #[Given('une fermeture racine découpée en milieu et fin, la racine et le milieu partageant leur date de départ')]
    public function uneFermetureRacineDecoupee(): void
    {
        // Fenêtre FIXE et CLAIRE : hors des vacances scolaires seedées (Noël s'achève le 3 janv.,
        // l'hiver commence en février) et hors des fenêtres des autres scénarios (novembre 2026,
        // +49/+63/+77 j). Forme imposée par la règle de découpe : un enfant est une semaine
        // Mon→Dim entière, les semaines COMPLÈTES de la mère forment UN « milieu », la dernière
        // semaine entamée est le « fin ». Une racine qui part un lundi n'a donc pas de « début » :
        // son « milieu » PARTAGE sa date de départ — c'est l'aléa d'ordre de tri (`startDate, id`,
        // ids aléatoires) à reproduire. Le jeudi 14 janv. est couvert par la racine ET le milieu.
        $this->purgerDecorCalendaire();
        $this->rootId = $this->createEntry('Fermeture racine (vérité conflits)', '2027-01-11', '2027-01-26', null);
        $this->pointedChildId = $this->createEntry('Milieu', '2027-01-11', '2027-01-24', $this->rootId);
        $this->createEntry('Fin', '2027-01-25', '2027-01-31', $this->rootId);

        $this->matchThursday = '2027-01-14';
    }

    #[Given('le plan du milieu pointe une version portant un entraînement de l\'équipe le jeudi à 20h45 sur ce gymnase')]
    public function lePlanDuDebutPointeUneVersionAvecEntrainement(): void
    {
        // Chaque enfant naît AVEC son plan (rail « 1 entrée = 1 plan »).
        $this->debutPlanId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $this->pointedChildId),
            admin: true,
        );
        if ('' === $this->debutPlanId) {
            throw new RuntimeException('le plan du segment « milieu » est introuvable');
        }

        $version = $this->apiPost('schedules', ['schedulePlanId' => $this->debutPlanId, 'status' => 'DRAFT'], $this->token);
        $this->versionId = $this->idOf($version, 'version overlay du milieu');

        // La saison de la version : le créneau doit la porter pour rester tenant-visible.
        $seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule WHERE id=\'%s\'', $this->versionId),
            admin: true,
        );
        if ('' === $seasonId) {
            throw new RuntimeException('la saison de la version overlay est introuvable');
        }

        // Un entraînement JEUDI (ISO 4) 20:45, 90 min, sur l'équipe + le gymnase + le coach jetables.
        // Aucune génération moteur : la case est posée à la main (décision de cadrage).
        $this->dbalExec(
            \sprintf(
                'INSERT INTO schedule_slot_template'
                . ' (id, version, created_at, updated_at, club_id, season_id, schedule_id, team_id, venue_id, coach_id, day_of_week, start_time, duration_minutes, lock_level)'
                . ' VALUES (gen_random_uuid(), 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', 4, \'20:45\', 90, \'NONE\')',
                $this->clubId,
                $seasonId,
                $this->versionId,
                $this->teamId,
                $this->venueId,
                $this->coachId,
            ),
            admin: true,
        );

        // Le plan du « milieu » pointe cette version : elle devient l'overlay effectif de la période.
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET chosen_schedule_id=\'%s\' WHERE id=\'%s\'', $this->versionId, $this->debutPlanId),
            admin: true,
        );
    }

    #[Given('un match à domicile de l\'équipe ce jeudi, coup d\'envoi à 20h45, sur ce gymnase')]
    public function unMatchADomicileCeJeudiSoir(): void
    {
        $fixture = $this->apiPost('fixtures', [
            'teamId' => $this->teamId,
            'matchDate' => $this->matchThursday,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire jetable',
            'venueId' => $this->venueId,
            'kickoffTime' => '20:45',
        ], $this->token);
        $this->fixtureId = $this->idOf($fixture, 'match à domicile');
    }

    #[Given('une rencontre de championnat le samedi et un amical placé le dimanche du même week-end')]
    public function unChampionnatEtUnAmicalLeMemeWeekend(): void
    {
        // Week-end fixe et clair : 16 janv. 2027 (samedi) / 17 janv. (dimanche), hors
        // des vacances scolaires seedées et des fenêtres des autres scénarios.
        $saturday = '2027-01-16';
        $sunday = '2027-01-17';

        // Une compétition jetable → la rencontre du samedi n'est PAS un amical, elle fait
        // du week-end un « week-end de match ».
        $this->competitionId = $this->idOf(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Championnat jetable (vérité conflits)', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition jetable',
        );
        $this->championshipFixtureId = $this->idOf(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $saturday, 'homeAway' => 'AWAY', 'opponentLabel' => 'Adversaire championnat', 'competitionId' => $this->competitionId], $this->token),
            'rencontre de championnat',
        );

        // Amical à DOMICILE le dimanche du même week-end, puis posé (gymnase + heure).
        $this->friendlyId = $this->idOf(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $sunday, 'homeAway' => 'HOME', 'opponentLabel' => 'Amical de gala'], $this->token),
            'amical',
        );
        $placed = $this->apiPut(\sprintf('fixtures/%s', $this->friendlyId), [
            'teamId' => $this->teamId,
            'matchDate' => $sunday,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical de gala',
            'venueId' => $this->venueId,
            'kickoffTime' => '15:00',
            'status' => 'PLACED',
        ], $this->token);
        if (200 !== $placed['status']) {
            throw new RuntimeException(\sprintf('placement manuel de l\'amical en échec (HTTP %d)', $placed['status']));
        }
    }

    #[Given('une fenêtre de ligue étroite le samedi matin cadre cette équipe')]
    public function uneFenetreDeLigueEtroiteLeSamedi(): void
    {
        // Isolation TOTALE : une catégorie DISTINCTIVE réaffectée à l'équipe jetable
        // (aucune vraie équipe ne la porte → zéro collision d'enveloppe) + un niveau
        // connu ; la fenêtre de ligue est seedée sous la ligue EFFECTIVE du club
        // (patron LeagueMatchWindowRepository::effectiveLeague), samedi 10:00-10:30.
        // Purge d'un éventuel orphelin d'un run tué (catégorie distinctive → sûr).
        $this->dbalExec(\sprintf('DELETE FROM league_match_window WHERE category=\'%s\'', self::CUP_CATEGORY_NAME), admin: true);

        $sportId = $this->dbalScalar(
            \sprintf('SELECT sport_id AS behatval FROM sport_category WHERE id=(SELECT sport_category_id FROM team WHERE id=\'%s\')', $this->teamId),
            admin: true,
        );
        if ('' === $sportId) {
            throw new RuntimeException('le sport de la catégorie de l\'équipe jetable est introuvable');
        }
        $this->cupCategoryId = $this->dbalScalar('SELECT gen_random_uuid()::text AS behatval', admin: true);
        $this->dbalExec(\sprintf(
            'INSERT INTO sport_category (id, version, created_at, updated_at, club_id, sport_id, name, is_custom, sort_order)'
            . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', true, 0)',
            $this->cupCategoryId,
            $this->clubId,
            $sportId,
            self::CUP_CATEGORY_NAME,
        ), admin: true);
        // L'équipe porte cette catégorie distinctive + un niveau REGIONAL connu.
        $this->dbalExec(\sprintf('UPDATE team SET sport_category_id=\'%s\', level=\'REGIONAL\' WHERE id=\'%s\'', $this->cupCategoryId, $this->teamId), admin: true);

        // Ligue effective (miroir de LeagueMatchWindowRepository::effectiveLeague).
        $clubLeague = $this->dbalScalar(\sprintf('SELECT COALESCE(league, \'\') AS behatval FROM club WHERE id=\'%s\'', $this->clubId), admin: true);
        $hasWindows = '' !== $this->dbalScalar(\sprintf('SELECT id AS behatval FROM league_match_window WHERE league=\'%s\' LIMIT 1', $clubLeague), admin: true);
        $effectiveLeague = ('' !== $clubLeague && $hasWindows) ? $clubLeague : 'AURA';

        // Fenêtre étroite : samedi (ISO 6) 10:00-10:30 — un coup d'envoi le soir sera
        // hors fenêtre. Genre NULL = catalogue-large → cadre l'équipe quel que soit
        // son genre. Niveau REGIONAL = celui de l'équipe.
        $this->cupWindowId = $this->dbalScalar('SELECT gen_random_uuid()::text AS behatval', admin: true);
        $this->dbalExec(\sprintf(
            'INSERT INTO league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max)'
            . ' VALUES (\'%s\', now(), \'%s\', \'%s\', \'REGIONAL\', NULL, 6, \'10:00\', \'10:30\')',
            $this->cupWindowId,
            $effectiveLeague,
            self::CUP_CATEGORY_NAME,
        ), admin: true);

        $this->cupSaturday = '2027-01-16';
    }

    #[Given('une rencontre de coupe à domicile ce samedi, coup d\'envoi le soir hors de la fenêtre')]
    public function uneRencontreDeCoupeADomicileHorsFenetre(): void
    {
        // Une COUPE (competitionType CUP) → la rencontre PORTE une compétition, elle
        // n'est jamais un amical (competitionId non null).
        $this->cupCompetitionId = $this->idOf(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Coupe du Rhône (vérité conflits)', 'competitionType' => 'CUP'], $this->token),
            'compétition coupe',
        );
        // Coup d'envoi 18:00 le samedi → hors de la fenêtre 10:00-10:30 : violation
        // de ligue (HOME + coup d'envoi suffisent au détecteur, sans placement).
        $this->cupFixtureId = $this->idOf(
            $this->apiPost('fixtures', [
                'teamId' => $this->teamId,
                'matchDate' => $this->cupSaturday,
                'homeAway' => 'HOME',
                'opponentLabel' => 'Adversaire coupe',
                'competitionId' => $this->cupCompetitionId,
                'kickoffTime' => '18:00',
            ], $this->token),
            'rencontre de coupe',
        );
    }

    #[When('je demande les conflits des matchs')]
    public function jeDemandeLesConflits(): void
    {
        $response = $this->apiGet('fixtures/conflicts', $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('GET /api/fixtures/conflicts a répondu %d (200 attendu)', $response['status']));
        }
        $conflicts = $response['json']['conflicts'] ?? null;
        $this->conflicts = \is_array($conflicts) ? array_values($conflicts) : [];
    }

    #[Then('un conflit d\'entraînement porte ce match et sa borne de début est l\'heure murale sans décalage')]
    public function unConflitDEntrainementEnHeureMurale(): void
    {
        $start = null;
        foreach ($this->conflicts as $conflict) {
            if (!\is_array($conflict)) {
                continue;
            }
            $fixtureBlock = $conflict['fixture'] ?? null;
            $fixtureId = \is_array($fixtureBlock) ? ($fixtureBlock['fixtureId'] ?? null) : null;
            if ('MATCH_TRAINING' === ($conflict['type'] ?? null) && $fixtureId === $this->fixtureId) {
                $start = $conflict['start'] ?? null;

                break;
            }
        }

        if (!\is_string($start)) {
            throw new RuntimeException('aucun conflit MATCH_TRAINING ne porte ce match — le repli sur la racine sans plan a masqué l\'entraînement (faux négatif P4-188)');
        }

        // Heure murale, sans offset : « <date>T20:45:00 » (le recouvrement commence au coup d'envoi
        // de la case, aligné sur le kickoff) — jamais « …+02:00 ».
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T20:45:00$/', $start)) {
            throw new RuntimeException(\sprintf('borne de début attendue en heure murale « …T20:45:00 » sans décalage, obtenue « %s »', $start));
        }
    }

    #[Then('le radar signale l\'amical sur un créneau de match, pour cause de week-end de match')]
    public function leRadarSignaleLAmicalSurUnCreneau(): void
    {
        $found = null;
        foreach ($this->conflicts as $conflict) {
            if (!\is_array($conflict) || 'FRIENDLY_ON_MATCH_SLOT' !== ($conflict['type'] ?? null)) {
                continue;
            }
            $fixtureBlock = $conflict['fixture'] ?? null;
            $fixtureId = \is_array($fixtureBlock) ? ($fixtureBlock['fixtureId'] ?? null) : null;
            if ($fixtureId === $this->friendlyId) {
                $found = $conflict;

                break;
            }
        }

        if (null === $found) {
            throw new RuntimeException('aucune alerte FRIENDLY_ON_MATCH_SLOT ne porte cet amical — le week-end de match n\'a pas été vu');
        }
        $reasons = $found['reasons'] ?? null;
        if (!\is_array($reasons) || !\in_array('MATCH_WEEKEND', $reasons, true)) {
            throw new RuntimeException(\sprintf('l\'alerte n\'invoque pas MATCH_WEEKEND (raisons : %s)', \is_array($reasons) ? implode(',', array_map(strval(...), $reasons)) : 'aucune'));
        }
    }

    #[Then('le radar signale la coupe hors fenêtre de ligue, et jamais comme un amical sur créneau')]
    public function leRadarSignaleLaCoupeHorsFenetre(): void
    {
        $leagueViolation = null;
        $friendlyOnCup = null;
        foreach ($this->conflicts as $conflict) {
            if (!\is_array($conflict)) {
                continue;
            }
            $fixtureBlock = $conflict['fixture'] ?? null;
            $fixtureId = \is_array($fixtureBlock) ? ($fixtureBlock['fixtureId'] ?? null) : null;
            if ($fixtureId !== $this->cupFixtureId) {
                continue;
            }
            if ('LEAGUE_WINDOW_VIOLATION' === ($conflict['type'] ?? null)) {
                $leagueViolation = $conflict;
            }
            if ('FRIENDLY_ON_MATCH_SLOT' === ($conflict['type'] ?? null)) {
                $friendlyOnCup = $conflict;
            }
        }

        if (null === $leagueViolation) {
            throw new RuntimeException('aucune violation de fenêtre de ligue ne porte la coupe — elle n\'a pas été soumise à l\'enveloppe (traitée en amical ?)');
        }
        if (null !== $friendlyOnCup) {
            throw new RuntimeException('la coupe est signalée FRIENDLY_ON_MATCH_SLOT — une coupe est un VRAI match, jamais un amical');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        foreach ([$this->fixtureId, $this->friendlyId, $this->championshipFixtureId, $this->cupFixtureId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        foreach ([$this->competitionId, $this->cupCompetitionId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('competitions/%s', $id), $this->token);
            }
        }
        if ('' !== $this->teamCoachId) {
            $this->apiDelete(\sprintf('team_coaches/%s', $this->teamCoachId), $this->token);
        }
        // La suppression de la racine cascade ses enfants, leurs plans et leurs versions (dont la
        // version overlay et son créneau).
        if ('' !== $this->rootId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->rootId), $this->token);
        }

        // Ceinture et bretelles : rien ne doit référencer l'équipe/le gymnase avant leur suppression.
        if ('' !== $this->teamId) {
            $this->dbalExec(\sprintf('DELETE FROM schedule_slot_template WHERE team_id=\'%s\'', $this->teamId), admin: true);
        }
        if ('' !== $this->teamId) {
            $this->apiDelete(\sprintf('teams/%s', $this->teamId), $this->token);
        }
        if ('' !== $this->coachId) {
            $this->apiDelete(\sprintf('coaches/%s', $this->coachId), $this->token);
        }
        if ('' !== $this->venueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->venueId), $this->token);
        }

        // Décor P4-194 : la fenêtre de ligue seedée (globale, par catégorie
        // DISTINCTIVE → sûr) et la catégorie jetable (après la suppression de
        // l'équipe qui la référençait).
        $this->dbalExec(\sprintf('DELETE FROM league_match_window WHERE category=\'%s\'', self::CUP_CATEGORY_NAME), admin: true);
        if ('' !== $this->cupCategoryId) {
            $this->dbalExec(\sprintf('DELETE FROM sport_category WHERE id=\'%s\'', $this->cupCategoryId), admin: true);
        }

        // Le DELETE d'API d'une racine découpée peut être refusé sans lever ici : on
        // repasse en SQL pour ne jamais laisser de décor derrière soi.
        $this->purgerDecorCalendaire();

        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /**
     * Démonte tout le décor jetable et repose le pointeur du socle. Quoi qu'il arrive.
     */
    /**
     * Purge SQL du décor calendaire de CE scénario, par TITRE : les enfants et leurs
     * plans/versions/créneaux d'abord, la racine ensuite. Idempotente, et jouée AUSSI
     * avant la création — un run précédent tué (ou un DELETE d'API refusé, qui ne lève
     * rien ici) laissait sinon une racine derrière lui, et la découpe suivante partait
     * en 422 « semaines complètes ».
     */
    private function purgerDecorCalendaire(): void
    {
        if ('' === $this->clubId) {
            return;
        }
        $titles = '(\'Fermeture racine (vérité conflits)\', \'Milieu\', \'Fin\')';
        $scope = \sprintf('club_id=\'%s\' AND title IN %s', $this->clubId, $titles);
        $plans = \sprintf('SELECT id FROM schedule_plan WHERE calendar_entry_id IN (SELECT id FROM calendar_entry WHERE %s)', $scope);
        $this->dbalExec(\sprintf('DELETE FROM schedule_slot_template WHERE schedule_id IN (SELECT id FROM schedule WHERE schedule_plan_id IN (%s))', $plans), admin: true);
        $this->dbalExec(\sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE id IN (%s)', $plans), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM schedule WHERE schedule_plan_id IN (%s)', $plans), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM schedule_plan WHERE calendar_entry_id IN (SELECT id FROM calendar_entry WHERE %s)', $scope), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE %s AND parent_entry_id IS NOT NULL', $scope), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE %s', $scope), admin: true);
    }

    private function createEntry(string $title, string $start, string $end, ?string $parentEntryId): string
    {
        $body = [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
        ];
        if (null !== $parentEntryId) {
            $body['parentEntryId'] = $parentEntryId;
        }

        return $this->idOf($this->apiPost('calendar_entries', $body, $this->token), \sprintf('entrée « %s »', $title));
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function idOf(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour (HTTP %d) : %s', $what, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }

        return $id;
    }
}
