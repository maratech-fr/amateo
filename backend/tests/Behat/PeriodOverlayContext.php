<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Plan de période en overlay de bout en bout, sur la stack qui tourne.
 *
 * Reproduit ce que faisait le smoke « overlay » (ADR-0002) :
 *   - scénario 1 : période CLOSURE → plan né du geste d'Adapter (POST
 *     /schedule_plans) → version → génération OVERLAY (grille propre de la
 *     période) → COMPLETED ;
 *   - scénario 2 : le plan de fermeture recopie les blocs partagés du socle ; on
 *     transcrit (séances verrouillées HARD → V1), on libère UN membre d'un bloc
 *     (suppression de sa séance → trou), on remplit, et on AFFIRME que le membre
 *     libéré recolle sur la case exacte de son partenaire resté épinglé (balayage
 *     bloc-aware).
 *
 * Autosuffisant : pointeur de socle posé si vide puis restauré (à NULL) ; la
 * suppression de la période en fin de scénario cascade son plan et ses versions.
 */
final class PeriodOverlayContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const int POLL_INTERVAL_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 120;

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $entryId = '';

    private string $overlayStatus = '';

    private string $plan2Id = '';

    private string $v1Id = '';

    private string $v2Id = '';

    private string $keepTeam = '';

    private string $freeTeam = '';

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

        // Un plan secondaire exige que le socle pointe une version (SocleGuard).
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

    #[Given('une période de fermeture à venir')]
    public function unePeriodeDeFermeture(): void
    {
        // La fenêtre doit rester CLAIRE de toute période déjà seedée
        // (PeriodWindowUniquenessGuard 409 sur chevauchement). +49 jours passe
        // au-delà de toutes les fenêtres du seed et glisse avec le temps réel.
        $this->entryId = $this->createClosurePeriod('next monday +49 days', 'Fermeture overlay fonctionnel');
    }

    #[When('j\'ouvre son plan de période puis je génère une version en overlay')]
    public function jOuvreLePlanEtGenere(): void
    {
        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->entryId], $this->token);
        $planId = $this->idOf($plan, 'plan de période');

        $version = $this->apiPost('schedules', ['schedulePlanId' => $planId, 'status' => 'DRAFT'], $this->token);
        $versionId = $this->idOf($version, 'version overlay');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $versionId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération overlay a répondu %d (202 attendu)', $launched['status']));
        }

        $this->overlayStatus = $this->pollUntilTerminal($versionId);
    }

    #[Then('/^la génération overlay aboutit avec le statut « (?P<statut>[A-Z]+) »$/')]
    public function laGenerationOverlayAboutit(string $statut): void
    {
        if ($statut !== $this->overlayStatus) {
            throw new RuntimeException(\sprintf('statut attendu « %s », obtenu « %s »', $statut, $this->overlayStatus));
        }
    }

    #[Given('une nouvelle période de fermeture dont le plan recopie les blocs partagés du socle')]
    public function uneNouvellePeriodeAvecBlocs(): void
    {
        $this->entryId = $this->createClosurePeriod('next monday +63 days', 'Fermeture overlay remplissage');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->entryId], $this->token);
        $this->plan2Id = $this->idOf($plan, 'plan de période');
    }

    #[Given('une transcription depuis le socle qui verrouille ces séances, aboutie en une première version')]
    public function uneTranscriptionAboutie(): void
    {
        $transcribed = $this->apiPost(\sprintf('schedule_plans/%s/transcribe-from-socle', $this->plan2Id), [], $this->token);
        $this->v1Id = $this->idOf($transcribed, 'transcription');

        $status = $this->pollUntilTerminal($this->v1Id);
        if ('COMPLETED' !== $status) {
            throw new RuntimeException(\sprintf('la transcription depuis le socle n\'a pas abouti (statut « %s »)', $status));
        }
    }

    #[Given('je libère un membre d\'un bloc partagé en supprimant sa séance transcrite')]
    public function jeLibereUnMembreDeBloc(): void
    {
        // Un bloc partagé de CE plan dont les deux membres (chacun dans UN seul
        // bloc, pour lever l'ambiguïté) sont transcrits sur la MÊME case en V1 :
        // on en garde un épinglé, on libère l'autre.
        // Enveloppé dans un SELECT extérieur : dbal:run-sql traite une requête
        // commençant par WITH comme une ÉCRITURE (« 0 rows affected », aucun jeu
        // de résultats) ; un SELECT en tête lui fait rendre les lignes.
        $target = $this->dbalScalar(
            \sprintf(
                'SELECT sub.behatval AS behatval FROM ('
                . ' WITH pbt AS ('
                . ' SELECT t.block_id, t.team_id FROM shared_training_block_team t'
                . ' JOIN shared_training_block b ON b.id = t.block_id'
                . ' WHERE b.schedule_plan_id = \'%1$s\''
                . '), single AS ('
                . ' SELECT team_id FROM pbt GROUP BY team_id HAVING COUNT(DISTINCT block_id) = 1'
                . ')'
                . ' SELECT keep.team_id || \'|\' || drop_.team_id || \'|\' || drop_.id AS behatval'
                . ' FROM pbt ta'
                . ' JOIN pbt tb ON tb.block_id = ta.block_id AND tb.team_id <> ta.team_id'
                . ' JOIN single sa ON sa.team_id = ta.team_id'
                . ' JOIN single sb ON sb.team_id = tb.team_id'
                . ' JOIN schedule_slot_template keep ON keep.schedule_id = \'%2$s\' AND keep.team_id = ta.team_id'
                . ' JOIN schedule_slot_template drop_ ON drop_.schedule_id = \'%2$s\' AND drop_.team_id = tb.team_id'
                . ' AND drop_.venue_id = keep.venue_id AND drop_.day_of_week = keep.day_of_week AND drop_.start_time = keep.start_time'
                . ' ORDER BY keep.team_id, drop_.team_id LIMIT 1'
                . ') sub',
                $this->plan2Id,
                $this->v1Id,
            ),
            admin: true,
        );

        $parts = '' === $target ? [] : explode('|', $target);
        if (3 !== \count($parts)) {
            throw new RuntimeException('aucun bloc partagé à deux membres co-présents trouvé pour cette période');
        }

        [$this->keepTeam, $this->freeTeam, $freeSlotId] = $parts;

        $this->dbalExec(
            \sprintf('DELETE FROM schedule_slot_template WHERE id = \'%s\'', $freeSlotId),
            admin: true,
        );
    }

    #[When('je lance le remplissage de la période')]
    public function jeLanceLeRemplissage(): void
    {
        $fill = $this->apiPost(\sprintf('schedules/%s/fill', $this->v1Id), [], $this->token);
        if (202 !== $fill['status']) {
            throw new RuntimeException(\sprintf('le remplissage a répondu %d (202 attendu)', $fill['status']));
        }
        $this->v2Id = $this->idOf($fill, 'version de remplissage');

        $status = $this->pollUntilTerminal($this->v2Id);
        if ('COMPLETED' !== $status) {
            throw new RuntimeException(\sprintf('le remplissage n\'a pas abouti (statut « %s »)', $status));
        }
    }

    #[Then('le remplissage aboutit et le membre libéré partage de nouveau la case de son partenaire épinglé')]
    public function leMembreLibereRecolle(): void
    {
        $copresent = $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template a'
                . ' JOIN schedule_slot_template b ON b.schedule_id = a.schedule_id AND b.venue_id = a.venue_id'
                . ' AND b.day_of_week = a.day_of_week AND b.start_time = a.start_time'
                . ' WHERE a.schedule_id = \'%s\' AND a.team_id = \'%s\' AND b.team_id = \'%s\'',
                $this->v2Id,
                $this->keepTeam,
                $this->freeTeam,
            ),
            admin: true,
        );

        if ((int) $copresent < 1) {
            throw new RuntimeException('le membre de bloc libéré n\'a PAS recollé sur la case de son partenaire épinglé (balayage non bloc-aware)');
        }
    }

    /**
     * Supprime la période créée (cascade son plan + ses versions) et repose le
     * pointeur du socle à NULL si c'est nous qui l'avons posé. Quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        if ('' !== $this->entryId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->entryId), $this->token);
        }

        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    private function createClosurePeriod(string $startExpr, string $title): string
    {
        $start = date('Y-m-d', (int) strtotime($startExpr));
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));

        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);

        return $this->idOf($entry, 'période de fermeture');
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
