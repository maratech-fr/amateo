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
 * Lot correctif import IA (« G2 ») PR1 — l'import du parc par IA « en un geste », de bout en bout sur
 * la stack qui tourne, RECALÉ sur le format `parc-v2` : le gestionnaire colle le texte parc-v2 (une
 * ligne par CASE), l'ANALYSE lui rend l'aperçu cochable des sections DÉRIVÉES (équipes, gymnase,
 * créneaux, bloc mutualisé, réservations), puis l'IMPORT les fait naître et POSE la réservation au
 * socle — le tout par le vrai rail HTTP /api/clubs/{id}/parc-import(/analyze). Les coachs ne sont PLUS
 * extraits (D11). C'est une PREUVE DE ZONE de l'ENCHAÎNEMENT (analyse → import → socle), pas une règle
 * isolée.
 *
 * Les règles isolées sont déjà prouvées plus bas :
 *  - socle EN VIGUEUR → 409 sans rien écrire : {@see ParcImportExecuteTest::testImportRefusedWhenSeasonPlanChosen} ;
 *  - idempotence « aucun doublon » : {@see ParcImportExecuteTest::testReplayingTheSameImportCreatesNoDuplicate} ;
 *  - case parc-v2 → réservation dérivée HONORÉE par le VRAI moteur /generate :
 *    {@see ParcImportReservationSemanticsGateTest} (NR bloquant) ;
 *  - case à 2 équipes + groupe → bloc mutualisé nommé : {@see ParcImportExecuteTest::testACaseWithAGroupeCreatesABlockLabelledByThatGroupe}.
 * Cette feature prouve l'ENCHAÎNEMENT réel : l'aperçu dérivé, l'import qui pose la réservation au socle
 * (schedulePlanId null) ET crée le bloc mutualisé nommé de la case à deux équipes.
 *
 * Décor : club de démonstration (patron {@see TeamsImportContext} + {@see SoclePlansContext}). Tout ce
 * que l'import crée porte un nom jetable (préfixe « ZZQWXYBEHAT »), purgé en fin de scénario. Le socle
 * est rouvert pour l'onboarding puis RESTAURÉ « en vigueur ».
 */
final class ParcImportContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    // Noms VOLONTAIREMENT inertes (préfixe « ZZQWXYBEHAT », aucun token de basket) : sur le décor BCCL
    // un token réel ferait un appariement FLOU au lieu du « aCreer » attendu à l'onboarding.
    private const string TEAM_A = 'ZZQWXYBEHAT UN';

    private const string TEAM_B = 'ZZQWXYBEHAT DEUX';

    private const string VENUE = 'ZZQWXYBEHAT SALLE';

    private const string GROUP_LABEL = 'PARC BEHAT GROUPE';

    private string $token = '';

    private string $clubId = '';

    private string $seasonId = '';

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

    #[When('j\'analyse mon parc de deux équipes, un gymnase, deux cases dont une mutualisée')]
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
        foreach (['gymnases', 'equipes', 'creneaux', 'mutualisations', 'reservations'] as $section) {
            foreach ($this->sectionLines($section) as $i => $line) {
                if ('aCreer' !== ($line['statut'] ?? null)) {
                    throw new RuntimeException(\sprintf('section « %s », ligne %d : statut « %s » attendu « aCreer »', $section, $i + 1, var_export($line['statut'] ?? null, true)));
                }
            }
        }
    }

    #[Then('l\'aperçu reconnaît les équipes et le gymnase déjà présents')]
    public function lApercuReconnaitDejaPresents(): void
    {
        foreach (['gymnases', 'equipes'] as $section) {
            foreach ($this->sectionLines($section) as $i => $line) {
                if ('apparie' !== ($line['statut'] ?? null)) {
                    throw new RuntimeException(\sprintf('section « %s », ligne %d : statut « %s » attendu « apparie » (déjà présent après le premier import)', $section, $i + 1, var_export($line['statut'] ?? null, true)));
                }
            }
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

    #[Then('les deux équipes et le gymnase existent')]
    public function lesEntitesExistent(): void
    {
        if ($this->teamCount(self::TEAM_A) < 1 || $this->teamCount(self::TEAM_B) < 1) {
            throw new RuntimeException('les deux équipes importées doivent exister en base');
        }
        if ($this->venueCount() < 1) {
            throw new RuntimeException('le gymnase importé doit exister en base');
        }
    }

    #[Then('le bloc mutualisé « PARC BEHAT GROUPE » existe avec ses deux équipes')]
    public function leBlocMutualiseExiste(): void
    {
        if (1 !== $this->namedBlockCount()) {
            throw new RuntimeException('la case à deux équipes + groupe doit créer exactement un bloc mutualisé nommé « ' . self::GROUP_LABEL . ' »');
        }
        if (2 !== $this->namedBlockMemberCount()) {
            throw new RuntimeException('le bloc mutualisé nommé doit regrouper exactement les deux équipes de la case');
        }
    }

    #[Then('la réservation de ma première équipe est posée au socle sur sa case réservée du lundi')]
    public function laReservationEstPoseeAuSocle(): void
    {
        if ($this->socleReservationCount() < 1) {
            throw new RuntimeException('la réservation dérivée doit être posée au socle (schedule_plan_id NULL) sur le lundi 18:00');
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
        if (0 !== $this->venueCount() || 0 !== $this->socleReservationCount() || 0 !== $this->namedBlockCount()) {
            throw new RuntimeException('un refus 409 n\'écrit rien : aucun gymnase, bloc ni réservation jetable ne doit exister');
        }
    }

    #[Then('aucun doublon n\'est créé')]
    public function aucunDoublonNestCree(): void
    {
        if (1 !== $this->teamCount(self::TEAM_A) || 1 !== $this->teamCount(self::TEAM_B)) {
            throw new RuntimeException('recoller le parc ne doit pas doubler les équipes');
        }
        if (1 !== $this->venueCount()) {
            throw new RuntimeException('recoller le parc ne doit pas doubler le gymnase');
        }
        if (1 !== $this->namedBlockCount()) {
            throw new RuntimeException('recoller le parc ne doit pas doubler le bloc mutualisé');
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

        $this->purgeDisposable();
    }

    /**
     * Le parc parc-v2 : un gymnase jetable, DEUX cases — une simple (TEAM_A, lundi 18:00 → réservation
     * au socle) et une mutualisée (TEAM_A + TEAM_B, mardi 17:00, `groupe` = le label du bloc). L'appli
     * DÉDUIT les équipes, le gymnase, les créneaux, le bloc et les réservations.
     *
     * @return array<string, mixed>
     */
    private function parc(): array
    {
        return [
            'format' => 'parc-v2',
            'gymnases' => [['nom' => self::VENUE]],
            'cases' => [
                ['gymnase' => self::VENUE, 'jour' => 1, 'debut' => '18:00', 'duree' => 90, 'equipes' => [self::TEAM_A]],
                ['gymnase' => self::VENUE, 'jour' => 2, 'debut' => '17:00', 'duree' => 90, 'equipes' => [self::TEAM_A, self::TEAM_B], 'groupe' => self::GROUP_LABEL],
            ],
        ];
    }

    /**
     * Analyse le parc puis coche toutes les lignes DÉRIVÉES « à créer », et importe — le geste réel du
     * gestionnaire sur un parc neuf. Sur un socle en vigueur, l'analyse 409 : on importe alors sans
     * décision (le gate refuse avant toute écriture).
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function postImport(): array
    {
        $analyze = $this->apiPost(
            \sprintf('clubs/%s/parc-import/analyze', $this->clubId),
            $this->parc(),
            $this->token,
            ['X-Season-Id' => $this->seasonId],
        );

        $decisions = [];
        if (200 === $analyze['status']) {
            foreach ((array) ($analyze['json']['sections'] ?? []) as $section => $lines) {
                foreach (\is_array($lines) ? $lines : [] as $i => $line) {
                    if (!\is_array($line) || 'aCreer' !== ($line['statut'] ?? null)) {
                        continue;
                    }
                    $decision = ['section' => $section, 'index' => $i, 'coche' => true];
                    // Refonte import IA PR2 — le genre est OBLIGATOIRE à la création d'une équipe
                    // (§0 point 1 AMENDÉ, « plus MIXTE »). Les équipes du parc jetable (« ZZQWXYBEHAT … »)
                    // sont inertes (aucun marqueur de genre) : on tranche MIXTE comme le ferait le
                    // gestionnaire au menu « Genre » de l'aperçu, sinon l'import refuserait (422).
                    if ('equipes' === $section) {
                        $decision['corrections'] = ['genre' => 'MIXTE'];
                    }
                    $decisions[] = $decision;
                }
            }
        }

        $response = $this->apiPost(
            \sprintf('clubs/%s/parc-import', $this->clubId),
            ['payload' => $this->parc(), 'decisions' => $decisions],
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

    private function namedBlockCount(): int
    {
        return (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM shared_training_block WHERE club_id=\'%s\' AND label=\'%s\'', $this->clubId, self::GROUP_LABEL),
            admin: true,
        );
    }

    private function namedBlockMemberCount(): int
    {
        return (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM shared_training_block_team m'
                . ' JOIN shared_training_block b ON b.id=m.block_id'
                . ' WHERE b.club_id=\'%s\' AND b.label=\'%s\'',
                $this->clubId,
                self::GROUP_LABEL,
            ),
            admin: true,
        );
    }

    /** Réservations SOCLE (schedule_plan_id NULL) de TEAM_A sur la case lundi 18:00 du gymnase jetable. */
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
        $blocks = \sprintf('SELECT id FROM shared_training_block WHERE club_id=\'%s\' AND label=\'%s\'', $this->clubId, self::GROUP_LABEL);

        $this->dbalExec(\sprintf('DELETE FROM reservation WHERE club_id=\'%s\' AND (team_id IN (%s) OR venue_id IN (%s))', $this->clubId, $teams, $venues), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM shared_training_block_team WHERE block_id IN (%s) OR team_id IN (%s)', $blocks, $teams), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM shared_training_block WHERE club_id=\'%s\' AND label=\'%s\'', $this->clubId, self::GROUP_LABEL), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM venue_training_slot WHERE venue_id IN (%s)', $venues), admin: true);
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
}
