<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Une version en vigueur s'exporte en PDF ; rien ne s'exporte sans session (axe auth &
 * memberships, rail export réel : Messenger → worker Puppeteer → fichier servi par nginx), sur la
 * stack qui tourne.
 *
 * Deux promesses, en HTTP contre la vraie chaîne :
 *   - le gestionnaire demande l'export PDF de sa version de saison en vigueur → 202, le worker
 *     rend le document (statut `completed`, URL renseignée), et le fichier servi est un vrai PDF
 *     non vide (entête « %PDF », type application/pdf) ;
 *   - la même demande SANS session (aucun Bearer) est refusée (401) : l'export ne fuit pas.
 *
 * Décor : le club de démonstration et sa version de saison COMPLETED en vigueur. L'export écrit
 * l'état d'export SUR cette version ; on le restaure dans son état d'entrée en fin — quoi qu'il
 * arrive. Le fichier rendu porte l'identifiant de la version dans son nom : un nouvel export
 * l'écrase, il ne s'accumule pas (la commande de purge des exports le balaie par ailleurs).
 */
final class ExportContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private const int POLL_INTERVAL_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 120;

    private string $token = '';

    private string $clubId = '';

    private string $scheduleId = '';

    private string $statusAtEntry = '';

    private string $urlAtEntry = '';

    private string $pdfUrl = '';

    private int $unauthStatus = 0;

    private string $fixtureId = '';

    private string $matchDate = '';

    private int $matchesStatus = 0;

    private string $matchesContentType = '';

    private string $matchesBytes = '';

    #[Given('le club de démonstration et son gestionnaire connecté')]
    public function leClubEtSonGestionnaireConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // La version de saison EN VIGUEUR : celle que le socle pointe, sinon la COMPLETED la plus
        // fraîche — celle qu'on exporte pour l'afficher au gymnase.
        $this->scheduleId = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL LIMIT 1', $this->clubId),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->scheduleId)) {
            $this->scheduleId = $this->dbalScalar(
                \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
                admin: true,
            );
        }
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->scheduleId)) {
            throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
        }

        // Photographie de l'état d'export pour le restaurer en fin (l'export le mute).
        $this->statusAtEntry = $this->dbalScalar(
            \sprintf('SELECT COALESCE(pdf_export_status, \'\') AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );
        $this->urlAtEntry = $this->dbalScalar(
            \sprintf('SELECT COALESCE(pdf_export_url, \'\') AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );
    }

    #[When('je demande l\'export PDF du planning en vigueur')]
    public function jeDemandeLexport(): void
    {
        $launched = $this->apiPost(\sprintf('schedules/%s/export-pdf', $this->scheduleId), [], $this->token);
        if (202 !== $launched['status']) {
            throw new RuntimeException(\sprintf('la demande d\'export a répondu %d (202 attendu)', $launched['status']));
        }

        $this->pdfUrl = $this->pollUntilExported($this->scheduleId);
    }

    #[When('je demande l\'export du planning en vigueur sans session')]
    public function jeDemandeLexportSansSession(): void
    {
        // Aucun Bearer : le chemin non authentifié. On ne « répare » jamais l'appel avec un jeton.
        $refused = $this->publicPost(\sprintf('schedules/%s/export-pdf', $this->scheduleId), []);
        $this->unauthStatus = $refused['status'];
    }

    #[Then('je reçois un fichier PDF non vide')]
    public function jeRecoisUnPdfNonVide(): void
    {
        // P4-357 — le rendu se télécharge par la route AUTHENTIFIÉE renvoyée dans `pdfExportUrl`
        // (`/api/schedules/{id}/export-pdf/{filename}`), plus par une URL statique publique. On
        // porte donc le MÊME Bearer que le déclenchement : c'est le geste réel du gestionnaire.
        $absoluteUrl = $this->renderOrigin() . $this->pdfUrl;

        $response = $this->client->request('GET', $absoluteUrl, [
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
        ]);
        $status = $response->getStatusCode();
        if (200 !== $status) {
            throw new RuntimeException(\sprintf('le fichier exporté n\'est pas servi (HTTP %d) à %s', $status, $absoluteUrl));
        }

        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        if (!str_contains($contentType, 'application/pdf')) {
            throw new RuntimeException(\sprintf('le fichier exporté n\'est pas servi comme un PDF (type « %s »)', $contentType));
        }

        $bytes = $response->getContent(false);
        if ('' === $bytes) {
            throw new RuntimeException('le fichier PDF exporté est vide');
        }
        if (!str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException('le fichier exporté ne porte pas l\'entête d\'un PDF (« %PDF »)');
        }
    }

    #[Then('l\'ancienne adresse statique du rendu ne répond plus')]
    public function lAncienneAdresseStatiqueNeRepondPlus(): void
    {
        // Même nom de fichier, ancienne adresse statique publique `/exports/<nom>` : l'edge la
        // renvoie désormais en 404 (le rendu ne fuit plus hors du firewall JWT).
        $legacyUrl = $this->renderOrigin() . '/exports/' . basename($this->pdfUrl);

        $status = $this->client->request('GET', $legacyUrl)->getStatusCode();
        if (404 !== $status) {
            throw new RuntimeException(\sprintf('l\'ancienne adresse statique aurait dû répondre 404, obtenu %d à %s', $status, $legacyUrl));
        }
    }

    #[Then('le rendu n\'est pas servi sans session')]
    public function leRenduNEstPasServiSansSession(): void
    {
        // La route authentifiée, SANS Bearer : le firewall JWT refuse, rien n'est servi.
        $absoluteUrl = $this->renderOrigin() . $this->pdfUrl;

        $status = $this->client->request('GET', $absoluteUrl)->getStatusCode();
        if (!\in_array($status, [401, 403], true)) {
            throw new RuntimeException(\sprintf('le rendu sans session aurait dû être refusé (401/403), obtenu %d à %s', $status, $absoluteUrl));
        }
    }

    #[Then('la demande est refusée faute de session')]
    public function laDemandeEstRefusee(): void
    {
        if (!\in_array($this->unauthStatus, [401, 403], true)) {
            throw new RuntimeException(\sprintf('l\'export sans session aurait dû être refusé (401/403), obtenu %d', $this->unauthStatus));
        }
    }

    #[Given('un match amical de mon club dans la plage exportée')]
    public function unMatchAmicalDansLaPlage(): void
    {
        // Une équipe de la saison en cours et une date de cette saison : le scénario crée ce qu'il vérifie.
        $row = $this->dbalScalar(
            \sprintf('SELECT t.id || \'|\' || LEAST(GREATEST(CURRENT_DATE + 7, s.start_date), s.end_date) AS behatval FROM team t JOIN season s ON s.id=t.season_id WHERE t.club_id=\'%s\' AND CURRENT_DATE BETWEEN s.start_date AND s.end_date LIMIT 1', $this->clubId),
            admin: true,
        );
        [$teamId, $date] = array_pad(explode('|', $row), 2, '');
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $teamId) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('aucune équipe dans la saison en cours — la base est-elle seedée ?');
        }
        $this->matchDate = $date;

        $created = $this->apiPost('fixtures', ['teamId' => $teamId, 'matchDate' => $date, 'homeAway' => 'AWAY', 'opponentLabel' => 'Export Parents Jetable'], $this->token);
        $fixtureId = $created['json']['id'] ?? null;
        if (!\is_string($fixtureId) || '' === $fixtureId) {
            throw new RuntimeException(\sprintf('la création du match a répondu %d', $created['status']));
        }
        $this->fixtureId = $fixtureId;
    }

    #[When('j\'exporte les matchs de cette plage')]
    public function jExporteLesMatchs(): void
    {
        $response = $this->client->request('POST', $this->renderOrigin() . '/api/fixtures/export-pdf', [
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
            'json' => ['from' => $this->matchDate, 'to' => $this->matchDate, 'teamId' => null],
            // Le rendu Puppeteer est synchrone sur ce rail : le délai du client couvre les 30 s du worker.
            'timeout' => 60,
        ]);
        $this->matchesStatus = $response->getStatusCode();
        $this->matchesContentType = $response->getHeaders(false)['content-type'][0] ?? '';
        $this->matchesBytes = $response->getContent(false);
    }

    #[Then('je repars avec le PDF des matchs')]
    public function jeReparsAvecLePdfDesMatchs(): void
    {
        if (200 !== $this->matchesStatus) {
            throw new RuntimeException(\sprintf('l\'export des matchs a répondu %d (200 attendu)', $this->matchesStatus));
        }
        if (!str_contains($this->matchesContentType, 'application/pdf')) {
            throw new RuntimeException(\sprintf('l\'export des matchs n\'est pas servi comme un PDF (type « %s »)', $this->matchesContentType));
        }
        if (!str_starts_with($this->matchesBytes, '%PDF')) {
            throw new RuntimeException('l\'export des matchs ne porte pas l\'entête d\'un PDF (« %PDF »)');
        }
    }

    /** Retire le match créé par le scénario — quoi qu'il arrive. */
    #[AfterScenario]
    public function retirerLeMatchJetable(): void
    {
        if ('' === $this->fixtureId) {
            return;
        }
        $this->apiDelete(\sprintf('fixtures/%s', $this->fixtureId), $this->token);
        $this->fixtureId = '';
    }

    /**
     * Restaure l'état d'export de la version dans son état d'entrée — quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token || '' === $this->scheduleId) {
            return;
        }

        $status = '' === $this->statusAtEntry ? 'NULL' : \sprintf('\'%s\'', $this->statusAtEntry);
        $url = '' === $this->urlAtEntry ? 'NULL' : \sprintf('\'%s\'', $this->urlAtEntry);
        $this->dbalExec(
            \sprintf('UPDATE schedule SET pdf_export_status=%s, pdf_export_url=%s WHERE id=\'%s\'', $status, $url, $this->scheduleId),
            admin: true,
        );
    }

    /** L'origine HTTP (hors préfixe /api) à laquelle la route de téléchargement est servie. */
    private function renderOrigin(): string
    {
        $apiBase = rtrim((string) (getenv('BEHAT_API_BASE') ?: 'http://nginx/api'), '/');

        return (string) preg_replace('#/api$#', '', $apiBase);
    }

    private function pollUntilExported(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $status = '';

        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture du planning en échec (HTTP %d)', $response['status']));
            }

            $status = $response['json']['pdfExportStatus'] ?? '';
            if ('completed' === $status) {
                $url = $response['json']['pdfExportUrl'] ?? '';
                if (!\is_string($url) || '' === $url) {
                    throw new RuntimeException('l\'export est marqué terminé mais sans URL de fichier');
                }

                return $url;
            }
            if ('failed' === $status) {
                throw new RuntimeException('le worker d\'export a échoué (statut « failed »)');
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('l\'export n\'a pas abouti dans le délai imparti (dernier statut « %s »)', \is_string($status) ? $status : 'inconnu'));
    }
}
