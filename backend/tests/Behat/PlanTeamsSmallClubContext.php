<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Les équipes d'un plan de vacances (lot 4, PR 3a), sur le PETIT CLUB réaliste ({@see SmallClubFixtureTrait}, palier
 * 60 s), par les gestes réels de l'API : le planning de saison est en vigueur, des vacances à venir ont leur plan ; le
 * gestionnaire écarte une équipe des vacances, lui donne une séance, puis lui rend ses réglages de saison, et chaque
 * génération des vacances le reflète (ligne 14 du plan) ; quand les séances d'une équipe changent dans la saison, le
 * plan de vacances garde celles qu'il avait à sa création et l'équipe y figure au réglage propre (ligne 16, décision
 * du fondateur du 2026-11-22).
 *
 * Les équipes visées sont des deux premiers rangs (SM1, SF1, U20M1, U17M1) : elles jouent les vacances avant comme
 * après la pré-sélection posée à la naissance du plan.
 *
 * Les gymnases d'un plan de vacances (lot 4, PR 3b, ligne 25 du plan) : le gestionnaire met un gymnase hors service pour
 * les vacances, la génération ne l'utilise pas ; il le remet en service, le gymnase retrouve sa grille telle quelle et
 * ne figure plus parmi les réglages des vacances.
 *
 * « Charger » une version des vacances (lot conception 5, PR 3, ligne 14 du plan) : la version 1 a été générée avec une
 * équipe écartée, une équipe à une séance, un gymnase hors service, puis deux de ses séances verrouillées ; la version 2,
 * après avoir tout rendu et retiré un créneau de la grille. Charger la version 1 remet équipes, grille et gymnases des
 * vacances, et fait de ses verrous ceux d'où repartira la régénération. Jumeau PHPUnit cité :
 * `Integration/Plans/LoadVersionTest` (charger une période laisse le socle, une version ne touche que son plan).
 *
 * Jumeaux PHPUnit cités : la publication d'un réglage (`Api/PeriodSettingUpsertTest`), la pré-sélection à la naissance
 * (`Integration/Plans/PlanBirthTeamDefaultTest`), la transcription (`Integration/Service/PeriodPlanTranscriberTest`) ;
 * le gymnase hors de la demande au moteur (`Integration/ScheduleConstraintBuilderOverlayTest::testDisabledVenueLeavesThePayloadEntirely`), la grille gardée hors service
 * (`Integration/Api/VenuePeriodOverrideApiTest::testDisabledKeepsTheVenuesGridInsteadOfEmptyingIt`).
 */
final class PlanTeamsSmallClubContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $holidayPlanId = '';

    /** L'identifiant du réglage de l'équipe visée dans les vacances, rendu par sa première publication. */
    private string $settingId = '';

    /** L'identifiant du réglage du gymnase mis hors service, rendu par sa publication. */
    private string $venueSettingId = '';

    /** Le nombre de créneaux du gymnase dans la grille des vacances, avant de le mettre hors service. */
    private int $venueGridBefore = -1;

    private string $sf1SettingId = '';

    private string $u17SettingId = '';

    private string $holidayVersionOneId = '';

    private string $holidayVersionTwoId = '';

    /** @var array<string, string> famille des réglages des vacances → son état à la génération de la version 1 */
    private array $holidayStateOfVersionOne = [];

    #[Given('un petit club réaliste dont le planning de saison est en vigueur, et des vacances à venir')]
    public function unPetitClubAvecDesVacancesAVenir(): void
    {
        $this->buildSmallClub();
        $this->generateAndValidateSmallClubSocle();
        $start = $this->smallClubFutureMonday(35, 5);
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => 'Vacances (équipes d\'un plan)',
            'startDate' => $start,
            'endDate' => $end,
        ], $this->smallClubToken);
        if (!\in_array($entry['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('déclaration des vacances refusée (HTTP %d) — %s', $entry['status'], json_encode($entry['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->holidayPlanId = $this->openSmallClubPeriodPlan($this->smallClubStringField($entry['json'], 'id'));
    }

    #[Given('/^les (?P<team>\S+) n\'ont rien de particulier dans les vacances$/u')]
    public function rienDeParticulier(string $team): void
    {
        $this->teamId($team);
    }

    /** Une réservation du plan de vacances : la case que l'équipe tient, comme le gestionnaire la pose dans la grille des vacances. */
    #[Given('/^les (?P<team>\S+) ont un créneau réservé dans les vacances$/u')]
    public function unCreneauReserve(string $team): void
    {
        $reservation = $this->apiPost('reservations', [
            'teamId' => $this->teamId($team),
            'venueId' => $this->smallClubVenueIds['Gymnase Centre'] ?? throw new RuntimeException('Gymnase Centre absent du petit club'),
            'dayOfWeek' => 2,
            'startTime' => '19:30',
            'durationMinutes' => 90,
            'schedulePlanId' => $this->holidayPlanId,
        ], $this->smallClubToken);
        if (!\in_array($reservation['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('réservation dans les vacances refusée (HTTP %d) — %s', $reservation['status'], json_encode($reservation['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** Un groupe mutualisé du plan de vacances : les deux équipes partagent une séance commune. */
    #[Given('/^les (?P<team>\S+) s\'entraînent en groupe mutualisé avec les (?P<partner>\S+) dans les vacances$/u')]
    public function unGroupeMutualise(string $team, string $partner): void
    {
        $block = $this->apiPost('shared_training_blocks', [
            'teamIds' => [$this->teamId($team), $this->teamId($partner)],
            'commonSessions' => 1,
            'schedulePlanId' => $this->holidayPlanId,
        ], $this->smallClubToken);
        if (!\in_array($block['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('groupe mutualisé des vacances refusé (HTTP %d) — %s', $block['status'], json_encode($block['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** Le geste de l'étape Équipes des vacances : décocher l'équipe. */
    #[When('/^j\'écarte les (?P<team>\S+) des vacances$/u')]
    public function jEcarte(string $team): void
    {
        $published = $this->apiPost('team_period_overrides', [
            'schedulePlanId' => $this->holidayPlanId,
            'teamId' => $this->teamId($team),
            'isActive' => false,
            'sessionsPerWeek' => null,
        ], $this->smallClubToken);
        if (!\in_array($published['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('écarter l\'équipe des vacances a été refusé (HTTP %d) — %s', $published['status'], json_encode($published['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->settingId = $this->smallClubStringField($published['json'], 'id');
    }

    /** Le geste de l'étape Équipes : recocher l'équipe et lui donner une séance pour les vacances (le réglage existe). */
    #[When('/^je donne (?P<count>\d+) séance aux (?P<team>\S+) pour les vacances$/u')]
    public function jeDonneDesSeances(string $count, string $team): void
    {
        $this->settingIdOrFail();
        $updated = $this->apiPut(\sprintf('team_period_overrides/%s', $this->settingId), [
            'schedulePlanId' => $this->holidayPlanId,
            'teamId' => $this->teamId($team),
            'isActive' => true,
            'sessionsPerWeek' => (int) $count,
        ], $this->smallClubToken);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('régler les séances des vacances a été refusé (HTTP %d) — %s', $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** Le geste « rendre les réglages de saison » : le réglage de la période est retiré. */
    #[When('/^je rends aux (?P<team>\S+) leurs réglages de saison$/u')]
    public function jeRendsLesReglagesDeSaison(string $team): void
    {
        $this->teamId($team);
        $deleted = $this->apiDelete(\sprintf('team_period_overrides/%s', $this->settingIdOrFail()), $this->smallClubToken);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('rendre les réglages de saison a été refusé (HTTP %d)', $deleted['status']));
        }
    }

    /** Le compte des séances de l'équipe dans une version des vacances générée maintenant, sur les réglages du moment. */
    #[Then('/^les (?P<team>\S+) ont (?:toujours |leurs )?(?P<count>\d+) séances? dans le planning des vacances$/u')]
    public function lesSeancesDansLesVacances(string $team, string $count): void
    {
        $expected = (int) $count;
        $version = $this->generateSmallClubPeriodVersion($this->holidayPlanId);
        fwrite(\STDERR, \sprintf("\n[PlanTeams] génération des vacances %s en %.1fs\n", $version['status'], $this->smallClubLastSolveSeconds));
        if ('COMPLETED' !== $version['status']) {
            throw new RuntimeException(\sprintf('la génération des vacances n\'a pas abouti (statut « %s »)', $version['status']));
        }
        $sessions = (int) $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'',
            $version['id'],
            $this->teamId($team),
        ), admin: true);
        $total = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'', $version['id']), admin: true);
        if (0 === $total) {
            throw new RuntimeException('la version des vacances est vide : le compte de l\'équipe ne prouverait rien');
        }
        if ($expected !== $sessions) {
            throw new RuntimeException(\sprintf('les %s ont %d séance(s) dans les vacances, %d attendue(s)', $team, $sessions, $expected));
        }
    }

    /** Le geste de l'étape Équipes de la saison, sous planning en vigueur : le serveur ne refuse pas les séances. */
    #[When('/^les (?P<team>\S+) passent à (?P<count>\d+) séances dans le planning de saison$/u')]
    public function lesSeancesDeSaisonChangent(string $team, string $count): void
    {
        $teamId = $this->teamId($team);
        $current = $this->apiGet(\sprintf('teams/%s', $teamId), $this->smallClubToken);
        if (200 !== $current['status'] || (int) ($current['json']['sessionsPerWeek'] ?? 0) === (int) $count) {
            throw new RuntimeException(\sprintf('témoin : les %s doivent avoir un autre nombre de séances que %s dans la saison (HTTP %d)', $team, $count, $current['status']));
        }
        $updated = $this->apiPut(\sprintf('teams/%s', $teamId), [
            'name' => $team,
            'sportCategoryId' => $current['json']['sportCategoryId'] ?? null,
            'priorityTierId' => $current['json']['priorityTierId'] ?? null,
            'sessionsPerWeek' => (int) $count,
        ], $this->smallClubToken);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('changer les séances de saison a été refusé (HTTP %d) — %s', $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** L'étape Équipes des vacances liste l'équipe parmi les réglages propres, avec les séances de la création du plan. */
    #[Then('/^les (?P<team>\S+) y figurent parmi les équipes au réglage propre, avec (?P<count>\d+) séances$/u')]
    public function auReglagePropre(string $team, string $count): void
    {
        $listed = $this->apiGet(\sprintf('team_period_overrides?schedulePlanId=%s', $this->holidayPlanId), $this->smallClubToken);
        $members = \is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [];
        $teamId = $this->teamId($team);
        foreach ($members as $member) {
            if (\is_array($member) && $teamId === ($member['teamId'] ?? null)) {
                if ((int) $count !== (int) ($member['sessionsPerWeek'] ?? -1) || true !== ($member['isActive'] ?? null)) {
                    throw new RuntimeException(\sprintf('le réglage propre des %s dit %s', $team, json_encode($member, \JSON_UNESCAPED_UNICODE)));
                }

                return;
            }
        }

        throw new RuntimeException(\sprintf('les %s ne figurent pas parmi les réglages propres des vacances (%d listé(s))', $team, \count($members)));
    }

    /** Le geste de l'étape Gymnases des vacances : mettre le gymnase hors service pour la période. */
    #[When('/^je mets le (?P<venue>Gymnase \S+) hors service pour les vacances$/u')]
    public function jeMetsHorsService(string $venue): void
    {
        $this->venueGridBefore = $this->holidayGridCountOf($venue);
        if ($this->venueGridBefore <= 0) {
            throw new RuntimeException(\sprintf('témoin : le %s doit avoir des créneaux dans la grille des vacances', $venue));
        }
        $published = $this->apiPost('venue_period_overrides', [
            'schedulePlanId' => $this->holidayPlanId,
            'venueId' => $this->venueId($venue),
            'mode' => 'DISABLED',
        ], $this->smallClubToken);
        if (!\in_array($published['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('mettre le gymnase hors service a été refusé (HTTP %d) — %s', $published['status'], json_encode($published['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->venueSettingId = $this->smallClubStringField($published['json'], 'id');
    }

    /** Une version des vacances générée maintenant : aucune séance au gymnase hors service, d'autres ailleurs. */
    #[Then('/^aucune séance des vacances ne se tient au (?P<venue>Gymnase \S+)$/u')]
    public function aucuneSeanceAuGymnase(string $venue): void
    {
        $version = $this->generateSmallClubPeriodVersion($this->holidayPlanId);
        fwrite(\STDERR, \sprintf("\n[PlanVenues] génération des vacances %s en %.1fs\n", $version['status'], $this->smallClubLastSolveSeconds));
        if ('COMPLETED' !== $version['status']) {
            throw new RuntimeException(\sprintf('la génération des vacances n\'a pas abouti (statut « %s »)', $version['status']));
        }
        $total = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'', $version['id']), admin: true);
        if (0 === $total) {
            throw new RuntimeException('la version des vacances est vide : « aucune séance au gymnase » ne prouverait rien');
        }
        $atVenue = (int) $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND venue_id=\'%s\'',
            $version['id'],
            $this->venueId($venue),
        ), admin: true);
        if (0 !== $atVenue) {
            throw new RuntimeException(\sprintf('%d séance(s) des vacances au %s, hors service', $atVenue, $venue));
        }
    }

    /** Le geste « remettre en service » : le réglage du gymnase est retiré, sa ligne reprend le défaut. */
    #[When('/^je remets le (?P<venue>Gymnase \S+) en service pour les vacances$/u')]
    public function jeRemetsEnService(string $venue): void
    {
        $this->venueId($venue);
        if ('' === $this->venueSettingId) {
            throw new RuntimeException('aucun réglage du gymnase dans les vacances : le scénario le met d\'abord hors service');
        }
        $deleted = $this->apiDelete(\sprintf('venue_period_overrides/%s', $this->venueSettingId), $this->smallClubToken);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('remettre le gymnase en service a été refusé (HTTP %d)', $deleted['status']));
        }
    }

    /** La grille du gymnase dans les vacances est celle d'avant, et l'étape Gymnases ne le liste plus parmi les réglages. */
    #[Then('/^le (?P<venue>Gymnase \S+) a retrouvé sa grille des vacances et ne figure plus parmi les réglages des vacances$/u')]
    public function aRetrouveSaGrille(string $venue): void
    {
        $grid = $this->holidayGridCountOf($venue);
        if ($this->venueGridBefore !== $grid) {
            throw new RuntimeException(\sprintf('le %s a %d créneau(x) dans la grille des vacances, %d avant de le mettre hors service', $venue, $grid, $this->venueGridBefore));
        }
        $listed = $this->apiGet(\sprintf('venue_period_overrides?schedulePlanId=%s', $this->holidayPlanId), $this->smallClubToken);
        if (200 !== $listed['status']) {
            throw new RuntimeException(\sprintf('la liste des réglages de gymnase des vacances a été refusée (HTTP %d)', $listed['status']));
        }
        foreach (\is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [] as $member) {
            if (\is_array($member) && $this->venueId($venue) === ($member['venueId'] ?? null)) {
                throw new RuntimeException(\sprintf('le %s figure encore parmi les réglages des vacances : %s', $venue, json_encode($member, \JSON_UNESCAPED_UNICODE)));
            }
        }
    }

    /**
     * La version 1 des vacances, générée après que le gestionnaire a écarté les SF1, donné une séance aux U17M1 et mis
     * le Gymnase Sud hors service pour les vacances ; deux de ses séances libres sont ensuite verrouillées.
     */
    #[Given('une version 1 des vacances où les SF1 sont écartées, les U17M1 ont 1 séance, le Gymnase Sud est hors service et deux séances sont verrouillées')]
    public function uneVersion1DesVacances(): void
    {
        $this->jEcarte('SF1');
        $this->sf1SettingId = $this->settingId;
        $this->publishTeamSetting('U17M1', true, 1);
        $this->u17SettingId = $this->settingId;
        $this->jeMetsHorsService('Gymnase Sud');
        $version = $this->generateSmallClubPeriodVersion($this->holidayPlanId);
        if ('COMPLETED' !== $version['status']) {
            throw new RuntimeException(\sprintf('la version 1 des vacances n\'a pas abouti (statut « %s »)', $version['status']));
        }
        $this->holidayVersionOneId = $version['id'];
        $this->lockFreeSmallClubSessions($this->holidayVersionOneId, 2);
        $this->holidayStateOfVersionOne = $this->holidayState();
    }

    #[Given('une version 2 des vacances générée après avoir rendu aux SF1 et aux U17M1 leurs réglages de saison, remis le Gymnase Sud en service et retiré un créneau du Gymnase Nord')]
    public function uneVersion2DesVacances(): void
    {
        foreach ([$this->sf1SettingId, $this->u17SettingId] as $setting) {
            $deleted = $this->apiDelete(\sprintf('team_period_overrides/%s', $setting), $this->smallClubToken);
            if (204 !== $deleted['status']) {
                throw new RuntimeException(\sprintf('rendre les réglages de saison a été refusé (HTTP %d)', $deleted['status']));
            }
        }
        $this->jeRemetsEnService('Gymnase Sud');
        $slot = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM venue_training_slot WHERE schedule_plan_id = \'%s\' AND venue_id = \'%s\' ORDER BY day_of_week, start_time LIMIT 1', $this->holidayPlanId, $this->venueId('Gymnase Nord')), admin: true);
        $removed = $this->apiDelete(\sprintf('venue_training_slots/%s', $slot), $this->smallClubToken);
        if (!\in_array($removed['status'], [200, 204], true)) {
            throw new RuntimeException(\sprintf('retirer un créneau du Gymnase Nord pour les vacances a été refusé (HTTP %d) — %s', $removed['status'], json_encode($removed['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $version = $this->generateSmallClubPeriodVersion($this->holidayPlanId);
        if ('COMPLETED' !== $version['status']) {
            throw new RuntimeException(\sprintf('la version 2 des vacances n\'a pas abouti (statut « %s »)', $version['status']));
        }
        $this->holidayVersionTwoId = $version['id'];
        $now = $this->holidayState();
        foreach ($this->holidayStateOfVersionOne as $family => $state) {
            if ('verrous' !== $family && $now[$family] === $state) {
                throw new RuntimeException(\sprintf('témoin : %s de la version 2 des vacances devrait différer de la version 1 (« %s »)', $family, $state));
            }
        }
        if ($this->holidayVersionTwoId !== $this->holidayLockSource()) {
            throw new RuntimeException('témoin : la version 2 des vacances devrait être celle d\'où repart la régénération');
        }
    }

    #[When('je charge la version 1 des vacances')]
    public function jeChargeLaVersion1DesVacances(): void
    {
        $loaded = $this->apiPost(\sprintf('schedules/%s/load', $this->holidayVersionOneId), [], $this->smallClubToken);
        if (200 !== $loaded['status']) {
            throw new RuntimeException(\sprintf('« Charger » la version 1 des vacances a répondu %d — %s', $loaded['status'], json_encode($loaded['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('les équipes, la grille et les gymnases des vacances sont ceux de la version 1')]
    public function lesReglagesDesVacancesSontCeuxDeLaVersion1(): void
    {
        $now = $this->holidayState();
        foreach ($this->holidayStateOfVersionOne as $family => $state) {
            if ($now[$family] !== $state) {
                throw new RuntimeException(\sprintf("%s des vacances après chargement ≠ version 1\n  attendu : %s\n  obtenu  : %s", $family, $state, $now[$family]));
            }
        }
    }

    #[Then('les verrous de la version 1 sont ceux d\'où repartira la prochaine régénération')]
    public function lesVerrousDeLaVersion1SontLaSource(): void
    {
        if ($this->holidayVersionOneId !== $this->holidayLockSource()) {
            throw new RuntimeException(\sprintf('la régénération des vacances repartirait de « %s », pas de la version 1 chargée', $this->holidayLockSource()));
        }
        $locks = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id = \'%s\' AND lock_level = \'HARD\'', $this->holidayVersionOneId), admin: true);
        if ($locks < 2) {
            throw new RuntimeException(\sprintf('la version 1 des vacances ne porte plus que %d verrou(s), 2 attendus', $locks));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** Publie le réglage d'une équipe pour les vacances (le geste de l'étape Équipes) ; garde son id. */
    private function publishTeamSetting(string $team, bool $active, int $sessions): void
    {
        $published = $this->apiPost('team_period_overrides', [
            'schedulePlanId' => $this->holidayPlanId, 'teamId' => $this->teamId($team), 'isActive' => $active, 'sessionsPerWeek' => $sessions,
        ], $this->smallClubToken);
        if (!\in_array($published['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('régler les %s pour les vacances a été refusé (HTTP %d) — %s', $team, $published['status'], json_encode($published['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->settingId = $this->smallClubStringField($published['json'], 'id');
    }

    /**
     * Les réglages du plan des vacances, famille par famille, en texte trié : équipes (participation, séances),
     * grille (créneaux), gymnases (en service ou non).
     *
     * @return array<string, string>
     */
    private function holidayState(): array
    {
        $plan = $this->holidayPlanId;

        return [
            'équipes' => $this->dbalScalar(\sprintf('SELECT string_agg(team_id || \' \' || participates || \' \' || sessions_per_week || \' \' || max_sessions_per_day, \' ; \' ORDER BY team_id) AS behatval FROM plan_team WHERE schedule_plan_id = \'%s\'', $plan), admin: true),
            'grille' => $this->dbalScalar(\sprintf('SELECT string_agg(venue_id || \' \' || day_of_week || \' \' || to_char(start_time, \'HH24:MI\') || \' \' || duration_minutes || \' \' || capacity, \' ; \' ORDER BY venue_id, day_of_week, start_time) AS behatval FROM venue_training_slot WHERE schedule_plan_id = \'%s\'', $plan), admin: true),
            'gymnases' => $this->dbalScalar(\sprintf('SELECT string_agg(venue_id || \' \' || participates, \' ; \' ORDER BY venue_id) AS behatval FROM plan_venue WHERE schedule_plan_id = \'%s\'', $plan), admin: true),
        ];
    }

    private function holidayLockSource(): string
    {
        return $this->dbalScalar(\sprintf('SELECT COALESCE(lock_source_schedule_id::text, \'\') AS behatval FROM schedule_plan WHERE id = \'%s\'', $this->holidayPlanId), admin: true);
    }

    private function teamId(string $team): string
    {
        return $this->smallClubTeamIds[$team] ?? throw new RuntimeException(\sprintf('aucune équipe « %s » dans le petit club', $team));
    }

    private function venueId(string $venue): string
    {
        return $this->smallClubVenueIds[$venue] ?? throw new RuntimeException(\sprintf('aucun gymnase « %s » dans le petit club', $venue));
    }

    /** Les créneaux du gymnase dans la grille propre des vacances (copiée de la saison à la naissance du plan). */
    private function holidayGridCountOf(string $venue): int
    {
        return (int) $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM venue_training_slot WHERE schedule_plan_id=\'%s\' AND venue_id=\'%s\'',
            $this->holidayPlanId,
            $this->venueId($venue),
        ), admin: true);
    }

    private function settingIdOrFail(): string
    {
        if ('' === $this->settingId) {
            throw new RuntimeException('aucun réglage de l\'équipe dans les vacances : le scénario l\'écarte d\'abord');
        }

        return $this->settingId;
    }
}
