<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\SchedulePlanProvisioner;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Un verrou HARD est souverain à la régénération (axes constraint semantics + planning lifecycle),
 * sur la stack qui tourne.
 *
 * Trois promesses, en HTTP contre la vraie API et le vrai moteur (rail asynchrone Messenger → worker
 * → moteur → import) :
 *   - une séance verrouillée en dur ne bouge pas : après régénération, l'équipe occupe la MÊME case
 *     (le payload re-fournit les créneaux HARD des versions de base comme épingles) ;
 *   - un déplacement IMPOSSIBLE — vers une case fermée (un gymnase sans aucun créneau ouvert) — est
 *     REFUSÉ et NOMMÉ (422, code `slot_unavailable`), jamais appliqué en silence : la séance ne
 *     bouge pas. C'est le verdict de placement que gardent `SlotMoveVerdictTest` /
 *     `SlotPlacementVerdictTest` (« l'impossible est nommé, rien n'est écrit ») ;
 *   - une règle de jours qui CONTREDIT un verrou ne le déplace pas : le créneau reste à sa case, et
 *     la règle violée est SIGNALÉE (diagnostic). Le verrou GAGNE — une vérité absolue — et l'on
 *     informe des règles qu'il enfreint. Le verrou (`lockLevel: HARD`) ET la règle contraire voyagent
 *     tous deux dans le payload : c'est le moteur qui arbitre, il fixe le verrou hors du solveur.
 *
 * Décor et nettoyage par l'API : verrou posé/retiré via `manual-edit/lock`, règle créée/supprimée via
 * `/constraints`, versions nées supprimées via `DELETE /schedules/{id}` (le processeur purge ses
 * séances/diagnostics et relâche le pointeur). SEUL le pointeur du socle est manipulé en SQL brut :
 * la seule voie API pour dépointer (`/reopen`, `/validate`) DÉTRUIT au passage les plans de période
 * FUTURS du club (invariant socle), ce qu'on ne veut pas infliger au seed. Comme cette écriture SQL
 * échappe au listener Doctrine d'invalidation, un `@BeforeScenario` purge le pool `cache.schedule`
 * (payload solveur) avant chaque scénario, pour qu'aucune régénération ne serve un payload périmé.
 *
 * Budget : deux générations (scénarios a et c ; le b est un déplacement synchrone). Autosuffisant :
 * on rouvre le socle (pointeur dépointé), on verrouille UNE séance libre de la version en vigueur, et
 * en fin de scénario on retire le verrou, la règle et le gymnase jetable, on SUPPRIME les versions
 * nées de la régénération et on restaure le pointeur du socle — quoi qu'il arrive.
 */
final class LockContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private const int POLL_INTERVAL_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 650;

    /** Nom distinctif de la règle de jours ajoutée (scénario c) — attendu dans le diagnostic. */
    private const string DAY_RULE_NAME = 'Le verrou contredit ce jour (fonctionnel)';

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

    /** Règle de jours contraire au verrou (scénario c). */
    private string $constraintId = '';

    /** @var list<string> Versions de saison présentes à l'entrée — à épargner au nettoyage. */
    private array $preexistingVersionIds = [];

    private string $regeneratedId = '';

    private string $finalStatus = '';

    /** Vrai seulement si un scénario a RÉOUVERT le socle (SQL brut) : conditionne sa restauration en fin. */
    private bool $reopenedSocle = false;

    /** V1 — plan de PÉRIODE : le plan, sa version source (un overlay généré DANS le scénario), et les séances qu'on y verrouille. */
    private string $periodPlanId = '';

    private string $periodSourceVersionId = '';

    /** @var list<array{slotId: string, teamId: string, venueId: string, day: int, start: string}> */
    private array $periodLockedSlots = [];

    /** @var list<string> Toutes les versions de période nées DANS le scénario (source + régénérations) — supprimées et contrôlées en fin. */
    private array $periodCreatedVersionIds = [];

    /** @var list<string> Versions de période présentes sous le plan à l'entrée — à épargner et à contrôler au nettoyage (aucun résidu). */
    private array $periodPreexistingVersionIds = [];

    /** V1bis — scénario « Charger » : entrée de calendrier JETABLE d'un plan de période NEUF (non validé) ; supprimée en fin (cascade plan + versions). */
    private string $periodDisposableEntryId = '';

    /** V1bis — vrai seulement si le scénario « Charger » a dû POINTER le socle (SocleGuard) : conditionne sa remise à NULL en fin. */
    private bool $socleSetForPeriod = false;

    private string $periodLastRegeneratedId = '';

    private string $periodFinalStatus = '';

    /**
     * Purge le pool `cache.schedule` (payload solveur) avant chaque scénario. Le pointeur du socle
     * est toggle en SQL brut (voir l'en-tête de classe), écriture que le listener Doctrine
     * d'invalidation ne voit pas : sans cette purge, une régénération pourrait servir un payload
     * périmé d'un scénario précédent.
     */
    #[BeforeScenario]
    public function purgerLeCachePayload(): void
    {
        $process = new Process(
            ['php', 'bin/console', 'cache:pool:clear', 'cache.schedule'],
            \dirname(__DIR__, 2),
            ['APP_ENV' => 'dev'],
        );
        $process->setTimeout(60.0);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('impossible de purger le cache du payload solveur avant le scénario');
        }
    }

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

        // Rouvrir : on dépointe le socle en SQL brut (la voie API détruirait les plans de période
        // futurs — voir l'en-tête de classe). Une version choisie est en lecture seule et non
        // régénérable ; restauré en fin de scénario.
        $this->pointSocleTo('');
        $this->reopenedSocle = true;
    }

    #[Given('le club de démonstration, connecté, avec un plan de période portant une version terminée')]
    public function leClubAvecPlanDePeriodeTermine(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // Un plan de PÉRIODE (closure/holiday) du club, VALIDÉ (sa version CHOISIE est COMPLETED) et
        // dont la version choisie porte AU MOINS deux séances LIBRES — gage qu'une régénération
        // produira elle aussi des séances libres, qu'on pourra verrouiller/déverrouiller proprement
        // par l'API sans toucher un verrou seedé (réservation) qu'on ne saurait pas restaurer.
        $this->periodPlanId = $this->dbalScalar(
            \sprintf(
                'SELECT p.id AS behatval FROM schedule_plan p'
                . ' JOIN schedule s ON s.id = p.chosen_schedule_id'
                . ' WHERE p.club_id=\'%s\' AND p.type IN (\'CLOSURE\',\'HOLIDAY\') AND s.status=\'COMPLETED\''
                . ' AND (SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id AND t.lock_level=\'NONE\') >= 2'
                . ' ORDER BY s.created_at DESC LIMIT 1',
                $this->clubId,
            ),
            admin: true,
        );
        if (!$this->isUuid($this->periodPlanId)) {
            throw new RuntimeException('aucun plan de période validé portant une version terminée avec ≥ 2 séances libres — la base est-elle seedée ?');
        }

        // Les versions déjà présentes sous ce plan — à épargner et à contrôler en fin (aucun résidu).
        $this->periodPreexistingVersionIds = $this->periodVersionIds();

        // Le VRAI parcours gestionnaire : « régénérer d'abord ». On ne verrouille jamais la version
        // CHOISIE (lecture seule, 409) ni le pointeur du seed. On génère une NOUVELLE version (overlay
        // DRAFT → generate, comme `useRegenerateOverlay`) : elle est COMPLETED et NON choisie, on peut
        // donc y poser des verrous par l'API, et elle devient la DONNÉE EN COURS (dernière COMPLETED
        // du plan) dont la régénération repartira.
        $overlay = $this->creerEtGenererOverlayDePeriode();
        if ('COMPLETED' !== $overlay['status']) {
            throw new RuntimeException(\sprintf('la version source de période aurait dû aboutir (COMPLETED), statut obtenu « %s »', $overlay['status']));
        }
        $this->periodSourceVersionId = $overlay['id'];
    }

    /**
     * V1bis — décor du scénario « Charger ». « Charger » (load-locks) n'est offert que sur un plan de
     * période NON validé (LoadLocksController refuse 409 un plan validé : « Rouvrez-le d'abord ») : on
     * ne peut donc pas l'éprouver sur le plan validé du seed. On construit un plan de période JETABLE,
     * non validé (patron de TrainingBlockContext) : entrée de fermeture jetable → plan → transcription
     * du socle (v1 COMPLETED, sans solve) → régénération (v2). v2 devient les DONNÉES EN COURS ; v1 est
     * la version plus ANCIENNE que « Charger » désignera. Aucune mutation du pointeur choisi du seed ;
     * tout est supprimé en fin de scénario (cascade de l'entrée) avec contrôle SQL d'absence de résidu.
     */
    #[Given('le club de démonstration, connecté, avec un plan de période jetable portant une version terminée plus ancienne que les données en cours')]
    public function leClubAvecPlanDePeriodeJetableAncienne(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // Ouvrir un plan de période exige un socle EN VIGUEUR (SocleGuard) : on le pose si vide (et on
        // le repose à NULL en fin), sans jamais toucher un pointeur déjà posé par le seed.
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if (!$this->isUuid($chosen)) {
            $target = $this->freshestCompletedSeasonVersion();
            if ('' === $target) {
                throw new RuntimeException('aucune version de saison COMPLETED pour pointer le socle — la base est-elle seedée ?');
            }
            $this->pointSocleTo($target);
            $this->socleSetForPeriod = true;
        }

        // Entrée de fermeture JETABLE (hors vacances scolaires) + son plan de période NON validé.
        $this->periodDisposableEntryId = $this->createDisposableClosureEntry();
        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->periodDisposableEntryId], $this->token);
        if (!\in_array($plan['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la création du plan de période jetable a répondu %d (201 attendu)', $plan['status']));
        }
        $this->periodPlanId = (string) ($plan['json']['id'] ?? '');
        if (!$this->isUuid($this->periodPlanId)) {
            throw new RuntimeException('la création du plan de période jetable n\'a pas rendu d\'identifiant');
        }

        // Plan NEUF : aucune version préexistante à épargner (le contrôle de résidu de fin exige 0).
        $this->periodPreexistingVersionIds = [];

        // Transcription du socle → v1 (COMPLETED, sans solve) : la version plus ANCIENNE, celle que
        // « Charger » désignera. On verrouille sur ELLE (periodSourceVersionId).
        $transcribed = $this->apiPost(\sprintf('schedule_plans/%s/transcribe-from-socle', $this->periodPlanId), [], $this->token);
        if (!\in_array($transcribed['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la transcription du socle a répondu %d (201 attendu)', $transcribed['status']));
        }
        $this->periodSourceVersionId = (string) ($transcribed['json']['id'] ?? '');
        if (!$this->isUuid($this->periodSourceVersionId)) {
            throw new RuntimeException('la transcription du socle n\'a pas rendu d\'identifiant de version');
        }
        $status = $this->pollUntilTerminal($this->periodSourceVersionId);
        if ('COMPLETED' !== $status) {
            throw new RuntimeException(\sprintf('la transcription du socle aurait dû aboutir (COMPLETED), statut obtenu « %s »', $status));
        }

        // La transcription verrouille (HARD) TOUTE séance copiée (PeriodPlanTranscriber) : pour
        // pouvoir éprouver « verrouiller des séances LIBRES », on en DÉVERROUILLE deux — d'origine
        // MANUAL (jamais une réservation, qu'on ne saurait pas restaurer). Elles deviennent NONE sur
        // v1, et la régénération qui suit les replacera librement (créneaux ouverts dans v2).
        $toFree = $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(id::text, \',\') AS behatval FROM ('
                . ' SELECT id FROM schedule_slot_template WHERE schedule_id=\'%s\' AND lock_level=\'HARD\' AND lock_origin=\'MANUAL\' ORDER BY id LIMIT 2'
                . ') q',
                $this->periodSourceVersionId,
            ),
            admin: true,
        );
        $freeIds = '' === $toFree ? [] : array_values(array_filter(explode(',', $toFree), $this->isUuid(...)));
        if (2 !== \count($freeIds)) {
            throw new RuntimeException('la transcription du socle aurait dû porter au moins deux séances HARD d\'origine MANUAL à déverrouiller');
        }
        foreach ($freeIds as $slotId) {
            $unlocked = $this->apiPost(\sprintf('schedule-slots/%s/manual-edit/lock', $slotId), ['lockLevel' => 'NONE'], $this->token);
            if (200 !== $unlocked['status']) {
                throw new RuntimeException(\sprintf('le déverrouillage d\'une séance transcrite a répondu %d (200 attendu)', $unlocked['status']));
            }
        }

        // Une régénération → v2, qui devient les DONNÉES EN COURS (dernière COMPLETED du plan). Sans
        // « Charger », la régénération finale repartirait de v2 et perdrait les verrous de v1 : c'est
        // ce que le scénario éprouve. v1 reste periodSourceVersionId.
        $v2 = $this->creerEtGenererOverlayDePeriode();
        if ('COMPLETED' !== $v2['status']) {
            throw new RuntimeException(\sprintf('la version « données en cours » de période aurait dû aboutir (COMPLETED), statut obtenu « %s »', $v2['status']));
        }
    }

    #[Given('deux séances libres de cette version verrouillées en dur')]
    public function deuxSeancesLibresVerrouillees(): void
    {
        $raw = $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(row, \';\') AS behatval FROM ('
                . ' SELECT id || \'|\' || team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') AS row'
                . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' AND lock_level=\'NONE\' ORDER BY id LIMIT 2'
                . ') q',
                $this->periodSourceVersionId,
            ),
            admin: true,
        );
        $rows = '' === $raw ? [] : explode(';', $raw);
        if (2 !== \count($rows)) {
            throw new RuntimeException('la version de période devait porter deux séances libres à verrouiller');
        }

        foreach ($rows as $encoded) {
            $cols = explode('|', $encoded);
            if (5 !== \count($cols)) {
                throw new RuntimeException('format de séance inattendu lors du verrouillage de période');
            }
            [$slotId, $teamId, $venueId, $day, $start] = $cols;
            $locked = $this->apiPost(
                \sprintf('schedule-slots/%s/manual-edit/lock', $slotId),
                ['lockLevel' => 'HARD'],
                $this->token,
            );
            if (200 !== $locked['status']) {
                throw new RuntimeException(\sprintf('le verrouillage d\'une séance de période a répondu %d (200 attendu)', $locked['status']));
            }
            $this->periodLockedSlots[] = ['slotId' => $slotId, 'teamId' => $teamId, 'venueId' => $venueId, 'day' => (int) $day, 'start' => $start];
        }
    }

    #[When('je charge les verrous de cette version')]
    public function jeChargeLesVerrousDeCetteVersion(): void
    {
        // V1bis — « Charger » : pose le pointeur de source des verrous du plan sur la version source
        // (le rail que `useLoadLocks` suit côté front). Ne génère pas ; la régénération qui suit
        // repartira de SES verrous.
        $loaded = $this->apiPost(\sprintf('schedules/%s/load-locks', $this->periodSourceVersionId), [], $this->token);
        if (200 !== $loaded['status']) {
            throw new RuntimeException(\sprintf('« Charger » les verrous d\'une version de période a répondu %d (200 attendu)', $loaded['status']));
        }
    }

    #[When('je régénère le plan de période')]
    #[When('je régénère encore le plan de période')]
    public function jeRegenereLePlanDePeriode(): void
    {
        $overlay = $this->creerEtGenererOverlayDePeriode();
        $this->periodLastRegeneratedId = $overlay['id'];
        $this->periodFinalStatus = $overlay['status'];
    }

    #[Then('les deux séances reviennent verrouillées, aux mêmes cases')]
    public function lesDeuxSeancesReviennentVerrouillees(): void
    {
        if ('COMPLETED' !== $this->periodFinalStatus) {
            throw new RuntimeException(\sprintf('la régénération de période aurait dû aboutir (COMPLETED), statut obtenu « %s »', $this->periodFinalStatus));
        }
        if ([] === $this->periodLockedSlots) {
            throw new RuntimeException('aucune séance verrouillée à vérifier — le décor a-t-il bien verrouillé ?');
        }

        foreach ($this->periodLockedSlots as $slot) {
            $count = (int) $this->dbalScalar(
                \sprintf(
                    'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\' AND lock_level=\'HARD\'',
                    $this->periodLastRegeneratedId,
                    $slot['teamId'],
                    $slot['venueId'],
                    $slot['day'],
                    $slot['start'],
                ),
                admin: true,
            );
            if ($count < 1) {
                throw new RuntimeException('une séance verrouillée d\'un plan de période aurait dû revenir VERROUILLÉE (HARD) à la même case après régénération');
            }
        }
    }

    #[Given('une séance de cette version verrouillée en dur')]
    public function uneSeanceVerrouilleeEnDur(): void
    {
        // Une séance LIBRE (verrou NONE) : le verrou HARD et son retrait se font alors proprement par
        // l'API, sans jamais toucher un verrou seedé (réservation) qu'on ne saurait pas restaurer.
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT id || \'|\' || team_id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') AS behatval'
                . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' AND lock_level=\'NONE\' ORDER BY id LIMIT 1',
                $this->socleVersionId,
            ),
            admin: true,
        );
        $parts = '' === $row ? [] : explode('|', $row);
        if (5 !== \count($parts)) {
            throw new RuntimeException('aucune séance libre sur la version de saison en vigueur — la base est-elle seedée ?');
        }
        [$this->slotId, $this->lockTeamId, $this->lockVenueId, $day, $this->lockStartTime] = $parts;
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

    #[Given('une règle qui interdit à cette équipe de s\'entraîner ce jour-là')]
    public function uneRegleQuiInterditCeJour(): void
    {
        $created = $this->apiPost('constraints', [
            'name' => self::DAY_RULE_NAME,
            'scope' => 'TEAM',
            'scopeTargetId' => $this->lockTeamId,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [$this->lockDay]],
            'isActive' => true,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la création de la règle de jours a répondu %d (201 attendu)', $created['status']));
        }
        $this->constraintId = (string) ($created['json']['id'] ?? '');
        if ('' === $this->constraintId) {
            throw new RuntimeException('la règle de jours n\'a pas rendu d\'identifiant');
        }
    }

    #[Given('une règle « au moins une séance ce jour-là » pour cette équipe')]
    public function uneRegleAuMoinsUneSeanceCeJour(): void
    {
        // ALIGN-16 — un jour IMPOSÉ (forcedDays) sur le jour de la séance verrouillée. Le verrou
        // n'a pas de variable côté moteur ; sans le correctif, « au moins une séance ce jour »
        // posé sur un jour dont les créneaux libres seraient fermés rendrait la génération
        // infaisable. Le verrou SATISFAIT le jour imposé → la régénération doit aboutir.
        $created = $this->apiPost('constraints', [
            'name' => 'Au moins une séance ce jour (fonctionnel)',
            'scope' => 'TEAM',
            'scopeTargetId' => $this->lockTeamId,
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forcedDays' => [$this->lockDay]],
            'isActive' => true,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la création de la règle « au moins une séance » a répondu %d (201 attendu)', $created['status']));
        }
        $this->constraintId = (string) ($created['json']['id'] ?? '');
        if ('' === $this->constraintId) {
            throw new RuntimeException('la règle « au moins une séance » n\'a pas rendu d\'identifiant');
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

    #[Then('la régénération aboutit, le verrou satisfaisant le jour imposé')]
    public function laRegenerationAboutitVerrouSatisfaitJourImpose(): void
    {
        // ALIGN-16 — une séance verrouillée le jour imposé SATISFAIT « au moins une séance ce
        // jour » : la génération ne doit jamais échouer pour ce motif, et le verrou reste intact.
        if ('COMPLETED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la régénération aurait dû aboutir (COMPLETED) : une séance verrouillée le jour imposé satisfait « au moins une séance ce jour-là » ; statut obtenu « %s »', $this->finalStatus));
        }

        $this->assertSeanceALaMemeCaseDans($this->regeneratedId);
    }

    #[Then('le verrou reste à sa case et la règle violée est signalée')]
    public function leVerrouResteEtLaRegleEstSignalee(): void
    {
        if ('COMPLETED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la régénération aurait dû aboutir (COMPLETED) : un verrou est souverain, il n\'échoue pas sur une règle contraire ; statut obtenu « %s »', $this->finalStatus));
        }

        // Le verrou GAGNE : la séance reste exactement à sa case malgré la règle qui l'interdit.
        $this->assertSeanceALaMemeCaseDans($this->regeneratedId);

        // La règle violée est SIGNALÉE : un diagnostic de la version régénérée, pour cette équipe,
        // nomme la règle de jours entrée en conflit avec le verrou.
        $signalements = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_diagnostic WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND message LIKE \'%%%s%%\'',
                $this->regeneratedId,
                $this->lockTeamId,
                self::DAY_RULE_NAME,
            ),
            admin: true,
        );
        if ($signalements < 1) {
            throw new RuntimeException('la règle de jours contredite par le verrou aurait dû être signalée par un diagnostic nommant la règle');
        }
    }

    /**
     * Retire la règle et le gymnase jetable, ENLÈVE le verrou de la séance libre (API), SUPPRIME les
     * versions nées de la régénération (DELETE API : purge séances/diagnostics et relâche le pointeur)
     * et restaure le pointeur du socle. Quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token || '' === $this->clubId) {
            return;
        }

        // V1 (période) — SUPPRIMER toutes les versions de période nées DANS le scénario (la version
        // SOURCE comme les régénérations) par DELETE API : le processeur purge séances/diagnostics et
        // relâche le pointeur. Aucune n'est la version choisie du seed — on ne l'a jamais touchée —
        // donc toutes sont supprimables, et les verrous posés sur la source disparaissent avec elle.
        // Le pointeur du plan de période N'EST JAMAIS touché ici (on n'a ni validé ni rouvert la période).
        foreach ($this->periodCreatedVersionIds as $versionId) {
            $this->apiDelete(\sprintf('schedules/%s', $versionId), $this->token);
        }
        // V1bis — le scénario « Charger » pose le pointeur de source des verrous (schedule_plan.
        // lock_source_schedule_id) sur une version du plan de période. La suppression ci-dessus des
        // versions créées DANS le scénario le relâche déjà (FK ON DELETE SET NULL, la version pointée
        // en fait partie), mais un scénario interrompu AVANT ces suppressions le laisserait posé. On
        // le remet à NULL (état de la branche) quoi qu'il arrive.
        if ('' !== $this->periodPlanId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET lock_source_schedule_id = NULL WHERE id = \'%s\'', $this->periodPlanId),
                admin: true,
            );
        }
        // V1bis (scénario « Charger ») — SUPPRIMER l'entrée de calendrier JETABLE : sa suppression
        // cascade le plan de période, toutes ses versions (la transcription v1 comprise, non tracée
        // ci-dessus) et leurs séances/verrous. Puis, si c'est NOUS qui avons pointé le socle pour
        // ouvrir ce plan, le remettre à NULL — jamais si le seed le pointait déjà.
        if ('' !== $this->periodDisposableEntryId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->periodDisposableEntryId), $this->token);
        }
        if ($this->socleSetForPeriod) {
            $this->pointSocleTo('');
        }

        if ('' !== $this->constraintId) {
            $this->apiDelete(\sprintf('constraints/%s', $this->constraintId), $this->token);
        }

        // La séance choisie était LIBRE : retirer le verrou la remet dans son état d'origine (NONE).
        if ('' !== $this->slotId) {
            $this->apiPost(
                \sprintf('schedule-slots/%s/manual-edit/lock', $this->slotId),
                ['lockLevel' => 'NONE'],
                $this->token,
            );
        }

        // Supprime toute version de saison née depuis l'entrée (la régénération). Le socle étant
        // dépointé, la version née n'est pas la version choisie et n'est pas la SEULE terminée du
        // plan (les préexistantes demeurent) : le DELETE API passe et purge ses enfants.
        foreach ($this->seasonVersionIds() as $versionId) {
            if (\in_array($versionId, $this->preexistingVersionIds, true)) {
                continue;
            }
            $this->apiDelete(\sprintf('schedules/%s', $versionId), $this->token);
        }

        // Restaure « socle en vigueur » (SQL brut, voir l'en-tête) : la version d'entrée si elle
        // existe encore, sinon la plus fraîche COMPLETED parmi les préexistantes. UNIQUEMENT si le
        // scénario a RÉOUVERT le socle — un scénario de PÉRIODE n'y a jamais touché, le repointer
        // corromprait le pointeur du seed pour les runs suivants.
        if ($this->reopenedSocle) {
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

        // V1 (période) — contrôle du nettoyage : plus AUCUNE version de période créée DANS le scénario
        // ne subsiste sous le plan (toutes supprimées ; seules les préexistantes du seed demeurent).
        if ('' !== $this->periodPlanId) {
            $residus = array_values(array_diff($this->periodVersionIds(), $this->periodPreexistingVersionIds));
            if ([] !== $residus) {
                throw new RuntimeException(\sprintf('nettoyage de période incomplet : %d version(s) créée(s) dans le scénario subsistent sous le plan', \count($residus)));
            }
        }
    }

    /**
     * Crée une NOUVELLE version sous le plan de période (overlay DRAFT nu), la génère et attend son
     * verdict. C'est le rail que `useRegenerateOverlay` suit côté front (créer + generate) : la
     * version repart des DONNÉES EN COURS (la dernière version COMPLETED du plan,
     * {@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan}) et en réépingle les
     * verrous HARD. Chaque version créée est tracée pour le nettoyage de fin de scénario.
     *
     * @return array{id: string, status: string}
     */
    private function creerEtGenererOverlayDePeriode(): array
    {
        $created = $this->apiPost('schedules', ['schedulePlanId' => $this->periodPlanId, 'status' => 'DRAFT'], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la création de la version de période a répondu %d (201 attendu)', $created['status']));
        }
        $newId = (string) ($created['json']['id'] ?? '');
        if ('' === $newId) {
            throw new RuntimeException('la création de la version de période n\'a pas rendu d\'identifiant');
        }
        $this->periodCreatedVersionIds[] = $newId;

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $newId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('le déclenchement de la génération de période a répondu %d (202 attendu)', $launched['status']));
        }

        return ['id' => $newId, 'status' => $this->pollUntilTerminal($newId)];
    }

    private function assertSeanceALaMemeCaseDans(string $scheduleId): void
    {
        $count = $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND venue_id=\'%s\' AND day_of_week=%d AND to_char(start_time, \'HH24:MI\')=\'%s\'',
                $scheduleId,
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

    /** @return list<string> Toutes les versions (quel que soit le statut) sous le plan de période courant. */
    private function periodVersionIds(): array
    {
        if ('' === $this->periodPlanId) {
            return [];
        }

        $raw = $this->dbalScalar(
            \sprintf('SELECT string_agg(id::text, \',\') AS behatval FROM schedule WHERE schedule_plan_id=\'%s\'', $this->periodPlanId),
            admin: true,
        );

        return array_values(array_filter(explode(',', $raw), $this->isUuid(...)));
    }

    /**
     * V1bis — crée une entrée de calendrier de fermeture JETABLE (kind=period, closure) sur une
     * fenêtre de 4 jours SANS vacances scolaires de la zone du club, et rend son identifiant.
     * Patron de TrainingBlockContext / PeriodOverlayContext.
     */
    private function createDisposableClosureEntry(): string
    {
        $start = $this->holidayFreeMonday(56);
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));

        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => 'Charger les verrous (fonctionnel)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        if (!\in_array($entry['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la création de la période jetable a répondu %d (201 attendu)', $entry['status']));
        }
        $id = (string) ($entry['json']['id'] ?? '');
        if (!$this->isUuid($id)) {
            throw new RuntimeException('la création de la période jetable n\'a pas rendu d\'identifiant');
        }

        return $id;
    }

    /**
     * Le lundi (Y-m-d) d'une fenêtre de 4 jours SANS vacances scolaires de la zone du club, à partir de
     * « next monday +$offsetDays », en glissant de semaine en semaine. Un décor relatif à aujourd'hui
     * traverserait sinon les vacances au fil de l'année (patron TrainingBlockContext).
     */
    private function holidayFreeMonday(int $offsetDays): string
    {
        $monday = (int) strtotime(\sprintf('next monday +%d days', $offsetDays));
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $start = date('Y-m-d', $monday);
            $end = date('Y-m-d', (int) strtotime('+4 days', $monday));
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

        throw new RuntimeException(\sprintf('aucune fenêtre de 4 jours sans vacances scolaires trouvée après « next monday +%d days »', $offsetDays));
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
