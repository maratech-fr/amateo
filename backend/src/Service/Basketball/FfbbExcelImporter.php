<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\Team;
use App\Enum\Gender;
use App\Exception\ImportRejectedException;
use App\Service\PlanEntitlements;
use App\Service\SportCategoryResolver;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class FfbbExcelImporter
{
    private const DEFAULT_PRIORITY_TIER_ID = 5;
    private const DEFAULT_SESSIONS_PER_WEEK = 2;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanEntitlements $planEntitlements,
        private readonly SportCategoryResolver $sportCategoryResolver,
    ) {}

    /**
     * @param list<int>|null                                             $selectedRows numéros de ligne Excel à importer (P3-7) ;
     *                                                                                 null = tout importer (comportement historique)
     * @param array<int, array{tierId: int, gender: string, ctc?: bool}> $overrides    rang + genre (et entente CTC) édités
     *                                                                                 dans l'aperçu (#4, chantier D), keyés par
     *                                                                                 numéro de ligne Excel, appliqués aux SEULES
     *                                                                                 lignes créées ; absent = défaut historique
     *                                                                                 (rang D, genre vide, hors entente)
     *
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function import(string $filePath, string $clubId, string $seasonId, ?array $selectedRows = null, array $overrides = []): array
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

        // #4 — les rangs valides pour valider un override (le genre l'est par `Gender::tryFrom`).
        // Un override dont le rang n'existe pas est un 400, levé AVANT tout persist (tout-ou-rien).
        $validTierIds = [];
        foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
            $validTierIds[$tier->getId()] = true;
        }

        foreach ($rows as $rowIndex => $row) {
            if (null !== $selectedSet && !isset($selectedSet[$rowIndex + 2])) {
                continue;
            }

            /** @var array<mixed> $row */
            $nom = $this->stringValue($row[$columnMap['nom']] ?? null);
            $categorie = $this->stringValue($row[$columnMap['catégorie']] ?? null);
            $organisme = $this->stringValue($row[$columnMap['organisme']] ?? null);

            // Le NUMÉRO est optionnel (une équipe « sans numéro » est légitime, #4) :
            // seuls nom, catégorie et organisme font une ligne exploitable.
            if (\in_array('', [$nom, $categorie, $organisme], true)) {
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

            $sportCategory = $this->sportCategoryResolver->findOrCreate($categorie, $clubId, $sport->getId());

            // #4 — l'override (rang + genre édités dans l'aperçu) ne s'applique qu'ici,
            // à une ligne RETENUE pour création. Sans override : défaut historique (rang D,
            // genre vide, hors entente). Un override invalide jette AVANT tout persist
            // (tout-ou-rien). L'entente (chantier D) n'est posée que si l'override la coche —
            // jamais déduite du libellé — et le partenaire reste toujours vide (texte libre).
            [$tierId, $gender, $isCtc] = $this->resolveOverride($overrides[$rowIndex + 2] ?? null, $validTierIds);

            $team = (new Team)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSportCategoryId($sportCategory->getId())
                ->setPriorityTierId($tierId)
                ->setGender($gender)
                ->setIsCtc($isCtc)
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
     * Sert aussi, pour chaque ligne (#4), un rang suggéré (par catégorie + numéro,
     * résolu PAR LABEL via le repository `PriorityTier`) et un genre lu dans le nom —
     * pré-remplissant les deux colonnes éditables de l'aperçu, sans RIEN décider :
     * le gestionnaire reste libre de les changer.
     *
     * @return array{
     *     rows: list<array{row: int, name: string, category: string, number: string, alreadyPresent: bool, suggestedTierId: int, suggestedGender: ?string, suggestedCtc: bool}>,
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

        // #4 — le rang suggéré est résolu PAR LABEL (jamais un id en dur) : on charge
        // la table canonique label→id une fois pour tout l'analyse.
        $tierIdByLabel = [];
        foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
            $tierIdByLabel[$tier->getLabel()] = $tier->getId();
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

            // Le NUMÉRO est optionnel (équipe « sans numéro » → rang suggéré C, #4) :
            // seuls nom, catégorie et organisme font une ligne exploitable.
            if (\in_array('', [$nom, $categorie, $organisme], true)) {
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

            $suggestedLabel = $this->suggestedTierLabel($categorie, $numero);
            $suggestedGender = $this->readGender($nom);

            $out[] = [
                'row' => $excelRow,
                'name' => $nom,
                'category' => $categorie,
                'number' => $numero,
                'alreadyPresent' => $alreadyPresent,
                'suggestedTierId' => $tierIdByLabel[$suggestedLabel] ?? self::DEFAULT_PRIORITY_TIER_ID,
                'suggestedGender' => $suggestedGender?->value,
                'suggestedCtc' => $this->suggestsCtc($nom),
            ];
        }

        return ['rows' => $out, 'errors' => $errors, 'total' => $total];
    }

    /**
     * Résout un override (rang + genre + entente édités dans l'aperçu) en valeurs de
     * l'équipe. `null` = pas d'override → défaut historique (rang D, genre vide, hors
     * entente). Un rang inconnu ou un genre hors `Gender::cases()` → 400 nommé, AVANT
     * tout persist. L'entente (chantier D) n'est posée que si l'override la coche
     * explicitement (`ctc: true`) — jamais d'office, même quand le libellé porte « CTC ».
     *
     * @param array{tierId: int, gender: string, ctc?: bool}|null $override
     * @param array<int, true>                                    $validTierIds
     *
     * @return array{0: int, 1: ?Gender, 2: bool}
     */
    private function resolveOverride(?array $override, array $validTierIds): array
    {
        if (null === $override) {
            return [self::DEFAULT_PRIORITY_TIER_ID, null, false];
        }

        $tierId = $override['tierId'];
        if (!isset($validTierIds[$tierId])) {
            throw ImportRejectedException::badRequest(\sprintf('Rang inconnu (%d) dans les options d\'import.', $tierId));
        }

        $isCtc = $override['ctc'] ?? false;

        $genderRaw = $override['gender'];
        if ('' === $genderRaw) {
            return [$tierId, null, $isCtc];
        }

        $gender = Gender::tryFrom($genderRaw);
        if (!$gender instanceof Gender) {
            throw ImportRejectedException::badRequest(\sprintf('Genre inconnu (%s) dans les options d\'import.', $genderRaw));
        }

        return [$tierId, $gender, $isCtc];
    }

    /**
     * Chantier D — l'analyse SUGGÈRE l'entente (CTC) pour une équipe dont le libellé
     * porte « CTC » ou « ENTENTE » (casse indifférente), sans RIEN décider : le
     * gestionnaire reste libre de décocher. Aucun marqueur → jamais suggérée.
     */
    private function suggestsCtc(string $name): bool
    {
        $upper = mb_strtoupper($name, 'UTF-8');

        return str_contains($upper, 'CTC') || str_contains($upper, 'ENTENTE');
    }

    /**
     * #4 — le LABEL de rang suggéré pour une équipe, d'après sa catégorie et son
     * numéro. Seniors n°1/2/3/4+ → S/A/B/C ; jeunes n°1/2/3+ → A/B/C ; loisir → D ;
     * sans numéro → C (jamais le défaut D). Le label est ensuite résolu en id via le
     * repository `PriorityTier` (jamais un id en dur).
     */
    private function suggestedTierLabel(string $category, string $number): string
    {
        $cat = mb_strtolower($category, 'UTF-8');
        if (str_contains($cat, 'loisir')) {
            return 'D';
        }

        $rank = ctype_digit($number) ? (int) $number : 0; // sans numéro → 0

        if (str_contains($cat, 'senior')) {
            return match (true) {
                1 === $rank => 'S',
                2 === $rank => 'A',
                3 === $rank => 'B',
                default => 'C', // n°4 et +, ET sans numéro
            };
        }

        // Jeunes (toute catégorie d'âge) : n°1/2/3+ → A/B/C ; sans numéro → C.
        return match (true) {
            1 === $rank => 'A',
            2 === $rank => 'B',
            default => 'C',
        };
    }

    /**
     * #4 — le genre LU dans le nom, seulement dans un motif clair : une catégorie
     * d'âge suivie de la lettre (`U13F1`, `U18M`), ou `SM…`/`SF…`. Jamais la 1re
     * lettre M/F trouvée au hasard (« Mercredi Basket » n'est pas masculin), jamais
     * MIXTE en automatique : à défaut de motif, `null` (le gestionnaire tranche).
     */
    private function readGender(string $name): ?Gender
    {
        // Catégorie d'âge suivie de la lettre : U13F1, U18M.
        if (1 === preg_match('/\bU\d+\s*([MF])/i', $name, $matches)) {
            return Gender::from(mb_strtoupper($matches[1], 'UTF-8'));
        }

        // SM… → M, SF… → F (code FFBB « Senior Masculin/Féminin »).
        if (1 === preg_match('/\bS([MF])/i', $name, $matches)) {
            return Gender::from(mb_strtoupper($matches[1], 'UTF-8'));
        }

        return null;
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
