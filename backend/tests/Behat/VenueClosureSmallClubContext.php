<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P1 — une fermeture de gymnase adapte le planning de période (axe planning lifecycle, ADR-0002),
 * sur le PETIT CLUB réaliste ({@see SmallClubFixtureTrait}, palier 60 s).
 *
 * La promesse, de bout en bout contre la vraie API et le vrai moteur : le gestionnaire ferme le
 * gymnase le PLUS OCCUPÉ de son socle, exactement comme l'application le fait — deux gestes :
 *   - une PÉRIODE au calendrier (entrée `closure` de deux semaines pleines) ;
 *   - une INDISPONIBILITÉ datée qui porte le gymnase (contrainte FACILITY `venue_closed`, mêmes
 *     dates, attachée à l'entrée — donc hors de la génération de saison, cf. {@see ConstraintInput}).
 * Il ouvre le plan de période et l'adapte — le flux P1 réel : TRANSCRIRE le socle (copie HARD des
 * séances, le couple (gymnase, jour) fermé sauté en « à replacer ») puis REMPLIR (le solveur ne
 * replace que les séances du gymnase fermé, les autres restent ancrées). Alors :
 *   - aucune séance ne se tient au gymnase fermé (il a perdu ses créneaux dans la grille de période) ;
 *   - d'autres se tiennent sur les gymnases restants — le planning n'est pas vide (anti-vide : sans
 *     lui, « zéro séance au gymnase fermé » serait vrai pour rien) ;
 *   - aucune équipe de rang S (SM1, SF1) n'est déclassée : elles gardent TOUTES leurs séances
 *     (critère de réussite P1 « aucune prioritaire ne perd »), garanti par une version COMPLETED.
 *     NB : le JOUR exact d'une séance DÉPLACÉE du gymnase fermé peut changer — la référence socle
 *     n'est qu'un bonus souple, pas une garantie dure ; asserter l'identité des jours rendrait ce
 *     scénario (required check) instable (mesuré ~1 run sur 6).
 *
 * Fermer le plus occupé n'est faisable sur deux gymnases restants que parce que les deux équipes de
 * Camille (SM1 + U13M1, affectées par le décor) sont MUTUALISÉES — une séance commune de moins à
 * placer. C'est le geste réel « on partage plutôt que de tout refaire ».
 *
 * Tout est bâti et jeté PAR L'API : le teardown du petit club purge le workspace entier (période,
 * plan, versions, bloc de mutualisation et contrainte compris) — aucune restauration de pointeur de
 * seed, contrairement aux contexts BCCL.
 */
final class VenueClosureSmallClubContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $socleId = '';

    private string $closedVenueId = '';

    private string $windowStart = '';

    private string $windowEnd = '';

    private string $periodPlanId = '';

    private string $overlayId = '';

    private string $overlayStatus = '';

    #[Given('un petit club réaliste dont les deux équipes de Camille sont mutualisées, avec son planning de saison en vigueur')]
    public function unPetitClubAvecCamilleMutualisee(): void
    {
        $this->buildSmallClub();

        // Les deux équipes de Camille (SM1 rang S + U13M1), une séance commune : la mutualisation
        // qui rend faisable la fermeture du gymnase le plus occupé. Portée SAISON (schedulePlanId
        // null) — le socle la respecte dès sa génération.
        $this->mutualizeCamillesTeams();

        $this->socleId = $this->generateAndValidateSmallClubSocle();
    }

    #[Given('une fermeture de deux semaines du gymnase le plus occupé du socle, déclarée comme dans l\'application')]
    public function uneFermetureDuGymnaseLePlusOccupe(): void
    {
        $this->closedVenueId = $this->busiestVenueOnSocle();

        // Deux SEMAINES PLEINES (lundi → dimanche) hors vacances : une fermeture `closure` n'ouvre un
        // plan « d'un bloc » que si sa fenêtre fait UN seul segment (SchedulePlanStateProcessor) — des
        // semaines complètes consécutives forment un « milieu » unique, une semaine entamée non.
        $this->windowStart = $this->smallClubFutureMonday(49, 14);
        $this->windowEnd = date('Y-m-d', (int) strtotime($this->windowStart . ' +13 days'));

        // Geste 1 : la période au calendrier. Geste 2 : l'indisponibilité datée qui porte le gymnase,
        // attachée à l'entrée — comme le fait l'application (frontend/src/features/cockpit/queries.ts).
        $entryId = $this->createSmallClubClosureEntry($this->windowStart, $this->windowEnd, 'Fermeture du gymnase (petit club)');
        $this->declareVenueClosedConstraint($this->closedVenueId, $this->windowStart, $this->windowEnd, 'Fermeture du gymnase (petit club)', $entryId);

        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);
    }

    #[When('j\'ouvre le plan de période et je génère une version')]
    public function jOuvreLePlanEtGenere(): void
    {
        // Flux P1 réel (charte) : TRANSCRIRE le socle dans le plan (copie HARD des séances, le
        // couple (gymnase, jour) FERMÉ sauté en « à replacer »), puis REMPLIR — le solveur ne
        // replace QUE les séances du gymnase fermé, les autres restent ancrées en dur.
        $v1 = $this->transcribeSmallClubSocleToPlan($this->periodPlanId);
        $filled = $this->fillSmallClubPeriodVersion($v1);
        $this->overlayId = $filled['id'];
        $this->overlayStatus = $filled['status'];
        fwrite(\STDERR, \sprintf("\n[VenueClosure] remplissage %s en %.1fs\n", $this->overlayStatus, $this->smallClubLastSolveSeconds));
    }

    #[Then('la génération aboutit, aucune séance ne se tient au gymnase fermé, d\'autres se tiennent ailleurs, et aucune équipe de rang S ne perd de séance')]
    public function lePlanningSAdapte(): void
    {
        if ('COMPLETED' !== $this->overlayStatus) {
            throw new RuntimeException(\sprintf('la génération de la période de fermeture aurait dû aboutir (COMPLETED), obtenu « %s »', $this->overlayStatus));
        }

        // 1. Aucune séance au gymnase fermé : il a perdu ses créneaux dans la grille de période.
        $atClosed = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND venue_id=\'%s\'', $this->overlayId, $this->closedVenueId),
            admin: true,
        );
        if (0 !== $atClosed) {
            throw new RuntimeException(\sprintf('%d séance(s) se tiennent au gymnase FERMÉ — la fermeture n\'a pas été honorée', $atClosed));
        }

        // 2. Anti-vide : d'autres séances se tiennent sur les gymnases restants. Sans cette borne,
        // « zéro au gymnase fermé » serait vrai pour un planning entièrement vide.
        $elsewhere = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND venue_id<>\'%s\'', $this->overlayId, $this->closedVenueId),
            admin: true,
        );
        if ($elsewhere < 1) {
            throw new RuntimeException('aucune séance ailleurs — l\'overlay est vide : la fermeture ne prouve rien sur un planning vide');
        }

        // 3. Aucune équipe de rang S n'est déclassée : SM1 et SF1 gardent TOUTES leurs séances dans
        // le planning adapté (critère de réussite P1 « aucune prioritaire ne perd »). Le flux
        // transcrit→remplit ancre en dur les séances des gymnases restants et le remplissage replace
        // celles du gymnase fermé ; une version COMPLETED a donc replacé chaque séance due — les
        // prioritaires ne perdent rien. (Le JOUR exact d'une séance DÉPLACÉE du gymnase fermé peut
        // changer : la référence socle n'est qu'un bonus souple — cf. rapport, point d'arbitrage.)
        foreach (['SM1', 'SF1'] as $rankSTeamName) {
            $teamId = $this->smallClubTeamIds[$rankSTeamName] ?? '';
            if ('' === $teamId) {
                throw new RuntimeException(\sprintf('l\'équipe de rang S « %s » est absente du décor', $rankSTeamName));
            }
            $socleSessions = $this->sessionCount($this->socleId, $teamId);
            $overlaySessions = $this->sessionCount($this->overlayId, $teamId);
            if ($overlaySessions < $socleSessions) {
                throw new RuntimeException(\sprintf('l\'équipe de rang S « %s » a perdu des séances (socle %d → adapté %d) : une prioritaire a été déclassée', $rankSTeamName, $socleSessions, $overlaySessions));
            }
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /**
     * Lance le REMPLISSAGE d'une version transcrite (POST /schedules/{v1}/fill) : le solveur place
     * les séances « à replacer » (celles du gymnase fermé) sur les gymnases restants, les séances
     * copiées HARD restant ancrées. Rend la version remplie (attendue COMPLETED) et sa durée.
     *
     * @return array{id: string, status: string}
     */
    private function fillSmallClubPeriodVersion(string $v1Id): array
    {
        $fill = $this->apiPost(\sprintf('schedules/%s/fill', $v1Id), [], $this->smallClubToken);
        if (!\in_array($fill['status'], [200, 202], true)) {
            throw new RuntimeException(\sprintf('le remplissage de la période a répondu %d (202 attendu)', $fill['status']));
        }
        $v2Id = $this->smallClubStringField($fill['json'], 'id');

        $start = microtime(true);
        $status = $this->pollSmallClubUntilTerminal($v2Id);
        $this->smallClubLastSolveSeconds = microtime(true) - $start;

        return ['id' => $v2Id, 'status' => $status];
    }

    /**
     * Mutualise les deux équipes de Camille (SM1 + U13M1) en une séance commune, à portée SAISON
     * (schedulePlanId null) : le socle la respecte dès sa génération. commonSessions = 1 ≤ 2 séances
     * hebdomadaires de chacune (garde Σ, SharedTrainingBlockStateProcessor).
     */
    private function mutualizeCamillesTeams(): void
    {
        $block = $this->apiPost('shared_training_blocks', [
            'teamIds' => [$this->smallClubTeamIds['SM1'], $this->smallClubTeamIds['U13M1']],
            'commonSessions' => 1,
            'schedulePlanId' => null,
        ], $this->smallClubToken);
        if (!\in_array($block['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la mutualisation des deux équipes de Camille a été refusée (HTTP %d) — %s', $block['status'], json_encode($block['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }
    }

    /**
     * Le gymnase le PLUS OCCUPÉ du socle (le plus de séances placées), départagé par id pour le
     * déterminisme. C'est celui qu'on ferme : fermer le plus chargé est le cas qui éprouve vraiment
     * la capacité des gymnases restants.
     */
    private function busiestVenueOnSocle(): string
    {
        $venueId = $this->dbalScalar(
            \sprintf(
                'SELECT venue_id AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND venue_id IS NOT NULL'
                . ' GROUP BY venue_id ORDER BY COUNT(*) DESC, venue_id LIMIT 1',
                $this->socleId,
            ),
            admin: true,
        );
        if ('' === $venueId) {
            throw new RuntimeException('le socle ne porte aucune séance placée — le gymnase le plus occupé est introuvable');
        }

        return $venueId;
    }

    /**
     * Déclare l'indisponibilité datée qui FERME le gymnase (contrainte FACILITY `venue_closed`),
     * attachée à l'entrée de calendrier — le second geste de la fermeture, identique à l'application.
     * Attachée à l'entrée, elle est hors de la génération de SAISON et ne vaut que pour la période.
     */
    private function declareVenueClosedConstraint(string $venueId, string $start, string $end, string $title, string $entryId): void
    {
        $created = $this->apiPost('constraints', [
            'name' => $title,
            'scope' => 'FACILITY',
            'scopeTargetId' => $venueId,
            'family' => 'FACILITY',
            'ruleType' => 'HARD',
            'config' => ['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end],
            'calendarEntryId' => $entryId,
            'isActive' => true,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la déclaration de la fermeture du gymnase a été refusée (HTTP %d) — %s', $created['status'], json_encode($created['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }
    }

    /**
     * Le nombre de séances de l'équipe dans la version donnée. Lecture admin (traverse la RLS pour
     * le pointage).
     */
    private function sessionCount(string $scheduleId, string $teamId): int
    {
        return (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'', $scheduleId, $teamId),
            admin: true,
        );
    }
}
