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
 *   - « Une fermeture obtient son planning » : période CLOSURE → plan né du geste
 *     d'Adapter (POST /schedule_plans) → version → génération OVERLAY (grille propre
 *     de la période) → COMPLETED ; puis, sur cette même génération, la prolongation de
 *     deux semaines ne fait pas bouger l'empreinte de structure ; la grille propre de la
 *     période comprend la copie de l'occupation posée dans la saison (déplacer la copie ne touche
 *     pas la saison, « Reprendre la grille » ramène celle de la saison) ;
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
    use PosesVenueOccupancyTrait;

    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    /** Durée de l'occupation « École » posée dans la grille de saison (2h15). */
    private const int OCCUPANCY_MINUTES = 135;

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

    private string $forcedTeamId = '';

    private int $forcedDay = 0;

    private string $forcedConstraintId = '';

    private string $redatePlanId = '';

    private string $redateVersionId = '';

    private string $redateTitle = '';

    private string $redateStart = '';

    private string $redateNewEnd = '';

    /** Empreinte de structure du plan de période relevée juste après sa génération (P4-266). */
    private string $redatePlanHashBefore = '';

    private string $splitStart = '';

    private string $splitShortEnd = '';

    private int $splitPreviewCount = 0;

    /** Gymnase de saison qui porte l'occupation « École », et l'occupation telle que la saison la liste. */
    private string $occupancyVenueId = '';

    /** @var array{id: string, dayOfWeek: int, startTime: string, durationMinutes: int, label: string}|null */
    private ?array $seasonOccupancy = null;

    /** La règle de saison « pas le vendredi » posée après la naissance du plan de fermeture, et son équipe. */
    private string $fridayRuleId = '';

    private string $fridayRuleTeamId = '';

    /** Vrai si la règle « pas le vendredi » vise tout le club (lot 4, PR 4). */
    private bool $fridayRuleForTheClub = false;

    /** L'identifiant de la décoche de « pas le vendredi » dans la période, rendu par sa publication. */
    private string $fridayRuleSettingId = '';

    /** Jour et début où la copie de la période a été déplacée. */
    private int $movedDay = 0;

    private string $movedStart = '';

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
        // 18 jours sans vacances : la fermeture (4 j) et sa prolongation de deux semaines.
        $this->redateStart = $this->holidayFreeMonday(49, 18);
        $this->redateTitle = 'Fermeture overlay fonctionnel';
        $this->entryId = $this->createClosurePeriod($this->redateStart, $this->redateTitle);
    }

    #[When('j\'ouvre son plan de période puis je génère une version en overlay')]
    public function jOuvreLePlanEtGenere(): void
    {
        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->entryId], $this->token);
        $this->redatePlanId = $this->idOf($plan, 'plan de période');

        $version = $this->apiPost('schedules', ['schedulePlanId' => $this->redatePlanId, 'status' => 'DRAFT'], $this->token);
        $this->redateVersionId = $this->idOf($version, 'version overlay');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $this->redateVersionId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération overlay a répondu %d (202 attendu)', $launched['status']));
        }

        $this->overlayStatus = $this->pollUntilTerminal($this->redateVersionId);
        if ('COMPLETED' !== $this->overlayStatus) {
            return; // le « Alors » qui suit nomme le statut obtenu ; la prolongation n'est pas jouée.
        }

        // P4-266 — l'empreinte de structure du plan JUSTE APRÈS la génération : la référence que la
        // prolongation ne doit pas faire bouger (le signal « à régénérer » se dérive de sa divergence).
        $this->redatePlanHashBefore = $this->planStructureHash($this->redatePlanId);
        if ('' === $this->redatePlanHashBefore) {
            throw new RuntimeException('le plan de période ne sert aucune empreinte de structure après génération — décor non tenu');
        }
    }

    #[Then('/^la génération overlay aboutit avec le statut « (?P<statut>[A-Z]+) »$/')]
    public function laGenerationOverlayAboutit(string $statut): void
    {
        if ($statut !== $this->overlayStatus) {
            throw new RuntimeException(\sprintf('statut attendu « %s », obtenu « %s »', $statut, $this->overlayStatus));
        }
    }

    /** La fermeture et son plan, nés par les gestes du gestionnaire (créer la période, puis l'adapter). */
    #[Given('une fermeture déjà créée')]
    public function uneFermetureDejaCreee(): void
    {
        $this->entryId = $this->createClosurePeriod($this->holidayFreeMonday(49, 7), 'Fermeture née avant la règle');
        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->entryId], $this->token);
        $this->redatePlanId = $this->idOf($plan, 'plan de période');
    }

    /** Des vacances et leur plan, nés par les gestes du gestionnaire (créer la période, puis l'adapter). */
    #[Given('des vacances déjà créées')]
    public function desVacancesDejaCreees(): void
    {
        $start = date('Y-m-d', (int) strtotime($this->holidayFreeMonday(77, 7)));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Vacances nées avant la règle',
            'startDate' => $start,
            'endDate' => date('Y-m-d', (int) strtotime($start . ' +4 days')),
        ], $this->token);
        $this->entryId = $this->idOf($entry, 'période de vacances');
        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->entryId], $this->token);
        $this->redatePlanId = $this->idOf($plan, 'plan de période');
    }

    /**
     * Lot 4, PR 4 : une règle de SAISON « pas le vendredi », posée après la naissance du plan de période — pour une équipe
     * qui joue la période et s'entraîne dans le socle (le vendredi de préférence), ou pour tout le club.
     */
    #[When('/^j\'ajoute la règle de saison « pas le vendredi » (?P<portee>pour une équipe de la période|pour tout le club)$/u')]
    public function jAjouteLaRegleDeSaisonPasLeVendrediAvecSaPortee(string $portee): void
    {
        $forTheClub = 'pour tout le club' === $portee;
        $this->fridayRuleTeamId = $this->dbalScalar(
            \sprintf(
                'SELECT t.team_id AS behatval FROM schedule_slot_template t JOIN schedule_plan p ON p.chosen_schedule_id = t.schedule_id'
                . ' JOIN plan_team l ON l.team_id = t.team_id AND l.schedule_plan_id = \'%s\' AND l.participates'
                . ' WHERE p.club_id=\'%s\' AND p.type=\'SEASON\' AND t.team_id IS NOT NULL ORDER BY (t.day_of_week = 5) DESC, t.id LIMIT 1',
                $this->redatePlanId,
                $this->clubId,
            ),
            admin: true,
        );
        if ('' === $this->fridayRuleTeamId) {
            throw new RuntimeException('aucune équipe de la période ne s\'entraîne dans le planning de saison en vigueur — la base est-elle seedée ?');
        }

        $created = $this->apiPost('constraints', [
            'name' => 'Pas le vendredi (règle de saison posée après la période)',
            'scope' => $forTheClub ? 'CLUB' : 'TEAM',
            'scopeTargetId' => $forTheClub ? null : $this->fridayRuleTeamId,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [5]],
            'isActive' => true,
        ], $this->token);
        $this->fridayRuleId = $this->idOf($created, 'règle « pas le vendredi »');
        $this->fridayRuleForTheClub = $forTheClub;
    }

    /** Le geste de l'étape Règles de la période : décocher la règle pour ce plan. */
    #[When('je décoche « pas le vendredi » pour la période')]
    public function jeDecochePasLeVendredi(): void
    {
        $published = $this->apiPost('constraint_period_overrides', [
            'schedulePlanId' => $this->redatePlanId,
            'constraintId' => $this->fridayRuleIdOrFail(),
            'isActive' => false,
        ], $this->token);
        $this->fridayRuleSettingId = $this->idOf($published, 'décoche de « pas le vendredi »');
    }

    /** Lot 4, PR 4 : décocher la règle pour la fermeture, puis la recocher — la coche revient au défaut de la fermeture. */
    #[When('je décoche puis je recoche « pas le vendredi » pour la fermeture')]
    public function jeDecochePuisJeRecochePasLeVendredi(): void
    {
        $this->jeDecochePasLeVendredi();
        $deleted = $this->apiDelete(\sprintf('constraint_period_overrides/%s', $this->fridayRuleSettingId), $this->token);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('recocher « pas le vendredi » a été refusé (HTTP %d)', $deleted['status']));
        }
    }

    /** Aucun geste : l'étape Règles de la période ne liste aucun réglage pour la règle, elle suit le défaut de la nature. */
    #[When('je laisse les règles de la période à leur défaut')]
    public function jeLaisseLesReglesAuDefaut(): void
    {
        $listed = $this->apiGet(\sprintf('constraint_period_overrides?schedulePlanId=%s', $this->redatePlanId), $this->token);
        if (200 !== $listed['status']) {
            throw new RuntimeException(\sprintf('la liste des réglages de règle de la période a été refusée (HTTP %d)', $listed['status']));
        }
        foreach (\is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [] as $member) {
            if (\is_array($member) && $this->fridayRuleIdOrFail() === ($member['constraintId'] ?? null)) {
                throw new RuntimeException(\sprintf('la règle a déjà un réglage dans la période : %s', json_encode($member, \JSON_UNESCAPED_UNICODE)));
            }
        }
    }

    #[When('je génère une version de la période')]
    public function jeGenereUneVersionDeLaPeriode(): void
    {
        $this->jeGenereUneVersionDeLaFermeture();
    }

    /**
     * Lot 4, PR 4 : la version de la période, générée maintenant. « respecte » : la règle part au moteur avec la demande
     * (lue dans la demande enregistrée de la version) et aucune séance visée n'a lieu le vendredi, l'équipe (ou le club)
     * en ayant au moins une. « ignore » : la règle ne part pas au moteur ; témoin : la version n'est pas vide.
     */
    #[Then('/^la génération de la période (?P<verdict>respecte|ignore) « pas le vendredi »$/u')]
    public function laGenerationDeLaPeriodeRespecteOuIgnore(string $verdict): void
    {
        if ('COMPLETED' !== $this->overlayStatus) {
            throw new RuntimeException(\sprintf('la génération de la période n\'a pas abouti (statut « %s »)', $this->overlayStatus));
        }
        $sent = '1' === $this->dbalScalar(
            \sprintf('SELECT count(*) AS behatval FROM schedule WHERE id=\'%s\' AND snapshot_data::text LIKE \'%%%s%%\'', $this->redateVersionId, $this->fridayRuleIdOrFail()),
            admin: true,
        );
        $sessions = $this->dbalScalar(
            \sprintf(
                'SELECT count(*) || \'|\' || count(*) FILTER (WHERE day_of_week = 5) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'%s',
                $this->redateVersionId,
                $this->fridayRuleForTheClub ? ' AND team_id IS NOT NULL' : \sprintf(' AND team_id=\'%s\'', $this->fridayRuleTeamId),
            ),
            admin: true,
        );
        [$total, $friday] = array_map(intval(...), explode('|', $sessions) + [1 => '0']);
        if (0 === $total) {
            throw new RuntimeException('les équipes visées n\'ont aucune séance dans la période : le témoin ne prouve rien');
        }
        if ('ignore' === $verdict) {
            if ($sent) {
                throw new RuntimeException('la règle « pas le vendredi » est partie au moteur avec la demande de la période');
            }

            return;
        }
        if (!$sent) {
            throw new RuntimeException('la règle « pas le vendredi » n\'est pas partie au moteur avec la demande de la période');
        }
        if (0 !== $friday) {
            throw new RuntimeException(\sprintf('%d séance(s) visée(s) le vendredi dans la période', $friday));
        }
    }

    /** Une règle de SAISON (sans période) : pas le vendredi, pour une équipe qui s'y entraîne dans le socle. */
    #[When('j\'ajoute la règle de saison « pas le vendredi »')]
    public function jAjouteLaRegleDeSaisonPasLeVendredi(): void
    {
        $this->fridayRuleTeamId = $this->dbalScalar(
            \sprintf(
                'SELECT t.team_id AS behatval FROM schedule_slot_template t JOIN schedule_plan p ON p.chosen_schedule_id = t.schedule_id'
                . ' WHERE p.club_id=\'%s\' AND p.type=\'SEASON\' AND t.team_id IS NOT NULL ORDER BY (t.day_of_week = 5) DESC, t.id LIMIT 1',
                $this->clubId,
            ),
            admin: true,
        );
        if ('' === $this->fridayRuleTeamId) {
            throw new RuntimeException('aucune séance dans le planning de saison en vigueur — la base est-elle seedée ?');
        }

        $created = $this->apiPost('constraints', [
            'name' => 'Pas le vendredi (règle de saison posée après la fermeture)',
            'scope' => 'TEAM',
            'scopeTargetId' => $this->fridayRuleTeamId,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [5]],
            'isActive' => true,
        ], $this->token);
        $this->fridayRuleId = $this->idOf($created, 'règle « pas le vendredi »');
    }

    #[When('je génère une version de la fermeture')]
    public function jeGenereUneVersionDeLaFermeture(): void
    {
        $version = $this->apiPost('schedules', ['schedulePlanId' => $this->redatePlanId, 'status' => 'DRAFT'], $this->token);
        $this->redateVersionId = $this->idOf($version, 'version de la fermeture');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $this->redateVersionId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération de la fermeture a répondu %d (202 attendu)', $launched['status']));
        }
        $this->overlayStatus = $this->pollUntilTerminal($this->redateVersionId);
    }

    /**
     * La règle part au moteur avec la demande de la fermeture (lue dans la demande enregistrée de la version), et
     * l'équipe n'a aucune séance le vendredi ; témoin : elle a au moins une séance dans la fermeture.
     */
    #[Then('la génération de la fermeture respecte « pas le vendredi »')]
    public function laGenerationDeLaFermetureRespectePasLeVendredi(): void
    {
        if ('COMPLETED' !== $this->overlayStatus) {
            throw new RuntimeException(\sprintf('la génération de la fermeture n\'a pas abouti (statut « %s »)', $this->overlayStatus));
        }
        $sent = $this->dbalScalar(
            \sprintf('SELECT count(*) AS behatval FROM schedule WHERE id=\'%s\' AND snapshot_data::text LIKE \'%%%s%%\'', $this->redateVersionId, $this->fridayRuleId),
            admin: true,
        );
        if ('1' !== $sent) {
            throw new RuntimeException('la règle « pas le vendredi » n\'est pas partie au moteur avec la demande de la fermeture');
        }
        $sessions = $this->dbalScalar(
            \sprintf(
                'SELECT count(*) || \'|\' || count(*) FILTER (WHERE day_of_week = 5) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'',
                $this->redateVersionId,
                $this->fridayRuleTeamId,
            ),
            admin: true,
        );
        [$total, $friday] = array_map(intval(...), explode('|', $sessions) + [1 => '0']);
        if (0 === $total) {
            throw new RuntimeException('l\'équipe de la règle n\'a aucune séance dans la fermeture : le témoin ne prouve rien');
        }
        if (0 !== $friday) {
            throw new RuntimeException(\sprintf('l\'équipe de la règle a %d séance(s) le vendredi dans la fermeture', $friday));
        }
    }

    #[Given('une nouvelle période de fermeture dont le plan recopie les blocs partagés du socle')]
    public function uneNouvellePeriodeAvecBlocs(): void
    {
        $this->entryId = $this->createClosurePeriod($this->holidayFreeMonday(63, 4), 'Fermeture overlay remplissage');

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

    #[Given('une règle « au moins une séance » sur le jour d\'une séance épinglée du socle')]
    public function uneRegleAuMoinsUneSeanceSurUnJourEpingle(): void
    {
        // ALIGN-16 — un jour IMPOSÉ sur le jour d'une séance que le socle a verrouillée (la
        // transcription V1 que le remplissage épingle en HARD). Côté moteur, l'épingle n'a pas de
        // variable : sans le correctif, « au moins une séance ce jour » posé par-dessus un jour
        // par ailleurs fermé rendrait le remplissage infaisable. L'épingle SATISFAIT le jour imposé.
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT team_id || \'|\' || day_of_week AS behatval'
                . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' ORDER BY id LIMIT 1',
                $this->v1Id,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (2 !== \count($parts)) {
            throw new RuntimeException('aucune séance transcrite trouvée sur la première version du plan de période');
        }
        [$this->forcedTeamId, $day] = $parts;
        $this->forcedDay = (int) $day;

        $created = $this->apiPost('constraints', [
            'name' => 'Au moins une séance ce jour (remplissage fonctionnel)',
            'scope' => 'TEAM',
            'scopeTargetId' => $this->forcedTeamId,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forcedDays' => [$this->forcedDay]],
            'isActive' => true,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la création de la règle « au moins une séance » a répondu %d (201 attendu)', $created['status']));
        }
        $this->forcedConstraintId = (string) ($created['json']['id'] ?? '');
        if ('' === $this->forcedConstraintId) {
            throw new RuntimeException('la règle « au moins une séance » n\'a pas rendu d\'identifiant');
        }
    }

    #[Then('le remplissage aboutit et l\'équipe au jour imposé garde sa séance ce jour-là')]
    public function leRemplissageHonoreLeJourImpose(): void
    {
        // Le When a déjà exigé COMPLETED. On vérifie que l'équipe au jour imposé garde bien une
        // séance ce jour-là dans la version de remplissage (l'épingle du socle a tenu la promesse).
        $onForcedDay = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND day_of_week=%d',
                $this->v2Id,
                $this->forcedTeamId,
                $this->forcedDay,
            ),
            admin: true,
        );
        if ($onForcedDay < 1) {
            throw new RuntimeException('l\'équipe au jour imposé n\'a aucune séance ce jour-là dans le remplissage — le jour imposé n\'a pas été honoré');
        }
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

    #[Then('la période porte les nouvelles dates, son plan aussi, la version existe toujours et le planning n\'est pas signalé à régénérer')]
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

        // P4-266 — un re-datage qui ne fait PAS entrer/sortir de contrainte datée ne change rien au
        // payload /generate (buildForPeriodPlan ne dépend des dates de l'entrée QUE via
        // PeriodConstraintSelection : contraintes datées retenues, fermetures effectives, équipes
        // désactivées). L'empreinte servie reste donc égale au snapshotHash FIGÉ de la version, et au
        // hash relevé AVANT le re-datage : le planning n'est PAS signalé à régénérer (règle fondateur
        // « si ça ne change rien au planning, pas de bandeau »).
        $hashAfter = $this->planStructureHash($this->redatePlanId);
        if ('' === $hashAfter) {
            throw new RuntimeException('le plan ne sert plus d\'empreinte de structure après le re-datage');
        }
        $snapshotHash = $this->dbalScalar(
            \sprintf('SELECT snapshot_hash AS behatval FROM schedule WHERE id = \'%s\'', $this->redateVersionId),
            admin: true,
        );
        if ($hashAfter !== $snapshotHash) {
            throw new RuntimeException('l\'empreinte servie a divergé du snapshotHash de la version alors que le re-datage ne touche aucune contrainte datée : le planning se signalerait à régénérer à tort');
        }
        if ($hashAfter !== $this->redatePlanHashBefore) {
            throw new RuntimeException('l\'empreinte de structure a bougé après le re-datage alors qu\'il ne change rien à la structure');
        }
    }

    #[Given('une fermeture à venir découpée en trois semaines-segments, chacune avec son plan')]
    public function uneFermetureDecoupeeEnTrois(): void
    {
        // Décor jetable, bien au-delà des périodes seedées, sur trois semaines pleines SANS vacances
        // scolaires (la règle de découpe ne compte que les semaines OFFERTES : une semaine de
        // vacances lun→ven fait un trou, et le 3ᵉ segment devenait « une semaine complète isolée »
        // → 422, constaté le 2026-09-14 quand « +77 j » a atterri sur Noël). Mère du MERCREDI de la
        // semaine 1 au SAMEDI de la semaine 3 : découpée en début (semaine entamée de tête), milieu
        // (semaine 2 pleine), fin (semaine entamée de queue).
        $w1mon = (int) strtotime($this->holidayFreeMonday(77, 20));
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

    #[Given('/^l\'occupation « (?P<label>[^»]+) » posée dans la grille de saison$/u')]
    public function lOccupationPoseeDansLaGrilleDeSaison(string $label): void
    {
        // Un gymnase de la saison en vigueur qui porte des créneaux de saison : sa grille est copiée
        // à la naissance du plan de période. La plage libre (le mardi d'abord) est lue par l'API.
        $this->occupancyVenueId = $this->dbalScalar(
            \sprintf(
                'SELECT s.venue_id AS behatval FROM venue_training_slot s'
                . ' JOIN schedule_plan p ON p.club_id = s.club_id AND p.season_id = s.season_id AND p.type = \'SEASON\''
                . ' WHERE s.club_id = \'%s\' AND s.schedule_plan_id IN (SELECT id FROM schedule_plan WHERE type = \'SEASON\') ORDER BY s.venue_id LIMIT 1',
                $this->clubId,
            ),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->occupancyVenueId)) {
            throw new RuntimeException('aucun gymnase de saison avec des créneaux — la base est-elle seedée ?');
        }

        $free = $this->plageLibre($this->token, $this->occupancyVenueId, self::OCCUPANCY_MINUTES, [2, 1, 3, 4, 6, 7]);
        $posed = $this->poseOccupation($this->token, $this->occupancyVenueId, $free['day'], $free['start'], self::OCCUPANCY_MINUTES, $label);
        if (201 !== $posed['status']) {
            throw new RuntimeException(\sprintf('la pose de l\'occupation « %s » dans la grille de saison a répondu %d (201 attendu) — %s', $label, $posed['status'], $this->messageDuRefus($posed['json'])));
        }

        $this->seasonOccupancy = $this->theOnlyOccupancy(null, 'la grille de saison');
        if ($label !== $this->seasonOccupancy['label'] || $free['day'] !== $this->seasonOccupancy['dayOfWeek'] || $free['start'] !== $this->seasonOccupancy['startTime']) {
            throw new RuntimeException(\sprintf('la grille de saison ne liste pas l\'occupation posée : %s', json_encode($this->seasonOccupancy, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('/^le plan de période porte sa copie de l\'occupation « (?P<label>[^»]+) »$/u')]
    public function lePlanPorteSaCopie(string $label): void
    {
        $copy = $this->theOnlyOccupancy($this->redatePlanId, 'la grille de la période');
        $season = $this->seasonOccupancyOrFail();
        if ($season['id'] === $copy['id']) {
            throw new RuntimeException('la période liste l\'occupation de la saison elle-même, pas sa copie');
        }
        unset($copy['id'], $season['id']);
        if ($season !== $copy || $label !== $copy['label']) {
            throw new RuntimeException(\sprintf('la copie de la période (%s) diffère de l\'occupation de la saison (%s)', json_encode($copy, \JSON_UNESCAPED_UNICODE), json_encode($season, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('je déplace celle de la période au vendredi')]
    public function jeDeplaceCelleDeLaPeriodeAuVendredi(): void
    {
        $copy = $this->theOnlyOccupancy($this->redatePlanId, 'la grille de la période');
        $free = $this->plageLibre($this->token, $this->occupancyVenueId, $copy['durationMinutes'], [5], $this->redatePlanId);
        $this->movedDay = $free['day'];
        $this->movedStart = $free['start'];

        $moved = $this->apiPut(\sprintf('venue_occupancies/%s', $copy['id']), [
            'venueId' => $this->occupancyVenueId,
            'dayOfWeek' => $free['day'],
            'startTime' => $free['start'],
            'durationMinutes' => $copy['durationMinutes'],
            'label' => $copy['label'],
        ], $this->token);
        if (200 !== $moved['status']) {
            throw new RuntimeException(\sprintf('le déplacement de la copie de la période a répondu %d (200 attendu) — %s', $moved['status'], $this->messageDuRefus($moved['json'])));
        }

        $after = $this->theOnlyOccupancy($this->redatePlanId, 'la grille de la période');
        if ($this->movedDay !== $after['dayOfWeek'] || $this->movedStart !== $after['startTime']) {
            throw new RuntimeException(\sprintf('la période ne liste pas l\'occupation déplacée au vendredi %s : %s', $this->movedStart, json_encode($after, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('celle de la saison n\'a pas bougé')]
    public function celleDeLaSaisonNaPasBouge(): void
    {
        $season = $this->theOnlyOccupancy(null, 'la grille de saison');
        if ($this->seasonOccupancyOrFail() !== $season) {
            throw new RuntimeException(\sprintf('déplacer la copie de la période a touché la saison : %s (avant : %s)', json_encode($season, \JSON_UNESCAPED_UNICODE), json_encode($this->seasonOccupancy, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('je reprends la grille du planning de saison pour ce gymnase')]
    public function jeReprendsLaGrilleDuPlanningDeSaison(): void
    {
        $reset = $this->apiPost('venue_period_overrides/reset-grid', ['schedulePlanId' => $this->redatePlanId, 'venueId' => $this->occupancyVenueId], $this->token);
        if (200 !== $reset['status']) {
            throw new RuntimeException(\sprintf('« Reprendre la grille » a répondu %d (200 attendu)', $reset['status']));
        }
    }

    #[Then('la période porte de nouveau l\'occupation de la saison')]
    public function laPeriodePorteDeNouveauLOccupationDeLaSaison(): void
    {
        $copy = $this->theOnlyOccupancy($this->redatePlanId, 'la grille de la période après « Reprendre »');
        $season = $this->seasonOccupancyOrFail();
        unset($copy['id'], $season['id']);
        if ($season !== $copy) {
            throw new RuntimeException(\sprintf('après « Reprendre la grille », la période porte %s au lieu de l\'occupation de la saison %s', json_encode($copy, \JSON_UNESCAPED_UNICODE), json_encode($season, \JSON_UNESCAPED_UNICODE)));
        }
    }

    /**
     * Supprime la période créée (cascade son plan + ses versions, et les copies d'occupation de
     * son plan), retire l'occupation posée dans la grille de saison et repose le pointeur du socle
     * à NULL si c'est nous qui l'avons posé. Quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        $this->retireOccupationsPosees($this->token);

        if ('' !== $this->forcedConstraintId) {
            $this->apiDelete(\sprintf('constraints/%s', $this->forcedConstraintId), $this->token);
        }

        if ('' !== $this->fridayRuleId) {
            $this->apiDelete(\sprintf('constraints/%s', $this->fridayRuleId), $this->token);
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

    /**
     * L'unique occupation listée pour le gymnase de l'occupation dans une couche (saison si
     * `$planId` est nul) — le décor n'en pose qu'une : en trouver zéro ou deux est un échec nommé.
     *
     * @return array{id: string, dayOfWeek: int, startTime: string, durationMinutes: int, label: string}
     */
    private function theOnlyOccupancy(?string $planId, string $where): array
    {
        $listed = $this->occupationsListees($this->token, $this->occupancyVenueId, $planId);
        if (1 !== \count($listed)) {
            throw new RuntimeException(\sprintf('%s liste %d occupation(s) pour ce gymnase (1 attendue) : %s', $where, \count($listed), json_encode($listed, \JSON_UNESCAPED_UNICODE)));
        }

        return $listed[0];
    }

    /** @return array{id: string, dayOfWeek: int, startTime: string, durationMinutes: int, label: string} */
    private function seasonOccupancyOrFail(): array
    {
        return $this->seasonOccupancy ?? throw new RuntimeException('aucune occupation de saison posée par le scénario');
    }

    /** L'empreinte de structure COURANTE d'un plan, servie par la route structure-hash (P4-266). */
    private function planStructureHash(string $planId): string
    {
        $seen = $this->apiGet(\sprintf('schedule_plans/%s/structure-hash', $planId), $this->token);
        $hash = $seen['json']['currentStructureHash'] ?? null;

        return \is_string($hash) ? $hash : '';
    }

    /**
     * Le lundi (Y-m-d) d'une fenêtre de `$spanDays` jours SANS vacances scolaires de la zone du
     * club, à partir de « next monday +$offsetDays » — glisse de semaine en semaine jusqu'à en
     * trouver une. Un décor relatif à aujourd'hui traverse les vacances au fil de l'année ; la
     * règle de découpe (semaines OFFERTES) et les gardes de saison ne pardonnent pas.
     */
    private function holidayFreeMonday(int $offsetDays, int $spanDays): string
    {
        $monday = (int) strtotime(\sprintf('next monday +%d days', $offsetDays));
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $start = date('Y-m-d', $monday);
            $end = date('Y-m-d', (int) strtotime(\sprintf('+%d days', $spanDays), $monday));
            $hits = $this->dbalScalar(\sprintf(
                'SELECT count(*) AS behatval FROM school_holiday_period h JOIN club c ON c.school_zone = h.zone WHERE c.id=\'%s\' AND h.start_date <= \'%s\' AND h.end_date >= \'%s\'',
                $this->clubId,
                $end,
                $start,
            ), admin: true);
            if ('0' === $hits) {
                return $start;
            }
            $monday = (int) strtotime('+7 days', $monday);
        }

        throw new RuntimeException(\sprintf('aucune fenêtre de %d jours sans vacances scolaires trouvée après « next monday +%d days »', $spanDays, $offsetDays));
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

    private function fridayRuleIdOrFail(): string
    {
        if ('' === $this->fridayRuleId) {
            throw new RuntimeException('aucune règle « pas le vendredi » : le scénario l\'ajoute d\'abord');
        }

        return $this->fridayRuleId;
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function idOf(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            // Le corps de la réponse est nommé dans l'erreur : un 422 muet a coûté une enquête
            // (2026-09-14) — la raison servie (violations / detail) doit se lire dans le rapport.
            $detail = json_encode($response['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour (HTTP %d) — %s', $what, $response['status'], \is_string($detail) ? mb_substr($detail, 0, 600) : '?'));
        }

        return $id;
    }
}
