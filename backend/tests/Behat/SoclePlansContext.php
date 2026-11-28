<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\SocleGuard;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Le planning de saison commande les plannings de période (ADR-0002), sur la stack qui tourne.
 *
 * Prouve la souveraineté du socle : le valider efface les plannings de période ENTIÈREMENT à venir
 * (critère `findWithPlanNotStarted` : date de début > aujourd'hui) et épargne ceux déjà commencés ;
 * le retravailler (lot 5, PR 5) ne détruit rien, garde ce qui attend la validation, et l'état « en
 * attente » (`GET /api/schedule_plans/{id}/pending-changes`) le dit ; revenir au planning en vigueur
 * (« Charger » la version en vigueur) abandonne les modifications ; des vacances nées pendant des
 * modifications prennent la grille en vigueur ; aucun planning de période ne naît sans socle jamais
 * validé (`SocleGuard::assertSeasonPlanChosen`, décor : un petit club neuf).
 *
 * Décor : club de démonstration. Chaque scénario forge SES deux plannings de période — un à venir
 * (par le vrai geste « Adapter », POST /schedule_plans) et un déjà commencé (semé en base : l'API
 * refuse une fenêtre passée) — puis restaure le pointeur du socle et retire ce qu'il a créé, quoi
 * qu'il arrive. Le geste « valider » ne détruit que lorsqu'il DÉPLACE la base : on dépointe le socle
 * en silence avant de re-valider la même version, exactement le cas « pointeur NULL » que la garde
 * destructive n'exempte pas.
 */
final class SoclePlansContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private string $token = '';

    private string $clubId = '';

    private string $seasonId = '';

    /** Version que le socle pointait à l'entrée du scénario (à restaurer). */
    private string $chosenAtEntry = '';

    /** Planning de période à venir, né du vrai geste « Adapter » (destiné à disparaître). */
    private string $futureEntryId = '';

    private string $futurePlanId = '';

    /** Planning de période déjà commencé, semé en base (destiné à subsister). */
    private string $startedEntryId = '';

    private string $startedPlanId = '';

    /** Collecte de doléances sur des vacances à venir (Q8bis : détruite par la bascule du socle). */
    private string $collecteEntryId = '';

    private string $collecteCampaignId = '';

    private string $collecteWishId = '';

    /** Entrée de la période dont on tente l'ouverture sans socle (scénario refus). */
    private string $refusedEntryId = '';

    /** Vacances à venir adaptées sur la grille du socle (scénario des deux grilles). */
    private string $holidayEntryId = '';

    private string $holidayPlanId = '';

    /** Créneau ajouté à la grille du socle par le scénario, retiré en fin. */
    private string $addedSocleSlotId = '';

    private int $refusedStatus = 0;

    private string $refusedError = '';

    /** La copie de travail née de « Retravailler ». */
    private string $workCopyId = '';

    /** Créneau ajouté à la grille du socle sans valider (scénario des deux grilles), retiré en fin. */
    private string $pendingSocleSlotId = '';

    private string $pendingSocleSlot = '';

    /**
     * Le lien de coach principal changé sans valider : la ligne d'origine (id|équipe|coach|saison|créée le), et le lien
     * posé à sa place. Le nettoyage remet la ligne d'origine, sous son identifiant : la copie en vigueur la cite.
     */
    private string $originalMainLink = '';

    private string $replacingMainLinkId = '';

    private string $replacingCoachId = '';

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

        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $this->seasonId) {
            throw new RuntimeException('aucun planning de saison — la base est-elle seedée ?');
        }

        // Ouvrir un planning de période exige le socle en vigueur : s'il ne pointe rien, on pose la
        // version COMPLETED la plus fraîche le temps du scénario (restaurée en fin).
        $this->chosenAtEntry = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $this->chosenAtEntry) {
            $this->chosenAtEntry = $this->freshestCompletedSeasonVersion();
            if ('' === $this->chosenAtEntry) {
                throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
            }
            $this->pointSocleTo($this->chosenAtEntry);
        }
    }

    #[Given('un planning de période à venir et un planning de période déjà commencé')]
    public function unPlanAVenirEtUnPlanCommence(): void
    {
        // À VENIR : le vrai geste « Adapter » sur une fermeture d'une semaine pleine (lun→ven = un
        // seul segment, accepté « d'un bloc »), bien au-delà des fenêtres seedées.
        $start = date('Y-m-d', (int) strtotime('next monday +105 days'));
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => 'Fermeture à venir (fonctionnel socle)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $this->futureEntryId = $this->idOf($entry, 'période à venir');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->futureEntryId], $this->token);
        $this->futurePlanId = $this->idOf($plan, 'planning de période à venir');

        // DÉJÀ COMMENCÉ : semé en base (l'API refuse une fenêtre passée). Fenêtre à cheval sur
        // aujourd'hui → date de début ≤ aujourd'hui, donc hors du critère « à venir ».
        $this->startedEntryId = $this->uuid();
        $this->startedPlanId = $this->uuid();
        $startedStart = date('Y-m-d', (int) strtotime('-3 days'));
        $startedEnd = date('Y-m-d', (int) strtotime('+3 days'));
        $this->dbalExec(
            \sprintf(
                'INSERT INTO calendar_entry (id, created_at, updated_at, club_id, season_id, kind, title, start_date, end_date, is_disruptive, period_type, status)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'period\', \'Fermeture déjà commencée (fonctionnel socle)\', \'%s\', \'%s\', false, \'closure\', \'active\')',
                $this->startedEntryId,
                $this->clubId,
                $this->seasonId,
                $startedStart,
                $startedEnd,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO schedule_plan (id, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, calendar_entry_id)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'CLOSURE\', \'Fermeture déjà commencée (fonctionnel socle)\', \'%s\', \'%s\', \'%s\')',
                $this->startedPlanId,
                $this->clubId,
                $this->seasonId,
                $startedStart,
                $startedEnd,
                $this->startedEntryId,
            ),
            admin: true,
        );
    }

    #[Given('une collecte de doléances ouverte sur des vacances à venir')]
    public function uneCollecteSurVacancesAVenir(): void
    {
        // Vacances À VENIR, adaptées par le vrai geste « Adapter » → un plan de période qui entre
        // dans la bascule du socle (`findWithPlanNotStarted`). Fenêtre bien au-delà des seeds et
        // des autres scénarios (qui utilisent +105/+112 jours).
        $start = date('Y-m-d', (int) strtotime('next monday +126 days'));
        $end = date('Y-m-d', (int) strtotime($start . ' +11 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Vacances à venir (collecte fonctionnel)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $this->collecteEntryId = $this->idOf($entry, 'vacances à venir');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->collecteEntryId], $this->token);
        $this->idOf($plan, 'planning des vacances à venir'); // overlay futur → entre dans la bascule

        // Collecte + une doléance, semées en base, ancrées à la MÈRE des vacances (coach facultatif).
        $this->collecteCampaignId = $this->uuid();
        $this->collecteWishId = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO coach_wish_campaign (id, version, created_at, updated_at, club_id, season_id, calendar_entry_id, deadline, weeks, team_ids)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', \'[]\', \'[]\')',
                $this->collecteCampaignId,
                $this->clubId,
                $this->seasonId,
                $this->collecteEntryId,
                $end,
            ),
            admin: true,
        );
        $this->dbalExec(
            \sprintf(
                'INSERT INTO coach_wish (id, version, created_at, updated_at, club_id, season_id, calendar_entry_id, week_start, team_id, slots_wanted, unavailable_days)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', 2, \'[]\')',
                $this->collecteWishId,
                $this->clubId,
                $this->seasonId,
                $this->collecteEntryId,
                $start,
                $this->uuid(),
            ),
            admin: true,
        );
    }

    #[When('je valide le planning de saison')]
    public function jeValideLePlanningDeSaison(): void
    {
        // Valider ne détruit que lorsqu'il DÉPLACE la base — c.-à-d. lorsqu'il choisit une version
        // qui n'est pas celle déjà pointée. On dépointe donc en silence (sans le geste « Rouvrir »,
        // qui détruirait déjà), puis on re-valide la même version : la garde voit un pointeur NULL,
        // qu'elle n'exempte pas, et efface les plannings de période à venir.
        $this->pointSocleTo('');
        // Seule la version chargée se valide. Le dépointage silencieux ci-dessus laisse des sœurs qu'un
        // vrai parcours n'a pas (valider les supprime) : on la désigne, en SQL pour la même raison.
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET lock_source_schedule_id=\'%s\' WHERE season_id=\'%s\' AND type=\'SEASON\'', $this->chosenAtEntry, $this->seasonId),
            admin: true,
        );

        $validated = $this->apiPost(
            \sprintf('schedules/%s/validate', $this->chosenAtEntry),
            ['confirmDeleteOverlays' => true],
            $this->token,
        );
        if (200 !== $validated['status']) {
            throw new RuntimeException(\sprintf('la validation du planning de saison a répondu %d (200 attendu)', $validated['status']));
        }
    }

    #[When('je retravaille le planning de saison')]
    public function jeRetravailleLePlanningDeSaison(): void
    {
        $reworked = $this->apiPost(\sprintf('schedules/%s/rework', $this->chosenAtEntry), [], $this->token);
        if (201 !== $reworked['status']) {
            throw new RuntimeException(\sprintf('retravailler le planning de saison a répondu %d (201 attendu) — corps : %s', $reworked['status'], json_encode($reworked['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->workCopyId = $this->idOf($reworked, 'copie de travail');
    }

    #[Then('les plannings de période subsistent et les matchs restent ouverts')]
    public function lesPlanningsDePeriodeSubsistentEtLesMatchsRestentOuverts(): void
    {
        $plans = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\' OR id=\'%s\'', $this->futureEntryId, $this->startedPlanId),
            admin: true,
        );
        if ('2' !== $plans) {
            throw new RuntimeException(\sprintf('retravailler ne détruit rien : les deux plannings de période (à venir, commencé) devraient subsister, %s trouvé(s)', $plans));
        }
        // Un geste du module des matchs qui bâtit sur le planning de saison en vigueur (SocleGuard) : jamais refusé.
        $matches = $this->apiGet('fixtures/league-validation', $this->token);
        if (409 === $matches['status']) {
            throw new RuntimeException('les matchs devraient rester ouverts pendant qu\'on retravaille le planning de saison (409 obtenu)');
        }
    }

    #[Then('la collecte et ses doléances des vacances à venir subsistent')]
    public function laCollecteSubsiste(): void
    {
        $campaign = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM coach_wish_campaign WHERE id=\'%s\'', $this->collecteCampaignId), admin: true);
        $wish = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM coach_wish WHERE id=\'%s\'', $this->collecteWishId), admin: true);
        if ('1' !== $campaign || '1' !== $wish) {
            throw new RuntimeException(\sprintf('retravailler ne détruit rien : la collecte (%s) et sa doléance (%s) devraient subsister', $campaign, $wish));
        }
    }

    #[Then('le planning de saison en vigueur reste le même, et la copie de travail est la version chargée')]
    public function lEnVigueurResteLeMeme(): void
    {
        $pointers = $this->dbalScalar(
            \sprintf('SELECT COALESCE(chosen_schedule_id::text, \'-\') || \'|\' || COALESCE(lock_source_schedule_id::text, \'-\') AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND season_id=\'%s\'', $this->clubId, $this->seasonId),
            admin: true,
        );
        if ($pointers !== $this->chosenAtEntry . '|' . $this->workCopyId) {
            throw new RuntimeException(\sprintf('attendu : en vigueur %s, chargée %s (la copie de travail) ; lu : %s', $this->chosenAtEntry, $this->workCopyId, $pointers));
        }
    }

    #[Then('la copie de travail est à jour')]
    public function laCopieDeTravailEstAJour(): void
    {
        $version = $this->apiGet(\sprintf('schedules/%s', $this->workCopyId), $this->token);
        $snapshotHash = $version['json']['snapshotHash'] ?? null;
        $current = $this->apiGet(\sprintf('schedule_plans/%s/structure-hash', $this->soclePlanId()), $this->token);
        $currentHash = $current['json']['currentStructureHash'] ?? null;
        if (!\is_string($snapshotHash) || !\is_string($currentHash)) {
            throw new RuntimeException(\sprintf('empreintes illisibles : copie de travail (HTTP %d), plan (HTTP %d)', $version['status'], $current['status']));
        }
        if ($snapshotHash !== $currentHash) {
            throw new RuntimeException('la copie de travail, née sans rien en attente, devrait être à jour (son empreinte = celle du plan)');
        }
    }

    #[Given('le coach principal d\'une équipe est changé, sans valider')]
    public function leCoachPrincipalDUneEquipeEstChange(): void
    {
        // Une équipe tenue par UN coach principal, dont le lien est dans la copie de la version en vigueur, et un autre
        // coach du club qui ne l'entraîne pas. Le geste du Wizard : retirer le lien, poser le nouveau.
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT tc.id || \'|\' || tc.team_id || \'|\' || tc.coach_id || \'|\' || tc.season_id || \'|\' || tc.created_at::text AS behatval FROM team_coach tc'
                . ' JOIN schedule_state ss ON ss.schedule_id = \'%s\''
                . ' WHERE tc.club_id = \'%s\' AND tc.season_id = \'%s\' AND tc.role = \'MAIN\' AND jsonb_exists(ss.data::jsonb -> \'TeamCoach\', tc.id::text)'
                . ' AND (SELECT COUNT(*) FROM team_coach o WHERE o.team_id = tc.team_id AND o.role = \'MAIN\') = 1 ORDER BY tc.id LIMIT 1',
                $this->chosenAtEntry,
                $this->clubId,
                $this->seasonId,
            ),
            admin: true,
        );
        $parts = explode('|', $row);
        if (5 !== \count($parts)) {
            throw new RuntimeException('aucun coach principal de la copie en vigueur à changer — la base est-elle seedée ?');
        }
        [$linkId, $teamId, $coachId] = $parts;
        $this->replacingCoachId = $this->dbalScalar(
            \sprintf(
                'SELECT c.id AS behatval FROM coach c WHERE c.club_id = \'%s\' AND c.season_id = \'%s\' AND c.id <> \'%s\' AND NOT EXISTS (SELECT 1 FROM team_coach x WHERE x.team_id = \'%s\' AND x.coach_id = c.id) ORDER BY c.id LIMIT 1',
                $this->clubId,
                $this->seasonId,
                $coachId,
                $teamId,
            ),
            admin: true,
        );
        if ('' === $this->replacingCoachId) {
            throw new RuntimeException('aucun autre coach du club pour reprendre l\'équipe');
        }

        $removed = $this->apiDelete(\sprintf('team_coaches/%s', $linkId), $this->token);
        if (204 !== $removed['status']) {
            throw new RuntimeException(\sprintf('retirer le coach principal a répondu %d (204 attendu)', $removed['status']));
        }
        $this->originalMainLink = $row;
        $this->replacingMainLinkId = $this->idOf($this->apiPost('team_coaches', ['teamId' => $teamId, 'coachId' => $this->replacingCoachId, 'role' => 'MAIN'], $this->token), 'nouveau coach principal');
    }

    #[Then('le changement de coach est toujours en attente')]
    public function leChangementDeCoachEstToujoursEnAttente(): void
    {
        $pending = $this->pendingChanges();
        if (true !== ($pending['pending'] ?? null) || !\in_array('TeamCoach', $this->changedFamilies($pending), true)) {
            throw new RuntimeException(\sprintf('le changement de coach principal devrait toujours attendre la validation ; lu : %s', json_encode($pending, \JSON_UNESCAPED_UNICODE)));
        }
        [, $teamId] = explode('|', $this->originalMainLink);
        $live = $this->dbalScalar(\sprintf('SELECT coach_id AS behatval FROM team_coach WHERE team_id = \'%s\' AND role = \'MAIN\'', $teamId), admin: true);
        if ($live !== $this->replacingCoachId) {
            throw new RuntimeException('retravailler ne remet pas l\'état de travail : le nouveau coach principal devrait rester posé');
        }
    }

    #[Then('rien n\'attend la validation')]
    public function rienNAttendLaValidation(): void
    {
        $pending = $this->pendingChanges();
        if (false !== ($pending['pending'] ?? null) || false !== ($pending['hasWorkVersion'] ?? null) || [] !== ($pending['changes'] ?? null)) {
            throw new RuntimeException(\sprintf('rien ne devrait attendre la validation ; lu : %s', json_encode($pending, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('des modifications attendent la validation')]
    public function desModificationsAttendent(): void
    {
        $pending = $this->pendingChanges();
        if (true !== ($pending['pending'] ?? null) || false !== ($pending['hasWorkVersion'] ?? null) || !\in_array('TeamCoach', $this->changedFamilies($pending), true)) {
            throw new RuntimeException(\sprintf('le coach principal changé devrait attendre la validation, sans copie de travail ; lu : %s', json_encode($pending, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('des modifications attendent la validation, sur une copie de travail')]
    public function desModificationsAttendentSurUneCopie(): void
    {
        $pending = $this->pendingChanges();
        if (true !== ($pending['pending'] ?? null) || true !== ($pending['hasWorkVersion'] ?? null)) {
            throw new RuntimeException(\sprintf('une copie de travail devrait attendre la validation ; lu : %s', json_encode($pending, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('je reviens au planning en vigueur')]
    public function jeReviensAuPlanningEnVigueur(): void
    {
        $loaded = $this->apiPost(\sprintf('schedules/%s/load', $this->chosenAtEntry), [], $this->token);
        if (200 !== $loaded['status']) {
            throw new RuntimeException(\sprintf('revenir au planning en vigueur a répondu %d (200 attendu) — corps : %s', $loaded['status'], json_encode($loaded['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('l\'équipe a retrouvé son coach principal')]
    public function lEquipeARetrouveSonCoachPrincipal(): void
    {
        [, $teamId, $coachId] = explode('|', $this->originalMainLink);
        $live = $this->dbalScalar(\sprintf('SELECT string_agg(coach_id::text, \',\') AS behatval FROM team_coach WHERE team_id = \'%s\' AND role = \'MAIN\'', $teamId), admin: true);
        if ($live !== $coachId) {
            throw new RuntimeException(\sprintf('revenir au planning en vigueur remet le coach principal validé (%s) ; lu : %s', $coachId, $live));
        }
    }

    #[When('je valide la copie de travail')]
    public function jeValideLaCopieDeTravail(): void
    {
        $validated = $this->apiPost(\sprintf('schedules/%s/validate', $this->workCopyId), ['confirmDeleteOverlays' => true], $this->token);
        if (200 !== $validated['status']) {
            throw new RuntimeException(\sprintf('valider la copie de travail a répondu %d (200 attendu)', $validated['status']));
        }
    }

    #[Given('un créneau ajouté à la grille du planning de saison, sans valider')]
    public function unCreneauAjouteSansValider(): void
    {
        $venueId = $this->dbalScalar(
            \sprintf('SELECT venue_id AS behatval FROM venue_training_slot WHERE schedule_plan_id = \'%s\' ORDER BY id LIMIT 1', $this->soclePlanId()),
            admin: true,
        );
        if ('' === $venueId) {
            throw new RuntimeException('la grille du planning de saison est vide — la base est-elle seedée ?');
        }
        // Le dimanche à 8 h : hors de toute grille seedée, et distinct du créneau de l'étape suivante (7 h).
        $slot = $this->apiPost('venue_training_slots', ['venueId' => $venueId, 'dayOfWeek' => 7, 'startTime' => '08:00', 'durationMinutes' => 60, 'capacity' => 1], $this->token);
        if (201 !== $slot['status']) {
            throw new RuntimeException(\sprintf('ajouter un créneau à la grille du planning de saison a répondu %d (201 attendu)', $slot['status']));
        }
        $this->pendingSocleSlotId = $this->idOf($slot, 'créneau de saison en attente');
        $this->pendingSocleSlot = \sprintf('%s|7|08:00|60|1', $venueId);
    }

    #[Then('la grille des vacances est celle du planning de saison en vigueur, sans le créneau ajouté depuis')]
    public function laGrilleDesVacancesEstCelleEnVigueur(): void
    {
        $socle = $this->gridOf('');
        if (!\in_array($this->pendingSocleSlot, $socle, true)) {
            throw new RuntimeException('témoin : le créneau ajouté sans valider devrait figurer dans la grille de travail du planning de saison');
        }
        $inForce = array_values(array_filter($socle, fn (string $slot): bool => $slot !== $this->pendingSocleSlot));
        $holiday = $this->gridOf($this->holidayPlanId);
        if ($inForce !== $holiday) {
            throw new RuntimeException(\sprintf('la grille des vacances (%d créneaux) devrait être celle du planning de saison en vigueur (%d créneaux), sans le créneau ajouté depuis', \count($holiday), \count($inForce)));
        }
    }

    #[Given('un petit club neuf, dont le planning de saison n\'a jamais été validé')]
    public function unPetitClubNeufJamaisValide(): void
    {
        $this->registerSmallClub();
        $this->token = $this->smallClubToken;
        $this->clubId = $this->smallClubId;
        $chosen = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL', $this->clubId), admin: true);
        if ('0' !== $chosen) {
            throw new RuntimeException('témoin : le planning de saison d\'un club neuf n\'a jamais été validé');
        }
    }

    #[Then('le planning de période à venir a disparu et celui déjà commencé subsiste')]
    public function lePlanAVenirADisparuLautreSubsiste(): void
    {
        $future = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $this->futureEntryId),
            admin: true,
        );
        if ('0' !== $future) {
            throw new RuntimeException('le planning de période à venir aurait dû disparaître avec la bascule du socle');
        }

        $started = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE id=\'%s\'', $this->startedPlanId),
            admin: true,
        );
        if ('1' !== $started) {
            throw new RuntimeException('le planning de période déjà commencé aurait dû subsister — jamais détruit au milieu');
        }
    }

    #[Then('la collecte et ses doléances des vacances à venir ont disparu')]
    public function laCollecteADisparu(): void
    {
        $campaign = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM coach_wish_campaign WHERE calendar_entry_id=\'%s\'', $this->collecteEntryId),
            admin: true,
        );
        if ('0' !== $campaign) {
            throw new RuntimeException('la collecte des vacances à venir aurait dû disparaître avec la bascule du socle');
        }

        $wishes = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM coach_wish WHERE calendar_entry_id=\'%s\'', $this->collecteEntryId),
            admin: true,
        );
        if ('0' !== $wishes) {
            throw new RuntimeException('les doléances des vacances à venir auraient dû disparaître avec la collecte');
        }
    }

    #[Given('des vacances à venir, adaptées sur la grille du planning de saison')]
    public function desVacancesAVenirAdaptees(): void
    {
        // Fenêtre au-delà de celles des autres scénarios (+105, +112, +126 jours).
        $start = date('Y-m-d', (int) strtotime('next monday +140 days'));
        $end = date('Y-m-d', (int) strtotime($start . ' +6 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Vacances à venir (fonctionnel grilles)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $this->holidayEntryId = $this->idOf($entry, 'vacances à venir');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->holidayEntryId], $this->token);
        $this->holidayPlanId = $this->idOf($plan, 'planning des vacances à venir');
    }

    #[Then('modifier la grille du planning de saison ne change pas la grille des vacances')]
    public function modifierLaGrilleDuSocleNeChangePasLesVacances(): void
    {
        $holidayBefore = $this->gridOf($this->holidayPlanId);
        $venueId = explode('|', $holidayBefore[0])[0];

        // Un créneau de saison neuf, le dimanche à 7 h : hors de toute grille seedée.
        $slot = $this->apiPost('venue_training_slots', [
            'venueId' => $venueId,
            'dayOfWeek' => 7,
            'startTime' => '07:00',
            'durationMinutes' => 60,
            'capacity' => 1,
        ], $this->token);
        if (201 !== $slot['status']) {
            throw new RuntimeException(\sprintf('ajouter un créneau à la grille du planning de saison a répondu %d (201 attendu)', $slot['status']));
        }
        $this->addedSocleSlotId = $this->idOf($slot, 'créneau de saison');

        $added = \sprintf('%s|7|07:00|60|1', $venueId);
        $socle = $this->gridOf('');
        if (!\in_array($added, $socle, true)) {
            throw new RuntimeException(\sprintf('le créneau ajouté (%s) devrait figurer dans la grille du planning de saison (%d créneaux lus)', $added, \count($socle)));
        }
        if ($holidayBefore !== $this->gridOf($this->holidayPlanId)) {
            throw new RuntimeException('ajouter un créneau au planning de saison a changé la grille des vacances — chacun devrait garder la sienne');
        }
    }

    #[When('je tente d\'ouvrir le planning d\'une période à venir')]
    public function jeTenteDouvrirUnPlanSansSocle(): void
    {
        // Le geste « Adapter » (naissance du plan) ne dépend PAS du socle ; c'est GÉNÉRER une
        // version de période — bâtie SUR le calendrier de base — qui l'exige. On adapte donc la
        // période, puis on tente d'en ouvrir une version : c'est CE geste qui est refusé sans socle.
        $start = date('Y-m-d', (int) strtotime('next monday +112 days'));
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => 'Fermeture sans socle (fonctionnel)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $this->refusedEntryId = $this->idOf($entry, 'période à venir');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->refusedEntryId], $this->token);
        $planId = $this->idOf($plan, 'plan de période');

        $refused = $this->apiPost('schedules', ['schedulePlanId' => $planId, 'status' => 'DRAFT'], $this->token);
        $this->refusedStatus = $refused['status'];
        $this->refusedError = \is_string($refused['json']['error'] ?? null) ? $refused['json']['error'] : (\is_string($refused['json']['detail'] ?? null) ? $refused['json']['detail'] : '');
    }

    #[Then('l\'ouverture est refusée faute de planning de saison en vigueur')]
    public function louvertureEstRefusee(): void
    {
        if (409 !== $this->refusedStatus) {
            throw new RuntimeException(\sprintf('l\'ouverture aurait dû être refusée (409), obtenu %d', $this->refusedStatus));
        }
        if (SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE !== $this->refusedError) {
            throw new RuntimeException(\sprintf('le refus aurait dû être celui du socle (« %s »), message obtenu « %s »', SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE, $this->refusedError));
        }
    }

    /**
     * Restaure le pointeur du socle (comme SeasonGenerationContext) et retire tout ce que le
     * scénario a créé — quoi qu'il arrive (succès, échec, exception).
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        // Restaure « socle en vigueur » : la version d'entrée si elle existe encore, sinon la plus
        // fraîche COMPLETED. Ce qui compte pour les autres scénarios, c'est l'état « en vigueur ».
        if ('' !== $this->clubId) {
            $target = '';
            if ('' !== $this->chosenAtEntry
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

        if ('' !== $this->addedSocleSlotId) {
            $this->apiDelete(\sprintf('venue_training_slots/%s', $this->addedSocleSlotId), $this->token);
        }
        if ('' !== $this->pendingSocleSlotId) {
            $this->apiDelete(\sprintf('venue_training_slots/%s', $this->pendingSocleSlotId), $this->token);
        }

        // Le coach principal changé sans valider : le lien posé part, la ligne d'origine revient sous son identifiant
        // (la copie en vigueur la cite) — si « Revenir au planning en vigueur » ne l'a pas déjà remise.
        if ('' !== $this->replacingMainLinkId) {
            $this->dbalExec(\sprintf('DELETE FROM team_coach WHERE id=\'%s\'', $this->replacingMainLinkId), admin: true);
        }
        if ('' !== $this->originalMainLink) {
            [$linkId, $teamId, $coachId, $seasonId, $createdAt] = explode('|', $this->originalMainLink);
            $this->dbalExec(
                \sprintf(
                    'INSERT INTO team_coach (id, version, created_at, updated_at, club_id, season_id, team_id, coach_id, role, wish_recipient) VALUES (\'%s\', 1, \'%s\', now(), \'%s\', \'%s\', \'%s\', \'%s\', \'MAIN\', false) ON CONFLICT (id) DO NOTHING',
                    $linkId,
                    $createdAt,
                    $this->clubId,
                    $seasonId,
                    $teamId,
                    $coachId,
                ),
                admin: true,
            );
        }

        // La copie de travail jamais validée part ; l'étoile revient sur la version en vigueur.
        if ('' !== $this->workCopyId && '' !== $this->clubId) {
            $chosen = $this->dbalScalar(\sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND season_id=\'%s\'', $this->clubId, $this->seasonId), admin: true);
            if ($chosen !== $this->workCopyId) {
                $this->dbalExec(\sprintf('UPDATE schedule_plan SET lock_source_schedule_id=chosen_schedule_id WHERE club_id=\'%s\' AND type=\'SEASON\' AND season_id=\'%s\'', $this->clubId, $this->seasonId), admin: true);
                $this->dbalExec(\sprintf('DELETE FROM schedule_slot_template WHERE schedule_id=\'%s\'', $this->workCopyId), admin: true);
                $this->dbalExec(\sprintf('DELETE FROM schedule WHERE id=\'%s\'', $this->workCopyId), admin: true);
            }
        }

        // La collecte des vacances à venir : purgée par la bascule en cas de succès, retirée
        // ici défensivement si le scénario s'est arrêté avant (DELETE tolérant).
        if ('' !== $this->collecteWishId) {
            $this->dbalExec(\sprintf('DELETE FROM coach_wish WHERE id=\'%s\'', $this->collecteWishId), admin: true);
        }
        if ('' !== $this->collecteCampaignId) {
            $this->dbalExec(\sprintf('DELETE FROM coach_wish_campaign WHERE id=\'%s\'', $this->collecteCampaignId), admin: true);
        }

        // Le planning de période à venir : son entrée survit à la bascule (l'entrée n'est jamais
        // détruite), on la retire ; DELETE tolérant si elle a déjà disparu.
        foreach ([$this->futureEntryId, $this->refusedEntryId, $this->collecteEntryId, $this->holidayEntryId] as $entryId) {
            if ('' !== $entryId) {
                $this->apiDelete(\sprintf('calendar_entries/%s', $entryId), $this->token);
            }
        }

        // Le planning déjà commencé, semé en base : on le retire en base (plan puis entrée).
        if ('' !== $this->startedPlanId) {
            $this->dbalExec(\sprintf('DELETE FROM schedule_plan WHERE id=\'%s\'', $this->startedPlanId), admin: true);
        }
        if ('' !== $this->startedEntryId) {
            $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE id=\'%s\'', $this->startedEntryId), admin: true);
        }

        $this->teardownSmallClub();
    }

    /**
     * L'état « en attente » du planning de saison, tel que l'écran le lit.
     *
     * @return array<mixed>
     */
    private function pendingChanges(): array
    {
        $pending = $this->apiGet(\sprintf('schedule_plans/%s/pending-changes', $this->soclePlanId()), $this->token);
        if (200 !== $pending['status']) {
            throw new RuntimeException(\sprintf('lire ce qui attend la validation a répondu %d (200 attendu)', $pending['status']));
        }

        return $pending['json'];
    }

    /**
     * Les familles nommées par la liste de ce qui attend.
     *
     * @param array<mixed> $pending
     *
     * @return list<string>
     */
    private function changedFamilies(array $pending): array
    {
        $changes = \is_array($pending['changes'] ?? null) ? $pending['changes'] : [];

        return array_values(array_filter(array_map(static fn (mixed $line): mixed => \is_array($line) ? ($line['family'] ?? null) : null, $changes), 'is_string'));
    }

    private function soclePlanId(): string
    {
        return $this->dbalScalar(\sprintf('SELECT id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND season_id=\'%s\'', $this->clubId, $this->seasonId), admin: true);
    }

    /**
     * La grille d'un plan lue par l'API, toutes pages : `gymnase|jour|heure|durée|capacité`, triée. `''` = la
     * liste par défaut, celle du planning de saison.
     *
     * @return list<string>
     */
    private function gridOf(string $planId): array
    {
        $query = '' === $planId ? 'venue_training_slots?' : \sprintf('venue_training_slots?schedulePlanId=%s&', $planId);
        $grid = [];
        for ($page = 1; $page <= 50; ++$page) {
            $response = $this->apiGet($query . 'page=' . $page, $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lire la grille a répondu %d (200 attendu)', $response['status']));
            }
            $members = $response['json']['member'] ?? [];
            $members = array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
            foreach ($members as $slot) {
                $start = \is_string($slot['startTime'] ?? null) ? $slot['startTime'] : '';
                $grid[] = \sprintf('%s|%s|%s|%s|%s', \is_string($slot['venueId'] ?? null) ? $slot['venueId'] : '', \is_int($slot['dayOfWeek'] ?? null) ? $slot['dayOfWeek'] : '', 1 === preg_match('/T?(\d{2}:\d{2})/', $start, $m) ? $m[1] : $start, \is_int($slot['durationMinutes'] ?? null) ? $slot['durationMinutes'] : '', \is_int($slot['capacity'] ?? null) ? $slot['capacity'] : '');
            }
            // Jusqu'à la page vide : `totalItems` de cette liste ne compte que la page (30 lus, 82 en base).
            if ([] === $members) {
                break;
            }
        }
        sort($grid);

        return $grid;
    }

    private function freshestCompletedSeasonVersion(): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
            admin: true,
        );

        // dbal:run-sql rend une ligne « [OK] … empty result set. » quand rien ne sort : ce n'est pas
        // un identifiant. On ne garde qu'une valeur en forme d'UUID.
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? $id : '';
    }

    private function pointSocleTo(string $scheduleId): void
    {
        $value = '' === $scheduleId ? 'NULL' : \sprintf('\'%s\'', $scheduleId);
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET chosen_schedule_id=%s WHERE club_id=\'%s\' AND type=\'SEASON\'', $value, $this->clubId),
            admin: true,
        );
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
