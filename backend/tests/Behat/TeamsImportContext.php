<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * L'import d'équipes FFBB « choisit ses lignes » (P3-7), de bout en bout sur la
 * stack qui tourne : le gestionnaire analyse son fichier (dry-run — quelles
 * équipes, lesquelles déjà présentes), puis n'importe que les lignes cochées ;
 * un fichier d'un AUTRE club est refusé dès l'analyse, sans rien créer.
 *
 * On possède des équipes jetables préfixées « BEHAT » (jamais dans le seed BCCL)
 * et on les efface en fin de scénario.
 */
final class TeamsImportContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string TEAM_A = 'BEHAT SF1';

    private const string TEAM_B = 'BEHAT SM2';

    private const string TEAM_C = 'BEHAT U13';

    private const string FOREIGN_CODE = 'ZZZ0000001';

    private string $token = '';

    private string $clubId = '';

    private string $ffbbCode = '';

    private string $seasonId = '';

    private string $categoryId = '';

    private string $categoryName = '';

    /** @var array<mixed> */
    private array $analyzeJson = [];

    private int $analyzeStatus = 0;

    #[Given('le club de démonstration, connecté, avec son code FFBB')]
    public function leClubConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        $this->ffbbCode = $this->dbalScalar(
            \sprintf('SELECT ffbb_club_code AS behatval FROM club WHERE id=\'%s\'', $this->clubId),
            admin: true,
        );
        if ('' === $this->ffbbCode) {
            throw new RuntimeException('le club de démonstration n\'a pas de code FFBB — l\'import d\'équipes ne peut pas être testé');
        }

        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\' ORDER BY start_date DESC LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $this->seasonId) {
            throw new RuntimeException('aucune saison pour le club de démonstration');
        }

        // Une catégorie EXISTANTE du club : la réutiliser évite que l'import crée une
        // catégorie orpheline à nettoyer (findOrCreateSportCategory la retrouve).
        $categories = $this->members($this->apiGet('sport_categories', $this->token)['json']);
        $categoryId = $categories[0]['id'] ?? null;
        $categoryName = $categories[0]['name'] ?? null;
        if (!\is_string($categoryId) || '' === $categoryId || !\is_string($categoryName) || '' === $categoryName) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir le fichier d\'import');
        }
        $this->categoryId = $categoryId;
        $this->categoryName = $categoryName;

        // Départ propre : aucune équipe jetable résiduelle d'un run interrompu.
        $this->purgeDisposableTeams();
    }

    #[Given('une équipe jetable « BEHAT SF1 » déjà présente')]
    public function uneEquipeDejaPresente(): void
    {
        $created = $this->apiPost('teams', [
            'name' => self::TEAM_A,
            'sportCategoryId' => $this->categoryId,
            'priorityTierId' => 1,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de l\'équipe déjà présente refusée (HTTP %d)', $created['status']));
        }
    }

    #[When('j\'analyse un fichier de trois équipes dont « BEHAT SF1 »')]
    public function jAnalyseTroisEquipes(): void
    {
        $path = $this->writeTeamsXlsx($this->ffbbCode);
        [$this->analyzeStatus, $this->analyzeJson] = $this->multipart('import-teams/analyze', $path, ['seasonId' => $this->seasonId]);
        @unlink($path);
        if (200 !== $this->analyzeStatus) {
            throw new RuntimeException(\sprintf('l\'analyse a répondu %d (200 attendu)', $this->analyzeStatus));
        }
    }

    #[Then('l\'analyse liste les trois équipes')]
    public function lAnalyseListeLesTrois(): void
    {
        $rows = $this->analyzeJson['rows'] ?? [];
        if (!\is_array($rows) || 3 !== \count($rows)) {
            throw new RuntimeException(\sprintf('trois équipes attendues, %d listée(s)', \is_array($rows) ? \count($rows) : 0));
        }
        if (3 !== ($this->analyzeJson['total'] ?? null)) {
            throw new RuntimeException('le total lu devrait être 3');
        }
    }

    #[Then('« BEHAT SF1 » est marquée déjà présente')]
    public function sf1EstDejaPresente(): void
    {
        if (true !== $this->analyzedRow(self::TEAM_A)['alreadyPresent']) {
            throw new RuntimeException('« BEHAT SF1 » existe déjà en base : l\'analyse devrait la marquer déjà présente');
        }
    }

    #[Then('« BEHAT SM2 » et « BEHAT U13 » ne sont pas marquées déjà présentes')]
    public function sm2EtU13NeSontPasPresentes(): void
    {
        foreach ([self::TEAM_B, self::TEAM_C] as $name) {
            if (false !== $this->analyzedRow($name)['alreadyPresent']) {
                throw new RuntimeException(\sprintf('« %s » est neuve : elle ne devrait PAS être marquée déjà présente', $name));
            }
        }
    }

    #[When('j\'importe le fichier en ne cochant que « BEHAT SM2 » et « BEHAT U13 »')]
    public function jImporteEnCochantDeux(): void
    {
        // Ordre du fichier : en-tête = ligne 1, BEHAT SF1 = 2, BEHAT SM2 = 3, BEHAT U13 = 4.
        // On ne coche que les lignes 3 et 4 → BEHAT SF1 (ligne 2) ne doit PAS entrer.
        $path = $this->writeTeamsXlsx($this->ffbbCode);
        [$status, $json] = $this->multipart('import-teams', $path, [
            'seasonId' => $this->seasonId,
            'rows' => json_encode([3, 4], \JSON_THROW_ON_ERROR),
        ]);
        @unlink($path);
        if (200 !== $status) {
            throw new RuntimeException(\sprintf('l\'import a répondu %d (200 attendu)', $status));
        }
        if (2 !== ($json['created'] ?? null)) {
            throw new RuntimeException(\sprintf('deux équipes attendues, created=%s', var_export($json['created'] ?? null, true)));
        }
    }

    #[Then('« BEHAT SM2 » et « BEHAT U13 » sont créées')]
    public function sm2EtU13SontCreees(): void
    {
        foreach ([self::TEAM_B, self::TEAM_C] as $name) {
            if ($this->teamCount($name) < 1) {
                throw new RuntimeException(\sprintf('« %s » cochée devrait exister en base', $name));
            }
        }
    }

    #[Then('« BEHAT SF1 » est absente de la base')]
    public function sf1EstAbsente(): void
    {
        if (0 !== $this->teamCount(self::TEAM_A)) {
            throw new RuntimeException('« BEHAT SF1 » n\'était pas cochée : elle ne doit PAS avoir été créée');
        }
    }

    #[When('j\'analyse un fichier d\'équipes portant le code d\'un autre club')]
    public function jAnalyseUnAutreClub(): void
    {
        $path = $this->writeTeamsXlsx(self::FOREIGN_CODE);
        [$this->analyzeStatus, $this->analyzeJson] = $this->multipart('import-teams/analyze', $path, ['seasonId' => $this->seasonId]);
        @unlink($path);
    }

    #[Then('l\'analyse est refusée')]
    public function lAnalyseEstRefusee(): void
    {
        if (422 !== $this->analyzeStatus) {
            throw new RuntimeException(\sprintf('un fichier d\'un autre club doit être refusé en 422 (reçu %d)', $this->analyzeStatus));
        }
    }

    #[Then('aucune équipe jetable n\'a été créée')]
    public function aucuneEquipeCreee(): void
    {
        foreach ([self::TEAM_A, self::TEAM_B, self::TEAM_C] as $name) {
            if (0 !== $this->teamCount($name)) {
                throw new RuntimeException(\sprintf('l\'analyse est un dry-run : « %s » ne doit pas exister', $name));
            }
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->clubId) {
            return;
        }
        $this->purgeDisposableTeams();
    }

    private function purgeDisposableTeams(): void
    {
        $names = implode(', ', array_map(
            static fn (string $n): string => \sprintf('\'%s\'', $n),
            [self::TEAM_A, self::TEAM_B, self::TEAM_C],
        ));
        $this->dbalExec(
            \sprintf('DELETE FROM team WHERE club_id=\'%s\' AND name IN (%s)', $this->clubId, $names),
            admin: true,
        );
    }

    private function teamCount(string $name): int
    {
        return (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM team WHERE club_id=\'%s\' AND name=\'%s\'', $this->clubId, $name),
            admin: true,
        );
    }

    /**
     * La ligne analysée d'un nom donné, sinon une erreur nommée.
     *
     * @return array{row: int, name: string, category: string, number: string, alreadyPresent: bool}
     */
    private function analyzedRow(string $name): array
    {
        $rows = $this->analyzeJson['rows'] ?? [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && ($row['name'] ?? null) === $name) {
                /* @var array{row: int, name: string, category: string, number: string, alreadyPresent: bool} $row */
                return $row;
            }
        }

        throw new RuntimeException(\sprintf('la ligne « %s » est absente de l\'analyse', $name));
    }

    /**
     * Dépose un fichier .xlsx multipart (file + champs) sur un endpoint d'import
     * d'équipes du club, comme le ferait le navigateur.
     *
     * @param array<string, string> $fields
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function multipart(string $endpoint, string $path, array $fields): array
    {
        $parts = ['file' => DataPart::fromPath($path)] + $fields;
        $form = new FormDataPart($parts);

        $response = $this->client->request('POST', \sprintf('clubs/%s/%s', $this->clubId, $endpoint), [
            'headers' => array_merge(['Authorization' => 'Bearer ' . $this->token], $form->getPreparedHeaders()->toArray()),
            'body' => $form->bodyToIterable(),
        ]);
        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $json = '' === $raw ? [] : json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return [$status, \is_array($json) ? $json : []];
    }

    private function writeTeamsXlsx(string $clubCode): string
    {
        $org = \sprintf('%s - CLUB BEHAT', $clubCode);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
            [self::TEAM_A, $this->categoryName, '1', $org],
            [self::TEAM_B, $this->categoryName, '2', $org],
            [self::TEAM_C, $this->categoryName, '3', $org],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'behat-teams') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);

        return $path;
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
