<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : supprimer une équipe (sans compétition), un gymnase ou un coach emporte ce qui ne
 * vit que par lui — séances, réservations, règles qui le VISENT (portée + cible), liens — et laisse
 * le reste du club tel quel ; la remise à zéro de la saison efface la structure et garde le club,
 * sa saison et son gestionnaire.
 *
 * Décor DÉDIÉ et léger ({@see SmallClubFixtureTrait::registerSmallClub}, puis 3 équipes, 2 gymnases
 * de 6 créneaux, 2 coachs par l'API), une génération du socle (non validé). Les vérités sont des
 * POINTAGES SQL (admin) table par table. Plus bas : `Integration/Api/CascadeDeleteApiTest`
 * (chaque étape du plan de cascade), `Security/ResetSeasonControllerTest` (remise à zéro).
 */
final class DeletionCascadeContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $seasonId = '';

    private string $scheduleId = '';

    private int $sudSessionsBefore = 0;

    private int $sessionsOfU15Before = 0;

    /** @var array<string, string> type de règle → id */
    private array $ruleIds = [];

    #[Given('un petit club de trois équipes sans compétition, deux gymnases et deux coachs, dont le planning de saison est généré')]
    public function unPetitClubDeTroisEquipes(): void
    {
        $this->registerSmallClub();
        $this->seasonId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);

        $categories = $this->apiGet('sport_categories', $this->smallClubToken);
        $category = $categories['json']['member'][0]['id'] ?? null;
        if (!\is_string($category)) {
            throw new RuntimeException('aucune catégorie sportive pour le petit club');
        }
        foreach (['U13M1', 'U15M1', 'SM1'] as $name) {
            $this->smallClubTeamIds[$name] = $this->createdId($this->apiPost('teams', [
                'name' => $name, 'sportCategoryId' => $category, 'priorityTierId' => 3, 'sessionsPerWeek' => 2,
            ], $this->smallClubToken), 'équipe ' . $name);
        }
        foreach (['Gymnase Nord', 'Gymnase Sud'] as $venue) {
            $venueId = $this->createdId($this->apiPost('venues', ['name' => $venue, 'source' => 'manual'], $this->smallClubToken), $venue);
            $this->smallClubVenueIds[$venue] = $venueId;
            foreach ([1, 2, 4] as $day) {
                foreach (['18:00', '19:30'] as $start) {
                    $this->createdId($this->apiPost('venue_training_slots', [
                        'venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1,
                    ], $this->smallClubToken), 'créneau de ' . $venue);
                }
            }
        }
        foreach (['Camille', 'Dominique'] as $firstName) {
            $this->smallClubCoachIds[$firstName] = $this->createdId($this->apiPost('coaches', ['firstName' => $firstName], $this->smallClubToken), 'coach ' . $firstName);
        }
        $this->assignSmallClubCoach('Camille', 'U13M1');
        $this->assignSmallClubCoach('Camille', 'U15M1');
        $this->assignSmallClubCoach('Dominique', 'SM1');
    }

    #[Given('/^l\'équipe « (?P<team>[^»]+) » a une réservation au « (?P<venue>[^»]+) » et une règle à elle$/u')]
    public function uneReservationEtUneRegle(string $team, string $venue): void
    {
        $this->createdId($this->apiPost('reservations', [
            'teamId' => $this->smallClubTeamIds[$team], 'venueId' => $this->smallClubVenueIds[$venue],
            'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'schedulePlanId' => null,
        ], $this->smallClubToken), 'réservation de ' . $team);
        $this->ruleIds['équipe'] = $this->createdId($this->apiPost('constraints', [
            'name' => $team . ' · pas avant 18:00', 'scope' => 'TEAM', 'scopeTargetId' => $this->smallClubTeamIds[$team],
            'family' => 'TIME', 'ruleType' => 'HARD', 'config' => ['minStartTime' => '18:00'], 'isActive' => true,
        ], $this->smallClubToken), 'règle de ' . $team);
    }

    #[Given('/^l\'équipe « (?P<team>[^»]+) » a une réservation au « (?P<venue>[^»]+) »$/u')]
    public function uneReservation(string $team, string $venue): void
    {
        $this->createdId($this->apiPost('reservations', [
            'teamId' => $this->smallClubTeamIds[$team], 'venueId' => $this->smallClubVenueIds[$venue],
            'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90, 'schedulePlanId' => null,
        ], $this->smallClubToken), 'réservation de ' . $team);
    }

    #[Given('/^le « (?P<venue>[^»]+) » a une règle à lui$/u')]
    public function leGymnaseAUneRegle(string $venue): void
    {
        $this->ruleIds['gymnase'] = $this->createdId($this->apiPost('constraints', [
            'name' => $venue . ' · au moins une séance', 'scope' => 'FACILITY', 'scopeTargetId' => $this->smallClubVenueIds[$venue],
            'family' => 'FACILITY', 'ruleType' => 'PREFERRED', 'config' => ['minAtVenueCount' => 1], 'isActive' => true,
        ], $this->smallClubToken), 'règle du ' . $venue);
    }

    #[Given('/^le coach « (?P<coach>[^»]+) », qui entraîne « (?P<a>[^»]+) » et « (?P<b>[^»]+) », a une indisponibilité$/u')]
    public function leCoachAUneIndisponibilite(string $coach): void
    {
        $this->ruleIds['coach'] = $this->createdId($this->apiPost('constraints', [
            'name' => $coach . ' · indisponible le vendredi', 'scope' => 'COACH', 'scopeTargetId' => $this->smallClubCoachIds[$coach],
            'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'config' => ['unavailableDays' => [5]], 'isActive' => true,
        ], $this->smallClubToken), 'indisponibilité de ' . $coach);

        $this->scheduleId = $this->generateSmallClubSeason();
        foreach (['U13M1', 'U15M1', 'SM1'] as $team) {
            if (0 === $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND team_id=\'%s\'', $this->scheduleId, $this->smallClubTeamIds[$team]))) {
                throw new RuntimeException(\sprintf('décor : le socle généré ne place aucune séance de %s', $team));
            }
        }
        foreach (['Gymnase Nord', 'Gymnase Sud'] as $venue) {
            if (0 === $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND venue_id=\'%s\' AND team_id IS NOT NULL', $this->scheduleId, $this->smallClubVenueIds[$venue]))) {
                throw new RuntimeException(\sprintf('décor : le socle généré ne place aucune séance au %s', $venue));
            }
        }
    }

    /**
     * Posée APRÈS la génération du décor : la règle ne change rien aux séances que les étapes
     * suivantes comptent ; elle n'existe que pour être nommée par la suppression du gymnase.
     */
    #[Given('/^l\'équipe « (?P<team>[^»]+) » a une règle qui lui impose le « (?P<venue>[^»]+) »$/u')]
    public function uneRegleQuiImposeLeGymnase(string $team, string $venue): void
    {
        $this->ruleIds['gymnase imposé'] = $this->createdId($this->apiPost('constraints', [
            'name' => $team . ' · s\'entraîne au ' . $venue, 'scope' => 'TEAM', 'scopeTargetId' => $this->smallClubTeamIds[$team],
            'family' => 'FACILITY', 'ruleType' => 'HARD', 'config' => ['forcedVenueId' => $this->smallClubVenueIds[$venue]], 'isActive' => true,
        ], $this->smallClubToken), 'règle qui impose le ' . $venue . ' à ' . $team);
    }

    /** Ce que la fenêtre de suppression du gymnase affiche : la ligne servie par `/deletion-impact`, au texte près. */
    #[Then('/^la suppression du « (?P<venue>[^»]+) » annonce (?P<count>\d+) (?P<label>contraintes? qu.+)$/u')]
    public function laSuppressionDuGymnaseAnnonce(string $venue, string $count, string $label): void
    {
        $impact = $this->apiGet(\sprintf('venues/%s/deletion-impact', $this->smallClubVenueIds[$venue]), $this->smallClubToken);
        if (200 !== $impact['status']) {
            throw new RuntimeException(\sprintf('l\'annonce de suppression du %s a répondu %d : %s', $venue, $impact['status'], json_encode($impact['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $expected = (int) $count;
        $lines = \is_array($impact['json']['lines'] ?? null) ? $impact['json']['lines'] : [];
        foreach ($lines as $line) {
            if (\is_array($line) && ($line[1 === $expected ? 'one' : 'many'] ?? null) === $label && ($line['count'] ?? null) === $expected) {
                return;
            }
        }

        throw new RuntimeException(\sprintf('la suppression du %s n\'annonce pas « %d %s » ; lignes servies : %s', $venue, $expected, $label, json_encode($lines, \JSON_UNESCAPED_UNICODE)));
    }

    #[Then('/^plus aucune règle ne nomme le « (?P<venue>[^»]+) »$/u')]
    public function plusAucuneRegleNeNommeLeGymnase(string $venue): void
    {
        $this->assertNone([
            'règle qui impose le gymnase' => ['constraint', \sprintf('id=\'%s\'', $this->ruleIds['gymnase imposé'])],
            'règle dont un réglage nomme le gymnase' => ['constraint', \sprintf('season_id=\'%s\' AND config::text LIKE \'%%%s%%\'', $this->seasonId, $this->smallClubVenueIds[$venue])],
        ], 'après la suppression du ' . $venue);
    }

    #[When('/^je supprime l\'équipe « (?P<team>[^»]+) »$/u')]
    public function jeSupprimeLEquipe(string $team): void
    {
        $this->assertDeleted($this->apiDelete(\sprintf('teams/%s', $this->smallClubTeamIds[$team]), $this->smallClubToken), 'l\'équipe ' . $team);
    }

    #[Then('/^l\'équipe « (?P<team>[^»]+) » n\'a plus ni séance, ni réservation, ni règle, ni lien de coach$/u')]
    public function lEquipeNAPlusRien(string $team): void
    {
        $teamId = $this->smallClubTeamIds[$team];
        $this->assertNone([
            'équipe' => ['team', \sprintf('id=\'%s\'', $teamId)],
            'séance' => ['schedule_slot_template', \sprintf('team_id=\'%s\'', $teamId)],
            'réservation' => ['reservation', \sprintf('team_id=\'%s\'', $teamId)],
            'règle' => ['constraint', \sprintf('id=\'%s\'', $this->ruleIds['équipe'])],
            'lien de coach' => ['team_coach', \sprintf('team_id=\'%s\'', $teamId)],
        ], 'l\'équipe ' . $team);
    }

    #[Then('/^« (?P<coach>[^»]+) » entraîne toujours « (?P<team>[^»]+) », et les séances des autres équipes sont intactes$/u')]
    public function leCoachEntraineToujours(string $coach, string $team): void
    {
        if (1 !== $this->count('team_coach', \sprintf('team_id=\'%s\' AND coach_id=\'%s\'', $this->smallClubTeamIds[$team], $this->smallClubCoachIds[$coach]))) {
            throw new RuntimeException(\sprintf('%s n\'entraîne plus %s après la suppression d\'une autre équipe', $coach, $team));
        }
        foreach (['U15M1', 'SM1'] as $other) {
            if (2 !== $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND team_id=\'%s\'', $this->scheduleId, $this->smallClubTeamIds[$other]))) {
                throw new RuntimeException(\sprintf('%s n\'a plus ses deux séances après la suppression d\'une autre équipe', $other));
            }
        }
    }

    #[When('/^je supprime le gymnase « (?P<venue>[^»]+) »$/u')]
    public function jeSupprimeLeGymnase(string $venue): void
    {
        $this->sudSessionsBefore = $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND venue_id=\'%s\' AND team_id IS NOT NULL', $this->scheduleId, $this->smallClubVenueIds['Gymnase Sud']));
        $this->assertDeleted($this->apiDelete(\sprintf('venues/%s', $this->smallClubVenueIds[$venue]), $this->smallClubToken), 'le ' . $venue);
    }

    #[Then('/^le « (?P<venue>[^»]+) » n\'a plus ni créneau, ni séance, ni règle$/u')]
    public function leGymnaseNAPlusRien(string $venue): void
    {
        $venueId = $this->smallClubVenueIds[$venue];
        $this->assertNone([
            'gymnase' => ['venue', \sprintf('id=\'%s\'', $venueId)],
            'créneau' => ['venue_training_slot', \sprintf('venue_id=\'%s\'', $venueId)],
            'séance' => ['schedule_slot_template', \sprintf('venue_id=\'%s\'', $venueId)],
            'règle' => ['constraint', \sprintf('id=\'%s\'', $this->ruleIds['gymnase'])],
        ], 'le ' . $venue);
    }

    #[Then('/^le « (?P<venue>[^»]+) » garde ses créneaux et ses séances$/u')]
    public function leGymnaseGardeSesCreneaux(string $venue): void
    {
        $venueId = $this->smallClubVenueIds[$venue];
        if (6 !== $this->count('venue_training_slot', \sprintf('venue_id=\'%s\'', $venueId))) {
            throw new RuntimeException(\sprintf('le %s n\'a plus ses 6 créneaux', $venue));
        }
        $sessions = $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND venue_id=\'%s\' AND team_id IS NOT NULL', $this->scheduleId, $venueId));
        if (0 === $sessions || $sessions !== $this->sudSessionsBefore) { // la réservation de U15M1 garantit au moins une séance
            throw new RuntimeException(\sprintf('le %s porte %d séances (avant la suppression de l\'autre gymnase : %d)', $venue, $sessions, $this->sudSessionsBefore));
        }
    }

    #[When('/^je supprime le coach « (?P<coach>[^»]+) »$/u')]
    public function jeSupprimeLeCoach(string $coach): void
    {
        $this->sessionsOfU15Before = $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND team_id=\'%s\'', $this->scheduleId, $this->smallClubTeamIds['U15M1']));
        if (0 === $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND team_id=\'%s\' AND coach_id=\'%s\'', $this->scheduleId, $this->smallClubTeamIds['U15M1'], $this->smallClubCoachIds[$coach]))) {
            throw new RuntimeException(\sprintf('décor : aucune séance de U15M1 ne nomme %s avant sa suppression', $coach));
        }
        $this->assertDeleted($this->apiDelete(\sprintf('coaches/%s', $this->smallClubCoachIds[$coach]), $this->smallClubToken), 'le coach ' . $coach);
    }

    #[Then('/^« (?P<coach>[^»]+) » n\'a plus ni lien d\'équipe, ni indisponibilité$/u')]
    public function leCoachNAPlusRien(string $coach): void
    {
        $coachId = $this->smallClubCoachIds[$coach];
        $this->assertNone([
            'coach' => ['coach', \sprintf('id=\'%s\'', $coachId)],
            'lien d\'équipe' => ['team_coach', \sprintf('coach_id=\'%s\'', $coachId)],
            'indisponibilité' => ['constraint', \sprintf('id=\'%s\'', $this->ruleIds['coach'])],
        ], $coach);
    }

    #[Then('/^l\'équipe « (?P<team>[^»]+) » garde ses séances, sans coach nommé$/u')]
    public function lEquipeGardeSesSeancesSansCoach(string $team): void
    {
        $teamId = $this->smallClubTeamIds[$team];
        $sessions = $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND team_id=\'%s\'', $this->scheduleId, $teamId));
        if (0 === $sessions || $sessions !== $this->sessionsOfU15Before) {
            throw new RuntimeException(\sprintf('%s porte %d séances après la suppression de son coach (avant : %d)', $team, $sessions, $this->sessionsOfU15Before));
        }
        $named = $this->count('schedule_slot_template', \sprintf('schedule_id=\'%s\' AND team_id=\'%s\' AND coach_id IS NOT NULL', $this->scheduleId, $teamId));
        if (0 !== $named) {
            throw new RuntimeException(\sprintf('%d séances de %s nomment encore le coach supprimé', $named, $team));
        }
    }

    /** Décision 1 du lot 3 (A) : supprimer le coach garde la fiche de la personne, sans rôle, jusqu'à la purge de la saison. */
    #[Then('/^la fiche de « (?P<coach>[^»]+) » existe toujours dans le club$/u')]
    public function laFicheExisteToujours(string $coach): void
    {
        if (1 !== $this->count('person', \sprintf('club_id=\'%s\' AND first_name=\'%s\'', $this->smallClubId, $coach))) {
            throw new RuntimeException(\sprintf('la fiche de %s a disparu avec son rôle de coach', $coach));
        }
    }

    /** La remise à zéro balaie les fiches que plus aucun rôle ne pointe. */
    #[Then('le club n\'a plus aucune fiche de personne')]
    public function leClubNAPlusAucuneFiche(): void
    {
        $this->assertNone(['fiche de personne' => ['person', \sprintf('club_id=\'%s\'', $this->smallClubId)]], 'le club remis à zéro');
    }

    #[When('je remets la saison à zéro')]
    public function jeRemetsLaSaisonAZero(): void
    {
        $reset = $this->apiDelete('reset-season', $this->smallClubToken);
        if (200 !== $reset['status']) {
            throw new RuntimeException(\sprintf('la remise à zéro a répondu %d (200 attendu) : %s', $reset['status'], json_encode($reset['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('la saison n\'a plus ni équipe, ni coach, ni gymnase, ni créneau, ni règle, ni réservation, ni séance')]
    public function laSaisonEstVide(): void
    {
        $season = \sprintf('season_id=\'%s\'', $this->seasonId);
        $this->assertNone([
            'équipe' => ['team', $season],
            'coach' => ['coach', $season],
            'gymnase' => ['venue', $season],
            'créneau' => ['venue_training_slot', $season],
            'règle' => ['constraint', $season],
            'réservation' => ['reservation', $season],
            'séance' => ['schedule_slot_template', $season],
        ], 'la saison remise à zéro');
    }

    #[Then('le club existe toujours, avec sa saison, et j\'en suis toujours le gestionnaire')]
    public function leClubExisteToujours(): void
    {
        if (1 !== $this->count('season', \sprintf('id=\'%s\'', $this->seasonId))) {
            throw new RuntimeException('la remise à zéro a emporté la saison elle-même');
        }
        $me = $this->apiGet('me', $this->smallClubToken);
        $club = $me['json']['club'] ?? null;
        if (200 !== $me['status'] || !\is_array($club) || ($club['id'] ?? null) !== $this->smallClubId) {
            throw new RuntimeException('après la remise à zéro, le gestionnaire ne retrouve plus son club');
        }
        if (1 !== $this->count('club_user', \sprintf('club_id=\'%s\' AND user_id=\'%s\' AND role=\'admin\' AND is_active', $this->smallClubId, $this->smallClubUserId))) {
            throw new RuntimeException('après la remise à zéro, le gestionnaire n\'est plus gestionnaire actif du club');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @param array{status: int, json: array<mixed>} $response */
    private function assertDeleted(array $response, string $what): void
    {
        if (204 !== $response['status']) {
            throw new RuntimeException(\sprintf('supprimer %s a répondu %d (204 attendu) : %s', $what, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** @param array<string, array{0: string, 1: string}> $checks libellé → [table, filtre] */
    private function assertNone(array $checks, string $subject): void
    {
        foreach ($checks as $label => [$table, $where]) {
            $count = $this->count($table, $where);
            if (0 !== $count) {
                throw new RuntimeException(\sprintf('%s : il reste %d ligne(s) « %s »', $subject, $count, $label));
            }
        }
    }

    private function count(string $table, string $where): int
    {
        return (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM "%s" WHERE %s', $table, $where), admin: true);
    }

    /** @param array{status: int, json: array<mixed>} $response */
    private function createdId(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\in_array($response['status'], [200, 201], true) || !\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création de « %s » refusée (HTTP %d) : %s', $what, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }

        return $id;
    }
}
