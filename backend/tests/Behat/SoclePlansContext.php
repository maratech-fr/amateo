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
 * Prouve la souveraineté du socle : valider annonce ce qui change (`GET /api/schedules/{id}/validate-impact`, lot 5,
 * PR 6) et se confirme sur le jeton de la liste vue ; seul un changement du froid efface les plannings de période
 * ENTIÈREMENT à venir (critère `findWithPlanNotStarted` : date de début > aujourd'hui), jamais ceux déjà commencés ni la
 * collecte ; un nom, un verrou, une séance déplacée n'effacent rien (décor : petit club jetable pour l'annonce, qui
 * paie une génération ; club de démonstration pour les gestes sans génération, remis en place en fin de scénario) ;
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

    /**
     * La famille de la ligne « séances déplacées » de l'annonce (`changes`), pour information seulement : elle porte
     * toujours `destroys: false`. Nom choisi par le test (contrat de la PR 6) ; un renommage côté code est mécanique.
     */
    private const string MOVED_SESSIONS = 'ScheduleSlotTemplate';

    /** La version générée après des modifications froides (scénario de l'annonce). */
    private string $candidateId = '';

    /** @var array<mixed> la dernière annonce lue (`GET /api/schedules/{id}/validate-impact`) */
    private array $impact = [];

    /** L'équipe renommée par le scénario et son nom d'avant (remis en fin). */
    private string $renamedTeamId = '';

    private string $renamedTeamName = '';

    /** Le groupe mutualisé renommé et son libellé d'avant (remis en fin). */
    private string $renamedGroupId = '';

    private string $renamedGroupLabel = '';

    /** La séance déplacée ou verrouillée, et sa place d'avant : `gymnase|jour|heure|verrou|origine` (remise en fin). */
    private string $touchedSessionId = '';

    private string $touchedSessionBefore = '';

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

    #[Given('un petit club dont le planning de saison est en vigueur')]
    public function unPetitClubDontLeSocleEstEnVigueur(): void
    {
        $this->buildSmallClub();
        $this->chosenAtEntry = $this->generateAndValidateSmallClubSocle();
        $this->token = $this->smallClubToken;
        $this->clubId = $this->smallClubId;
        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if (!$this->smallClubSocleIsChosen($this->chosenAtEntry)) {
            throw new RuntimeException('témoin : le planning de saison du petit club devrait être en vigueur');
        }
    }

    #[Given('une règle et deux créneaux ajoutés au planning de saison, puis une version générée')]
    public function uneRegleEtDeuxCreneauxPuisUneVersionGeneree(): void
    {
        $rule = $this->apiPost('constraints', [
            'name' => 'U11 · plutôt après 18:00', 'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'PREFERRED',
            'config' => ['targetTags' => ['U11'], 'minStartTime' => '18:00'], 'isActive' => true,
        ], $this->token);
        if (!\in_array($rule['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('ajouter une règle au planning de saison a répondu %d — corps : %s', $rule['status'], json_encode($rule['json'], \JSON_UNESCAPED_UNICODE)));
        }
        foreach (['18:00', '19:30'] as $start) {
            // Le vendredi : hors de la grille du petit club (lundi → jeudi).
            $slot = $this->apiPost('venue_training_slots', ['venueId' => $this->smallClubVenueIds['Gymnase Centre'], 'dayOfWeek' => 5, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1], $this->token);
            if (201 !== $slot['status']) {
                throw new RuntimeException(\sprintf('ajouter un créneau à la grille du planning de saison a répondu %d (201 attendu)', $slot['status']));
            }
        }
        $this->candidateId = $this->generateSmallClubSeason();
        // La version terminée devient la version chargée (l'étoile suit chaque génération terminée) : témoin.
        $loaded = $this->dbalScalar(\sprintf('SELECT lock_source_schedule_id AS behatval FROM schedule_plan WHERE id=\'%s\'', $this->soclePlanId()), admin: true);
        if ($loaded !== $this->candidateId) {
            throw new RuntimeException(\sprintf('témoin : la version générée (%s) devrait être la version chargée, lu %s', $this->candidateId, $loaded));
        }
    }

    #[When('je demande ce que change la validation')]
    public function jeDemandeCeQueChangeLaValidation(): void
    {
        $this->impact = $this->validateImpactOf($this->candidateId);
    }

    #[Then('j\'apprends que 1 règle et 2 créneaux changent')]
    public function jApprendsQueUneRegleEtDeuxCreneauxChangent(): void
    {
        $cold = [];
        foreach ($this->impactLines() as $line) {
            if (true === $line['destroys']) {
                $cold[$line['family']] = $line['count'];
            } elseif (self::MOVED_SESSIONS !== $line['family']) {
                throw new RuntimeException(\sprintf('seules les séances déplacées s\'annoncent sans supprimer ; ligne inattendue : %s', json_encode($line, \JSON_UNESCAPED_UNICODE)));
            }
        }
        // La règle, avec sa ligne dans le plan de saison quand elle en reçoit une ; la grille, ses deux créneaux.
        unset($cold['PlanConstraint']);
        ksort($cold);
        if (['Constraint' => 1, 'VenueTrainingSlot' => 2] !== $cold) {
            throw new RuntimeException(\sprintf('la liste devrait annoncer 1 règle et 2 créneaux qui changent ; lu : %s', json_encode($this->impact['changes'] ?? null, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('l\'annonce nomme les plannings de période à venir qui seront supprimés, et garde la doléance reçue')]
    public function lAnnonceNommeLesPlanningsSupprimesEtGardeLaDoleance(): void
    {
        $destroyed = array_column(\is_array($this->impact['destroyedPeriodPlans'] ?? null) ? $this->impact['destroyedPeriodPlans'] : [], 'entryId');
        sort($destroyed);
        $expected = [$this->futureEntryId, $this->collecteEntryId];
        sort($expected);
        if ($expected !== $destroyed) {
            throw new RuntimeException(\sprintf('l\'annonce devrait nommer les deux plannings de période à venir, jamais celui déjà commencé ; lu : %s', json_encode($this->impact['destroyedPeriodPlans'] ?? null, \JSON_UNESCAPED_UNICODE)));
        }
        if (1 !== ($this->impact['keptWishes'] ?? null)) {
            throw new RuntimeException(\sprintf('l\'annonce devrait dire la doléance reçue conservée (keptWishes = 1) ; lu : %s', json_encode($this->impact['keptWishes'] ?? null)));
        }
    }

    #[When('je valide en confirmant ce que la liste annonce')]
    public function jeValideEnConfirmant(): void
    {
        $this->confirmValidationOf($this->candidateId);
    }

    #[When('/^je (?P<geste>renomme une équipe|renomme un groupe mutualisé|verrouille une séance sans la déplacer|déplace une séance à la main|déplace une séance à la main et la verrouille) puis je valide la copie de travail$/u')]
    public function jeFaisUnGestePuisJeValideLaCopie(string $geste): void
    {
        match ($geste) {
            'renomme une équipe' => $this->renameATeam(),
            'renomme un groupe mutualisé' => $this->renameAGroup(),
            'verrouille une séance sans la déplacer' => $this->lockASession($this->aFreeSessionOfTheWorkCopy()),
            'déplace une séance à la main' => $this->moveASession(),
            'déplace une séance à la main et la verrouille' => $this->lockASession($this->moveASession()),
        };
        $this->impact = $this->validateImpactOf($this->workCopyId);
        $this->confirmValidationOf($this->workCopyId);
    }

    #[Then('/^la liste annonce (?P<liste>rien|1 séance déplacée)$/u')]
    public function laListeAnnonce(string $liste): void
    {
        $expected = 'rien' === $liste ? [] : [['family' => self::MOVED_SESSIONS, 'count' => 1, 'destroys' => false]];
        if ($expected !== $this->impactLines()) {
            throw new RuntimeException(\sprintf('la liste devrait annoncer « %s » ; lu : %s', $liste, json_encode($this->impact['changes'] ?? null, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('les plannings de période à venir sont gardés')]
    public function lesPlanningsDePeriodeAVenirSontGardes(): void
    {
        if ([] !== ($this->impact['destroyedPeriodPlans'] ?? null)) {
            throw new RuntimeException(\sprintf('l\'annonce ne devrait supprimer aucun planning de période ; lu : %s', json_encode($this->impact['destroyedPeriodPlans'] ?? null, \JSON_UNESCAPED_UNICODE)));
        }
        $plans = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\' OR id=\'%s\'', $this->futureEntryId, $this->startedPlanId),
            admin: true,
        );
        if ('2' !== $plans) {
            throw new RuntimeException(\sprintf('aucun froid n\'a changé : les deux plannings de période (à venir, commencé) devraient subsister, %s trouvé(s)', $plans));
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

        // La séance déplacée ou verrouillée d'une copie de travail validée (désormais en vigueur) : remise à sa place.
        if ('' !== $this->touchedSessionId && '' !== $this->touchedSessionBefore) {
            [$venueId, $day, $start, $lock, $origin] = explode('|', $this->touchedSessionBefore);
            $this->dbalExec(
                \sprintf(
                    'UPDATE schedule_slot_template SET venue_id=\'%s\', day_of_week=%d, start_time=\'%s\', lock_level=\'%s\', lock_origin=%s WHERE id=\'%s\'',
                    $venueId,
                    (int) $day,
                    $start,
                    $lock,
                    '' === $origin ? 'NULL' : \sprintf('\'%s\'', $origin),
                    $this->touchedSessionId,
                ),
                admin: true,
            );
        }
        if ('' !== $this->renamedTeamId) {
            $this->putTeamName($this->renamedTeamId, $this->renamedTeamName);
        }
        if ('' !== $this->renamedGroupId) {
            $this->putGroupLabel($this->renamedGroupId, $this->renamedGroupLabel);
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

    /**
     * L'annonce de la validation d'une version, telle que le dialogue la lit.
     *
     * @return array<mixed>
     */
    private function validateImpactOf(string $versionId): array
    {
        $impact = $this->apiGet(\sprintf('schedules/%s/validate-impact', $versionId), $this->token);
        if (200 !== $impact['status'] || !\is_string($impact['json']['changesToken'] ?? null)) {
            throw new RuntimeException(\sprintf('l\'annonce de la validation a répondu %d, sans jeton de liste ? corps : %s', $impact['status'], json_encode($impact['json'], \JSON_UNESCAPED_UNICODE)));
        }

        return $impact['json'];
    }

    /** Le geste « Valider » du dialogue, confirmé sur le jeton de la liste annoncée. */
    private function confirmValidationOf(string $versionId): void
    {
        $validated = $this->apiPost(\sprintf('schedules/%s/validate', $versionId), ['confirmChanges' => $this->impact['changesToken'] ?? null], $this->token);
        if (200 !== $validated['status']) {
            throw new RuntimeException(\sprintf('valider en confirmant la liste annoncée a répondu %d (200 attendu) — corps : %s', $validated['status'], json_encode($validated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** @return list<array{family: mixed, count: mixed, destroys: mixed}> les lignes de l'annonce, triées par famille */
    private function impactLines(): array
    {
        $lines = [];
        foreach (\is_array($this->impact['changes'] ?? null) ? $this->impact['changes'] : [] as $line) {
            if (\is_array($line)) {
                $lines[] = ['family' => $line['family'] ?? null, 'count' => $line['count'] ?? null, 'destroys' => $line['destroys'] ?? null];
            }
        }
        usort($lines, static fn (array $a, array $b): int => strcmp((string) $a['family'], (string) $b['family']));

        return $lines;
    }

    private function renameATeam(): void
    {
        $this->renamedTeamId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM team WHERE club_id=\'%s\' AND season_id=\'%s\' ORDER BY id LIMIT 1', $this->clubId, $this->seasonId),
            admin: true,
        );
        $team = $this->apiGet(\sprintf('teams/%s', $this->renamedTeamId), $this->token)['json'];
        $this->renamedTeamName = \is_string($team['name'] ?? null) ? $team['name'] : '';
        if ('' === $this->renamedTeamName) {
            throw new RuntimeException('aucune équipe à renommer — la base est-elle seedée ?');
        }
        $this->putTeamName($this->renamedTeamId, $this->renamedTeamName . ' (renommée)');
    }

    private function putTeamName(string $teamId, string $name): void
    {
        $current = $this->apiGet(\sprintf('teams/%s', $teamId), $this->token)['json'];
        $written = $this->apiPut(\sprintf('teams/%s', $teamId), [
            'name' => $name,
            'sportCategoryId' => $current['sportCategoryId'] ?? null,
            'priorityTierId' => $current['priorityTierId'] ?? null,
            'sessionsPerWeek' => $current['sessionsPerWeek'] ?? null,
            'level' => $current['level'] ?? null,
            'gender' => $current['gender'] ?? null,
            'maxSessionsPerDay' => $current['maxSessionsPerDay'] ?? null,
        ], $this->token);
        if (200 !== $written['status']) {
            throw new RuntimeException(\sprintf('renommer l\'équipe a répondu %d — corps : %s', $written['status'], json_encode($written['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    private function renameAGroup(): void
    {
        $this->renamedGroupId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM shared_training_block WHERE club_id=\'%s\' AND schedule_plan_id=\'%s\' ORDER BY id LIMIT 1', $this->clubId, $this->soclePlanId()),
            admin: true,
        );
        if ('' === $this->renamedGroupId) {
            throw new RuntimeException('aucun groupe mutualisé au planning de saison — la base est-elle seedée ?');
        }
        $group = $this->apiGet(\sprintf('shared_training_blocks/%s', $this->renamedGroupId), $this->token)['json'];
        $this->renamedGroupLabel = \is_string($group['label'] ?? null) ? $group['label'] : '';
        $this->putGroupLabel($this->renamedGroupId, ('' === $this->renamedGroupLabel ? 'Groupe' : $this->renamedGroupLabel) . ' (renommé)');
    }

    private function putGroupLabel(string $groupId, string $label): void
    {
        $current = $this->apiGet(\sprintf('shared_training_blocks/%s', $groupId), $this->token);
        $written = $this->apiPut(\sprintf('shared_training_blocks/%s', $groupId), [...$current['json'], 'label' => '' === $label ? null : $label], $this->token);
        if (200 !== $written['status']) {
            throw new RuntimeException(\sprintf('renommer le groupe mutualisé a répondu %d — corps : %s', $written['status'], json_encode($written['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** Une séance de la copie de travail, libre (ni verrouillée, ni d'un groupe) ; sa place d'avant est retenue. */
    private function aFreeSessionOfTheWorkCopy(): string
    {
        $row = $this->dbalScalar(
            \sprintf(
                'SELECT id || \'|\' || venue_id || \'|\' || day_of_week || \'|\' || to_char(start_time, \'HH24:MI\') || \'|\' || lock_level || \'|\' || COALESCE(lock_origin, \'\') AS behatval'
                . ' FROM schedule_slot_template WHERE schedule_id=\'%s\' AND lock_level=\'NONE\' AND shared_training_block_id IS NULL ORDER BY id LIMIT 1',
                $this->workCopyId,
            ),
            admin: true,
        );
        $parts = explode('|', $row);
        if (6 !== \count($parts)) {
            throw new RuntimeException('aucune séance libre dans la copie de travail — la base est-elle seedée ?');
        }
        $this->touchedSessionId = array_shift($parts);
        $this->touchedSessionBefore = implode('|', $parts);

        return $this->touchedSessionId;
    }

    private function lockASession(string $sessionId): void
    {
        $locked = $this->apiPost(\sprintf('schedule-slots/%s/manual-edit/lock', $sessionId), ['lockLevel' => 'HARD'], $this->token);
        if (200 !== $locked['status']) {
            throw new RuntimeException(\sprintf('verrouiller la séance a répondu %d — corps : %s', $locked['status'], json_encode($locked['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /**
     * Le vrai geste « déplacer » sur la copie de travail : une séance libre vers une case vide de la grille, le même jour.
     * Le verdict du moteur peut refuser une case (règle de l'équipe) : on essaie au plus huit couples séance → case.
     */
    private function moveASession(): string
    {
        $rows = $this->dbalScalar(
            \sprintf(
                'SELECT string_agg(pair, \';\') AS behatval FROM (SELECT s.id || \'|\' || s.venue_id || \'|\' || s.day_of_week || \'|\' || to_char(s.start_time, \'HH24:MI\') || \'|\' || s.lock_level || \'|\' || COALESCE(s.lock_origin, \'\')'
                . ' || \'>\' || g.venue_id || \'|\' || to_char(g.start_time, \'HH24:MI\') AS pair'
                . ' FROM schedule_slot_template s JOIN venue_training_slot g ON g.schedule_plan_id = \'%2$s\' AND g.day_of_week = s.day_of_week AND g.start_time <> s.start_time'
                . ' WHERE s.schedule_id = \'%1$s\' AND s.lock_level = \'NONE\' AND s.shared_training_block_id IS NULL'
                . ' AND NOT EXISTS (SELECT 1 FROM schedule_slot_template o WHERE o.schedule_id = \'%1$s\' AND o.venue_id = g.venue_id AND o.day_of_week = g.day_of_week'
                . ' AND o.start_time < g.start_time + make_interval(mins => g.duration_minutes) AND g.start_time < o.start_time + make_interval(mins => o.duration_minutes))'
                . ' AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.schedule_plan_id = \'%2$s\' AND r.venue_id = g.venue_id AND r.day_of_week = g.day_of_week AND r.start_time = g.start_time)'
                . ' ORDER BY s.id, g.start_time, g.venue_id LIMIT 8) pairs',
                $this->workCopyId,
                $this->soclePlanId(),
            ),
            admin: true,
        );
        $tried = [];
        foreach ('' === $rows ? [] : explode(';', $rows) as $pair) {
            [$session, $target] = explode('>', $pair);
            $parts = explode('|', $session);
            [$venueId, $start] = explode('|', $target);
            $moved = $this->apiPost(\sprintf('schedule-slots/%s/move', $parts[0]), ['venueId' => $venueId, 'dayOfWeek' => (int) $parts[2], 'startTime' => $start], $this->token);
            if (200 === $moved['status']) {
                $this->touchedSessionId = array_shift($parts);
                $this->touchedSessionBefore = implode('|', $parts);

                return $this->touchedSessionId;
            }
            $tried[] = \sprintf('%d %s', $moved['status'], json_encode($moved['json']['code'] ?? $moved['json']['error'] ?? null, \JSON_UNESCAPED_UNICODE));
        }

        throw new RuntimeException(\sprintf('décor : aucun déplacement accepté sur la copie de travail (%d essais : %s)', \count($tried), implode(' ; ', $tried)));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
