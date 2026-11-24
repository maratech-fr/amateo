<?php

declare(strict_types=1);

namespace App\Tests\Behat;

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
 * Route : `POST /api/schedules/{id}/regenerate-from` (la PR 3 du lot 5 la remplace par
 * `POST /api/schedules/{id}/load` : adaptation mécanique de l'étape « je charge la version 1 »).
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
        $loaded = $this->apiPost(\sprintf('schedules/%s/regenerate-from', $this->versionOneId), [], $this->smallClubToken);
        $this->expectStatus($loaded, [200], '« Charger » la version 1');
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
