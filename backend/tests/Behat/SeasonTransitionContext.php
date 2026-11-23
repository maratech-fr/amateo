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
 * retrait de la saison suivante ne touche pas la saison en cours.
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
