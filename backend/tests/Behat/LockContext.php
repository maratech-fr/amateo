<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Un verrou HARD est souverain à la régénération (axes constraint semantics + planning lifecycle),
 * sur la stack qui tourne.
 *
 * Deux promesses, en HTTP contre la vraie API et le vrai moteur (rail asynchrone Messenger → worker
 * → moteur → import) :
 *   - une séance verrouillée en dur ne bouge pas : après régénération, l'équipe occupe la MÊME case
 *     (le payload re-fournit les créneaux HARD des versions de base comme épingles) ;
 *   - un déplacement IMPOSSIBLE — vers une case fermée (un gymnase sans aucun créneau ouvert) — est
 *     REFUSÉ et NOMMÉ (422, code `slot_unavailable`), jamais appliqué en silence : la séance ne
 *     bouge pas. C'est le verdict de placement que gardent `SlotMoveVerdictTest` /
 *     `SlotPlacementVerdictTest` (« l'impossible est nommé, rien n'est écrit »).
 *
 * Budget : une seule génération (le scénario a). Autosuffisant : on rouvre le socle (pointeur
 * dépointé) le temps du scénario, on verrouille UNE séance de la version en vigueur, et en fin de
 * scénario on restaure le verrou d'origine, on retire le gymnase jetable, on SUPPRIME les versions
 * nées de la régénération (créneaux + diagnostics + métriques + snapshots + conflits) et on restaure
 * le pointeur du socle — quoi qu'il arrive.
 */
final class LockContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const int POLL_INTERVAL_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 650;

    private string $token = '';

    private string $clubId = '';

    /** Version de saison en vigueur à l'entrée (dépointée le temps du scénario, restaurée en fin). */
    private string $socleVersionId = '';

    private string $chosenAtEntry = '';

    /** Créneau verrouillé et sa case. */
    private string $slotId = '';

    private string $lockTeamId = '';

    private string $lockVenueId = '';

    private int $lockDay = 0;

    private string $lockStartTime = '';

    private string $slotOriginalLock = 'NONE';

    private string $slotOriginalOrigin = '';

    /** Gymnase jetable SANS aucun créneau ouvert — la « case fermée » du scénario b. */
    private string $closedVenueId = '';

    private int $moveStatus = 0;

    private string $moveMessage = '';

    /** @var list<string> Versions de saison présentes à l'entrée — à épargner au nettoyage. */
    private array $preexistingVersionIds = [];

    private string $regeneratedId = '';

    private string $finalStatus = '';

    #[Given('le club de démonstration, connecté, avec une version de saison rouverte')]
    public function leClubAvecVersionRouverte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        $this->chosenAtEntry = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        $this->socleVersionId = $this->isUuid($this->chosenAtEntry) ? $this->chosenAtEntry : $this->freshestCompletedSeasonVersion();
        if ('' === $this->socleVersionId) {
            throw new RuntimeException('aucune version de saison COMPLETED — la base est-elle seedée ?');
        }

        $this->preexistingVersionIds = $this->seasonVersionIds();

        // Rouvrir : on dépointe le socle le temps du scénario (une version choisie est en lecture
        // seule et non régénérable). Restauré en fin.
        $this->pointSocleTo('');
    }

    #[Given('une séance de cette version verrouillée en dur')]
    public function uneSeanceVerrouilleeEnDur(): void
    {
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT id || \'|\' || team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') || \'|\' || lock_level || \'|\' || COALESCE(lock_origin, \'\') AS behatval'
                . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' ORDER BY id LIMIT 1',
                $this->socleVersionId,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (7 !== \count($parts)) {
            throw new RuntimeException('aucune séance sur la version de saison en vigueur — la base est-elle seedée ?');
        }
        [$this->slotId, $this->lockTeamId, $this->lockVenueId, $day, $this->lockStartTime, $this->slotOriginalLock, $this->slotOriginalOrigin] = $parts;
        $this->lockDay = (int) $day;

        $locked = $this->apiPost(
            \sprintf('schedule-slots/%s/manual-edit/lock', $this->slotId),
            ['lockLevel' => 'HARD'],
            $this->token,
        );
        if (200 !== $locked['status']) {
            throw new RuntimeException(\sprintf('le verrouillage de la séance a répondu %d (200 attendu)', $locked['status']));
        }
    }

    #[When('je tente de déplacer cette séance vers une case sans créneau ouvert')]
    public function jeTenteUnDeplacementImpossible(): void
    {
        // Un gymnase JETABLE sans aucun créneau d'entraînement : toute case y est « fermée ».
        $venue = $this->apiPost('venues', ['name' => 'Gym Jetable Sans Créneau', 'source' => 'manual'], $this->token);
        $venueId = $venue['json']['id'] ?? null;
        if (!\is_string($venueId) || '' === $venueId) {
            throw new RuntimeException(\sprintf('création du gymnase jetable sans identifiant (HTTP %d)', $venue['status']));
        }
        $this->closedVenueId = $venueId;

        $moved = $this->apiPost(\sprintf('schedule-slots/%s/move', $this->slotId), [
            'dayOfWeek' => $this->lockDay,
            'startTime' => $this->lockStartTime,
            'venueId' => $this->closedVenueId,
        ], $this->token);
        $this->moveStatus = $moved['status'];
        foreach (['error', 'code', 'hydra:description', 'detail'] as $key) {
            if (\is_string($moved['json'][$key] ?? null)) {
                $this->moveMessage = $moved['json'][$key];

                break;
            }
        }
    }

    #[When('je régénère le planning de saison')]
    public function jeRegenere(): void
    {
        $launched = $this->apiPost(\sprintf('schedules/%s/regenerate', $this->socleVersionId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la régénération a répondu %d (202 attendu)', $launched['status']));
        }
        $id = $launched['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('la régénération n\'a pas rendu d\'identifiant de version');
        }
        $this->regeneratedId = $id;

        $this->finalStatus = $this->pollUntilTerminal($this->regeneratedId);
    }

    #[Then('la séance verrouillée occupe toujours la même case')]
    public function laSeanceVerrouilleeOccupeLaMemeCase(): void
    {
        if ('COMPLETED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la régénération aurait dû aboutir (COMPLETED), statut obtenu « %s »', $this->finalStatus));
        }

        $count = $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\'',
                $this->regeneratedId,
                $this->lockTeamId,
                $this->lockVenueId,
                $this->lockDay,
                $this->lockStartTime,
            ),
            admin: true,
        );
        if ((int) $count < 1) {
            throw new RuntimeException('la séance verrouillée en dur aurait dû rester à la même case après régénération');
        }
    }

    #[Then('le déplacement est refusé et nommé, et la séance n\'a pas bougé')]
    public function leDeplacementEstRefuseEtNomme(): void
    {
        if (422 !== $this->moveStatus) {
            throw new RuntimeException(\sprintf('un déplacement vers une case fermée aurait dû être refusé (422), obtenu %d', $this->moveStatus));
        }
        if (!str_contains($this->moveMessage, 'créneau') && !str_contains($this->moveMessage, 'slot_unavailable')) {
            throw new RuntimeException(\sprintf('le refus aurait dû nommer l\'absence de créneau ouvert, message obtenu « %s »', $this->moveMessage));
        }

        // « Sans la déplacer en silence » : la séance reste exactement à sa case d'origine.
        $stillThere = $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\'',
                $this->slotId,
                $this->lockVenueId,
                $this->lockDay,
                $this->lockStartTime,
            ),
            admin: true,
        );
        if ('1' !== $stillThere) {
            throw new RuntimeException('la séance n\'aurait jamais dû être déplacée par un geste refusé');
        }
    }

    /**
     * Retire la règle ajoutée, restaure le verrou d'origine, SUPPRIME les versions nées de la
     * régénération (avec leurs enfants) et restaure le pointeur du socle. Quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token || '' === $this->clubId) {
            return;
        }

        if ('' !== $this->closedVenueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->closedVenueId), $this->token);
        }

        // Restaure le verrou d'origine de la séance de la version en vigueur.
        if ('' !== $this->slotId) {
            $origin = '' === $this->slotOriginalOrigin ? 'NULL' : \sprintf('\'%s\'', $this->slotOriginalOrigin);
            $this->dbalExec(
                \sprintf('UPDATE schedule_slot_template SET lock_level=\'%s\', lock_origin=%s WHERE id=\'%s\'', $this->slotOriginalLock, $origin, $this->slotId),
                admin: true,
            );
        }

        // Supprime toute version de saison née depuis l'entrée (la régénération), enfants compris.
        foreach ($this->seasonVersionIds() as $versionId) {
            if (\in_array($versionId, $this->preexistingVersionIds, true)) {
                continue;
            }
            foreach (['constraint_conflict', 'schedule_structure_snapshot', 'schedule_slot_template', 'schedule_diagnostic', 'solver_metrics'] as $childTable) {
                $this->dbalExec(\sprintf('DELETE FROM %s WHERE schedule_id=\'%s\'', $childTable, $versionId), admin: true);
            }
            $this->dbalExec(\sprintf('DELETE FROM schedule WHERE id=\'%s\'', $versionId), admin: true);
        }

        // Restaure « socle en vigueur » : la version d'entrée si elle existe encore, sinon la plus
        // fraîche COMPLETED parmi les préexistantes.
        $target = '';
        if ($this->isUuid($this->chosenAtEntry)
            && '1' === $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE id=\'%s\'', $this->chosenAtEntry), admin: true)) {
            $target = $this->chosenAtEntry;
        }
        if ('' === $target) {
            $target = $this->freshestCompletedSeasonVersion();
        }
        if ('' !== $target) {
            $this->pointSocleTo($target);
        }
    }

    /** @return list<string> */
    private function seasonVersionIds(): array
    {
        $raw = $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(s.id::text, \',\') AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\'',
                $this->clubId,
            ),
            admin: true,
        );

        return array_values(array_filter(explode(',', $raw), $this->isUuid(...)));
    }

    private function freshestCompletedSeasonVersion(): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
            admin: true,
        );

        return $this->isUuid($id) ? $id : '';
    }

    private function pointSocleTo(string $scheduleId): void
    {
        $value = '' === $scheduleId ? 'NULL' : \sprintf('\'%s\'', $scheduleId);
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET chosen_schedule_id=%s WHERE club_id=\'%s\' AND type=\'SEASON\'', $value, $this->clubId),
            admin: true,
        );
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
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
}
