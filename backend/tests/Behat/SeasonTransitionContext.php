<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : préparer la saison suivante reprend la structure du club (équipes, coachs et
 * affectations, gymnases et créneaux, règles), une règle qui nomme un gymnase nomme celui de la
 * nouvelle saison, le geste ne se rejoue pas, et la saison en cours reste intacte. Les coachs des deux
 * saisons sont les mêmes personnes (lot 3, PR 2b) : une correction d'identité vaut pour les deux, un
 * retrait de la saison suivante ne touche pas la saison en cours. Les gymnases des deux saisons sont
 * aux mêmes lieux (PR 4) : une adresse corrigée vaut pour les deux ; dissocier un gymnase de sa salle
 * fédérale dans la saison suivante le passe sur un lieu neuf et laisse la saison en cours intacte
 * (décision 2, A). Le temps de trajet entre deux gymnases est celui de leurs lieux (PR 5) : une seule valeur, servie
 * dans chaque saison rangée selon les gymnases de cette saison, corrigée dans l'une, lue corrigée dans l'autre.
 *
 * Décor DÉDIÉ ({@see SmallClubFixtureTrait}, sans génération) passé hors Découverte par le geste
 * support réel `app:clubs:set-plan --plan=complete --tier=palier-1 --paid-season=next` : la bascule exige que la
 * saison suivante soit réglée (`SeasonTransitionService::transition`). Le club de démonstration
 * partagé n'est jamais touché. Le compte de chaque table est un POINTAGE SQL par saison (admin).
 * La bascule n'ouvre qu'au 1er mai : l'horloge de dev se fige dans la fenêtre
 * ({@see SeasonCalendarDayTrait}) — la date elle-même est la promesse de `SeasonPreparationDateContext`.
 * Plus bas : `Integration/Service/SeasonTransitionServiceTest` (remappage des clés de gymnase).
 */
final class SeasonTransitionContext extends BaseContext
{
    use SeasonCalendarDayTrait;
    use SmallClubFixtureTrait;

    /** Libellé lu par un humain → table et filtre du pointage. */
    private const array STRUCTURE = [
        'équipes' => 'team',
        'coachs' => 'coach',
        'affectations de coach' => 'team_coach',
        'gymnases' => 'venue',
        'créneaux de gymnase' => 'venue_training_slot',
        'règles' => 'constraint',
    ];

    private const string CORRECTED_PHONE = '0612345678';

    private const string LINKED_ADDRESS = '12 rue du Nord, 69100 Villeurbanne';

    private const string CORRECTED_ADDRESS = '14 rue du Nord, 69100 Villeurbanne';

    /** Les minutes en voiture du trajet Nord–Sud, saisies au décor, puis corrigées dans la saison suivante. */
    private int $drivingMinutes = 0;

    /** @var array<string, mixed> le « Gymnase Nord » de la saison en cours tel que l'écran le lit, juste avant la dissociation */
    private array $currentNordBeforeDissociation = [];

    private string $currentSeasonId = '';

    private string $nextSeasonId = '';

    private string $ruleTeam = '';

    private string $ruleVenue = '';

    /** @var array<string, int> */
    private array $currentBefore = [];

    /** @var array{status: int, json: array<mixed>} */
    private array $secondAttempt = ['status' => 0, 'json' => []];

    #[Given('un petit club dont l\'offre complète règle aussi la saison suivante')]
    public function unPetitClubDontLOffreCompleteRegleLaSaisonSuivante(): void
    {
        $this->buildSmallClub();
        $this->runConsole(['php', 'bin/console', 'app:clubs:set-plan', '--club=' . $this->smallClubId, '--plan=complete', '--tier=palier-1', '--paid-season=next']);
        $this->currentSeasonId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ('' === $this->currentSeasonId) {
            throw new RuntimeException('le petit club n\'a pas de saison en cours');
        }
    }

    /** La saison suivante ne se prépare qu'à partir du 1er mai : le scénario se place dans la fenêtre. */
    #[Given('/^nous sommes le (?P<day>.+)$/u')]
    public function nousSommesLe(string $day): void
    {
        $this->pinDevClock($this->seasonDay($day), $this->smallClubToken);
    }

    #[Given('/^une règle qui impose le « (?P<venue>[^»]+) » à l\'équipe « (?P<team>[^»]+) »$/u')]
    public function uneRegleQuiImposeLeGymnase(string $venue, string $team): void
    {
        $this->ruleTeam = $team;
        $this->ruleVenue = $venue;
        $created = $this->apiPost('constraints', [
            'name' => 'Toujours au ' . $venue,
            'scope' => 'TEAM',
            'scopeTargetId' => $this->smallClubTeamIds[$team],
            'family' => 'FACILITY',
            'ruleType' => 'HARD',
            'config' => ['forcedVenueId' => $this->smallClubVenueIds[$venue]],
            'isActive' => true,
        ], $this->smallClubToken);
        if (201 !== $created['status']) {
            throw new RuntimeException(\sprintf('la règle « toujours au %s » a répondu %d (201 attendu) : %s', $venue, $created['status'], json_encode($created['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->currentBefore = $this->structureOf($this->currentSeasonId);
    }

    /** Le geste « Associer à sa salle FFBB » de l'étape Gymnases : numéro fédéral, adresse et position (`VenuesStep.tsx::confirmAssociate`). */
    #[Given('/^le « (?P<venue>[^»]+) » est relié à sa salle fédérale, avec son adresse et sa position$/u')]
    public function leGymnaseEstRelieASaSalle(string $venue): void
    {
        $linked = $this->apiPut(\sprintf('venues/%s', $this->smallClubVenueIds[$venue]), [
            'name' => $venue,
            'source' => 'manual', // l'écran envoie toujours « manual » (`wizard/api.ts::updateVenue`)
            'externalRef' => 'SALLE-TEST-NORD',
            'address' => self::LINKED_ADDRESS,
            'latitude' => '45.7700000',
            'longitude' => '4.8800000',
        ], $this->smallClubToken);
        if (200 !== $linked['status']) {
            throw new RuntimeException(\sprintf('relier le %s à sa salle fédérale a répondu %d (200 attendu) : %s', $venue, $linked['status'], json_encode($linked['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** La fenêtre des trajets : une durée saisie à la main (`POST /api/venue_travel_times`), aucun calcul automatique. */
    #[Given('/^le trajet entre le « (?P<a>[^»]+) » et le « (?P<b>[^»]+) » est saisi à (?P<minutes>\d+) minutes en voiture$/u')]
    public function leTrajetEstSaisi(string $a, string $b, string $minutes): void
    {
        $created = $this->apiPost('venue_travel_times', [
            'venueAId' => $this->smallClubVenueIds[$a],
            'venueBId' => $this->smallClubVenueIds[$b],
            'drivingMinutes' => (int) $minutes,
        ], $this->smallClubToken);
        if (201 !== $created['status']) {
            throw new RuntimeException(\sprintf('saisir le trajet %s – %s a répondu %d (201 attendu) : %s', $a, $b, $created['status'], json_encode($created['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->drivingMinutes = (int) $minutes;
    }

    #[When('je prépare la saison suivante')]
    public function jePrepareLaSaisonSuivante(): void
    {
        $prepared = $this->apiPost(\sprintf('seasons/%s/transition', $this->currentSeasonId), [], $this->smallClubToken);
        $seasonId = $prepared['json']['seasonId'] ?? null;
        if (201 !== $prepared['status'] || !\is_string($seasonId) || '' === $seasonId) {
            throw new RuntimeException(\sprintf('préparer la saison suivante a répondu %d (201 attendu) : %s', $prepared['status'], json_encode($prepared['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->nextSeasonId = $seasonId;
    }

    #[Then('la saison suivante reprend la structure de la saison en cours :')]
    public function laSaisonSuivanteReprendLaStructure(TableNode $table): void
    {
        $next = $this->structureOf($this->nextSeasonId);
        foreach ($table->getColumnsHash() as $row) {
            $label = $row['ce qui est repris'];
            $expected = (int) $row['nombre'];
            if (!\array_key_exists($label, self::STRUCTURE)) {
                throw new RuntimeException(\sprintf('« %s » n\'est pas une partie de la structure connue du scénario', $label));
            }
            if ($expected !== $next[$label]) {
                throw new RuntimeException(\sprintf('la saison suivante porte %d %s (attendu %d)', $next[$label], $label, $expected));
            }
            if ($expected !== $this->currentBefore[$label]) {
                throw new RuntimeException(\sprintf('décor : la saison en cours portait %d %s (attendu %d)', $this->currentBefore[$label], $label, $expected));
            }
        }
    }

    #[Then('la règle de la saison suivante impose le « Gymnase Nord » de la saison suivante, pas celui de la saison en cours')]
    public function laRegleNommeLeGymnaseDeLaSaisonSuivante(): void
    {
        $forced = $this->dbalScalar(\sprintf(
            'SELECT config->>\'forcedVenueId\' AS behatval FROM "constraint" WHERE season_id=\'%s\' AND scope=\'TEAM\'',
            $this->nextSeasonId,
        ), admin: true);
        $nextVenue = $this->dbalScalar(\sprintf(
            'SELECT id AS behatval FROM venue WHERE season_id=\'%s\' AND name=\'%s\'',
            $this->nextSeasonId,
            $this->ruleVenue,
        ), admin: true);
        $nextTeam = $this->dbalScalar(\sprintf(
            'SELECT t.name AS behatval FROM "constraint" c JOIN team t ON t.id = c.scope_target_id WHERE c.season_id=\'%s\' AND t.season_id=\'%s\'',
            $this->nextSeasonId,
            $this->nextSeasonId,
        ), admin: true);
        if ('' === $nextVenue || $forced !== $nextVenue) {
            throw new RuntimeException(\sprintf('la règle de la saison suivante impose le gymnase « %s », attendu le « %s » de la saison suivante (« %s »)', $forced, $this->ruleVenue, $nextVenue));
        }
        if ($forced === $this->smallClubVenueIds[$this->ruleVenue]) {
            throw new RuntimeException('la règle de la saison suivante nomme encore le gymnase de la saison en cours');
        }
        if ($nextTeam !== $this->ruleTeam) {
            throw new RuntimeException(\sprintf('la règle de la saison suivante vise « %s », attendu l\'équipe « %s » de la saison suivante', $nextTeam, $this->ruleTeam));
        }
    }

    #[Then('la saison en cours a gardé toute sa structure')]
    public function laSaisonEnCoursAGardeSaStructure(): void
    {
        $after = $this->structureOf($this->currentSeasonId);
        if ($after !== $this->currentBefore) {
            throw new RuntimeException(\sprintf('la saison en cours a changé : avant %s, après %s', json_encode($this->currentBefore, \JSON_UNESCAPED_UNICODE), json_encode($after, \JSON_UNESCAPED_UNICODE)));
        }
        $forced = $this->dbalScalar(\sprintf(
            'SELECT config->>\'forcedVenueId\' AS behatval FROM "constraint" WHERE season_id=\'%s\' AND scope=\'TEAM\'',
            $this->currentSeasonId,
        ), admin: true);
        if ($forced !== $this->smallClubVenueIds[$this->ruleVenue]) {
            throw new RuntimeException('la règle de la saison en cours ne nomme plus son propre gymnase');
        }
    }

    #[When('je prépare la saison suivante une seconde fois')]
    public function jePrepareUneSecondeFois(): void
    {
        $this->secondAttempt = $this->apiPost(\sprintf('seasons/%s/transition', $this->currentSeasonId), [], $this->smallClubToken);
    }

    #[Then('/^c\'est refusé : « (?P<message>.+) », et la saison suivante déjà préparée est désignée$/u')]
    public function cEstRefuseEtLaSaisonSuivanteEstDesignee(string $message): void
    {
        if (409 !== $this->secondAttempt['status']) {
            throw new RuntimeException(\sprintf('préparer une seconde fois aurait dû être refusé (409), obtenu %d', $this->secondAttempt['status']));
        }
        $served = $this->secondAttempt['json']['error'] ?? '';
        if ($served !== $message) {
            throw new RuntimeException(\sprintf('le refus dit « %s » (attendu « %s »)', \is_string($served) ? $served : '', $message));
        }
        if (($this->secondAttempt['json']['existingSeasonId'] ?? null) !== $this->nextSeasonId) {
            throw new RuntimeException('le refus ne désigne pas la saison suivante déjà préparée');
        }
    }

    #[Then('le club n\'a que deux saisons')]
    public function leClubNAQueDeuxSaisons(): void
    {
        $count = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ('2' !== $count) {
            throw new RuntimeException(\sprintf('le club porte %s saisons (attendu 2)', $count));
        }
    }

    /** Pointage : chaque coach de la saison suivante pointe la fiche d'un coach de la saison en cours, et le club n'a pas de fiche en plus. */
    #[Then('/^les (?P<count>\d+) coachs de la saison suivante sont les mêmes personnes que ceux de la saison en cours$/u')]
    public function lesCoachsSontLesMemesPersonnes(string $count): void
    {
        $shared = $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM coach n JOIN coach c ON c.person_id = n.person_id AND c.season_id=\'%s\' WHERE n.season_id=\'%s\'',
            $this->currentSeasonId,
            $this->nextSeasonId,
        ), admin: true);
        if ($count !== $shared) {
            throw new RuntimeException(\sprintf('%s coachs de la saison suivante sont des personnes de la saison en cours (attendu %s)', $shared, $count));
        }
        $people = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM person WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ($count !== $people) {
            throw new RuntimeException(\sprintf('le club porte %s fiches de personne pour %s coachs (une fiche par personne attendue)', $people, $count));
        }
    }

    /** Le geste de l'étape Coachs du wizard, dans la saison suivante : `PUT /api/coaches/{id}`. */
    #[When('/^je corrige le téléphone de « (?P<coach>[^»]+) » dans la saison suivante$/u')]
    public function jeCorrigeLeTelephone(string $coach): void
    {
        $nextCoach = $this->coachOfSeason($coach, $this->nextSeasonId);
        // L'écran renvoie la fiche telle qu'il l'a lue, téléphone corrigé.
        $updated = $this->apiPut(\sprintf('coaches/%s', $nextCoach['id']), [
            'firstName' => $nextCoach['firstName'],
            'lastName' => $nextCoach['lastName'] ?? '',
            'phone' => self::CORRECTED_PHONE,
        ], $this->smallClubToken, ['X-Season-Id' => $this->nextSeasonId]);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('corriger le téléphone de %s a répondu %d (200 attendu) : %s', $coach, $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('/^« (?P<coach>[^»]+) » a ce téléphone dans la saison en cours$/u')]
    public function aCeTelephoneDansLaSaisonEnCours(string $coach): void
    {
        $phone = $this->coachOfSeason($coach, $this->currentSeasonId)['phone'] ?? null;
        if (self::CORRECTED_PHONE !== $phone) {
            throw new RuntimeException(\sprintf('dans la saison en cours, %s a le téléphone « %s » (attendu « %s », corrigé dans la saison suivante)', $coach, \is_string($phone) ? $phone : '', self::CORRECTED_PHONE));
        }
    }

    #[When('/^je supprime le coach « (?P<coach>[^»]+) » de la saison suivante$/u')]
    public function jeSupprimeLeCoachDeLaSaisonSuivante(string $coach): void
    {
        $nextCoach = $this->coachOfSeason($coach, $this->nextSeasonId);
        $deleted = $this->apiDelete(\sprintf('coaches/%s', $nextCoach['id']), $this->smallClubToken, ['X-Season-Id' => $this->nextSeasonId]);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('supprimer %s de la saison suivante a répondu %d (204 attendu) : %s', $coach, $deleted['status'], json_encode($deleted['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('/^« (?P<coach>[^»]+) » entraîne toujours ses équipes dans la saison en cours$/u')]
    public function entraineToujoursSesEquipes(string $coach): void
    {
        $current = $this->coachOfSeason($coach, $this->currentSeasonId);
        $teams = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM team_coach WHERE coach_id=\'%s\'', $current['id']), admin: true);
        if ('2' !== $teams) {
            throw new RuntimeException(\sprintf('dans la saison en cours, %s entraîne %s équipes (attendu 2, SM1 et U13M1)', $coach, $teams));
        }
        if (self::CORRECTED_PHONE !== ($current['phone'] ?? null)) {
            throw new RuntimeException(\sprintf('dans la saison en cours, %s a perdu son téléphone avec son retrait de la saison suivante', $coach));
        }
    }

    /** Pointage : chaque gymnase de la saison suivante est l'usage du lieu d'un gymnase de la saison en cours, et le club n'a pas de lieu en plus. */
    #[Then('/^les (?P<count>\d+) gymnases de la saison suivante sont aux mêmes lieux$/u')]
    public function lesGymnasesSontAuxMemesLieux(string $count): void
    {
        $shared = $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM venue n JOIN venue c ON c.place_id = n.place_id AND c.season_id=\'%s\' WHERE n.season_id=\'%s\'',
            $this->currentSeasonId,
            $this->nextSeasonId,
        ), admin: true);
        if ($count !== $shared) {
            throw new RuntimeException(\sprintf('%s gymnases de la saison suivante sont aux lieux de la saison en cours (attendu %s)', $shared, $count));
        }
        $places = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM place WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ($count !== $places) {
            throw new RuntimeException(\sprintf('le club porte %s lieux pour %s gymnases par saison (un lieu par gymnase attendu)', $places, $count));
        }
    }

    /** Le geste de l'étape Gymnases du wizard, dans la saison suivante : `PUT /api/venues/{id}`, l'adresse seule (position inchangée, aucun recalcul des trajets). */
    #[When('/^je corrige l\'adresse du « (?P<venue>[^»]+) » dans la saison suivante$/u')]
    public function jeCorrigeLAdresse(string $venue): void
    {
        $nextVenue = $this->venueOfSeason($venue, $this->nextSeasonId);
        $updated = $this->apiPut(\sprintf('venues/%s', $nextVenue['id']), [
            'name' => $venue,
            'source' => 'manual',
            'address' => self::CORRECTED_ADDRESS,
        ], $this->smallClubToken, ['X-Season-Id' => $this->nextSeasonId]);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('corriger l\'adresse du %s a répondu %d (200 attendu) : %s', $venue, $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('/^le « (?P<venue>[^»]+) » de la saison en cours a cette adresse$/u')]
    public function leGymnaseDeLaSaisonEnCoursACetteAdresse(string $venue): void
    {
        $address = $this->venueOfSeason($venue, $this->currentSeasonId)['address'] ?? null;
        if (self::CORRECTED_ADDRESS !== $address) {
            throw new RuntimeException(\sprintf('dans la saison en cours, le %s est au « %s » (attendu « %s », corrigé dans la saison suivante)', $venue, \is_string($address) ? $address : '', self::CORRECTED_ADDRESS));
        }
    }

    /**
     * Lot 3, PR 5 : une seule valeur au club, sans recopie — la fenêtre des trajets de chaque saison sert la MÊME ligne
     * (même identifiant, mêmes minutes), rangée selon les gymnases de cette saison.
     */
    #[Then('/^le temps de trajet entre le « (?P<a>[^»]+) » et le « (?P<b>[^»]+) » est le même dans les deux saisons$/u')]
    public function leTempsDeTrajetEstLeMemeDansLesDeuxSaisons(string $a, string $b): void
    {
        $current = $this->travelTimeOfSeason($a, $b, $this->currentSeasonId);
        $next = $this->travelTimeOfSeason($a, $b, $this->nextSeasonId);
        if ($current['id'] !== $next['id']) {
            throw new RuntimeException(\sprintf('le trajet %s – %s est une copie par saison (« %s » dans la saison en cours, « %s » dans la suivante), une seule valeur au club attendue', $a, $b, json_encode($current['id']), json_encode($next['id'])));
        }
        if ($this->drivingMinutes !== $current['drivingMinutes'] || $this->drivingMinutes !== $next['drivingMinutes']) {
            throw new RuntimeException(\sprintf('le trajet %s – %s vaut %s minutes dans la saison en cours et %s dans la suivante (attendu %d dans les deux)', $a, $b, json_encode($current['drivingMinutes']), json_encode($next['drivingMinutes']), $this->drivingMinutes));
        }
    }

    /** Le geste de la fenêtre des trajets, dans la saison suivante : `PUT /api/venue_travel_times/{id}`, couple nommé par les gymnases de cette saison. */
    #[When('/^je le corrige à (?P<minutes>\d+) minutes en voiture dans la saison suivante$/u')]
    public function jeCorrigeLeTempsDeTrajet(string $minutes): void
    {
        $row = $this->travelTimeOfSeason('Gymnase Nord', 'Gymnase Sud', $this->nextSeasonId);
        $updated = $this->apiPut(\sprintf('venue_travel_times/%s', $row['id']), [
            'venueAId' => $row['venueAId'],
            'venueBId' => $row['venueBId'],
            'drivingMinutes' => (int) $minutes,
        ], $this->smallClubToken, ['X-Season-Id' => $this->nextSeasonId]);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('corriger le trajet Nord – Sud dans la saison suivante a répondu %d (200 attendu) : %s', $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->drivingMinutes = (int) $minutes;
    }

    #[Then('/^il vaut (?P<minutes>\d+) minutes en voiture dans la saison en cours$/u')]
    public function ilVautDansLaSaisonEnCours(string $minutes): void
    {
        $current = $this->travelTimeOfSeason('Gymnase Nord', 'Gymnase Sud', $this->currentSeasonId);
        if ((int) $minutes !== $current['drivingMinutes']) {
            throw new RuntimeException(\sprintf('dans la saison en cours, le trajet Nord – Sud vaut %s minutes (attendu %s, corrigé dans la saison suivante)', json_encode($current['drivingMinutes']), $minutes));
        }
    }

    /** Le geste « Dissocier de sa salle FFBB » de l'étape Gymnases (`VenuesStep.tsx::confirmDissociate`), dans la saison suivante. */
    #[When('/^je dissocie le « (?P<venue>[^»]+) » de sa salle fédérale dans la saison suivante$/u')]
    public function jeDissocieLeGymnase(string $venue): void
    {
        $this->currentNordBeforeDissociation = $this->venueOfSeason($venue, $this->currentSeasonId);
        if (null === ($this->currentNordBeforeDissociation['address'] ?? null) || null === ($this->currentNordBeforeDissociation['latitude'] ?? null)) {
            throw new RuntimeException(\sprintf('décor : le %s de la saison en cours n\'a ni adresse ni position avant la dissociation', $venue));
        }
        $nextVenue = $this->venueOfSeason($venue, $this->nextSeasonId);
        $dissociated = $this->apiPut(\sprintf('venues/%s', $nextVenue['id']), [
            'name' => $venue,
            'source' => 'manual',
            'externalRef' => '',
        ], $this->smallClubToken, ['X-Season-Id' => $this->nextSeasonId]);
        if (200 !== $dissociated['status']) {
            throw new RuntimeException(\sprintf('dissocier le %s a répondu %d (200 attendu) : %s', $venue, $dissociated['status'], json_encode($dissociated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    /** Décision 2 du lot 3 (A) : la saison en cours garde numéro fédéral, adresse, position et temps de trajet. */
    #[Then('/^le « (?P<venue>[^»]+) » de la saison en cours garde son adresse et ses temps de trajet$/u')]
    public function leGymnaseDeLaSaisonEnCoursGardeTout(string $venue): void
    {
        $after = $this->venueOfSeason($venue, $this->currentSeasonId);
        foreach (['externalRef', 'address', 'latitude', 'longitude'] as $field) {
            if (($after[$field] ?? null) !== ($this->currentNordBeforeDissociation[$field] ?? null)) {
                throw new RuntimeException(\sprintf('dissocier le %s dans la saison suivante a changé son « %s » dans la saison en cours : « %s » devenu « %s »', $venue, $field, json_encode($this->currentNordBeforeDissociation[$field] ?? null), json_encode($after[$field] ?? null)));
            }
        }
        $minutes = $this->drivingMinutesFrom($this->smallClubVenueIds[$venue], $this->currentSeasonId);
        if ([$this->drivingMinutes] !== $minutes) {
            throw new RuntimeException(\sprintf('dans la saison en cours, les temps de trajet du %s sont %s (attendu [%d], saisis avant la dissociation)', $venue, json_encode($minutes), $this->drivingMinutes));
        }
    }

    /** Décision 2 du lot 3 (A) : le gymnase dissocié passe sur un lieu neuf, sans adresse ; le lieu de la saison en cours n'est pas touché. */
    #[Then('/^le « (?P<venue>[^»]+) » de la saison suivante n\'a plus d\'adresse, sur un lieu distinct de celui de la saison en cours$/u')]
    public function leGymnaseDeLaSaisonSuivanteEstSurUnLieuNeuf(string $venue): void
    {
        $next = $this->venueOfSeason($venue, $this->nextSeasonId);
        if (null !== ($next['address'] ?? null) || null !== ($next['latitude'] ?? null) || null !== ($next['externalRef'] ?? null)) {
            throw new RuntimeException(\sprintf('dans la saison suivante, le %s dissocié garde adresse, position ou numéro fédéral : %s', $venue, json_encode($next, \JSON_UNESCAPED_UNICODE)));
        }
        $placeOf = fn (string $venueId): string => $this->dbalScalar(\sprintf('SELECT place_id AS behatval FROM venue WHERE id=\'%s\'', $venueId), admin: true);
        $nextPlace = $placeOf((string) $next['id']);
        $currentPlace = $placeOf($this->smallClubVenueIds[$venue]);
        if ('' === $nextPlace || $nextPlace === $currentPlace) {
            throw new RuntimeException(\sprintf('le %s dissocié dans la saison suivante est encore sur le lieu de la saison en cours (« %s »)', $venue, $currentPlace));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->releaseDevClock($this->smallClubToken);
        $this->teardownSmallClub();
    }

    /**
     * Le coach d'une saison, lu comme l'écran le lit : `GET /api/coaches` dans cette saison (`X-Season-Id`).
     *
     * @return array<mixed>
     */
    private function coachOfSeason(string $firstName, string $seasonId): array
    {
        $listed = $this->apiGet('coaches', $this->smallClubToken, ['X-Season-Id' => $seasonId]);
        $members = \is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [];
        foreach ($members as $member) {
            if (\is_array($member) && ($member['firstName'] ?? null) === $firstName && \is_string($member['id'] ?? null)) {
                return $member;
            }
        }

        throw new RuntimeException(\sprintf('aucun coach « %s » dans la saison %s (HTTP %d)', $firstName, $seasonId, $listed['status']));
    }

    /**
     * Le gymnase d'une saison, lu comme l'écran le lit : `GET /api/venues` dans cette saison (`X-Season-Id`).
     *
     * @return array<mixed>
     */
    private function venueOfSeason(string $name, string $seasonId): array
    {
        $listed = $this->apiGet('venues', $this->smallClubToken, ['X-Season-Id' => $seasonId]);
        $members = \is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [];
        foreach ($members as $member) {
            if (\is_array($member) && ($member['name'] ?? null) === $name && \is_string($member['id'] ?? null)) {
                return $member;
            }
        }

        throw new RuntimeException(\sprintf('aucun gymnase « %s » dans la saison %s (HTTP %d)', $name, $seasonId, $listed['status']));
    }

    /**
     * Les minutes en voiture des trajets d'un gymnase, lues comme la fenêtre des trajets les lit dans cette saison.
     *
     * @return list<int|null>
     */
    private function drivingMinutesFrom(string $venueId, string $seasonId): array
    {
        // Comme l'écran (`wizard/api.ts::listVenueTravelTimes`) : la saison voyage par l'en-tête seul.
        $listed = $this->apiGet('venue_travel_times', $this->smallClubToken, ['X-Season-Id' => $seasonId]);
        $members = \is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [];
        $minutes = [];
        foreach ($members as $member) {
            if (\is_array($member) && \in_array($venueId, [$member['venueAId'] ?? null, $member['venueBId'] ?? null], true)) {
                $minutes[] = \is_int($member['drivingMinutes'] ?? null) ? $member['drivingMinutes'] : null;
            }
        }

        return $minutes;
    }

    /**
     * Le trajet entre deux gymnases d'une saison, lu comme la fenêtre des trajets le lit dans cette saison
     * (`GET /api/venue_travel_times`, `X-Season-Id`). Le couple servi est rangé selon les identifiants des gymnases
     * DE CETTE SAISON (plus petit d'abord) : une ligne rangée autrement, ou nommant un gymnase d'une autre saison,
     * n'est pas trouvée.
     *
     * @return array{id: mixed, venueAId: string, venueBId: string, drivingMinutes: mixed}
     */
    private function travelTimeOfSeason(string $a, string $b, string $seasonId): array
    {
        $ids = [(string) $this->venueOfSeason($a, $seasonId)['id'], (string) $this->venueOfSeason($b, $seasonId)['id']];
        sort($ids, \SORT_STRING);
        $listed = $this->apiGet('venue_travel_times', $this->smallClubToken, ['X-Season-Id' => $seasonId]);
        $members = \is_array($listed['json']['member'] ?? null) ? $listed['json']['member'] : [];
        foreach ($members as $member) {
            if (\is_array($member) && [$member['venueAId'] ?? null, $member['venueBId'] ?? null] === $ids) {
                return ['id' => $member['id'] ?? null, 'venueAId' => $ids[0], 'venueBId' => $ids[1], 'drivingMinutes' => $member['drivingMinutes'] ?? null];
            }
        }

        throw new RuntimeException(\sprintf('aucun trajet %s – %s rangé %s dans la saison %s (HTTP %d) : %s', $a, $b, json_encode($ids), $seasonId, $listed['status'], json_encode($members, \JSON_UNESCAPED_UNICODE)));
    }

    /** @return array<string, int> libellé → nombre de lignes de la saison */
    private function structureOf(string $seasonId): array
    {
        $counts = [];
        foreach (self::STRUCTURE as $label => $table) {
            $counts[$label] = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM "%s" WHERE season_id=\'%s\'', $table, $seasonId), admin: true);
        }

        return $counts;
    }
}
