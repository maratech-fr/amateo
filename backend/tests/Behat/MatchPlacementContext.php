<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Placement des matchs de bout en bout, sur la stack qui tourne.
 *
 * Reproduit ce que faisait le smoke « place-matches », désormais sur le rail ASYNCHRONE
 * de POST /api/fixtures/place (202 + run enfilé, résultat lu via GET placement-run une fois
 * terminal, patron de la génération). On possède TOUTES les ressources dont dépendent les
 * assertions (deux équipes + un gymnase jetables, une fenêtre samedi, un
 * créneau idéal samedi 15:30, deux fixtures) pour que la donnée WE réelle du seed ne
 * les perturbe pas. Deux gardes de restauration, exécutées quoi qu'il arrive :
 *   - la raison `no_access_window` est CLUB-WIDE → on sauve puis supprime toute
 *     fenêtre d'accès dominicale du club, et on la RECRÉE en fin de scénario ;
 *   - le module matchs exige un planning de saison en vigueur → si le pointeur
 *     était vide on le pose, et on le restaure (à NULL) en sortie.
 */
final class MatchPlacementContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private const int POLL_INTERVAL_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 650;

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $secondTeamId = '';

    private string $venueId = '';

    private string $windowId = '';

    private string $habitIdealId = '';

    private string $matchRuleId = '';

    private string $competitionId = '';

    private string $competitionId2 = '';

    private string $fxSat = '';

    private string $fxSat2 = '';

    private string $fxSun = '';

    private string $friendlyId = '';

    private string $coachId = '';

    private string $teamCoachAId = '';

    private string $teamCoachBId = '';

    private string $awayId = '';

    private string $homeId = '';

    private string $nightAnchorId = '';

    private string $travelCode = '';

    private string $closureEntryId = '';

    private string $closureConstraintId = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $manualPlaceResponse = ['status' => 0, 'json' => []];

    private string $saturday = '';

    private string $sunday = '';

    private string $otherSaturday = '';

    /** @var array<mixed> */
    private array $otherFixture = [];

    /** @var list<array{id: string, venueId: string, startTime: string, endTime: string}> */
    private array $sundayWindows = [];

    /** @var list<array{id: string, venueId: string, startTime: string, endTime: string}> */
    private array $saturdayWindows = [];

    /** @var array<mixed> */
    private array $placeResult = [];

    /** @var array{status: int, json: array<mixed>} */
    private array $secondPlaceResponse = ['status' => 0, 'json' => []];

    /** @var array<mixed> */
    private array $satFixture = [];

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

        // Le module matchs est gardé par un planning de saison EN VIGUEUR
        // (SocleGuard). S'il n'en pointe aucun, on en pose un le temps du scénario.
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM schedule WHERE club_id=\'%s\' AND status=\'COMPLETED\' ORDER BY created_at DESC LIMIT 1', $this->clubId),
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

        // Dates comptées depuis le JOUR DU CLUB (Europe/Paris) et non l'UTC du
        // serveur : près de minuit, strtotime() côté UTC pouvait viser un autre
        // samedi que celui affiché au gestionnaire.
        $paris = new DateTimeZone('Europe/Paris');
        $this->saturday = new DateTimeImmutable('now', $paris)->modify('next saturday')->format('Y-m-d');
        $this->sunday = new DateTimeImmutable($this->saturday, $paris)->modify('+1 day')->format('Y-m-d');
    }

    #[Given('deux équipes et un gymnase jetables')]
    public function deuxEquipesEtUnGymnaseJetables(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }

        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'Match Jetable A', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe A');
        $this->secondTeamId = $this->createdId($this->apiPost('teams', ['name' => 'Match Jetable B', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe B');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => 'Gym Jetable Matchs', 'source' => 'manual'], $this->token), 'gymnase');
    }

    #[Given('une fenêtre d\'accès le samedi de 14h00 à 18h00 sur ce gymnase')]
    public function uneFenetreLeSamedi(): void
    {
        $this->windowId = $this->createdId(
            $this->apiPost('venue_match_windows', ['venueId' => $this->venueId, 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '18:00'], $this->token),
            'fenêtre d\'accès',
        );
    }

    #[Given('un créneau idéal le samedi à 15h30 sur ce gymnase pour la première équipe')]
    public function unCreneauIdealSamedi1530(): void
    {
        $this->habitIdealId = $this->createdId(
            $this->apiPost('team_match_habits', ['teamId' => $this->teamId, 'dayOfWeek' => 6, 'kickoffTime' => '15:30', 'venueId' => $this->venueId, 'week' => 'A'], $this->token),
            'créneau idéal',
        );
    }

    #[Given('un créneau idéal le samedi à 21h30 sur ce gymnase pour la première équipe')]
    public function unCreneauIdealSamedi2130(): void
    {
        $this->habitIdealId = $this->createdId(
            $this->apiPost('team_match_habits', ['teamId' => $this->teamId, 'dayOfWeek' => 6, 'kickoffTime' => '21:30', 'venueId' => $this->venueId, 'week' => 'A'], $this->token),
            'créneau idéal tardif',
        );
    }

    #[Given('une fenêtre d\'accès tardive le samedi de 20h00 à 23h00 sur ce gymnase')]
    public function uneFenetreTardiveLeSamedi(): void
    {
        $this->windowId = $this->createdId(
            $this->apiPost('venue_match_windows', ['venueId' => $this->venueId, 'dayOfWeek' => 6, 'startTime' => '20:00', 'endTime' => '23:00'], $this->token),
            'fenêtre d\'accès tardive',
        );
    }

    #[Given('une règle du club « pas après 21h » le samedi')]
    public function uneRegleClubPasApres21h(): void
    {
        $this->matchRuleId = $this->createdId(
            $this->apiPost('match_constraints', ['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMax' => '21:00'], $this->token),
            'règle du club « pas après 21h »',
        );
    }

    #[Given('une règle du club « pas après 18h » le samedi')]
    public function uneRegleClubPasApres18h(): void
    {
        $this->matchRuleId = $this->createdId(
            $this->apiPost('match_constraints', ['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMax' => '18:00'], $this->token),
            'règle du club « pas après 18h »',
        );
    }

    #[Then('le match du samedi est placé par le solveur, au plus tard à 21h00')]
    public function leMatchDuSamediPlaceAuPlusTard21h(): void
    {
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }
        if ('SOLVER' !== ($this->satFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match du samedi n\'a pas été placé par le solveur');
        }
        // La règle HARD « pas après 21h » borne le coup d'envoi ; l'idéal 21h30 est écarté.
        $kickoff = $this->kickoff();
        if ($kickoff > '21:00') {
            throw new RuntimeException(\sprintf('coup d\'envoi %s après 21h00 — la règle HARD du club n\'a pas été honorée', $kickoff));
        }
        if (str_starts_with($kickoff, '21:30')) {
            throw new RuntimeException('le créneau idéal 21h30, qui viole la règle, a été retenu malgré tout');
        }
    }

    #[Given('une interdiction du gymnase jetable pour la première équipe')]
    public function uneInterdictionDuGymnasePourLaPremiereEquipe(): void
    {
        // P4-272 ④ — une règle de match scope TEAM : l'équipe A ne joue jamais dans le
        // gymnase jetable. HARD, sans jour ni horaire (une interdiction vaut tout le temps).
        $this->matchRuleId = $this->createdId(
            $this->apiPost('match_constraints', ['scope' => 'TEAM', 'scopeTargetId' => $this->teamId, 'venueId' => $this->venueId, 'ruleType' => 'HARD'], $this->token),
            'interdiction de gymnase',
        );
    }

    #[Then('le match du samedi est placé par le solveur, hors du gymnase interdit')]
    public function leMatchDuSamediPlaceHorsGymnaseInterdit(): void
    {
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }
        if ('SOLVER' !== ($this->satFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match du samedi n\'a pas été placé par le solveur');
        }
        // Le gymnase interdit ne peut JAMAIS être retenu (retiré du domaine).
        if (($this->satFixture['venueId'] ?? null) === $this->venueId) {
            throw new RuntimeException('le match a atterri sur le gymnase INTERDIT — le solveur ne devrait jamais l\'y poser');
        }
    }

    #[Then('le match du samedi reste sans créneau, faute d\'un gymnase interdit')]
    public function leMatchDuSamediResteSansCreneauGymnaseInterdit(): void
    {
        $reason = null;
        $unplaced = $this->placeResult['unplaced'] ?? [];
        foreach (\is_array($unplaced) ? $unplaced : [] as $entry) {
            if (\is_array($entry) && ($entry['matchId'] ?? null) === $this->fxSat) {
                $reason = $entry['reason'] ?? null;

                break;
            }
        }

        if ('team_venue_forbidden' !== $reason) {
            throw new RuntimeException(\sprintf('le match aurait dû rester sans créneau pour un gymnase interdit, raison obtenue « %s »', \is_string($reason) ? $reason : 'aucune'));
        }
    }

    #[Given('un entraîneur de la première équipe')]
    public function unEntraineurDeLaPremiereEquipe(): void
    {
        $this->coachId = $this->createdId(
            $this->apiPost('coaches', ['firstName' => 'Coach', 'lastName' => 'Indispo'], $this->token),
            'entraîneur',
        );
        $this->teamCoachAId = $this->createdId(
            $this->apiPost('team_coaches', ['teamId' => $this->teamId, 'coachId' => $this->coachId, 'role' => 'MAIN'], $this->token),
            'affectation coach↔équipe',
        );
    }

    #[Given('une indisponibilité de cet entraîneur le samedi de 14h00 à 16h00')]
    public function uneIndisponibiliteDuCoachSamedi(): void
    {
        // P4-272 ⑤ — une règle de match scope COACH : le coach est indisponible le samedi
        // 14h00-16h00. TOUJOURS SOFT (PREFERRED) : le placement l'évite sans jamais bloquer.
        $this->matchRuleId = $this->createdId(
            $this->apiPost('match_constraints', ['scope' => 'COACH', 'scopeTargetId' => $this->coachId, 'ruleType' => 'PREFERRED', 'daysOfWeek' => [6], 'kickoffMin' => '14:00', 'kickoffMax' => '16:00'], $this->token),
            'indisponibilité de coach',
        );
    }

    #[Then('le match du samedi est placé par le solveur, après 16h00')]
    public function leMatchDuSamediPlaceApres16h(): void
    {
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }
        if ('SOLVER' !== ($this->satFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match du samedi n\'a pas été placé par le solveur');
        }
        // La pénalité d'indisponibilité (SOFT) écarte le créneau idéal 15h30, dans la plage
        // 14h00-16h00, au profit d'un créneau hors plage — l'alternative existant, le match
        // se pose APRÈS 16h00 (comparaison HH:MM, insensible aux secondes du format API).
        $hhmm = substr($this->kickoff(), 0, 5);
        if ($hhmm <= '16:00') {
            throw new RuntimeException(\sprintf('coup d\'envoi %s dans l\'indisponibilité du coach (14h00-16h00) — la pénalité n\'a pas orienté le placement', $hhmm));
        }
    }

    #[Then('le match du samedi reste sans créneau, faute d\'une règle du club')]
    public function leMatchDuSamediResteSansCreneauRegleClub(): void
    {
        $reason = null;
        $unplaced = $this->placeResult['unplaced'] ?? [];
        foreach (\is_array($unplaced) ? $unplaced : [] as $entry) {
            if (\is_array($entry) && ($entry['matchId'] ?? null) === $this->fxSat) {
                $reason = $entry['reason'] ?? null;

                break;
            }
        }

        if ('club_rule_no_slot' !== $reason) {
            throw new RuntimeException(\sprintf('le match aurait dû rester sans créneau pour une règle du club, raison obtenue « %s »', \is_string($reason) ? $reason : 'aucune'));
        }
    }

    #[Given('un match à domicile le samedi et un autre le dimanche')]
    public function deuxMatchsADomicile(): void
    {
        // Le solveur ne place plus les amicaux (P4-193) : ces domiciles doivent être
        // rattachés à une COMPÉTITION pour être proposés au placement.
        $this->competitionId = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Championnat jetable', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition',
        );
        $this->fxSat = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire samedi', 'competitionId' => $this->competitionId], $this->token),
            'match du samedi',
        );
        $this->fxSun = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->sunday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire dimanche', 'competitionId' => $this->competitionId], $this->token),
            'match du dimanche',
        );
    }

    #[Given('deux matchs à domicile le même samedi, un par équipe')]
    public function deuxDomicilesLeMemeSamedi(): void
    {
        // Deux domiciles de compétition, un par équipe, le MÊME samedi sur le
        // même gymnase : sous D1 (P4-203) la salle ne tient que la durée du match
        // (plus l'échauffement), donc deux rencontres enchaînées tiennent dans la
        // fenêtre 14h00-18h00 là où l'ancienne empreinte 2h15 en aurait recalé une.
        $this->competitionId = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Championnat jetable A', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition A',
        );
        $this->competitionId2 = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->secondTeamId, 'name' => 'Championnat jetable B', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition B',
        );
        $this->fxSat = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire A', 'competitionId' => $this->competitionId], $this->token),
            'premier match du samedi',
        );
        $this->fxSat2 = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->secondTeamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire B', 'competitionId' => $this->competitionId2], $this->token),
            'second match du samedi',
        );
    }

    #[Given('une large fenêtre d\'accès le samedi de 14h00 à 23h30 sur ce gymnase')]
    public function uneLargeFenetreLeSamedi(): void
    {
        $this->windowId = $this->createdId(
            $this->apiPost('venue_match_windows', ['venueId' => $this->venueId, 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '23:30'], $this->token),
            'fenêtre d\'accès large',
        );
    }

    #[Given('un entraîneur qui partage les deux équipes')]
    public function unEntraineurPartage(): void
    {
        $this->coachId = $this->createdId(
            $this->apiPost('coaches', ['firstName' => 'Coach', 'lastName' => 'Partagé'], $this->token),
            'entraîneur',
        );
        $this->teamCoachAId = $this->createdId(
            $this->apiPost('team_coaches', ['teamId' => $this->teamId, 'coachId' => $this->coachId, 'role' => 'MAIN'], $this->token),
            'affectation coach↔équipe A',
        );
        $this->teamCoachBId = $this->createdId(
            $this->apiPost('team_coaches', ['teamId' => $this->secondTeamId, 'coachId' => $this->coachId, 'role' => 'MAIN'], $this->token),
            'affectation coach↔équipe B',
        );
    }

    #[Given('un match extérieur de la seconde équipe le samedi à 14h00, à long trajet aller-retour')]
    public function unExterieurALongTrajet(): void
    {
        // Un match extérieur de l'équipe B, coup d'envoi 14h00 — il occupe le coach.
        $this->awayId = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->secondTeamId, 'matchDate' => $this->saturday, 'homeAway' => 'AWAY', 'opponentLabel' => 'Loin FC', 'kickoffTime' => '14:00'], $this->token),
            'match extérieur',
        );

        // Trajet ALLER SIMPLE connu (180 min) rattaché au code organisme de l'adversaire.
        // Le champ opponentOrganismeCode n'est pas exposé au POST (posé à l'import) : on
        // l'estampille en base, comme la fédération le ferait, puis on injecte le trajet
        // (aller-retour = 2 × 180 = 360 min côté projection). Nettoyés en fin de scénario.
        // La saison = celle de la rencontre qu'on vient de créer, LUE À L'API (jamais parsée
        // depuis la sortie console d'un `run-sql`) : c'est exactement la saison courante que
        // la projection de trajet interroge.
        $seasonId = $this->apiGet(\sprintf('fixtures/%s', $this->awayId), $this->token)['json']['seasonId'] ?? null;
        if (!\is_string($seasonId) || '' === $seasonId) {
            throw new RuntimeException('la saison de la rencontre extérieure est introuvable');
        }
        $this->travelCode = 'BEHATD3' . substr(md5($this->awayId), 0, 8);
        // La rencontre pointe SA salle (« SALLE LOIN ») + son code organisme.
        $this->dbalExec(
            \sprintf('UPDATE fixture SET opponent_organisme_code=\'%s\', fbi_venue_label=\'SALLE LOIN\' WHERE id=\'%s\'', $this->travelCode, $this->awayId),
            admin: true,
        );
        // Amendement 2026-09-20 — le trajet est (1) un APPARIEMENT libellé→gymnase et (2) une
        // CONSTANTE en cache (siège → gymnase). Siège du club localisé, lien « SALLE LOIN » vers
        // un gymnase fixe (45.80, 5.00) et trajet aller simple 180 min en cache.
        $this->dbalExec(
            \sprintf('UPDATE club SET latitude=45.70, longitude=4.90 WHERE id=\'%s\'', $this->clubId),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO opponent_venue_link (id, version, created_at, updated_at, club_id, opponent_organisme_code, fbi_label, fbi_label_norm, venue_external_ref, venue_label, latitude, longitude, source) '
                . 'VALUES (gen_random_uuid(), 1, now(), now(), \'%s\', \'%s\', \'SALLE LOIN\', \'salle loin\', NULL, \'Salle Loin\', 45.80, 5.00, \'MANUAL\')',
                $this->clubId,
                $this->travelCode,
            ),
            admin: true,
        );
        // Aller simple VOLONTAIREMENT très long (720 min) : la fenêtre PERSONNE de l'extérieur
        // (radar) couvre alors tout le samedi, si bien que le conflit avec le domicile est
        // DÉTERMINISTE quelle que soit l'heure retenue par le solveur dans le décor du club.
        $this->dbalExec(
            \sprintf(
                'INSERT INTO club_travel_cache (id, club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon, minutes, resolved_at) '
                . 'VALUES (gen_random_uuid(), \'%s\', \'car\', 45.70000, 4.90000, 45.80000, 5.00000, 720, now())',
                $this->clubId,
            ),
            admin: true,
        );
        unset($seasonId);
    }

    #[Given('une fermeture du calendrier couvrant le samedi sur ce gymnase')]
    public function uneFermetureDuCalendrierCouvrantLeSamedi(): void
    {
        // P4-300 — une fermeture `venue_closed` du calendrier : une entrée de période PORTEUSE
        // (son id borne le repli legacy) + une contrainte FACILITY datée sur le gymnase jetable.
        // Semée en base (admin) : c'est un FAIT daté, pas un geste exposé à un POST simple.
        $paris = new DateTimeZone('Europe/Paris');
        $from = new DateTimeImmutable($this->saturday, $paris)->modify('-3 days')->format('Y-m-d');
        $to = new DateTimeImmutable($this->saturday, $paris)->modify('+3 days')->format('Y-m-d');
        $seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $seasonId) {
            throw new RuntimeException('aucune saison pour poser la fermeture du calendrier');
        }

        $this->closureEntryId = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO calendar_entry (id, created_at, updated_at, club_id, season_id, kind, title, start_date, end_date, is_disruptive, period_type, status)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'period\', \'Fermeture Matéo (fonctionnel matchs)\', \'%s\', \'%s\', false, \'closure\', \'active\')',
                $this->closureEntryId,
                $this->clubId,
                $seasonId,
                $from,
                $to,
            ),
            admin: true,
        );

        $this->closureConstraintId = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO "constraint" (id, version, created_at, updated_at, club_id, season_id, name, scope, scope_target_id, family, rule_type, config, calendar_entry_id, is_active, sort_order)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'Gymnase en travaux\', \'FACILITY\', \'%s\', \'FACILITY\', \'HARD\', \'{"type":"venue_closed","startDate":"%s","endDate":"%s"}\', \'%s\', true, 0)',
                $this->closureConstraintId,
                $this->clubId,
                $seasonId,
                $this->venueId,
                $from,
                $to,
                $this->closureEntryId,
            ),
            admin: true,
        );
    }

    #[Given('un match à domicile de la première équipe le samedi, déjà posé dans ce gymnase à 15h00')]
    public function unDomicileDejaPoseDansCeGymnase(): void
    {
        $this->competitionId = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Championnat jetable posé', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition',
        );
        $this->fxSat = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire posé', 'competitionId' => $this->competitionId], $this->token),
            'match à domicile posé',
        );
        // Posé À LA MAIN dans le gymnase jetable à 15h00, AVANT la fermeture (le PUT est alors libre).
        $result = $this->apiPut(\sprintf('fixtures/%s', $this->fxSat), [
            'teamId' => $this->teamId,
            'matchDate' => $this->saturday,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire posé',
            'competitionId' => $this->competitionId,
            'venueId' => $this->venueId,
            'kickoffTime' => '15:00',
            'status' => 'PLACED',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le placement manuel initial a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('le match du samedi est placé par le solveur, hors du gymnase fermé')]
    public function leMatchDuSamediPlaceHorsGymnaseFerme(): void
    {
        // P4-300 — une fermeture `venue_closed` du calendrier RETIRE le gymnase du domaine du
        // solveur, exactement comme une interdiction d'équipe. Le créneau idéal (15h30 sur le
        // gymnase jetable) aurait attiré le domicile sur ce gymnase SANS la fermeture — le
        // solveur le pose donc dans un AUTRE gymnase du club (les gymnases du seed gardent leurs
        // fenêtres du samedi), JAMAIS dans le gymnase fermé. C'est le témoin de la promesse : la
        // fermeture arrive bien au payload et vide le gymnase pour les matchs comme pour les
        // entraînements.
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }
        if ('SOLVER' !== ($this->satFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match du samedi n\'a pas été placé par le solveur');
        }
        // Le gymnase fermé ne peut JAMAIS être retenu (retiré du domaine par la fermeture).
        if (($this->satFixture['venueId'] ?? null) === $this->venueId) {
            throw new RuntimeException('le match a atterri sur le gymnase FERMÉ — la fermeture du calendrier n\'a pas été honorée côté matchs');
        }
    }

    #[Then('poser ce match à la main dans le gymnase fermé est refusé, avec un message de fermeture')]
    public function poserAlaMainDansLeGymnaseFermeEstRefuse(): void
    {
        $this->manualPlaceResponse = $this->apiPut(\sprintf('fixtures/%s', $this->fxSat), [
            'teamId' => $this->teamId,
            'matchDate' => $this->saturday,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire domicile',
            'competitionId' => $this->competitionId,
            'venueId' => $this->venueId,
            'kickoffTime' => '15:00',
            'status' => 'PLACED',
        ], $this->token);

        if (422 !== $this->manualPlaceResponse['status']) {
            throw new RuntimeException(\sprintf('le placement manuel dans un gymnase fermé aurait dû être refusé (422), obtenu %d', $this->manualPlaceResponse['status']));
        }
        // Le message vit dans les violations (rail 422 `$this->refuse`) ; `detail`/`hydra:description`
        // les concatènent. On ratisse les deux pour être robuste au format sérialisé.
        $json = $this->manualPlaceResponse['json'];
        $message = '';
        foreach (['detail', 'hydra:description'] as $key) {
            if (\is_string($json[$key] ?? null)) {
                $message .= $json[$key];
            }
        }
        $violations = $json['violations'] ?? [];
        foreach (\is_array($violations) ? $violations : [] as $violation) {
            if (\is_array($violation) && \is_string($violation['message'] ?? null)) {
                $message .= $violation['message'];
            }
        }
        if (!str_contains($message, 'fermée')) {
            throw new RuntimeException(\sprintf('le refus n\'a pas porté de message de fermeture (reçu « %s »)', $message));
        }
    }

    #[Then('le radar signale la fermeture de ce gymnase pour le match du samedi')]
    public function leRadarSignaleLaFermeture(): void
    {
        $response = $this->apiGet('fixtures/conflicts', $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('GET /api/fixtures/conflicts a répondu %d (200 attendu)', $response['status']));
        }
        $conflicts = $response['json']['conflicts'] ?? [];
        foreach (\is_array($conflicts) ? $conflicts : [] as $conflict) {
            if (!\is_array($conflict) || 'VENUE_UNAVAILABLE' !== ($conflict['type'] ?? null)) {
                continue;
            }
            if (($conflict['venueId'] ?? null) !== $this->venueId) {
                continue;
            }
            $fixture = \is_array($conflict['fixture'] ?? null) ? $conflict['fixture'] : [];
            if (($fixture['fixtureId'] ?? null) === $this->fxSat) {
                return;
            }
        }

        throw new RuntimeException('le radar n\'a pas signalé la fermeture du gymnase pour le match du samedi');
    }

    #[Given('un match à domicile de la première équipe le samedi à placer')]
    public function unDomicileAPlacer(): void
    {
        $this->competitionId = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Championnat jetable D3', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition',
        );
        $this->homeId = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire domicile', 'competitionId' => $this->competitionId], $this->token),
            'match à domicile',
        );
        // Réutilise le pipeline de lecture du step « je lance le placement ».
        $this->fxSat = $this->homeId;
    }

    #[Given('un match posé à la main le samedi à 23h00 sur ce gymnase, finissant après minuit')]
    public function uneAncreDeNuitFinissantApresMinuit(): void
    {
        // ENG-48 — un match posé À LA MAIN à 23h00 : avec sa durée (≥ 75 min) il FINIT après
        // minuit (23:00 + 75 = 00:15). C'est une ancre FIXED pour le solveur, hors de la fenêtre
        // d'accès (le geste manuel est libre), sur le MÊME gymnase que le match à placer.
        $competitionId = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->secondTeamId, 'name' => 'Championnat jetable nuit', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition de l\'ancre de nuit',
        );
        $this->nightAnchorId = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->secondTeamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire de nuit', 'competitionId' => $competitionId], $this->token),
            'match de nuit posé à la main',
        );
        // Posé À LA MAIN (MANUAL) à 23h00 sur le gymnase jetable : ancre FIXED du payload.
        $this->dbalExec(
            \sprintf(
                'UPDATE fixture SET status=\'PLACED\', placement_source=\'MANUAL\', venue_id=\'%s\', kickoff_time=\'23:00:00\' WHERE id=\'%s\'',
                $this->venueId,
                $this->nightAnchorId,
            ),
            admin: true,
        );
    }

    #[Then('l\'autre match du samedi est placé par le solveur malgré l\'ancre de nuit')]
    public function lAutreMatchEstPlaceMalgreLAncre(): void
    {
        // ENG-48 — l'ancre finissant après minuit NE rend PLUS tout le groupe (gymnase, date)
        // infaisable : le match à placer à côté d'elle est posé normalement par le solveur.
        $unplaced = \is_array($this->placeResult['unplaced'] ?? null) ? $this->placeResult['unplaced'] : [];
        foreach ($unplaced as $entry) {
            if (\is_array($entry) && ($entry['matchId'] ?? null) === $this->fxSat) {
                throw new RuntimeException('le match à placer est resté non placé — l\'ancre finissant après minuit a vidé tout le groupe (régression ENG-48)');
            }
        }

        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match à placer n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }
        if ('SOLVER' !== ($this->satFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match à placer n\'a pas été posé par le solveur');
        }
    }

    #[Then('le match à domicile est placé par le solveur, sans être bloqué par l\'extérieur')]
    public function leDomicileEstPlaceParLeSolveur(): void
    {
        // P4-240 ③ (décision B) : le solveur IGNORE l'empreinte personne de l'extérieur — il
        // ne l'empêche donc jamais de placer le domicile. Le domicile ressort PLACED, par le
        // SOLVER, quelle que soit l'heure retenue (le décor du club en décide ; on ne borne pas
        // l'heure, sensible aux autres rencontres du même samedi). C'est le « on gère après » :
        // le solveur pose, le radar (step suivant) signale le chevauchement.
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match à domicile n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }
        $source = $this->satFixture['placementSource'] ?? null;
        if ('SOLVER' !== $source) {
            throw new RuntimeException(\sprintf('le match à domicile n\'a pas été placé par le solveur (source « %s »)', \is_string($source) ? $source : 'inconnue'));
        }
    }

    #[Then('le radar signale le conflit de personne entre le domicile et l\'extérieur du coach partagé')]
    public function leRadarSignaleLeConflitDePersonne(): void
    {
        // Le placement ignore l'extérieur, mais le RADAR le voit : le coach partagé est en
        // double (match à domicile × match extérieur qui se chevauchent) → MATCH_MATCH sur
        // son identifiant, portant les deux rencontres. C'est le « on gère après » de la décision B.
        $response = $this->apiGet('fixtures/conflicts', $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('GET /api/fixtures/conflicts a répondu %d (200 attendu)', $response['status']));
        }
        $conflicts = $response['json']['conflicts'] ?? [];
        foreach (\is_array($conflicts) ? $conflicts : [] as $conflict) {
            if (!\is_array($conflict) || 'MATCH_MATCH' !== ($conflict['type'] ?? null) || ($conflict['coachId'] ?? null) !== $this->coachId) {
                continue;
            }
            $ids = [
                \is_array($conflict['left'] ?? null) ? ($conflict['left']['fixtureId'] ?? null) : null,
                \is_array($conflict['right'] ?? null) ? ($conflict['right']['fixtureId'] ?? null) : null,
            ];
            if (\in_array($this->homeId, $ids, true) && \in_array($this->awayId, $ids, true)) {
                return;
            }
        }

        throw new RuntimeException('le radar n\'a pas signalé le conflit de personne attendu entre le domicile et l\'extérieur du coach partagé');
    }

    #[Given('un amical à domicile le samedi sur ce gymnase, sans créneau posé')]
    public function unAmicalADomicile(): void
    {
        // Amical = aucune compétition (competitionId absent).
        $this->friendlyId = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Amical de gala'], $this->token),
            'amical',
        );
    }

    #[Given('plus aucune fenêtre d\'accès le dimanche dans tout le club')]
    public function plusAucuneFenetreLeDimanche(): void
    {
        $windows = $this->apiGet('venue_match_windows?itemsPerPage=100', $this->token);
        foreach ($this->members($windows['json']) as $window) {
            if (7 !== ($window['dayOfWeek'] ?? null)) {
                continue;
            }
            $id = $window['id'] ?? null;
            $venueId = $window['venueId'] ?? null;
            $startTime = $window['startTime'] ?? null;
            $endTime = $window['endTime'] ?? null;
            if (!\is_string($id) || !\is_string($venueId) || !\is_string($startTime) || !\is_string($endTime)) {
                continue;
            }
            $this->sundayWindows[] = ['id' => $id, 'venueId' => $venueId, 'startTime' => $startTime, 'endTime' => $endTime];
            $this->apiDelete(\sprintf('venue_match_windows/%s', $id), $this->token);
        }
    }

    /**
     * Le seed BCCL pose des fenêtres samedi sur Matéo/Armand/Debarros : sans elles écartées, deux
     * domiciles SANS préférence (aucun créneau idéal) sont plaçables sur PLUSIEURS gymnases, et
     * le solveur — indifférent entre gymnases à l'objectif — n'a aucune raison de les co-localiser
     * sur le gymnase jetable (le warm-start glouton, déterministe, retient d'ailleurs le premier
     * gymnase par ordre d'id). On rend le décor déterministe en ne laissant QUE la fenêtre du
     * gymnase jetable ce samedi ; l'intention « deux domiciles à 2h d'écart tiennent dans le MÊME
     * gymnase » (capacité D1) est intacte, recréation en fin de scénario (comme le dimanche).
     */
    #[Given('le club n\'offre aucune autre fenêtre d\'accès le samedi')]
    public function plusAucuneAutreFenetreLeSamedi(): void
    {
        $windows = $this->apiGet('venue_match_windows?itemsPerPage=100', $this->token);
        foreach ($this->members($windows['json']) as $window) {
            if (6 !== ($window['dayOfWeek'] ?? null)) {
                continue;
            }
            $id = $window['id'] ?? null;
            if (!\is_string($id) || $id === $this->windowId) {
                continue; // on garde la fenêtre du gymnase jetable
            }
            $venueId = $window['venueId'] ?? null;
            $startTime = $window['startTime'] ?? null;
            $endTime = $window['endTime'] ?? null;
            if (!\is_string($venueId) || !\is_string($startTime) || !\is_string($endTime)) {
                continue;
            }
            $this->saturdayWindows[] = ['id' => $id, 'venueId' => $venueId, 'startTime' => $startTime, 'endTime' => $endTime];
            $this->apiDelete(\sprintf('venue_match_windows/%s', $id), $this->token);
        }
    }

    #[When('je lance le placement des matchs')]
    public function jeLanceLePlacement(): void
    {
        $this->placeResult = $this->lancerEtAttendreLeRun([]);

        if ('' !== $this->fxSat) {
            $fixture = $this->apiGet(\sprintf('fixtures/%s', $this->fxSat), $this->token);
            if (200 !== $fixture['status']) {
                throw new RuntimeException(\sprintf('lecture du match du samedi en échec (HTTP %d)', $fixture['status']));
            }
            $this->satFixture = $fixture['json'];
        }
    }

    #[Then('l\'amical n\'est jamais proposé au solveur et reste sans créneau')]
    public function lAmicalResteSansCreneau(): void
    {
        // Absent des non-plaçables : le solveur ne l'a JAMAIS reçu (pas « rejeté »).
        $unplaced = $this->placeResult['unplaced'] ?? [];
        foreach (\is_array($unplaced) ? $unplaced : [] as $entry) {
            if (\is_array($entry) && ($entry['matchId'] ?? null) === $this->friendlyId) {
                throw new RuntimeException('l\'amical figure dans les non-plaçables — il aurait dû être IGNORÉ du solveur, pas listé');
            }
        }

        $fixture = $this->apiGet(\sprintf('fixtures/%s', $this->friendlyId), $this->token);
        $status = $fixture['json']['status'] ?? null;
        if ('UNPLACED' !== $status) {
            throw new RuntimeException(\sprintf('l\'amical devrait rester UNPLACED après le placement, statut « %s »', \is_string($status) ? $status : 'inconnu'));
        }
    }

    #[Then('je peux le placer à la main hors de la fenêtre d\'accès match')]
    public function jePlaceLAmicalHorsFenetre(): void
    {
        // Le geste manuel d'un amical est LIBRE (aucun verrou serveur) : le PUT à
        // 20h00, hors de la fenêtre 14h00-18h00, doit réussir et poser PLACED.
        $current = $this->apiGet(\sprintf('fixtures/%s', $this->friendlyId), $this->token)['json'];
        $result = $this->apiPut(\sprintf('fixtures/%s', $this->friendlyId), [
            'teamId' => $this->teamId,
            'matchDate' => \is_string($current['matchDate'] ?? null) ? $current['matchDate'] : $this->saturday,
            'homeAway' => 'HOME',
            'opponentLabel' => \is_string($current['opponentLabel'] ?? null) ? $current['opponentLabel'] : 'Amical de gala',
            'venueId' => $this->venueId,
            'kickoffTime' => '20:00',
            'status' => 'PLACED',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le placement manuel de l\'amical hors fenêtre a répondu %d (200 attendu — le geste manuel est libre)', $result['status']));
        }
        $status = $result['json']['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('l\'amical placé à la main devrait être PLACED, statut « %s »', \is_string($status) ? $status : 'inconnu'));
        }
    }

    #[Then('le match du samedi est placé par le solveur entre 14h00 et 16h15')]
    public function leMatchDuSamediEstPlace(): void
    {
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }

        $source = $this->satFixture['placementSource'] ?? null;
        if ('SOLVER' !== $source) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas marqué comme placé par le solveur (source « %s »)', \is_string($source) ? $source : 'inconnue'));
        }

        // Sous D1 (P4-203) la salle ne réserve plus l'échauffement : le coup
        // d'envoi peut désormais toucher l'ouverture de la fenêtre (14h00).
        $kickoff = $this->kickoff();
        if ($kickoff <= '13:59' || $kickoff >= '16:16') {
            throw new RuntimeException(\sprintf('coup d\'envoi %s hors de la plage légale 14:00-16:15', $kickoff));
        }
    }

    #[Then('les deux matchs du samedi sont posés par le solveur dans ce gymnase')]
    public function lesDeuxMatchsSontPoses(): void
    {
        $unplaced = \is_array($this->placeResult['unplaced'] ?? null) ? $this->placeResult['unplaced'] : [];
        foreach ([$this->fxSat, $this->fxSat2] as $id) {
            foreach ($unplaced as $entry) {
                if (\is_array($entry) && ($entry['matchId'] ?? null) === $id) {
                    throw new RuntimeException('un des deux domiciles est resté non plaçable — deux matchs enchaînés doivent tenir dans la fenêtre (D1)');
                }
            }

            $fixture = $this->apiGet(\sprintf('fixtures/%s', $id), $this->token)['json'];
            $status = $fixture['status'] ?? null;
            if ('PLACED' !== $status) {
                throw new RuntimeException(\sprintf('un domicile du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
            }
            if (($fixture['venueId'] ?? null) !== $this->venueId) {
                throw new RuntimeException('un domicile du samedi n\'a pas atterri sur le gymnase jetable');
            }
        }
    }

    #[Then('le match du samedi atterrit sur son créneau idéal, sur le gymnase à 15h30')]
    public function leMatchDuSamediSurSonIdeal(): void
    {
        $kickoff = $this->kickoff();
        if (!str_starts_with($kickoff, '15:30')) {
            throw new RuntimeException(\sprintf('l\'attraction du créneau idéal n\'a pas joué : coup d\'envoi %s au lieu de 15:30', $kickoff));
        }

        $venuePlaced = $this->satFixture['venueId'] ?? null;
        if ($venuePlaced !== $this->venueId) {
            throw new RuntimeException('le match n\'a pas atterri sur le gymnase du créneau idéal');
        }
    }

    #[Then('le match du dimanche reste sans créneau, faute de fenêtre d\'accès ce jour-là')]
    public function leMatchDuDimancheResteSansCreneau(): void
    {
        $reason = null;
        $unplaced = $this->placeResult['unplaced'] ?? [];
        foreach (\is_array($unplaced) ? $unplaced : [] as $entry) {
            if (\is_array($entry) && ($entry['matchId'] ?? null) === $this->fxSun) {
                $reason = $entry['reason'] ?? null;

                break;
            }
        }

        if ('no_access_window' !== $reason) {
            throw new RuntimeException(\sprintf('le match du dimanche aurait dû rester sans fenêtre d\'accès, raison obtenue « %s »', \is_string($reason) ? $reason : 'aucune'));
        }
    }

    #[Given('un match à domicile à placer le week-end prochain')]
    public function unMatchAPlacerLeWeekEndProchain(): void
    {
        $this->competitionId = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Championnat jetable WE', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition du week-end prochain',
        );
        $this->fxSat = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire proche', 'competitionId' => $this->competitionId], $this->token),
            'match du week-end prochain',
        );
    }

    #[Given('un match déjà posé par le solveur sur un autre week-end, à 20h00')]
    public function unMatchDejaPoseSurUnAutreWeekEnd(): void
    {
        // Deux semaines plus tard : hors de la fenêtre [lundi, dimanche] du week-end prochain.
        $paris = new DateTimeZone('Europe/Paris');
        $this->otherSaturday = new DateTimeImmutable($this->saturday, $paris)->modify('+14 days')->format('Y-m-d');

        $this->competitionId2 = $this->createdId(
            $this->apiPost('competitions', ['teamId' => $this->secondTeamId, 'name' => 'Championnat jetable autre WE', 'competitionType' => 'CHAMPIONSHIP'], $this->token),
            'compétition de l\'autre week-end',
        );
        $this->fxSat2 = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->secondTeamId, 'matchDate' => $this->otherSaturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire lointain', 'competitionId' => $this->competitionId2], $this->token),
            'match de l\'autre week-end',
        );

        // Posé PAR LE SOLVEUR à 20h00, HORS de la fenêtre d'accès 14h00-18h00 : si le
        // placement le re-résolvait, il devrait le déplacer (ou le laisser sans créneau) —
        // rester à 20h00 prouve qu'il est traité en ANCRE (FIXED), jamais renvoyé au solveur.
        $this->dbalExec(
            \sprintf(
                'UPDATE fixture SET status=\'PLACED\', placement_source=\'SOLVER\', venue_id=\'%s\', kickoff_time=\'20:00:00\' WHERE id=\'%s\'',
                $this->venueId,
                $this->fxSat2,
            ),
            admin: true,
        );
    }

    #[When('je lance le placement du seul week-end prochain')]
    public function jeLancePlacementDuSeulWeekEndProchain(): void
    {
        // Fenêtre Lun→Dim de la semaine du samedi prochain (le grain de « Placer ce week-end »).
        $paris = new DateTimeZone('Europe/Paris');
        $from = new DateTimeImmutable($this->saturday, $paris)->modify('-5 days')->format('Y-m-d');
        $to = new DateTimeImmutable($this->saturday, $paris)->modify('+1 day')->format('Y-m-d');

        $this->placeResult = $this->lancerEtAttendreLeRun(['from' => $from, 'to' => $to]);

        $this->satFixture = $this->apiGet(\sprintf('fixtures/%s', $this->fxSat), $this->token)['json'];
        $this->otherFixture = $this->apiGet(\sprintf('fixtures/%s', $this->fxSat2), $this->token)['json'];
    }

    #[Then('le match du week-end prochain est placé par le solveur')]
    public function leMatchDuWeekEndProchainEstPlace(): void
    {
        if ('PLACED' !== ($this->satFixture['status'] ?? null)) {
            throw new RuntimeException(\sprintf('le match du week-end prochain n\'est pas placé (statut « %s »)', \is_string($this->satFixture['status'] ?? null) ? $this->satFixture['status'] : 'inconnu'));
        }
        if ('SOLVER' !== ($this->satFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match du week-end prochain n\'a pas été placé par le solveur');
        }
    }

    #[Then('le match de l\'autre week-end n\'a pas bougé, toujours à 20h00 et posé par le solveur')]
    public function leMatchDeLAutreWeekEndNaPasBouge(): void
    {
        $kickoff = $this->otherFixture['kickoffTime'] ?? null;
        if (!\is_string($kickoff) || !str_starts_with($kickoff, '20:00')) {
            throw new RuntimeException(\sprintf('le match de l\'autre week-end a bougé : coup d\'envoi « %s » au lieu de 20:00 — l\'ancre hors fenêtre a été re-résolue', \is_string($kickoff) ? $kickoff : 'aucun'));
        }
        if ('PLACED' !== ($this->otherFixture['status'] ?? null) || 'SOLVER' !== ($this->otherFixture['placementSource'] ?? null)) {
            throw new RuntimeException('le match de l\'autre week-end n\'est plus posé par le solveur — il aurait dû rester intact');
        }
        if (($this->otherFixture['venueId'] ?? null) !== $this->venueId) {
            throw new RuntimeException('le match de l\'autre week-end a changé de gymnase — l\'ancre n\'a pas été respectée');
        }
    }

    #[When('je lance un placement puis, aussitôt, un second pour le même club')]
    public function jeLanceDeuxPlacementsConcurrents(): void
    {
        // Rail async (ADR-0003) : le verrou par club est pris par le CONTRÔLEUR dès l'enfilage
        // et tenu jusqu'à la fin du run côté worker. Juste après le 202 du premier, le verrou
        // est donc détenu : une seconde demande immédiate échoue DÉTERMINISTEMENT à l'acquisition.
        $first = $this->apiPost('fixtures/place', [], $this->token);
        if (202 !== $first['status']) {
            throw new RuntimeException(\sprintf('le premier placement a répondu %d (202 attendu)', $first['status']));
        }
        $runId = $first['json']['runId'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new RuntimeException('le premier placement enfilé n\'a pas renvoyé d\'identifiant de run');
        }

        // Seconde demande PENDANT le run ouvert : réponse capturée pour l'assertion.
        $this->secondPlaceResponse = $this->apiPost('fixtures/place', [], $this->token);

        // On laisse le premier run aller à son terme : ni verrou résiduel ni run en vol au
        // nettoyage (la suppression des fixtures ne doit pas croiser un placement en cours).
        $this->awaitRunTerminal($runId);
    }

    #[Then('la seconde demande est refusée par un conflit, avec un message métier')]
    public function laSecondeDemandeEstRefusee(): void
    {
        if (409 !== $this->secondPlaceResponse['status']) {
            throw new RuntimeException(\sprintf('la seconde demande aurait dû être refusée par un conflit (409), obtenu %d', $this->secondPlaceResponse['status']));
        }
        $error = $this->secondPlaceResponse['json']['error'] ?? null;
        if (!\is_string($error) || '' === $error) {
            throw new RuntimeException('le conflit de double placement n\'a pas porté de message métier');
        }
    }

    /**
     * Nettoyage dans l'ordre du smoke (`trap cleanup`) : les fixtures D'ABORD
     * (une équipe engagée refuse sa suppression), puis créneau idéal, fenêtre,
     * équipes, gymnase ; on RECRÉE les fenêtres dominicales retirées ; enfin on
     * repose le pointeur du socle à NULL si c'est nous qui l'avons posé.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        foreach ([$this->fxSat, $this->fxSat2, $this->fxSun, $this->friendlyId, $this->awayId, $this->homeId, $this->nightAnchorId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        // Trajet injecté en base (D3) + affectations coach : retirés avant les équipes.
        if ('' !== $this->travelCode) {
            $this->dbalExec(\sprintf('DELETE FROM opponent_venue_link WHERE opponent_organisme_code=\'%s\'', $this->travelCode), admin: true);
        }
        if ('' !== $this->clubId) {
            $this->dbalExec(\sprintf('DELETE FROM club_travel_cache WHERE club_id=\'%s\'', $this->clubId), admin: true);
        }
        // P4-300 — la fermeture du calendrier (contrainte puis entrée porteuse).
        if ('' !== $this->closureConstraintId) {
            $this->dbalExec(\sprintf('DELETE FROM "constraint" WHERE id=\'%s\'', $this->closureConstraintId), admin: true);
        }
        if ('' !== $this->closureEntryId) {
            $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE id=\'%s\'', $this->closureEntryId), admin: true);
        }
        foreach ([$this->teamCoachAId, $this->teamCoachBId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('team_coaches/%s', $id), $this->token);
            }
        }
        if ('' !== $this->coachId) {
            $this->apiDelete(\sprintf('coaches/%s', $this->coachId), $this->token);
        }
        if ('' !== $this->habitIdealId) {
            $this->apiDelete(\sprintf('team_match_habits/%s', $this->habitIdealId), $this->token);
        }
        if ('' !== $this->matchRuleId) {
            $this->apiDelete(\sprintf('match_constraints/%s', $this->matchRuleId), $this->token);
        }
        // Les compétitions sont portées par les équipes jetables (A/B) : elles partent
        // avec elles (cascade teams → competitions), les matchs ayant été supprimés
        // juste au-dessus (les équipes ne sont alors plus engagées).
        if ('' !== $this->windowId) {
            $this->apiDelete(\sprintf('venue_match_windows/%s', $this->windowId), $this->token);
        }
        foreach ([$this->teamId, $this->secondTeamId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('teams/%s', $id), $this->token);
            }
        }
        if ('' !== $this->venueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->venueId), $this->token);
        }

        foreach ($this->sundayWindows as $window) {
            $this->apiPost('venue_match_windows', [
                'venueId' => $window['venueId'],
                'dayOfWeek' => 7,
                'startTime' => $window['startTime'],
                'endTime' => $window['endTime'],
            ], $this->token);
        }
        foreach ($this->saturdayWindows as $window) {
            $this->apiPost('venue_match_windows', [
                'venueId' => $window['venueId'],
                'dayOfWeek' => 6,
                'startTime' => $window['startTime'],
                'endTime' => $window['endTime'],
            ], $this->token);
        }

        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /**
     * Lance le placement (rail async) et rend le RÉSULTAT du run une fois terminal.
     * Un corps vide/`{from,to}` enfile un run (202) ; le seul 200 synchrone restant est
     * « aucun match à placer » (corps direct), que l'on rend tel quel. Patron copié de
     * {@see SeasonGenerationContext::pollUntilTerminal} : sondage borné du dernier run.
     *
     * @param array<string, string> $body
     *
     * @return array<mixed> le `result` du run COMPLETED (placed / unplaced / diagnostics…)
     */
    private function lancerEtAttendreLeRun(array $body): array
    {
        $result = $this->apiPost('fixtures/place', $body, $this->token);
        if (200 === $result['status']) {
            return $result['json']; // « aucun match à placer » : réponse directe, pas de run.
        }
        if (202 !== $result['status']) {
            throw new RuntimeException(\sprintf('le placement des matchs a répondu %d (202 attendu)', $result['status']));
        }
        $runId = $result['json']['runId'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new RuntimeException('le placement enfilé n\'a pas renvoyé d\'identifiant de run');
        }

        return $this->awaitRunTerminal($runId);
    }

    /**
     * Sonde GET /api/fixtures/placement-run jusqu'à l'état terminal du run visé, puis rend son
     * `result`. Exige COMPLETED (un FAILED nomme l'échec) — borné par {@see TIMEOUT_SECONDS}.
     *
     * @return array<mixed>
     */
    private function awaitRunTerminal(string $runId): array
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $status = 'PENDING';

        do {
            $response = $this->apiGet('fixtures/placement-run', $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture du run de placement en échec (HTTP %d)', $response['status']));
            }
            $run = $response['json']['run'] ?? null;
            if (\is_array($run) && ($run['id'] ?? null) === $runId) {
                $status = \is_string($run['status'] ?? null) ? $run['status'] : 'PENDING';
                if (\in_array($status, ['COMPLETED', 'FAILED'], true)) {
                    if ('COMPLETED' !== $status) {
                        throw new RuntimeException(\sprintf('le run de placement a terminé en « %s » au lieu de COMPLETED', $status));
                    }
                    $data = $run['result'] ?? [];

                    return \is_array($data) ? $data : [];
                }
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('le placement n\'a pas abouti dans le délai imparti (dernier statut « %s »)', $status));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function kickoff(): string
    {
        $kickoff = $this->satFixture['kickoffTime'] ?? null;
        if (!\is_string($kickoff) || '' === $kickoff) {
            throw new RuntimeException('le match du samedi n\'a pas de coup d\'envoi');
        }

        return $kickoff;
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function createdId(array $response, string $what): string
    {
        if (!\in_array($response['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création %s refusée (HTTP %d)', $what, $response['status']));
        }
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour', $what));
        }

        return $id;
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $json): array
    {
        $members = $json['member'] ?? $json;

        return array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
    }
}
