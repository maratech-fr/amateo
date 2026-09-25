<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Exception\ImportRejectedException;
use App\Service\PlanEntitlements;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class FfbbExcelImporter
{
    private const DEFAULT_PRIORITY_TIER_ID = 5;
    private const DEFAULT_SESSIONS_PER_WEEK = 2;

    private ?int $nextSortOrderValue = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanEntitlements $planEntitlements,
    ) {}

    /**
     * @param list<int>|null $selectedRows numéros de ligne Excel à importer (P3-7) ;
     *                                     null = tout importer (comportement historique)
     *
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function import(string $filePath, string $clubId, string $seasonId, ?array $selectedRows = null): array
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            throw ImportRejectedException::badRequest('Club not found.');
        }

        $expectedClubCode = $club->getFfbbClubCode();
        if (null === $expectedClubCode || '' === $expectedClubCode) {
            throw ImportRejectedException::badRequest('Club does not have an FFBB club code configured.');
        }

        // Lecteur épinglé à Xlsx (pas d'auto-détection) : le garde d'upload ne
        // valide que nom/mime/taille, donc un HTML/CSV/XML déguisé en .xlsx ne doit
        // jamais atteindre un autre lecteur (parité avec l'import de rencontres,
        // FbiFixtureImporter — défense en profondeur, security-review PR-4).
        $spreadsheet = IOFactory::load($filePath, 0, [IOFactory::READER_XLSX]);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if ([] === $rows || $this->isEmptyRow($rows[0] ?? [])) {
            return ['created' => 0, 'skipped' => 0, 'errors' => ['Le fichier est vide.']];
        }

        $header = array_shift($rows);
        $columnMap = $this->buildColumnMap($header);

        if (!isset($columnMap['nom'], $columnMap['catégorie'], $columnMap['numéro'], $columnMap['organisme'])) {
            throw ImportRejectedException::badRequest('Colonnes requises manquantes : Nom, Catégorie, Numéro, Organisme.');
        }

        // P3-7 — la sélection est un ensemble de numéros de ligne Excel (rowIndex + 2).
        // Une ligne non cochée est ignorée AVANT tout contrôle : ni comptée, ni soumise
        // au code club (un code étranger sur une ligne non cochée ne fait rien).
        $selectedSet = null === $selectedRows ? null : array_fill_keys($selectedRows, true);

        $sport = $this->findDefaultSport();
        if (!$sport instanceof Sport) {
            // PAS un refus métier : l'absence du sport par défaut est une installation
            // cassée, sur laquelle le gestionnaire ne peut rien. Exception NUE, donc
            // branche générique du contrôleur → message neutre ET `logger->error`.
            // La classer en `ImportRejectedException` l'aurait affichée telle quelle
            // au club tout en la privant de journalisation.
            throw new RuntimeException('No default sport found for category creation.');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        /** @var list<Team> $toCreate équipes NEUVES retenues — persistées seulement après le contrôle de cap (tout-ou-rien). */
        $toCreate = [];

        // P4-35 — l'identité d'une équipe est (club, saison, NOM). L'ancien test
        // `findOneBy([clubId, seasonId])` demandait « cette saison a-t-elle UNE
        // équipe ? » : saison vierge + 4 lignes → created=2/skipped=2 selon l'ordre
        // du fichier (mesuré). On précharge les noms existants UNE fois et on suit
        // ceux du lot en cours — le flush étant unique et final, le repository ne
        // voit pas les équipes de ce lot.
        $knownNames = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $knownNames[mb_strtolower($team->getName(), 'UTF-8')] = true;
        }

        foreach ($rows as $rowIndex => $row) {
            if (null !== $selectedSet && !isset($selectedSet[$rowIndex + 2])) {
                continue;
            }

            /** @var array<mixed> $row */
            $nom = $this->stringValue($row[$columnMap['nom']] ?? null);
            $categorie = $this->stringValue($row[$columnMap['catégorie']] ?? null);
            $numero = $this->stringValue($row[$columnMap['numéro']] ?? null);
            $organisme = $this->stringValue($row[$columnMap['organisme']] ?? null);

            if (\in_array('', [$nom, $categorie, $numero, $organisme], true)) {
                continue;
            }

            $extractedClubCode = $this->extractClubCode($organisme);
            if (null === $extractedClubCode) {
                $errors[] = \sprintf('Ligne %d : impossible de lire le code club dans la colonne Organisme.', $rowIndex + 2);
                continue;
            }

            if ($extractedClubCode !== $expectedClubCode) {
                // Règle métier relayée telle quelle : les deux codes viennent du club
                // appelant et du fichier qu'il vient lui-même de déposer — rien d'un tiers.
                throw ImportRejectedException::unprocessable(\sprintf('Ce fichier appartient à un autre club (code %s, le vôtre est %s).', $extractedClubCode, $expectedClubCode));
            }

            $nameKey = mb_strtolower($nom, 'UTF-8');
            if (isset($knownNames[$nameKey])) {
                ++$skipped;
                continue;
            }
            $knownNames[$nameKey] = true;

            $sportCategory = $this->findOrCreateSportCategory($categorie, $clubId, $sport->getId());

            $team = (new Team)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSportCategoryId($sportCategory->getId())
                ->setPriorityTierId(self::DEFAULT_PRIORITY_TIER_ID)
                ->setName($nom)
                ->setSessionsPerWeek(self::DEFAULT_SESSIONS_PER_WEEK)
                ->setIsActive(true);

            $toCreate[] = $team;
            ++$created;
        }

        // P1-3 — cap payant : refus tout-ou-rien AVANT le premier persist, avec décompte.
        // Découverte/Bêta/démo (cap null via PlanEntitlements) importent tout. `remainingTeamSlots`
        // = cap − équipes déjà en base ; les doublons sautés ci-dessus n'y comptent pas.
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
        if ($season instanceof Season) {
            $remaining = $this->planEntitlements->remainingTeamSlots($club, $season);
            if (null !== $remaining && \count($toCreate) > $remaining) {
                throw ImportRejectedException::unprocessable(\sprintf('%d équipe(s) à créer dans le fichier, %d place(s) restante(s) sur votre offre. Retirez des lignes ou passez à l\'offre supérieure.', \count($toCreate), $remaining));
            }
        }

        foreach ($toCreate as $team) {
            $this->entityManager->persist($team);
        }

        // Flush UNIQUE et FINAL (P4-35) : l'import est tout-ou-rien. L'ancien
        // `flush()` au fil des catégories laissait des écritures partielles quand
        // une ligne ultérieure jetait (code club étranger → 422 avec la moitié du
        // fichier déjà en base). Il couvre aussi les catégories créées sans équipe.
        $this->entityManager->flush();

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * P3-7 — Dry-run : lit le fichier et rend, pour CHAQUE ligne d'équipe, si son
     * nom est DÉJÀ présent (club + saison, casse-insensible) ou déjà vu plus haut
     * dans le fichier — sans RIEN écrire. Le gestionnaire choisit alors les lignes
     * à importer et évite les doublons. Mêmes refus métier que `import()` : code
     * club étranger → 422 ; colonnes manquantes → 400 ; fichier vide → erreur.
     *
     * @return array{
     *     rows: list<array{row: int, name: string, category: string, number: string, alreadyPresent: bool}>,
     *     errors: list<string>,
     *     total: int
     * }
     */
    public function analyze(string $filePath, string $clubId, string $seasonId): array
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            throw ImportRejectedException::badRequest('Club not found.');
        }

        $expectedClubCode = $club->getFfbbClubCode();
        if (null === $expectedClubCode || '' === $expectedClubCode) {
            throw ImportRejectedException::badRequest('Club does not have an FFBB club code configured.');
        }

        // Lecteur épinglé à Xlsx (comme import()) : un HTML/CSV/XML déguisé ne doit
        // jamais atteindre un autre lecteur (défense en profondeur, security-review PR-4).
        $spreadsheet = IOFactory::load($filePath, 0, [IOFactory::READER_XLSX]);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if ([] === $rows || $this->isEmptyRow($rows[0] ?? [])) {
            return ['rows' => [], 'errors' => ['Le fichier est vide.'], 'total' => 0];
        }

        $header = array_shift($rows);
        $columnMap = $this->buildColumnMap($header);

        if (!isset($columnMap['nom'], $columnMap['catégorie'], $columnMap['numéro'], $columnMap['organisme'])) {
            throw ImportRejectedException::badRequest('Colonnes requises manquantes : Nom, Catégorie, Numéro, Organisme.');
        }

        // Dédup par NOM : l'identité d'une équipe est (club, saison, nom). On précharge
        // les noms existants, puis on marque `alreadyPresent` aussi pour un nom déjà vu
        // plus haut dans LE FICHIER (même sémantique que le `knownNames` de import()).
        $knownNames = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $knownNames[mb_strtolower($team->getName(), 'UTF-8')] = true;
        }

        $out = [];
        $errors = [];
        $total = 0;

        foreach ($rows as $rowIndex => $row) {
            /** @var array<mixed> $row */
            $nom = $this->stringValue($row[$columnMap['nom']] ?? null);
            $categorie = $this->stringValue($row[$columnMap['catégorie']] ?? null);
            $numero = $this->stringValue($row[$columnMap['numéro']] ?? null);
            $organisme = $this->stringValue($row[$columnMap['organisme']] ?? null);

            if (\in_array('', [$nom, $categorie, $numero, $organisme], true)) {
                continue;
            }
            ++$total;
            $excelRow = $rowIndex + 2;

            $extractedClubCode = $this->extractClubCode($organisme);
            if (null === $extractedClubCode) {
                $errors[] = \sprintf('Ligne %d : impossible de lire le code club dans la colonne Organisme.', $excelRow);
                continue;
            }

            if ($extractedClubCode !== $expectedClubCode) {
                throw ImportRejectedException::unprocessable(\sprintf('Ce fichier appartient à un autre club (code %s, le vôtre est %s).', $extractedClubCode, $expectedClubCode));
            }

            $nameKey = mb_strtolower($nom, 'UTF-8');
            $alreadyPresent = isset($knownNames[$nameKey]);
            $knownNames[$nameKey] = true;

            $out[] = [
                'row' => $excelRow,
                'name' => $nom,
                'category' => $categorie,
                'number' => $numero,
                'alreadyPresent' => $alreadyPresent,
            ];
        }

        return ['rows' => $out, 'errors' => $errors, 'total' => $total];
    }

    /**
     * @param array<mixed> $header
     *
     * @return array<string, int>
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $value) {
            $value = $this->stringValue($value);
            if ('' === $value) {
                continue;
            }
            $key = mb_strtolower($value, 'UTF-8');
            $map[$key] = $index;
        }

        return $map;
    }

    private function stringValue(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function extractClubCode(string $organisme): ?string
    {
        $parts = explode(' - ', $organisme, 2);
        $code = trim($parts[0]);

        return '' !== $code ? $code : null;
    }

    private function findDefaultSport(): ?Sport
    {
        return $this->entityManager->getRepository(Sport::class)->findOneBy(['isActive' => true]);
    }

    private function findOrCreateSportCategory(string $name, string $clubId, string $sportId): SportCategory
    {
        $repository = $this->entityManager->getRepository(SportCategory::class);

        $category = $repository->findOneBy([
            'name' => $name,
            'clubId' => $clubId,
            'sportId' => $sportId,
        ]);

        if ($category instanceof SportCategory) {
            return $category;
        }

        $category = $repository->findOneBy([
            'name' => $name,
            'clubId' => null,
            'sportId' => $sportId,
        ]);

        if ($category instanceof SportCategory) {
            return $category;
        }

        $category = (new SportCategory)
            ->setName($name)
            ->setClubId($clubId)
            ->setSportId($sportId)
            ->setIsCustom(true)
            // Comme SportCategoryStateProcessor::nextSortOrder : une catégorie créée se
            // range APRÈS le catalogue (0 = la place de « Vétéran », elle sautait en tête
            // de tous les sélecteurs). Pas de flush ici — l'import est tout-ou-rien, et
            // l'UUID naît au constructeur (P4-35).
            ->setSortOrder($this->nextSortOrder());

        $this->entityManager->persist($category);

        return $category;
    }

    /**
     * Le rang d'affichage suivant (même règle que SportCategoryStateProcessor).
     * Mémorisé puis incrémenté : le flush étant final, MAX(sortOrder) ne voit pas
     * les catégories du lot en cours — sans le compteur elles seraient ex æquo.
     */
    private function nextSortOrder(): int
    {
        if (null === $this->nextSortOrderValue) {
            $max = $this->entityManager->createQueryBuilder()
                ->select('MAX(c.sortOrder)')
                ->from(SportCategory::class, 'c')
                ->getQuery()
                ->getSingleScalarResult();
            $this->nextSortOrderValue = null === $max ? 0 : ((int) $max) + 1;
        }

        return $this->nextSortOrderValue++;
    }

    /** @param array<mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (null !== $cell && '' !== $cell) {
                return false;
            }
        }

        return true;
    }
}
