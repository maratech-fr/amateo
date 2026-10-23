<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Tests\CrossStack\ParcImportReservationSemanticsGateTest;
use App\Tests\Integration\Api\ParcImportExecuteTest;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Chantier G PR 4/4 — l'import du parc par IA « en un geste », de bout en bout sur la stack qui
 * tourne : le gestionnaire colle le texte parc-v1, l'ANALYSE lui rend l'aperçu cochable (dry-run),
 * puis l'IMPORT fait naître équipes/gymnase/créneau/coach et POSE la réservation au socle — le tout
 * par le vrai rail HTTP /api/clubs/{id}/parc-import(/analyze). C'est une PREUVE DE ZONE de
 * l'ENCHAÎNEMENT (analyse → import → socle), pas une règle isolée.
 *
 * Les règles isolées sont déjà prouvées plus bas, au niveau PHPUnit (noyau + DB) :
 *  - socle EN VIGUEUR → 409 sans rien écrire : {@see ParcImportExecuteTest::testImportRefusedWhenSeasonPlanChosen} ;
 *  - idempotence « aucun doublon » : {@see ParcImportExecuteTest::testReplayingTheSameImportCreatesNoDuplicate} ;
 *  - réservation importée → HONORÉE par le VRAI moteur /generate (verrou HARD) :
 *    {@see ParcImportReservationSemanticsGateTest} (NR bloquant).
 * Ce dernier gate porte déjà le smoke sémantique « la case réservée l'emporte sur la préférence
 * souple » contre le moteur réel : cette feature ne REGÉNÈRE donc pas (un solve sur le club de démo,
 * 11 équipes, serait lourd ET exigerait de rouvrir/restaurer le socle) — elle prouve que l'import
 * réel POSE bien la réservation au socle (schedulePlanId null), la jointure que le gate suppose.
 *
 * Décor : club de démonstration (patron {@see TeamsImportContext} + {@see SoclePlansContext}). Tout
 * ce que l'import crée porte un nom jetable (préfixe « PARC BEHAT ») ou un nom de coach distinctif,
 * purgé en fin de scénario. Le pointeur du socle est piloté (rouvert pour l'onboarding, en vigueur
 * pour le refus) puis RESTAURÉ « en vigueur » — le contrat des autres scénarios sur ce club.
 */
final class ParcImportContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    // Noms VOLONTAIREMENT inertes (préfixe « ZZQWXYBEHAT », aucun token de basket comme « SF1 » /
    // « U13 ») : sur le décor BCCL, un token réel ferait un appariement FLOU (« suggere ») au lieu
    // du « aCreer » attendu à l'onboarding. Le préfixe unique rend aussi la purge sûre.
    private const string TEAM_A = 'ZZQWXYBEHAT UN';

    private const string TEAM_B = 'ZZQWXYBEHAT DEUX';

    private const string VENUE = 'ZZQWXYBEHAT SALLE';

    private const string COACH_FIRST = 'Zzqwxybehat';

    private const string COACH_LAST = 'Zzcoachbehat';

    private string $token = '';

    private string $clubId = '';

    private string $seasonId = '';

    private string $categoryName = '';

    /** Version que le socle pointait à l'entrée du scénario (à restaurer « en vigueur »). */
    private string $chosenAtEntry = '';

    /** @var array<mixed> */
    private array $analyzeJson = [];

    private int $analyzeStatus = 0;

    /** @var array<mixed> */
    private array $importJson = [];

    private int $importStatus = 0;

    // ───────────────────────────── Décor ─────────────────────────────

    #[Given('le club de démonstration, prêt à importer son parc, le planning de saison rouvert')]
    public function leClubSocleRouvert(): void
    {
        $this->prepareClub();
        // Import = geste d'ONBOARDING : le socle NE DOIT PAS être en vigueur (sinon 409).
        $this->pointSocleTo('');
    }

    #[Given('le club de démonstration, prêt à importer son parc, le planning de saison en vigueur')]
    public function leClubSocleEnVigueur(): void
    {
        $this->prepareClub();
        $target = $this->freshestCompletedSeasonVersion();
        if ('' === $target) {
            throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
        }
        $this->pointSocleTo($target);
    }

    #[Given('mon parc déjà importé une fois')]
    public function monParcDejaImporteUneFois(): void
    {
        [$status, $json] = $this->postImport();
        if (200 !== $status) {
            throw new RuntimeException(\sprintf('le premier import a répondu %d (200 attendu) : %s', $status, json_encode($json['errors'] ?? $json, \JSON_UNESCAPED_UNICODE)));
        }
    }

    // ───────────────────────────── Analyse ─────────────────────────────

    #[When('j\'analyse mon parc de deux équipes, un gymnase, un créneau, un coach et une réservation')]
    public function jAnalyseMonParc(): void
    {
        $response = $this->apiPost(
            \sprintf('clubs/%s/parc-import/analyze', $this->clubId),
            $this->parc(),
            $this->token,
            ['X-Season-Id' => $this->seasonId],
        );
        $this->analyzeStatus = $response['status'];
        $this->analyzeJson = $response['json'];
        if (200 !== $this->analyzeStatus) {
            throw new RuntimeException(\sprintf('l\'analyse a répondu %d (200 attendu)', $this->analyzeStatus));
        }
    }

    #[Then('l\'aperçu propose de tout créer')]
    public function lApercuProposeDeToutCreer(): void
    {
        foreach (['gymnases', 'equipes', 'creneaux', 'coachs', 'reservations'] as $section) {
            foreach ($this->sectionLines($section) as $i => $line) {
                if ('aCreer' !== ($line['statut'] ?? null)) {
                    throw new RuntimeException(\sprintf('section « %s », ligne %d : statut « %s » attendu « aCreer » (tout est neuf à l\'onboarding)', $section, $i + 1, var_export($line['statut'] ?? null, true)));
                }
                if (true !== ($line['coche'] ?? null)) {
                    throw new RuntimeException(\sprintf('section « %s », ligne %d : une ligne « à créer » doit être cochée', $section, $i + 1));
                }
            }
        }
    }

    #[Then('l\'aperçu reconnaît les équipes, le gymnase et le coach déjà présents')]
    public function lApercuReconnaitDejaPresents(): void
    {
        foreach (['gymnases', 'equipes', 'coachs'] as $section) {
            foreach ($this->sectionLines($section) as $i => $line) {
                if ('apparie' !== ($line['statut'] ?? null)) {
                    throw new RuntimeException(\sprintf('section « %s », ligne %d : statut « %s » attendu « apparie » (déjà présent après le premier import)', $section, $i + 1, var_export($line['statut'] ?? null, true)));
                }
                if (!\is_string($line['cibleId'] ?? null) || '' === $line['cibleId']) {
                    throw new RuntimeException(\sprintf('section « %s », ligne %d : un appariement doit pointer une cible', $section, $i + 1));
                }
            }
        }
    }

    #[Then('la réservation déjà posée ressort « déjà présente »')]
    public function laReservationRessortDejaPresente(): void
    {
        $line = $this->sectionLines('reservations')[0] ?? null;
        if (!\is_array($line) || 'decoche' !== ($line['statut'] ?? null)) {
            throw new RuntimeException('la réservation déjà posée doit ressortir « décochée »');
        }
        if (!str_contains(mb_strtolower((string) ($line['raison'] ?? '')), 'déjà présent')) {
            throw new RuntimeException(\sprintf('la raison devrait dire « déjà présent », reçu « %s »', $line['raison'] ?? ''));
        }
    }

    // ───────────────────────────── Import ─────────────────────────────

    #[When('j\'importe tout le parc')]
    public function jImporteToutLeParc(): void
    {
        [$this->importStatus, $this->importJson] = $this->postImport();
    }

    #[Then('l\'import réussit')]
    public function lImportReussit(): void
    {
        if (200 !== $this->importStatus) {
            throw new RuntimeException(\sprintf('l\'import a répondu %d (200 attendu) : %s', $this->importStatus, json_encode($this->importJson['errors'] ?? $this->importJson, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('les deux équipes, le gymnase, le créneau et le coach existent')]
    public function lesEntitesExistent(): void
    {
        if ($this->teamCount(self::TEAM_A) < 1 || $this->teamCount(self::TEAM_B) < 1) {
            throw new RuntimeException('les deux équipes importées doivent exister en base');
        }
        if ($this->venueCount() < 1) {
            throw new RuntimeException('le gymnase importé doit exister en base');
        }
        if ($this->slotCount() < 1) {
            throw new RuntimeException('le créneau importé doit exister en base');
        }
        if ($this->coachCount() < 1) {
            throw new RuntimeException('le coach importé doit exister en base');
        }
    }

    #[Then('la réservation de « PARC BEHAT SF1 » est posée au socle sur sa case réservée du lundi')]
    public function laReservationEstPoseeAuSocle(): void
    {
        if ($this->socleReservationCount() < 1) {
            throw new RuntimeException('la réservation importée doit être posée au socle (schedule_plan_id NULL) sur le lundi 18:00');
        }
    }

    #[Then('l\'import est refusé faute de planning rouvert')]
    public function lImportEstRefuse(): void
    {
        if (409 !== $this->importStatus) {
            throw new RuntimeException(\sprintf('socle en vigueur → l\'import doit être refusé (409), reçu %d', $this->importStatus));
        }
    }

    #[Then('rien du parc n\'a été écrit')]
    public function rienDuParcNaEteEcrit(): void
    {
        foreach ([self::TEAM_A, self::TEAM_B] as $name) {
            if (0 !== $this->teamCount($name)) {
                throw new RuntimeException(\sprintf('un refus 409 n\'écrit rien : « %s » ne doit pas exister', $name));
            }
        }
        if (0 !== $this->venueCount() || 0 !== $this->coachCount() || 0 !== $this->socleReservationCount()) {
            throw new RuntimeException('un refus 409 n\'écrit rien : aucun gymnase, coach ni réservation jetable ne doit exister');
        }
    }

    #[Then('aucun doublon n\'est créé')]
    public function aucunDoublonNestCree(): void
    {
        // Après deux imports du même parc : exactement une occurrence de chaque entité jetable.
        if (1 !== $this->teamCount(self::TEAM_A) || 1 !== $this->teamCount(self::TEAM_B)) {
            throw new RuntimeException('recoller le parc ne doit pas doubler les équipes');
        }
        if (1 !== $this->venueCount()) {
            throw new RuntimeException('recoller le parc ne doit pas doubler le gymnase');
        }
        if (1 !== $this->slotCount()) {
            throw new RuntimeException('recoller le parc ne doit pas doubler le créneau');
        }
        if (1 !== $this->coachCount()) {
            throw new RuntimeException('recoller le parc ne doit pas doubler le coach');
        }
        if (1 !== $this->socleReservationCount()) {
            throw new RuntimeException('recoller le parc ne doit pas doubler la réservation');
        }
    }

    // ───────────────────────────── Nettoyage ─────────────────────────────

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->clubId) {
            return;
        }

        $this->purgeDisposable();

        // Restaure « socle en vigueur » (contrat des autres scénarios sur le club de démo).
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

    // ───────────────────────────── Helpers ─────────────────────────────

    private function prepareClub(): void
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

        $this->chosenAtEntry = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->chosenAtEntry)) {
            $this->chosenAtEntry = '';
        }

        // Une catégorie EXISTANTE du club : la nommer dans le parc évite une catégorie orpheline
        // (le résolveur la retrouve par nom normalisé, aucune création).
        $categories = $this->members($this->apiGet('sport_categories', $this->token)['json']);
        $categoryName = $categories[0]['name'] ?? null;
        if (!\is_string($categoryName) || '' === $categoryName) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir le parc');
        }
        $this->categoryName = $categoryName;

        // Départ propre : aucune entité jetable résiduelle d'un run interrompu.
        $this->purgeDisposable();
    }

    /**
     * Le parc parc-v1 : deux équipes, un gymnase, un créneau, un coach, une réservation — tout
     * jetable. La réservation vise la case du créneau (lundi 18:00).
     *
     * @return array<string, mixed>
     */
    private function parc(): array
    {
        return [
            'format' => 'parc-v1',
            'gymnases' => [['nom' => self::VENUE]],
            'equipes' => [
                ['nom' => self::TEAM_A, 'genre' => 'F', 'categorie' => $this->categoryName, 'seances' => 1],
                ['nom' => self::TEAM_B, 'genre' => 'M', 'categorie' => $this->categoryName, 'seances' => 1],
            ],
            'creneaux' => [['gymnase' => self::VENUE, 'jour' => 1, 'debut' => '18:00', 'duree' => 90]],
            'mutualisations' => [],
            'coachs' => [['nom' => self::COACH_FIRST . ' ' . self::COACH_LAST, 'equipe' => self::TEAM_A]],
            'reservations' => [['equipe' => self::TEAM_A, 'gymnase' => self::VENUE, 'jour' => 1, 'debut' => '18:00']],
        ];
    }

    /**
     * Toutes les lignes du parc cochées — l'import réel écrit tout. La réservation déjà présente
     * (re-import) est tolérée : l'executor la réutilise par triplet (idempotence).
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function postImport(): array
    {
        $parc = $this->parc();
        $decisions = [];
        foreach (['gymnases', 'equipes', 'creneaux', 'mutualisations', 'coachs', 'reservations'] as $section) {
            foreach (\is_array($parc[$section]) ? $parc[$section] : [] as $i => $_) {
                $decisions[] = ['section' => $section, 'index' => $i, 'coche' => true];
            }
        }

        $response = $this->apiPost(
            \sprintf('clubs/%s/parc-import', $this->clubId),
            ['payload' => $parc, 'decisions' => $decisions],
            $this->token,
            ['X-Season-Id' => $this->seasonId],
        );

        return [$response['status'], $response['json']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sectionLines(string $section): array
    {
        $sections = $this->analyzeJson['sections'] ?? [];
        $lines = \is_array($sections) ? ($sections[$section] ?? []) : [];

        return array_values(array_filter(\is_array($lines) ? $lines : [], 'is_array'));
    }

    private function teamCount(string $name): int
    {
        return (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM team WHERE club_id=\'%s\' AND name=\'%s\'', $this->clubId, $name),
            admin: true,
        );
    }

    private function venueCount(): int
    {
        return (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM venue WHERE club_id=\'%s\' AND name=\'%s\'', $this->clubId, self::VENUE),
            admin: true,
        );
    }

    private function slotCount(): int
    {
        return (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM venue_training_slot s JOIN venue v ON v.id=s.venue_id WHERE v.club_id=\'%s\' AND v.name=\'%s\'',
                $this->clubId,
                self::VENUE,
            ),
            admin: true,
        );
    }

    private function coachCount(): int
    {
        return (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM coach WHERE club_id=\'%s\' AND first_name=\'%s\' AND last_name=\'%s\'', $this->clubId, self::COACH_FIRST, self::COACH_LAST),
            admin: true,
        );
    }

    /** Réservations SOCLE (schedule_plan_id NULL) de SF1 sur la case lundi 18:00 du gymnase jetable. */
    private function socleReservationCount(): int
    {
        return (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM reservation r'
                . ' JOIN team t ON t.id=r.team_id JOIN venue v ON v.id=r.venue_id'
                . ' WHERE r.club_id=\'%s\' AND t.name=\'%s\' AND v.name=\'%s\''
                . ' AND r.day_of_week=1 AND EXTRACT(HOUR FROM r.start_time)=18 AND EXTRACT(MINUTE FROM r.start_time)=0'
                . ' AND r.schedule_plan_id IS NULL',
                $this->clubId,
                self::TEAM_A,
                self::VENUE,
            ),
            admin: true,
        );
    }

    private function purgeDisposable(): void
    {
        $teams = \sprintf('SELECT id FROM team WHERE club_id=\'%s\' AND name LIKE \'ZZQWXYBEHAT%%\'', $this->clubId);
        $venues = \sprintf('SELECT id FROM venue WHERE club_id=\'%s\' AND name=\'%s\'', $this->clubId, self::VENUE);

        $this->dbalExec(\sprintf('DELETE FROM reservation WHERE club_id=\'%s\' AND (team_id IN (%s) OR venue_id IN (%s))', $this->clubId, $teams, $venues), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM team_coach WHERE team_id IN (%s)', $teams), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM venue_training_slot WHERE venue_id IN (%s)', $venues), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM coach WHERE club_id=\'%s\' AND first_name=\'%s\' AND last_name=\'%s\'', $this->clubId, self::COACH_FIRST, self::COACH_LAST), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM team WHERE club_id=\'%s\' AND name LIKE \'ZZQWXYBEHAT%%\'', $this->clubId), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM venue WHERE club_id=\'%s\' AND name=\'%s\'', $this->clubId, self::VENUE), admin: true);
    }

    private function freshestCompletedSeasonVersion(): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
            admin: true,
        );

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
     * @param array<mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $json): array
    {
        $members = $json['member'] ?? $json;

        return array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
    }
}
