<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\PdfGenerator;
use App\Service\SeasonResolver;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use RuntimeException;

/**
 * Promesse : en Découverte, un crédit se prend quand la sortie est OBTENUE — une version prête (générer,
 * régénérer, combler), un PDF du planning prêt à télécharger — jamais à la demande ; une génération
 * échouée ou bloquée ne coûte rien ; combler à zéro crédit est refusé avant la file ; avec un crédit,
 * deux PDF lancés coup sur coup n'en livrent qu'un et le fichier livré n'est jamais remplacé ; une
 * offre payante, la bêta et la démonstration ne sont jamais décomptées.
 *
 * Décor : le petit club de {@see SmallClubFixtureTrait}, inscrit par l'API (donc non démo, en Découverte,
 * 10 crédits — vérifié à la première étape sur `/api/me`). Les scénarios PDF n'ont besoin que de
 * l'inscription et de deux plannings en brouillon (aucun solve). Le solde se lit comme le lit l'écran,
 * sur `/api/me` (`club.entitlements`). Changer d'offre ou passer en démonstration est un pointage SQL
 * (patron de {@see DiscoveryCreditContext}). Les fichiers livrés se lisent dans le répertoire des rendus
 * (`PdfGenerator::outputDir()`, partagé avec le worker de rendu) et par la route de téléchargement.
 */
final class CreditsContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $socleId = '';

    private string $periodPlanId = '';

    /** La dernière version du plan de période (celle que « Combler » vise). */
    private string $periodVersionId = '';

    /** La dernière version produite par un geste, et son statut terminal. */
    private string $lastVersionId = '';

    private string $lastStatus = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $lastResponse = ['status' => 0, 'json' => []];

    private int $schedulesBefore = 0;

    /** @var array<string, string> « A » / « B » → id du planning en brouillon */
    private array $drafts = [];

    private string $northVenueId = '';

    /** @var list<array{schedule: string, scope: string, status: int}> les deux demandes d'export, dans l'ordre */
    private array $exportRequests = [];

    private string $firstUrl = '';

    private string $firstBytes = '';

    #[Given('le petit club inscrit en offre Découverte, avec 10 crédits et aucun d\'utilisé')]
    public function lePetitClubEnDecouverte(): void
    {
        $this->buildSmallClub();
        $this->assertFreshDecouverte();
    }

    #[Given('un club inscrit en offre Découverte, à qui il reste un seul crédit')]
    public function unClubAQuiIlResteUnCredit(): void
    {
        $this->registerSmallClub();
        $this->assertFreshDecouverte();
        foreach (['A', 'B'] as $label) {
            $created = $this->apiPost('schedules', ['name' => 'Planning ' . $label, 'status' => 'DRAFT'], $this->smallClubToken);
            $this->drafts[$label] = $this->smallClubStringField($created['json'], 'id');
        }
        $venue = $this->apiPost('venues', ['name' => 'Gymnase Nord', 'source' => 'manual'], $this->smallClubToken);
        $this->northVenueId = $this->smallClubStringField($venue['json'], 'id');

        // Le geste réel qui use neuf crédits : neuf exports en tableur (sortie synchrone, réglée à la réponse).
        $this->exportXlsxTimes($this->drafts['A'], 9);
        $this->jAiUtilise(9, 10);
    }

    #[When('je génère son planning de saison et le valide')]
    public function jeGenereEtValideLeSocle(): void
    {
        $this->socleId = $this->generateAndValidateSmallClubSocle();
        $this->lastVersionId = $this->socleId;
        $this->lastStatus = 'COMPLETED';
    }

    #[When('je déclare une fermeture de deux semaines et que son plan part du planning de saison')]
    public function jeDeclareUneFermeture(): void
    {
        $start = $this->smallClubFutureMonday(49, 14);
        $end = date('Y-m-d', (int) strtotime($start . ' +13 days'));
        $entryId = $this->createSmallClubClosureEntry($start, $end, 'Fermeture (crédits)');
        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);
        // « Partir du planning de saison » : la transcription recopie le socle sans calcul.
        $this->periodVersionId = $this->transcribeSmallClubSocleToPlan($this->periodPlanId);
    }

    #[When('je comble automatiquement le planning de la fermeture')]
    public function jeComble(): void
    {
        $fill = $this->apiPost(\sprintf('schedules/%s/fill', $this->periodVersionId), [], $this->smallClubToken);
        if (202 !== $fill['status']) {
            throw new RuntimeException(\sprintf('le comblement a répondu %d (202 attendu) — %s', $fill['status'], $this->encoded($fill['json'])));
        }
        $this->lastVersionId = $this->smallClubStringField($fill['json'], 'id');
        $this->lastStatus = $this->pollSmallClubUntilTerminal($this->lastVersionId);
        $this->periodVersionId = $this->lastVersionId;
    }

    #[When('/^j\'exporte le planning de saison en tableur (?P<times>\d+) fois$/u')]
    public function jExporteLeSocleEnTableur(int $times): void
    {
        $this->exportXlsxTimes($this->socleId, $times);
    }

    #[When('je tente de combler le planning de la fermeture')]
    public function jeTenteDeCombler(): void
    {
        $this->schedulesBefore = $this->countSchedules();
        $this->lastResponse = $this->apiPost(\sprintf('schedules/%s/fill', $this->periodVersionId), [], $this->smallClubToken);
    }

    #[Given('un entraînement mutualisé qu\'aucun créneau ne peut accueillir')]
    public function unEntrainementImpossible(): void
    {
        // Patron de `une-contrainte-saisie-est-honoree.feature` : une séance de BLOC est un placement
        // dur ; le bloc doit commencer après 23h30, aucun créneau du club n'y ouvre → le solveur
        // échoue (INFEASIBLE). Deux équipes jetables, sans réservation, pour ne rien changer au décor.
        $teams = $this->disposableGroup(['Bloc Impossible A', 'Bloc Impossible B']);
        $this->createGroup($teams);
        $constraint = $this->apiPost('constraints', [
            'name' => 'Bloc impossible après 23h30 (crédits)',
            'scope' => 'TEAM',
            'scopeTargetId' => $teams[0],
            'family' => 'TIME',
            'ruleType' => 'HARD',
            'config' => ['minStartTime' => '23:30'],
            'isActive' => true,
        ], $this->smallClubToken);
        if (!\in_array($constraint['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de la contrainte impossible refusée (HTTP %d)', $constraint['status']));
        }
    }

    #[Given('un entraînement mutualisé dont aucun coach n\'est jamais disponible')]
    public function unEntrainementSansCoach(): void
    {
        $teams = $this->disposableGroup(['Bloc Sans Coach A', 'Bloc Sans Coach B']);
        foreach ($teams as $index => $teamId) {
            $coach = $this->apiPost('coaches', ['firstName' => 'Indisponible ' . $index, 'lastName' => 'Crédits'], $this->smallClubToken);
            $coachId = $this->smallClubStringField($coach['json'], 'id');
            $this->apiPost('team_coaches', ['teamId' => $teamId, 'coachId' => $coachId, 'role' => 'MAIN'], $this->smallClubToken);
            $off = $this->apiPost('constraints', [
                'name' => 'Jamais disponible (crédits)',
                'scope' => 'COACH',
                'scopeTargetId' => $coachId,
                'family' => 'COACH_AVAILABILITY',
                'ruleType' => 'HARD',
                'config' => ['unavailableDays' => [1, 2, 3, 4, 5, 6, 7]],
                'isActive' => true,
            ], $this->smallClubToken);
            if (!\in_array($off['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('création de l\'indisponibilité refusée (HTTP %d)', $off['status']));
            }
        }
        $this->createGroup($teams);
    }

    #[When('je génère le planning de saison')]
    public function jeGenereLePlanning(): void
    {
        $created = $this->apiPost('schedules', ['name' => 'Planning ' . date('H:i:s'), 'status' => 'DRAFT'], $this->smallClubToken);
        $id = $this->smallClubStringField($created['json'], 'id');
        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $id), [], $this->smallClubToken);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('la génération a répondu %d (202 attendu) — %s', $launched['status'], $this->encoded($launched['json'])));
        }
        $this->lastVersionId = $id;
        $this->lastStatus = $this->pollSmallClubUntilTerminal($id);
    }

    #[When('je régénère la version échouée')]
    public function jeRegenereLaVersionEchouee(): void
    {
        $launched = $this->apiPost(\sprintf('schedules/%s/regenerate', $this->lastVersionId), [], $this->smallClubToken);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('la régénération a répondu %d (202 attendu) — %s', $launched['status'], $this->encoded($launched['json'])));
        }
        $this->lastVersionId = $this->smallClubStringField($launched['json'], 'id');
        $this->lastStatus = $this->pollSmallClubUntilTerminal($this->lastVersionId);
    }

    #[When('je tente de générer le planning de saison')]
    public function jeTenteDeGenerer(): void
    {
        $created = $this->apiPost('schedules', ['name' => 'Planning bloqué ' . date('H:i:s'), 'status' => 'DRAFT'], $this->smallClubToken);
        $this->lastVersionId = $this->smallClubStringField($created['json'], 'id');
        $this->lastResponse = $this->apiPost(\sprintf('schedules/%s/generate', $this->lastVersionId), [], $this->smallClubToken);
    }

    #[Then('/^la version est prête et j\'ai utilisé (?P<used>\d+) crédits? sur (?P<max>\d+)$/u')]
    public function laVersionEstPrete(int $used, int $max): void
    {
        if ('COMPLETED' !== $this->lastStatus) {
            throw new RuntimeException(\sprintf('la version aurait dû être prête (COMPLETED), obtenu « %s »', $this->lastStatus));
        }
        $this->jAiUtilise($used, $max);
    }

    #[Then('/^la génération échoue et j\'ai utilisé (?P<used>\d+) crédits? sur (?P<max>\d+)$/u')]
    public function laGenerationEchoue(int $used, int $max): void
    {
        if ('FAILED' !== $this->lastStatus) {
            throw new RuntimeException(\sprintf('la génération aurait dû échouer (FAILED), obtenu « %s »', $this->lastStatus));
        }
        $this->jAiUtilise($used, $max);
    }

    #[Then('/^j\'ai utilisé (?P<used>\d+) crédits? sur (?P<max>\d+)$/u')]
    public function jAiUtilise(int $used, int $max): void
    {
        $entitlements = $this->entitlements();
        if ($used !== ($entitlements['creditsUsed'] ?? null) || $max !== ($entitlements['creditsMax'] ?? null)) {
            throw new RuntimeException(\sprintf('le club a utilisé %s crédit(s) sur %s (attendu %d sur %d)', json_encode($entitlements['creditsUsed'] ?? null), json_encode($entitlements['creditsMax'] ?? null), $used, $max));
        }
    }

    #[Then('/^c\'est refusé \(403\) : « (?P<message>.+) »$/u')]
    public function cEstRefuse(string $message): void
    {
        $served = $this->lastResponse['json']['detail'] ?? ($this->lastResponse['json']['error'] ?? '');
        if (403 !== $this->lastResponse['status'] || $served !== $message) {
            throw new RuntimeException(\sprintf('refus attendu 403 « %s », reçu %d « %s »', $message, $this->lastResponse['status'], \is_string($served) ? $served : $this->encoded($this->lastResponse['json'])));
        }
    }

    #[Then('aucune version n\'est née du refus')]
    public function aucuneVersionNee(): void
    {
        $after = $this->countSchedules();
        if ($this->schedulesBefore !== $after) {
            throw new RuntimeException(\sprintf('aucune version ne doit naître d\'un refus (%d avant, %d après)', $this->schedulesBefore, $after));
        }
    }

    #[Then('/^c\'est refusé comme un blocage \(422\), sans partir au calcul$/u')]
    public function cEstRefuseCommeUnBlocage(): void
    {
        if (422 !== $this->lastResponse['status'] || true !== ($this->lastResponse['json']['blocker'] ?? null)) {
            throw new RuntimeException(\sprintf('la génération devait être refusée comme un blocage (422, blocker) — reçu %d : %s', $this->lastResponse['status'], $this->encoded($this->lastResponse['json'])));
        }
        $status = $this->smallClubStringField($this->apiGet(\sprintf('schedules/%s', $this->lastVersionId), $this->smallClubToken)['json'], 'status');
        if ('DRAFT' !== $status) {
            throw new RuntimeException(\sprintf('une génération bloquée ne part pas au calcul : la version devait rester DRAFT, obtenu « %s »', $status));
        }
    }

    #[When('/^je demande coup sur coup l\'export PDF (?P<first>.+) puis (?P<second>.+)$/u')]
    public function jeDemandeCoupSurCoup(string $first, string $second): void
    {
        $this->exportRequests = [];
        foreach ([$first, $second] as $wording) {
            [$label, $scope] = $this->parseExport($wording);
            $body = 'all' === $scope ? [] : ['venueId' => $this->northVenueId];
            $response = $this->apiPost(\sprintf('schedules/%s/export-pdf', $this->drafts[$label]), $body, $this->smallClubToken);
            $this->exportRequests[] = ['schedule' => $this->drafts[$label], 'scope' => $scope, 'status' => $response['status']];
        }
    }

    #[Then('les deux demandes sont acceptées')]
    public function lesDeuxDemandesSontAcceptees(): void
    {
        foreach ($this->exportRequests as $index => $request) {
            if (202 !== $request['status']) {
                throw new RuntimeException(\sprintf('la demande n° %d a répondu %d (202 attendu) : demander ne coûte rien, le crédit se prend à la livraison', $index + 1, $request['status']));
            }
        }
    }

    #[Then('/^le premier fichier est prêt, se télécharge, et j\'ai utilisé (?P<used>\d+) crédits? sur (?P<max>\d+)$/u')]
    public function lePremierFichierEstPret(int $used, int $max): void
    {
        $first = $this->exportRequests[0];
        $this->firstUrl = $this->pollUntilPdf($first['schedule'], 'completed');
        $expectedSuffix = 'all' === $first['scope'] ? '-all.pdf' : '-' . substr($this->northVenueId, 0, 8) . '.pdf';
        if (!str_ends_with($this->firstUrl, $expectedSuffix)) {
            throw new RuntimeException(\sprintf('le fichier livré n\'est pas celui de la première demande (%s attendu) : %s', $expectedSuffix, $this->firstUrl));
        }
        $this->firstBytes = $this->download($this->firstUrl);
        // Le second rendu attend en file derrière le premier (un seul consommateur) : on attend qu'il
        // ait fini avant de juger quoi que ce soit.
        $second = $this->exportRequests[1];
        if ($second['schedule'] !== $first['schedule']) {
            $this->pollUntilPdf($second['schedule'], 'failed');
        } else {
            $this->waitUntilPdfSettles($first['schedule']);
        }
        $this->jAiUtilise($used, $max);
    }

    #[Then('le second export échoue, sans fichier')]
    public function leSecondEchoueSansFichier(): void
    {
        $second = $this->exportRequests[1]['schedule'];
        $url = $this->apiGet(\sprintf('schedules/%s', $second), $this->smallClubToken)['json']['pdfExportUrl'] ?? null;
        if (null !== $url) {
            throw new RuntimeException(\sprintf('un export refusé ne livre aucune URL — obtenu %s', $this->encoded(['url' => $url])));
        }
        $this->assertRenderFiles($second, []);
    }

    #[Then('le second export ne livre aucun fichier, et le premier reste téléchargeable')]
    public function leSecondNeLivreRienEtLePremierReste(): void
    {
        $schedule = $this->exportRequests[0]['schedule'];
        $this->assertStillServesTheFirstFile($schedule);
        $this->assertRenderFiles($schedule, [basename($this->firstUrl)]);
    }

    #[Then('le second export reçoit le même fichier que le premier')]
    public function leSecondRecoitLeMemeFichier(): void
    {
        $schedule = $this->exportRequests[0]['schedule'];
        $this->assertStillServesTheFirstFile($schedule);
        $this->assertRenderFiles($schedule, [basename($this->firstUrl)]);
    }

    #[When('le club passe à l\'offre Essentiel, réglée pour la saison')]
    public function leClubPasseAEssentiel(): void
    {
        $this->pointOffer('essentiel', false);
    }

    #[When('le club passe à la bêta, réglée pour la saison')]
    public function leClubPasseALaBeta(): void
    {
        $this->pointOffer('beta', false);
    }

    #[When('le club revient en Découverte et passe en démonstration')]
    public function leClubPasseEnDemonstration(): void
    {
        $this->pointOffer(null, true);
    }

    #[When('j\'exporte le planning A en PDF')]
    public function jExporteLePlanningA(): void
    {
        $launched = $this->apiPost(\sprintf('schedules/%s/export-pdf', $this->drafts['A']), [], $this->smallClubToken);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('la demande d\'export a répondu %d (202 attendu)', $launched['status']));
        }
    }

    #[Then('le fichier est prêt et le club n\'a utilisé aucun crédit de plus, sans plafond')]
    public function leFichierEstPretSansDecompte(): void
    {
        $this->download($this->pollUntilPdf($this->drafts['A'], 'completed'));
        $entitlements = $this->entitlements();
        if (9 !== ($entitlements['creditsUsed'] ?? null) || !\array_key_exists('creditsMax', $entitlements) || null !== $entitlements['creditsMax']) {
            throw new RuntimeException(\sprintf('un club non bridé a été décompté ou borné : %s', $this->encoded($entitlements)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        foreach ($this->drafts as $scheduleId) {
            foreach ($this->renderFiles($scheduleId) as $name) {
                @unlink(PdfGenerator::outputDir() . '/' . $name);
            }
        }
        $this->teardownSmallClub();
    }

    private function assertFreshDecouverte(): void
    {
        $entitlements = $this->entitlements();
        if ('decouverte' !== ($entitlements['planCode'] ?? null) || 10 !== ($entitlements['creditsMax'] ?? null) || 0 !== ($entitlements['creditsUsed'] ?? null)) {
            throw new RuntimeException(\sprintf('un club inscrit devait naître en Découverte, 0 crédit utilisé sur 10 : %s', $this->encoded($entitlements)));
        }
    }

    private function exportXlsxTimes(string $scheduleId, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $response = $this->client->request('POST', \sprintf('schedules/%s/export-xlsx', $scheduleId), [
                'headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken],
                'json' => (object) [],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(\sprintf('l\'export en tableur n° %d a répondu %d (200 attendu)', $i + 1, $response->getStatusCode()));
            }
        }
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function disposableGroup(array $names): array
    {
        $categories = $this->apiGet('sport_categories', $this->smallClubToken)['json'];
        $members = \is_array($categories['member'] ?? null) ? $categories['member'] : $categories;
        $first = \is_array($members) ? (array_values($members)[0] ?? null) : null;
        $category = \is_array($first) && \is_string($first['id'] ?? null) ? $first['id'] : '';
        if ('' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $ids = [];
        foreach ($names as $name) {
            $team = $this->apiPost('teams', ['name' => $name, 'sportCategoryId' => $category, 'priorityTierId' => 1, 'sessionsPerWeek' => 1], $this->smallClubToken);
            $ids[] = $this->smallClubStringField($team['json'], 'id');
        }

        return $ids;
    }

    /** @param list<string> $teamIds */
    private function createGroup(array $teamIds): void
    {
        $block = $this->apiPost('shared_training_blocks', ['teamIds' => $teamIds, 'commonSessions' => 1, 'schedulePlanId' => null], $this->smallClubToken);
        if (!\in_array($block['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de l\'entraînement mutualisé refusée (HTTP %d)', $block['status']));
        }
    }

    /** @return array{0: string, 1: string} [« A » | « B », « all » | « venue »] */
    private function parseExport(string $wording): array
    {
        if (1 !== preg_match('/^du planning (?P<label>[AB])(?:, (?P<scope>tous les gymnases|au seul Gymnase Nord))?$/u', $wording, $m)) {
            throw new RuntimeException(\sprintf('export non reconnu : « %s »', $wording));
        }

        return [$m['label'], 'au seul Gymnase Nord' === ($m['scope'] ?? '') ? 'venue' : 'all'];
    }

    /** Attend le statut d'export voulu et rend l'URL servie (vide sur un échec). */
    private function pollUntilPdf(string $scheduleId, string $expected): string
    {
        $deadline = time() + 90;
        $status = '';
        do {
            $json = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->smallClubToken)['json'];
            $status = \is_string($json['pdfExportStatus'] ?? null) ? $json['pdfExportStatus'] : '';
            if ($expected === $status) {
                return \is_string($json['pdfExportUrl'] ?? null) ? $json['pdfExportUrl'] : '';
            }
            if ('failed' === $status && 'completed' === $expected) {
                throw new RuntimeException(\sprintf('l\'export attendu « %s » a fini « %s »', $expected, $status));
            }
            sleep(1);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('l\'export n\'a pas atteint « %s » dans le délai (dernier statut « %s »)', $expected, $status));
    }

    /**
     * Le planning porte deux demandes en file : on attend que son statut d'export soit terminal et
     * STABLE trois lectures de suite (le second rendu passe « en cours » puis revient).
     */
    private function waitUntilPdfSettles(string $scheduleId): void
    {
        $deadline = time() + 90;
        $stable = 0;
        do {
            $status = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->smallClubToken)['json']['pdfExportStatus'] ?? '';
            $stable = \in_array($status, ['completed', 'failed'], true) ? $stable + 1 : 0;
            if ($stable >= 3) {
                return;
            }
            sleep(1);
        } while (time() < $deadline);

        throw new RuntimeException('les exports du planning ne se sont pas terminés dans le délai');
    }

    private function assertStillServesTheFirstFile(string $scheduleId): void
    {
        $json = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->smallClubToken)['json'];
        if ('completed' !== ($json['pdfExportStatus'] ?? null) || $this->firstUrl !== ($json['pdfExportUrl'] ?? null)) {
            throw new RuntimeException(\sprintf('le planning devait rester « prêt » sur l\'URL livrée %s — obtenu %s', $this->firstUrl, $this->encoded(['status' => $json['pdfExportStatus'] ?? null, 'url' => $json['pdfExportUrl'] ?? null])));
        }
        if ($this->firstBytes !== $this->download($this->firstUrl)) {
            throw new RuntimeException('le fichier livré a été remplacé par le second rendu, refusé faute de crédit');
        }
    }

    /** @param list<string> $expected */
    private function assertRenderFiles(string $scheduleId, array $expected): void
    {
        $files = $this->renderFiles($scheduleId);
        if ($expected !== $files) {
            throw new RuntimeException(\sprintf('fichiers de rendu attendus %s, trouvés %s (un rendu refusé ne laisse rien, temporaire compris)', $this->encoded($expected), $this->encoded($files)));
        }
    }

    /** @return list<string> */
    private function renderFiles(string $scheduleId): array
    {
        clearstatcache();
        $names = array_map('basename', glob(PdfGenerator::outputDir() . '/schedule-' . $scheduleId . '-*') ?: []);
        sort($names);

        return $names;
    }

    private function download(string $url): string
    {
        $apiBase = rtrim((string) (getenv('BEHAT_API_BASE') ?: 'http://nginx/api'), '/');
        $origin = (string) preg_replace('#/api$#', '', $apiBase);
        $response = $this->client->request('GET', $origin . $url, ['headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken]]);
        $bytes = $response->getContent(false);
        if (200 !== $response->getStatusCode() || !str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException(\sprintf('le fichier livré ne se télécharge pas (HTTP %d) à %s', $response->getStatusCode(), $url));
        }

        return $bytes;
    }

    private function pointOffer(?string $planCode, bool $isDemo): void
    {
        $start = $this->dbalScalar(\sprintf('SELECT MIN(start_date)::text AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('' === $start ? 'today' : $start));
        $plan = null === $planCode ? 'NULL' : \sprintf('(SELECT id FROM subscription_plan WHERE code=\'%s\')', $planCode);
        $paid = null === $planCode ? 'NULL' : (string) $year;
        $this->dbalExec(\sprintf('UPDATE club SET plan_id=%s, paid_season_year=%s, is_demo=%s WHERE id=\'%s\'', $plan, $paid, $isDemo ? 'true' : 'false', $this->smallClubId), admin: true);
    }

    private function countSchedules(): int
    {
        return (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
    }

    /** @return array<mixed> */
    private function entitlements(): array
    {
        $me = $this->apiGet('me', $this->smallClubToken);
        $club = $me['json']['club'] ?? null;
        $entitlements = \is_array($club) ? ($club['entitlements'] ?? null) : null;
        if (!\is_array($entitlements)) {
            throw new RuntimeException('/api/me ne sert pas les droits de l\'offre du club');
        }

        return $entitlements;
    }

    /** @param array<mixed> $value */
    private function encoded(array $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
