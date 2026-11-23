<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\PdfGenerator;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : au-dessus de son palier, un club n'a plus aucune sortie — générer, régénérer, combler,
 * placer les matchs, exporter le planning (tableur, PDF), exporter les matchs en PDF sont refusés 403
 * avec le message du dépassement, que `/api/me` sert aussi (`entitlements.teamCapMessage`) — ; consulter,
 * charger une version et télécharger un PDF déjà produit restent ouverts ; tout revient dès le retour
 * sous le plafond (supprimer une équipe sans match, changer de palier), une équipe qui a un match ne
 * se supprime pas ; Découverte, la bêta, le palier 3 et la démonstration ne sont jamais concernés.
 *
 * Décor : le petit club de {@see SmallClubFixtureTrait}, inscrit par l'API (Découverte), auquel le
 * plan autorise d'ajouter d'un coup les équipes qui le portent à 21, en UNE insertion SQL (levier de
 * durée, plan du lot 8 §PR 3) — dans la catégorie et la saison du club. Le geste du support est la
 * VRAIE commande lancée par la console (`app:clubs:set-plan`) ; la démonstration est un pointage SQL.
 * Les refus se jouent sur l'API réelle ; le contrôle central refuse AVANT le contrôleur, si bien que
 * la cible (le planning de saison pour régénérer, combler, exporter) ne change rien au verdict.
 *
 * Plus bas : les sept fonctions, le rôle, l'abus de saison et l'ordre des refus —
 * `Security/PlanEntitlementsTest` (bloquant).
 */
final class TeamCapContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $socleId = '';

    private string $draftId = '';

    /** La version générée à 21 équipes, que « Charger » restaure. */
    private string $versionId = '';

    private string $pdfUrl = '';

    private string $pdfBytes = '';

    /** @var list<string> les équipes ajoutées par SQL (sans match), dans l'ordre d'insertion */
    private array $addedTeamIds = [];

    private string $capMessage = '';

    private int $schedulesBefore = 0;

    private int $runsBefore = 0;

    /** @var array{status: int, json: array<mixed>} */
    private array $lastResponse = ['status' => 0, 'json' => []];

    /**
     * Aucun brouillon à côté : la validation retire les autres versions de saison, et un planning en
     * vigueur refuse d'en créer une (`SocleGuard::assertSeasonPlanNotChosen`). « Générer » vise donc
     * le planning de saison : le contrôle central refuse avant le contrôleur.
     */
    #[Given('le petit club, son planning de saison généré et validé, et un PDF de ce planning déjà produit')]
    public function lePetitClubAvecSocleEtPdf(): void
    {
        $this->buildSmallClub();
        $this->socleId = $this->generateAndValidateSmallClubSocle();
        $launched = $this->apiPost(\sprintf('schedules/%s/export-pdf', $this->socleId), [], $this->smallClubToken);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('la demande d\'export PDF du décor a répondu %d (202 attendu)', $launched['status']));
        }
        $this->pdfUrl = $this->pollUntilPdfCompleted($this->socleId);
        $this->pdfBytes = $this->download($this->pdfUrl);
    }

    #[Given('le petit club, avec 21 équipes, et une version de son planning de saison générée sans être validée')]
    public function lePetitClubAvecUneVersionA21(): void
    {
        $this->buildSmallClub();
        $this->clubCountsTeams(21);
        // L'insertion SQL n'invalide pas le cache des payloads du solveur (`.claude/rules/backend.md`).
        $this->runConsole(['php', 'bin/console', 'cache:pool:clear', 'cache.schedule']);
        $this->versionId = $this->generateSmallClubSeason();
    }

    #[Given('un club inscrit par l\'API, avec 21 équipes et un planning de saison en brouillon')]
    public function unClubInscritAvec21Equipes(): void
    {
        $this->registerSmallClub();
        $this->draftId = $this->smallClubStringField($this->apiPost('schedules', ['name' => 'Brouillon', 'status' => 'DRAFT'], $this->smallClubToken)['json'], 'id');
        $this->clubCountsTeams(21);
    }

    /** Porte le club au compte d'équipes voulu, en une insertion ; `$inactive` d'entre elles désactivées. */
    #[Given('/^le club compte (?P<count>\d+) équipes(?:, dont (?P<inactive>\d+) désactivées)?$/u')]
    public function clubCountsTeams(int $count, int|string $inactive = 0): void
    {
        $seasonId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\' ORDER BY start_date DESC LIMIT 1', $this->smallClubId), admin: true);
        $categoryId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM sport_category WHERE club_id=\'%s\' ORDER BY sort_order LIMIT 1', $this->smallClubId), admin: true);
        if ('' === $seasonId || '' === $categoryId) {
            throw new RuntimeException('le club n\'a ni saison ni catégorie où ajouter des équipes');
        }
        $current = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM team WHERE club_id=\'%s\' AND season_id=\'%s\'', $this->smallClubId, $seasonId), admin: true);
        $missing = $count - $current;
        if ($missing < (int) $inactive || $missing <= 0) {
            throw new RuntimeException(\sprintf('le club a déjà %d équipes : impossible d\'en compter %d dont %d désactivées ajoutées', $current, $count, (int) $inactive));
        }
        $this->dbalExec(\sprintf(
            'INSERT INTO team (id, version, created_at, updated_at, club_id, season_id, sport_category_id, priority_tier_id, name, sessions_per_week, is_active)'
            . ' SELECT gen_random_uuid(), 1, now(), now(), \'%1$s\', \'%2$s\', \'%3$s\', 5, \'Équipe ajoutée \' || g, 1, g > %4$d FROM generate_series(1, %5$d) AS g',
            $this->smallClubId,
            $seasonId,
            $categoryId,
            (int) $inactive,
            $missing,
        ), admin: true);
        $ids = $this->dbalScalar(\sprintf('SELECT string_agg(id::text, \',\' ORDER BY name) AS behatval FROM team WHERE club_id=\'%s\' AND name LIKE \'Équipe ajoutée %%\'', $this->smallClubId), admin: true);
        $this->addedTeamIds = '' === $ids ? [] : explode(',', $ids);

        $used = $this->entitlements()['teamsUsed'] ?? null;
        if ($count !== $used) {
            throw new RuntimeException(\sprintf('témoin : /api/me devait compter %d équipes, il en compte %s', $count, json_encode($used)));
        }
    }

    #[Given('aucun geste du support')]
    public function aucunGesteDuSupport(): void
    {
        // Le club reste en Découverte : l'inscription seule ne règle rien.
    }

    #[Given('le support règle la bêta pour la saison en cours')]
    public function leSupportRegleLaBeta(): void
    {
        $this->runConsole(['php', 'bin/console', 'app:clubs:set-plan', '--club=' . $this->smallClubId, '--plan=beta', '--paid-season=current']);
    }

    #[Given('/^le support règle l\'offre complète au palier (?P<position>[123]) pour la saison en cours$/u')]
    public function leSupportRegleLOffreComplete(string $position): void
    {
        $this->runConsole(['php', 'bin/console', 'app:clubs:set-plan', '--club=' . $this->smallClubId, '--plan=complete', '--tier=palier-' . $position, '--paid-season=current']);
    }

    #[Given('le club passe en démonstration, sans aucun geste du support')]
    public function leClubPasseEnDemonstration(): void
    {
        $this->dbalExec(\sprintf('UPDATE club SET is_demo = true WHERE id=\'%s\'', $this->smallClubId), admin: true);
    }

    /** Ce que chaque écran lit pour afficher le bandeau : `/api/me`, `entitlements.teamCapMessage`. */
    #[Then('/^le club est prévenu : « (?P<message>.+) »$/u')]
    public function leClubEstPrevenu(string $message): void
    {
        $this->capMessage = $message;
        $this->assertWarned($message);
    }

    #[Then('le club est toujours prévenu')]
    public function leClubEstToujoursPrevenu(): void
    {
        $this->assertWarned($this->capMessage);
    }

    #[Then('/^le club n\'est (?:plus|pas) prévenu$/u')]
    public function leClubNEstPasPrevenu(): void
    {
        $entitlements = $this->entitlements();
        if (!\array_key_exists('teamCapMessage', $entitlements) || null !== $entitlements['teamCapMessage']) {
            throw new RuntimeException(\sprintf('/api/me ne devait servir aucun message de dépassement (teamCapMessage: null) : %s', $this->encoded($entitlements)));
        }
    }

    #[Then('/^chacune de ces sorties m\'est refusée \(403\) avec ce message :$/u')]
    public function chacuneDeCesSortiesEstRefusee(TableNode $table): void
    {
        $this->schedulesBefore = $this->countOf('schedule');
        $this->runsBefore = $this->countOf('match_placement_run');
        foreach ($table->getColumnsHash() as $row) {
            $response = $this->attempt($row['sortie']);
            $served = $this->refusalText($response['json']);
            if (403 !== $response['status'] || $served !== $this->capMessage) {
                throw new RuntimeException(\sprintf('« %s » : attendu 403 « %s », reçu %d « %s »', $row['sortie'], $this->capMessage, $response['status'], $served));
            }
        }
    }

    #[Then('aucune version, aucun placement ni aucun nouveau PDF n\'est né des refus')]
    public function rienNEstNeDesRefus(): void
    {
        $schedules = $this->countOf('schedule');
        $runs = $this->countOf('match_placement_run');
        if ($this->schedulesBefore !== $schedules || $this->runsBefore !== $runs) {
            throw new RuntimeException(\sprintf('un refus ne fait naître ni version ni placement : versions %d → %d, placements %d → %d', $this->schedulesBefore, $schedules, $this->runsBefore, $runs));
        }
        $socle = $this->apiGet(\sprintf('schedules/%s', $this->socleId), $this->smallClubToken)['json'];
        if ('COMPLETED' !== ($socle['status'] ?? null) || !$this->smallClubSocleIsChosen($this->socleId)) {
            throw new RuntimeException(\sprintf('les solves refusés ne touchent pas le planning de saison : il reste COMPLETED et en vigueur — statut « %s »', \is_string($socle['status'] ?? null) ? $socle['status'] : ''));
        }
        if ('completed' !== ($socle['pdfExportStatus'] ?? null) || $this->pdfUrl !== ($socle['pdfExportUrl'] ?? null)) {
            throw new RuntimeException(\sprintf('l\'export PDF refusé ne relance aucun rendu : le PDF déjà produit reste servi — obtenu %s', $this->encoded(['status' => $socle['pdfExportStatus'] ?? null, 'url' => $socle['pdfExportUrl'] ?? null])));
        }
    }

    #[Then('je peux toujours consulter mes plannings et mes matchs, et télécharger le PDF déjà produit')]
    public function jePeuxToujoursConsulter(): void
    {
        foreach (['schedules', \sprintf('schedules/%s', $this->socleId), \sprintf('schedule_slot_templates?scheduleId=%s', $this->socleId), 'fixtures', 'me'] as $path) {
            $read = $this->apiGet($path, $this->smallClubToken);
            if (200 !== $read['status']) {
                throw new RuntimeException(\sprintf('consulter reste ouvert au-dessus du palier : %s a répondu %d', $path, $read['status']));
            }
        }
        if ($this->pdfBytes !== $this->download($this->pdfUrl)) {
            throw new RuntimeException('le PDF déjà produit devait se télécharger tel quel au-dessus du palier');
        }
    }

    #[When('/^un match est prévu pour l\'équipe (?P<team>\S+)$/u')]
    public function unMatchEstPrevu(string $team): void
    {
        $competition = $this->apiPost('competitions', ['teamId' => $this->smallClubTeamIds[$team], 'name' => 'Championnat', 'competitionType' => 'CHAMPIONSHIP'], $this->smallClubToken);
        $fixture = $this->apiPost('fixtures', [
            'teamId' => $this->smallClubTeamIds[$team],
            'matchDate' => date('Y-m-d', (int) strtotime('next saturday +14 days')),
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire du palier',
            'competitionId' => $this->smallClubStringField($competition['json'], 'id'),
        ], $this->smallClubToken);
        if (201 !== $fixture['status']) {
            throw new RuntimeException(\sprintf('le match de %s n\'a pas pu être prévu (HTTP %d) : %s', $team, $fixture['status'], $this->encoded($fixture['json'])));
        }
    }

    #[When('/^je tente de supprimer l\'équipe (?P<team>\S+)$/u')]
    public function jeTenteDeSupprimer(string $team): void
    {
        $this->lastResponse = $this->apiDelete(\sprintf('teams/%s', $this->smallClubTeamIds[$team]), $this->smallClubToken);
    }

    #[Then('/^la suppression est refusée \(409\) : « (?P<message>.+) »$/u')]
    public function laSuppressionEstRefusee(string $message): void
    {
        $served = $this->refusalText($this->lastResponse['json']);
        if (409 !== $this->lastResponse['status'] || $served !== $message) {
            throw new RuntimeException(\sprintf('suppression : attendu 409 « %s », reçu %d « %s »', $message, $this->lastResponse['status'], $served));
        }
    }

    #[When('je supprime une équipe qui n\'a pas de match')]
    public function jeSupprimeUneEquipeSansMatch(): void
    {
        $teamId = array_pop($this->addedTeamIds);
        if (null === $teamId) {
            throw new RuntimeException('aucune équipe ajoutée à supprimer');
        }
        $deleted = $this->apiDelete(\sprintf('teams/%s', $teamId), $this->smallClubToken);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('une équipe sans match se supprime : DELETE a répondu %d (204 attendu) — %s', $deleted['status'], $this->encoded($deleted['json'])));
        }
    }

    #[Then('/^aucune de ces sorties ne m\'est (?:plus )?refusée pour le palier :$/u')]
    public function aucuneDeCesSortiesNEstRefusee(TableNode $table): void
    {
        foreach ($table->getColumnsHash() as $row) {
            $response = $this->attempt($row['sortie']);
            // Le tableur est une sortie synchrone : servi = 200. Les autres peuvent buter sur une
            // autre règle (socle en vigueur, Récap) : jamais le refus du palier, jamais une panne.
            $refused = 'exporter le planning en tableur' === $row['sortie'] ? 200 !== $response['status'] : (403 === $response['status'] || $response['status'] >= 500);
            if ($refused) {
                throw new RuntimeException(\sprintf('« %s » ne devait pas être refusé : reçu %d « %s »', $row['sortie'], $response['status'], $this->refusalText($response['json'])));
            }
        }
    }

    #[When('je charge la version générée à 21 équipes')]
    public function jeChargeLaVersion(): void
    {
        $this->lastResponse = $this->apiPost(\sprintf('schedules/%s/regenerate-from', $this->versionId), [], $this->smallClubToken);
    }

    #[Then('le chargement est accepté et le club compte 21 équipes')]
    public function leChargementEstAccepte(): void
    {
        if (200 !== $this->lastResponse['status']) {
            throw new RuntimeException(\sprintf('charger une version reste ouvert : attendu 200, reçu %d — %s', $this->lastResponse['status'], $this->encoded($this->lastResponse['json'])));
        }
        $used = $this->entitlements()['teamsUsed'] ?? null;
        if (21 !== $used) {
            throw new RuntimeException(\sprintf('la version chargée rend ses 21 équipes : /api/me en compte %s', json_encode($used)));
        }
    }

    #[Then('charger cette version m\'est toujours ouvert')]
    public function chargerMEstToujoursOuvert(): void
    {
        $this->jeChargeLaVersion();
        if (200 !== $this->lastResponse['status']) {
            throw new RuntimeException(\sprintf('charger une version reste ouvert au-dessus du palier : attendu 200, reçu %d — %s', $this->lastResponse['status'], $this->encoded($this->lastResponse['json'])));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        foreach (array_filter([$this->socleId, $this->versionId, $this->draftId]) as $scheduleId) {
            foreach (glob(PdfGenerator::outputDir() . '/schedule-' . $scheduleId . '-*') ?: [] as $file) {
                @unlink($file);
            }
        }
        $this->teardownSmallClub();
    }

    /**
     * Joue une sortie par l'API réelle et rend son statut et son corps JSON (vide pour un fichier
     * servi). Le planning visé : le brouillon pour générer, le planning de saison (ou la version
     * générée) pour les autres.
     *
     * @return array{status: int, json: array<mixed>}
     */
    private function attempt(string $output): array
    {
        $target = '' !== $this->socleId ? $this->socleId : ('' !== $this->versionId ? $this->versionId : $this->draftId);
        $monday = date('Y-m-d', (int) strtotime('next monday'));

        return match ($output) {
            'générer le planning' => $this->send(\sprintf('schedules/%s/generate', '' !== $this->draftId ? $this->draftId : $target), []),
            'régénérer le planning' => $this->send(\sprintf('schedules/%s/regenerate', $target), []),
            'combler le planning' => $this->send(\sprintf('schedules/%s/fill', $target), []),
            'placer les matchs' => $this->send('fixtures/place', []),
            'exporter le planning en tableur' => $this->send(\sprintf('schedules/%s/export-xlsx', $target), []),
            'exporter le planning en PDF' => $this->send(\sprintf('schedules/%s/export-pdf', $target), []),
            'exporter les matchs en PDF' => $this->send('fixtures/export-pdf', ['from' => $monday, 'to' => date('Y-m-d', (int) strtotime($monday . ' +6 days')), 'teamId' => null]),
            default => throw new RuntimeException(\sprintf('sortie inconnue : « %s »', $output)),
        };
    }

    /**
     * POST brut : une sortie servie rend un fichier (tableur, PDF), que le décodage JSON de
     * `BaseContext::apiPost` refuserait.
     *
     * @param array<mixed> $body
     *
     * @return array{status: int, json: array<mixed>}
     */
    private function send(string $path, array $body): array
    {
        $response = $this->client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken, 'Accept' => 'application/ld+json'],
            'json' => [] === $body ? (object) [] : $body,
            'timeout' => 60,
        ]);
        $status = $response->getStatusCode();
        $type = $response->getHeaders(false)['content-type'][0] ?? '';
        $json = str_contains($type, 'json') ? json_decode($response->getContent(false), true) : null;

        return ['status' => $status, 'json' => \is_array($json) ? $json : []];
    }

    /** @param array<mixed> $json */
    private function refusalText(array $json): string
    {
        foreach (['detail', 'hydra:description', 'description', 'error'] as $key) {
            if (\is_string($json[$key] ?? null)) {
                return $json[$key];
            }
        }

        return '';
    }

    private function assertWarned(string $message): void
    {
        $entitlements = $this->entitlements();
        if ($message !== ($entitlements['teamCapMessage'] ?? null)) {
            throw new RuntimeException(\sprintf('/api/me devait servir le message du dépassement « %s » : %s', $message, $this->encoded($entitlements)));
        }
    }

    private function countOf(string $table): int
    {
        return (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM %s WHERE club_id=\'%s\'', $table, $this->smallClubId), admin: true);
    }

    private function pollUntilPdfCompleted(string $scheduleId): string
    {
        $deadline = time() + 90;
        $status = '';
        do {
            $json = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->smallClubToken)['json'];
            $status = \is_string($json['pdfExportStatus'] ?? null) ? $json['pdfExportStatus'] : '';
            if ('completed' === $status && \is_string($json['pdfExportUrl'] ?? null)) {
                return $json['pdfExportUrl'];
            }
            if ('failed' === $status) {
                throw new RuntimeException('le PDF du décor a échoué');
            }
            sleep(1);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('le PDF du décor n\'est pas prêt dans le délai (dernier statut « %s »)', $status));
    }

    private function download(string $url): string
    {
        $apiBase = rtrim((string) (getenv('BEHAT_API_BASE') ?: 'http://nginx/api'), '/');
        $origin = (string) preg_replace('#/api$#', '', $apiBase);
        $response = $this->client->request('GET', $origin . $url, ['headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken]]);
        $bytes = $response->getContent(false);
        if (200 !== $response->getStatusCode() || !str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException(\sprintf('le PDF ne se télécharge pas (HTTP %d) à %s', $response->getStatusCode(), $url));
        }

        return $bytes;
    }

    /** @return array<mixed> */
    private function entitlements(): array
    {
        $me = $this->apiGet('me', $this->smallClubToken);
        $club = $me['json']['club'] ?? null;
        $entitlements = \is_array($club) ? ($club['entitlements'] ?? null) : null;
        if (200 !== $me['status'] || !\is_array($entitlements)) {
            throw new RuntimeException(\sprintf('/api/me (HTTP %d) ne sert pas les droits de l\'offre du club', $me['status']));
        }

        return $entitlements;
    }

    /** @param array<mixed> $value */
    private function encoded(array $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
