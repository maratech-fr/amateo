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

    private string $redatePlanId = '';

    private string $redateVersionId = '';

    private string $redateTitle = '';

    private string $redateStart = '';

    private string $redateEnd = '';

    private string $redateNewEnd = '';

    private string $splitStart = '';

    private string $splitShortEnd = '';

    private int $splitPreviewCount = 0;

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

    #[Given('une fermeture à venir avec une version overlay aboutie')]
    public function uneFermetureAvecVersionAboutie(): void
    {
        // Fenêtre bien au-delà des périodes seedées ET des deux autres scénarios (+49/+63 j).
        $this->redateStart = date('Y-m-d', (int) strtotime('next monday +77 days'));
        $this->redateEnd = date('Y-m-d', (int) strtotime($this->redateStart . ' +4 days'));
        $this->redateTitle = 'Fermeture à re-dater';

        $this->entryId = $this->createClosurePeriod('next monday +77 days', $this->redateTitle);

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->entryId], $this->token);
        $this->redatePlanId = $this->idOf($plan, 'plan de période');

        $version = $this->apiPost('schedules', ['schedulePlanId' => $this->redatePlanId, 'status' => 'DRAFT'], $this->token);
        $this->redateVersionId = $this->idOf($version, 'version overlay');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $this->redateVersionId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération a répondu %d (202 attendu)', $launched['status']));
        }
        if ('COMPLETED' !== $this->pollUntilTerminal($this->redateVersionId)) {
            throw new RuntimeException('la génération overlay de la fermeture à re-dater n\'a pas abouti');
        }
    }

    #[When('je prolonge la fermeture de deux semaines')]
    public function jeProlongeLaFermeture(): void
    {
        // Découpage début·milieu·fin (fondateur 2026-09-05) : re-dater un plan « d'un bloc » ne se
        // fait QUE vers une fenêtre à UN segment. On prolonge donc jusqu'au DIMANCHE de la 3ᵉ
        // semaine (lun→dim aligné = un seul « milieu ») ; une fin au vendredi ferait une semaine
        // entamée (2 segments), désormais refusée. La fenêtre part du lundi $redateStart.
        $this->redateNewEnd = date('Y-m-d', (int) strtotime($this->redateStart . ' +20 days'));

        $put = $this->apiPut(\sprintf('calendar_entries/%s', $this->entryId), [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => $this->redateTitle,
            'startDate' => $this->redateStart,
            'endDate' => $this->redateNewEnd,
        ], $this->token);
        if (200 !== $put['status']) {
            throw new RuntimeException(\sprintf('le re-datage a répondu %d (200 attendu)', $put['status']));
        }
    }

    #[Then('la période porte les nouvelles dates, son plan aussi, la version existe toujours et le planning est signalé à régénérer')]
    public function laPeriodeEtSonPlanPortentLesNouvellesDates(): void
    {
        $entry = $this->apiGet(\sprintf('calendar_entries/%s', $this->entryId), $this->token);
        if (($entry['json']['endDate'] ?? '') !== $this->redateNewEnd) {
            throw new RuntimeException(\sprintf('la période porte encore « %s » (attendu « %s »)', $entry['json']['endDate'] ?? '', $this->redateNewEnd));
        }

        $planEnd = $this->dbalScalar(
            \sprintf('SELECT end_date::date AS behatval FROM schedule_plan WHERE id = \'%s\'', $this->redatePlanId),
            admin: true,
        );
        if ($planEnd !== $this->redateNewEnd) {
            throw new RuntimeException(\sprintf('le plan porte encore la fin « %s » (attendu « %s ») : la fenêtre n\'a pas été resynchronisée', $planEnd, $this->redateNewEnd));
        }

        $stillThere = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule WHERE id = \'%s\'', $this->redateVersionId),
            admin: true,
        );
        if ($this->redateVersionId !== $stillThere) {
            throw new RuntimeException('la version overlay n\'a pas survécu au re-datage');
        }

        $stale = $this->dbalScalar(
            \sprintf('SELECT CASE WHEN resources_changed_since_generation THEN \'oui\' ELSE \'non\' END AS behatval FROM schedule WHERE id = \'%s\'', $this->redateVersionId),
            admin: true,
        );
        if ('oui' !== $stale) {
            throw new RuntimeException('la version n\'est pas signalée à régénérer après le re-datage');
        }
    }

    #[Given('une fermeture à venir découpée en trois semaines-segments, chacune avec son plan')]
    public function uneFermetureDecoupeeEnTrois(): void
    {
        // Décor jetable, bien au-delà des périodes seedées, dans la même zone sans vacances que le
        // scénario 3 (+77 j). Mère du MERCREDI de la semaine 1 au SAMEDI de la semaine 3 : découpée
        // en début (semaine entamée de tête), milieu (semaine 2 pleine), fin (semaine entamée de queue).
        $w1mon = (int) strtotime('next monday +77 days');
        $d = static fn (int $off): string => date('Y-m-d', (int) strtotime(\sprintf('+%d days', $off), $w1mon));
        $this->splitStart = $d(2);       // semaine 1, mercredi
        $motherEnd = $d(19);             // semaine 3, samedi
        $this->splitShortEnd = $d(12);   // semaine 2, samedi (raccourci d'une semaine)

        $mother = $this->apiPost('calendar_entries', [
            'kind' => 'period', 'periodType' => 'closure',
            'title' => 'Incident découpé à re-dater', 'startDate' => $this->splitStart, 'endDate' => $motherEnd,
        ], $this->token);
        $this->entryId = $this->idOf($mother, 'mère découpée');

        // Trois enfants-segments : chacun naît AVEC son plan (POST enfant porteur de plan).
        foreach ([[$d(0), $d(6)], [$d(7), $d(13)], [$d(14), $d(20)]] as [$segStart, $segEnd]) {
            $child = $this->apiPost('calendar_entries', [
                'kind' => 'period', 'periodType' => 'closure', 'title' => 'Segment',
                'startDate' => $segStart, 'endDate' => $segEnd, 'parentEntryId' => $this->entryId,
            ], $this->token);
            $this->idOf($child, 'semaine-segment');
        }

        $me = $this->apiGet(\sprintf('calendar_entries/%s', $this->entryId), $this->token);
        if (true !== ($me['json']['redateNeedsPreview'] ?? null)) {
            throw new RuntimeException('la mère n\'est pas reconnue comme découpée (redateNeedsPreview attendu vrai)');
        }
    }

    #[When('je demande l\'aperçu du re-datage qui la raccourcit d\'une semaine, puis je confirme')]
    public function jApercoisEtConfirme(): void
    {
        $preview = $this->apiPost(\sprintf('calendar_entries/%s/redate-preview', $this->entryId), [
            'startDate' => $this->splitStart, 'endDate' => $this->splitShortEnd,
        ], $this->token);
        if (200 !== $preview['status']) {
            throw new RuntimeException(\sprintf('l\'aperçu a répondu %d (200 attendu)', $preview['status']));
        }
        $effects = $preview['json']['effects'] ?? [];
        $this->splitPreviewCount = \is_array($effects) ? \count($effects) : 0;
        $token = $preview['json']['token'] ?? '';
        if (!\is_string($token) || '' === $token) {
            throw new RuntimeException('l\'aperçu n\'a pas renvoyé de jeton');
        }

        $put = $this->apiPut(\sprintf('calendar_entries/%s', $this->entryId), [
            'kind' => 'period', 'periodType' => 'closure', 'title' => 'Incident découpé à re-dater',
            'startDate' => $this->splitStart, 'endDate' => $this->splitShortEnd, 'previewToken' => $token,
        ], $this->token);
        if (200 !== $put['status']) {
            throw new RuntimeException(\sprintf('la confirmation a répondu %d (200 attendu)', $put['status']));
        }
    }

    #[Then('l\'aperçu annonçait plusieurs changements, la mère porte les nouvelles dates et ses semaines sont ré-appariées')]
    public function laMereReAppariee(): void
    {
        if ($this->splitPreviewCount < 2) {
            throw new RuntimeException(\sprintf('l\'aperçu n\'annonçait que %d changement(s) (au moins 2 attendus)', $this->splitPreviewCount));
        }

        $entry = $this->apiGet(\sprintf('calendar_entries/%s', $this->entryId), $this->token);
        if (($entry['json']['endDate'] ?? '') !== $this->splitShortEnd) {
            throw new RuntimeException(\sprintf('la mère porte encore « %s » (attendu « %s »)', $entry['json']['endDate'] ?? '', $this->splitShortEnd));
        }

        // Ré-appariement : début conservé + fin glissée ; le milieu, non recouvert par un segment de
        // même rôle, est absorbé → deux semaines subsistent.
        $children = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM calendar_entry WHERE parent_entry_id = \'%s\'', $this->entryId),
            admin: true,
        );
        if (2 !== $children) {
            throw new RuntimeException(\sprintf('la mère porte %d semaines après re-datage (2 attendues : début conservé + fin glissée, milieu absorbé)', $children));
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
