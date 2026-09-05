<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Une semaine de vacances s'adapte : son plan naît, sa génération aboutit sur sa propre grille
 * (axe planning lifecycle — le rail overlay, côté VACANCES), sur la stack qui tourne.
 *
 * Le rail overlay n'avait de scénario lisible que pour une FERMETURE (`plan-de-periode-en-overlay`) ;
 * ici c'est le rail des VACANCES : détacher une semaine entière d'une mère VACANCES fait naître le
 * plan de la semaine (rail 1 entrée = 1 plan), on en génère une version en overlay, et on prouve
 * que les créneaux générés reposent sur le plan de la SEMAINE — jamais sur le socle.
 *
 * Autosuffisant : une mère VACANCES jetable sur deux semaines pleines de novembre, CLAIRES de
 * toute vacance scolaire semée et de toute période ; le socle est posé en vigueur s'il ne l'est
 * pas (SocleGuard exige une saison en vigueur pour générer une période) puis restauré ; la
 * suppression des entrées cascade plans et versions, quoi qu'il arrive.
 */
final class RepriseWeekContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const int POLL_INTERVAL_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 180;

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $motherId = '';

    private string $childId = '';

    private string $childPlanId = '';

    private string $versionId = '';

    private string $finalStatus = '';

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

        // Générer une version de période exige que le socle pointe une version (SocleGuard). On le
        // pose sur la version COMPLETED la plus fraîche s'il ne pointe rien (restauré en fin).
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
                admin: true,
            );
            if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $completed)) {
                throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
            }
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=\'%s\' WHERE club_id=\'%s\' AND type=\'SEASON\'', $completed, $this->clubId),
                admin: true,
            );
            $this->pointerSetBySelf = true;
        }
    }

    #[Given('des vacances jetables couvrant deux semaines pleines')]
    public function desVacancesSurDeuxSemaines(): void
    {
        // Lun 09/11 → dim 22/11 : deux semaines pleines de novembre, claires de toute vacance
        // scolaire seedée (Toussaint finit début nov., Noël arrive fin déc.) et de toute période.
        $mother = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Vacances de reprise (fonctionnel)',
            'startDate' => '2026-11-09',
            'endDate' => '2026-11-22',
        ], $this->token);
        $this->motherId = $this->idOf($mother, 'vacances jetables');
    }

    #[When('je détache une semaine entière et je génère son planning')]
    public function jeDetacheEtGenere(): void
    {
        // Lun 16/11 → dim 22/11 : une semaine entière, comprise dans les vacances → le détachement
        // fait naître le plan de la semaine (rail 1 entrée = 1 plan).
        $child = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Semaine de reprise (fonctionnel)',
            'startDate' => '2026-11-16',
            'endDate' => '2026-11-22',
            'parentEntryId' => $this->motherId,
        ], $this->token);
        $this->childId = $this->idOf($child, 'semaine détachée');

        $this->childPlanId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\' LIMIT 1', $this->childId),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->childPlanId)) {
            throw new RuntimeException('la semaine détachée n\'a pas fait naître son plan (rail 1 entrée = 1 plan)');
        }

        $version = $this->apiPost('schedules', ['schedulePlanId' => $this->childPlanId, 'status' => 'DRAFT'], $this->token);
        $this->versionId = $this->idOf($version, 'version de la semaine');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $this->versionId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération a répondu %d (202 attendu)', $launched['status']));
        }

        $this->finalStatus = $this->pollUntilTerminal($this->versionId);
    }

    #[Then('/^la génération aboutit sur la grille propre de la semaine, avec le statut « (?P<statut>[A-Z]+) »$/')]
    public function laGenerationAboutitSurSaGrille(string $statut): void
    {
        if ($statut !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('statut attendu « %s », obtenu « %s »', $statut, $this->finalStatus));
        }

        // La version repose sur le plan de la SEMAINE — jamais sur le socle.
        $planOfVersion = $this->dbalScalar(
            \sprintf('SELECT schedule_plan_id AS behatval FROM schedule WHERE id=\'%s\'', $this->versionId),
            admin: true,
        );
        if ($planOfVersion !== $this->childPlanId) {
            throw new RuntimeException('la version générée n\'est pas rattachée au plan de la semaine (elle reposerait sur le socle)');
        }

        $planType = $this->dbalScalar(
            \sprintf('SELECT type AS behatval FROM schedule_plan WHERE id=\'%s\'', $this->childPlanId),
            admin: true,
        );
        if ('SEASON' === $planType) {
            throw new RuntimeException('le plan de la semaine est de type SEASON : ce n\'est pas une grille de période propre');
        }

        // Des créneaux ont bien été placés sur cette grille propre (une reprise = l'entraînement
        // reprend) : la preuve serait creuse sur un planning vide.
        $slots = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'', $this->versionId),
            admin: true,
        );
        if ($slots < 1) {
            throw new RuntimeException('la génération de la semaine n\'a placé aucun créneau sur sa grille propre');
        }
    }

    /**
     * Supprime les entrées créées (cascade plans et versions) et repose le pointeur du socle à
     * NULL si c'est nous qui l'avons posé — quoi qu'il arrive (succès, échec, exception).
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        // La suppression de l'entrée cascade son plan et ses versions (EntityCascadeDeleter) ;
        // supprimer la mère cascade l'enfant. DELETE tolérant au 404 si déjà parti.
        if ('' !== $this->childId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->childId), $this->token);
        }
        if ('' !== $this->motherId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->motherId), $this->token);
        }

        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    private function pollUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $status = '';

        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture de la version en échec (HTTP %d)', $response['status']));
            }

            $status = $response['json']['status'] ?? '';
            if (\is_string($status) && \in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return $status;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('la génération n\'a pas abouti dans le délai imparti (dernier statut « %s »)', \is_string($status) ? $status : 'inconnu'));
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function idOf(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour (HTTP %d)', $what, $response['status']));
        }

        return $id;
    }
}
