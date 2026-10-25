<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Service\Basketball\FfbbExcelImporter;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use App\Service\SportCategoryResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * P4-35 — l'identité d'une équipe à l'import Excel est (club, saison, NOM), et
 * l'import est TOUT-OU-RIEN. Avant : `findOneBy([clubId, seasonId])` demandait
 * « cette saison a-t-elle UNE équipe ? » (saison vierge + 4 lignes → created=2,
 * skipped=2, mesuré — le nombre dépendait de l'ordre du fichier), et le flush
 * des catégories en pleine boucle laissait des écritures partielles quand une
 * ligne ultérieure jetait (422 code club étranger avec la moitié du fichier en
 * base). La correspondance UI persistée, elle, attend l'écran P3-7.
 */
#[Group('integration')]
final class FfbbExcelImporterTest extends KernelTestCase
{
    use TenantGucTrait;

    private const CLUB_CODE = 'ARA0069036';

    /** Rang par défaut historique (D) quand aucun override n'est fourni — miroir de l'importeur. */
    private const DEFAULT_TIER_ID = 5;

    private EntityManagerInterface $em;

    private Club $club;

    private string $seasonId;

    /** @var list<string> */
    private array $files = [];

    public function testVirginSeasonImportsEveryRowAndReimportSkipsByName(): void
    {
        $importer = $this->importer();
        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', self::CLUB_CODE . ' - MON CLUB'],
            ['SM2', 'Seniors', '2', self::CLUB_CODE . ' - MON CLUB'],
            ['U13M1', 'U13', '1', self::CLUB_CODE . ' - MON CLUB'],
            ['U15F1', 'U15', '1', self::CLUB_CODE . ' - MON CLUB'],
        ]);

        $result = $importer->import($file, $this->club->getId(), $this->seasonId);
        self::assertSame(['created' => 4, 'skipped' => 0, 'errors' => []], $result, 'saison vierge : les 4 lignes entrent — plus jamais created=2/skipped=2 selon l\'ordre');

        // Ré-import du même fichier : l'identité par NOM saute les 4, n'en double aucune.
        $again = $importer->import($file, $this->club->getId(), $this->seasonId);
        self::assertSame(['created' => 0, 'skipped' => 4, 'errors' => []], $again);
        self::assertCount(4, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]));
    }

    public function testExistingTeamOnlySkipsItsOwnName(): void
    {
        $existing = (new Team)->setClubId($this->club->getId())->setSeasonId($this->seasonId)
            ->setSportCategoryId($this->categoryId('Seniors'))->setName('SM1')->setPriorityTierId(1);
        $this->em->persist($existing);
        $this->em->flush();

        $result = $this->importer()->import($this->xlsx([
            ['SM1', 'Seniors', '1', self::CLUB_CODE . ' - MON CLUB'],
            ['SM2', 'Seniors', '2', self::CLUB_CODE . ' - MON CLUB'],
        ]), $this->club->getId(), $this->seasonId);

        self::assertSame(['created' => 1, 'skipped' => 1, 'errors' => []], $result, 'une équipe existante ne masque plus tout le fichier — created=0/skipped=N était le défaut mesuré');
    }

    public function testForeignClubCodeWritesNothingAtAll(): void
    {
        // La ligne 2 porte un code club ÉTRANGER : l'import jette (422 métier) — et
        // la ligne 1, sa catégorie personnalisée comprise, ne doit PAS être en base
        // (l'ancien flush de findOrCreateSportCategory l'y laissait).
        try {
            $this->importer()->import($this->xlsx([
                ['SM1', 'Catégorie inédite', '1', self::CLUB_CODE . ' - MON CLUB'],
                ['SM2', 'Seniors', '2', 'IDF9999999 - UN AUTRE CLUB'],
            ]), $this->club->getId(), $this->seasonId);
            self::fail('un code club étranger doit rejeter l\'import');
        } catch (ImportRejectedException) {
        }

        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]), 'tout-ou-rien : aucune équipe');
        self::assertNull($this->em->getRepository(SportCategory::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Catégorie inédite']), 'tout-ou-rien : pas de catégorie orpheline');
    }

    public function testCreatedCategoryRanksAfterTheCatalog(): void
    {
        $this->importer()->import($this->xlsx([
            ['Loisir A', 'Catégorie inédite', '1', self::CLUB_CODE . ' - MON CLUB'],
        ]), $this->club->getId(), $this->seasonId);

        $created = $this->em->getRepository(SportCategory::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'Catégorie inédite']);
        self::assertInstanceOf(SportCategory::class, $created);
        $max = 0;
        foreach ($this->em->getRepository(SportCategory::class)->findBy(['clubId' => $this->club->getId()]) as $category) {
            if ('Catégorie inédite' !== $category->getName()) {
                $max = max($max, $category->getSortOrder());
            }
        }
        self::assertGreaterThan($max, $created->getSortOrder(), 'une catégorie créée se range APRÈS le catalogue (0 = la place de « Vétéran », elle sautait en tête des sélecteurs)');
    }

    public function testHtmlDisguisedAsXlsxIsRejectedByThePinnedReader(): void
    {
        // SEC-22 — le lecteur est épinglé à Xlsx : un HTML portant une table
        // Nom/Catégorie/Numéro/Organisme (que le lecteur Html LIRAIT) ne doit
        // JAMAIS être ingéré. Test au niveau SERVICE : via HTTP, le garde zip du
        // contrôleur refuserait avant le parseur et rendrait l'épinglage intestable.
        $path = tempnam(sys_get_temp_dir(), 'html-') . '.xlsx';
        \assert(\is_string($path));
        file_put_contents($path, <<<'HTML'
            <html><body><table>
            <tr><th>Nom</th><th>Catégorie</th><th>Numéro</th><th>Organisme</th></tr>
            <tr><td>SM1</td><td>Seniors</td><td>1</td><td>ARA0069036 - MON CLUB</td></tr>
            </table></body></html>
            HTML);
        $this->files[] = $path;

        $threw = false;
        try {
            $this->importer()->import($path, $this->club->getId(), $this->seasonId);
        } catch (Throwable) {
            // Le lecteur Xlsx refuse ce qui n'est pas un classeur : c'est le comportement gardé.
            $threw = true;
        }

        self::assertTrue($threw, 'un HTML déguisé en .xlsx doit être refusé par le lecteur épinglé');
        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]), 'aucune équipe ne doit naître d\'un HTML');
    }

    public function testAnalyzeMarksAlreadyPresentByExistingNameAndInFileDuplicate(): void
    {
        // P3-7 — l'analyse liste les lignes et dit lesquelles feraient DOUBLON, sans
        // rien écrire. « Déjà présent » = même nom en base (club+saison) OU déjà vu
        // plus haut dans le fichier. Une équipe SF1 existe déjà pour ce club+saison.
        $existing = (new Team)->setClubId($this->club->getId())->setSeasonId($this->seasonId)
            ->setSportCategoryId($this->categoryId('Seniors'))->setName('SF1')->setPriorityTierId(1);
        $this->em->persist($existing);
        $this->em->flush();

        $result = $this->importer()->analyze($this->xlsx([
            ['SF1', 'Seniors', '6', self::CLUB_CODE . ' - MON CLUB'],   // ligne 2 : déjà en base
            ['SM1', 'Seniors', '1', self::CLUB_CODE . ' - MON CLUB'],   // ligne 3 : neuve
            ['SM1', 'Seniors', '1', self::CLUB_CODE . ' - MON CLUB'],   // ligne 4 : doublon DANS le fichier
        ]), $this->club->getId(), $this->seasonId);

        self::assertSame(3, $result['total']);
        self::assertSame([], $result['errors']);
        self::assertSame(
            ['row' => 2, 'name' => 'SF1', 'category' => 'Seniors', 'number' => '6', 'alreadyPresent' => true, 'suggestedTierId' => self::DEFAULT_TIER_ID, 'suggestedGender' => 'F', 'suggestedCtc' => false],
            $result['rows'][0],
            'le numéro de ligne est celui d\'Excel (en-tête = ligne 1), et SF1 est déjà en base ; aucun marqueur CTC → entente non suggérée',
        );
        self::assertSame(
            ['row' => 3, 'name' => 'SM1', 'category' => 'Seniors', 'number' => '1', 'alreadyPresent' => false, 'suggestedTierId' => self::DEFAULT_TIER_ID, 'suggestedGender' => 'M', 'suggestedCtc' => false],
            $result['rows'][1],
        );
        self::assertSame(4, $result['rows'][2]['row']);
        self::assertTrue($result['rows'][2]['alreadyPresent'], 'un nom déjà vu plus haut DANS le fichier est un doublon');

        // Dry-run : l'analyse n'écrit rien (seule l'équipe préexistante subsiste).
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]));
    }

    public function testImportOnlyCreatesTheSelectedRows(): void
    {
        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', self::CLUB_CODE . ' - MON CLUB'],   // ligne 2 — NON cochée
            ['SM2', 'Seniors', '2', self::CLUB_CODE . ' - MON CLUB'],   // ligne 3 — cochée
            ['U13M1', 'U13', '1', self::CLUB_CODE . ' - MON CLUB'],     // ligne 4 — cochée
        ]);

        $result = $this->importer()->import($file, $this->club->getId(), $this->seasonId, [3, 4]);
        self::assertSame(['created' => 2, 'skipped' => 0, 'errors' => []], $result);

        $names = array_map(
            static fn (Team $t): string => $t->getName(),
            $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]),
        );
        sort($names);
        self::assertSame(['SM2', 'U13M1'], $names, 'la ligne décochée SM1 n\'est PAS en base');
    }

    public function testAForeignCodeOnAnUncheckedRowNeverRejectsTheImport(): void
    {
        // La ligne 2 porte un code ÉTRANGER mais n'est PAS cochée : elle est ignorée
        // AVANT le contrôle de code, donc l'import ne jette pas et la ligne 3 se crée.
        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', 'IDF9999999 - UN AUTRE CLUB'],     // ligne 2 — étrangère, décochée
            ['SM2', 'Seniors', '2', self::CLUB_CODE . ' - MON CLUB'],  // ligne 3 — cochée
        ]);

        $result = $this->importer()->import($file, $this->club->getId(), $this->seasonId, [3]);
        self::assertSame(['created' => 1, 'skipped' => 0, 'errors' => []], $result);
    }

    public function testOfferCapCountsOnlyTheSelectedNewRows(): void
    {
        // Offre à cap = 1 équipe : cocher 2 lignes neuves franchit le cap (refus
        // tout-ou-rien), en cocher 1 passe — le cap se compte sur la SÉLECTION neuve.
        $plan = (new SubscriptionPlan)->setCode('cap1-' . uniqid('', true))->setName('Cap 1')
            ->setMaxTeams(1)->setMaxVenues(0)->setMaxGenerations(0);
        $this->em->persist($plan);
        $pivot = SeasonResolver::seasonYear(new DateTimeImmutable);
        $this->club->setPlanId($plan->getId())->setPaidSeasonYear($pivot);
        $this->em->flush();

        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', self::CLUB_CODE . ' - MON CLUB'],   // ligne 2
            ['SM2', 'Seniors', '2', self::CLUB_CODE . ' - MON CLUB'],   // ligne 3
        ]);

        try {
            $this->importer()->import($file, $this->club->getId(), $this->seasonId, [2, 3]);
            self::fail('deux équipes cochées pour un cap de 1 doivent être refusées');
        } catch (ImportRejectedException) {
        }
        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]), 'tout-ou-rien : rien créé');

        $result = $this->importer()->import($file, $this->club->getId(), $this->seasonId, [3]);
        self::assertSame(['created' => 1, 'skipped' => 0, 'errors' => []], $result);
    }

    public function testAnalyzeSuggestsRankFromCategoryAndNumberAndGenderFromName(): void
    {
        // #4 (retours Rillieux) — l'analyse NE PROPOSE PLUS tout en rang D : elle sert,
        // pour CHAQUE ligne, un rang suggéré (par catégorie + numéro) et un genre lu dans
        // le nom. Règle à MAISON UNIQUE côté back. Rangs résolus PAR LABEL via le repo
        // `PriorityTier` (jamais un id en dur) : on lit l'id attendu via `tierIdForLabel`.
        $this->seedPriorityTiers();
        $org = self::CLUB_CODE . ' - MON CLUB';
        $result = $this->importer()->analyze($this->xlsx([
            ['SM1', 'Seniors', '1', $org],            // senior n°1 → S ; « SM1 » → M
            ['SF2', 'Seniors', '2', $org],            // senior n°2 → A ; « SF2 » → F
            ['SM4', 'Seniors', '4', $org],            // senior n°4+ → C ; → M
            ['U13M1', 'U13', '1', $org],              // jeune n°1 → A ; « U13M1 » → M
            ['U13F2', 'U13', '2', $org],              // jeune n°2 → B ; « U13F2 » → F
            ['U15M3', 'U15', '3', $org],              // jeune n°3+ → C ; → M
            ['Loisir Detente', 'Loisir', '1', $org],  // loisir → D ; aucun genre lisible
            ['Veterans', 'Seniors', '', $org],        // sans numéro → C (jamais le défaut D) ; aucun genre
        ]), $this->club->getId(), $this->seasonId);

        $byName = [];
        foreach ($result['rows'] as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame($this->tierIdForLabel('S'), $byName['SM1']['suggestedTierId'], 'senior n°1 → S');
        self::assertSame('M', $byName['SM1']['suggestedGender']);
        self::assertSame($this->tierIdForLabel('A'), $byName['SF2']['suggestedTierId'], 'senior n°2 → A');
        self::assertSame('F', $byName['SF2']['suggestedGender']);
        self::assertSame($this->tierIdForLabel('C'), $byName['SM4']['suggestedTierId'], 'senior n°4+ → C');
        self::assertSame($this->tierIdForLabel('A'), $byName['U13M1']['suggestedTierId'], 'jeune n°1 → A');
        self::assertSame('M', $byName['U13M1']['suggestedGender']);
        self::assertSame($this->tierIdForLabel('B'), $byName['U13F2']['suggestedTierId'], 'jeune n°2 → B');
        self::assertSame('F', $byName['U13F2']['suggestedGender']);
        self::assertSame($this->tierIdForLabel('C'), $byName['U15M3']['suggestedTierId'], 'jeune n°3+ → C');
        self::assertSame($this->tierIdForLabel('D'), $byName['Loisir Detente']['suggestedTierId'], 'loisir → D');
        self::assertNull($byName['Loisir Detente']['suggestedGender'], 'aucun genre lisible → vide');
        self::assertSame($this->tierIdForLabel('C'), $byName['Veterans']['suggestedTierId'], 'sans numéro → C, jamais le défaut D');
        self::assertNull($byName['Veterans']['suggestedGender']);
    }

    public function testImportAppliesCheckedRowOverridesAndIgnoresUncheckedOnes(): void
    {
        // #4 — l'import accepte des `overrides` (rang + genre édités dans l'aperçu),
        // appliqués AUX SEULES lignes cochées. Un override sur une ligne NON cochée
        // n'a aucun effet (il ne la ressuscite pas).
        $this->seedPriorityTiers();
        $org = self::CLUB_CODE . ' - MON CLUB';
        $file = $this->xlsx([
            ['SM1', 'Seniors', '1', $org],   // ligne 2 — NON cochée
            ['SM2', 'Seniors', '2', $org],   // ligne 3 — cochée, forcée S + MIXTE
        ]);

        $result = $this->importer()->import($file, $this->club->getId(), $this->seasonId, [3], [
            2 => ['tierId' => $this->tierIdForLabel('S'), 'gender' => 'M'],      // ligne non cochée → ignoré
            3 => ['tierId' => $this->tierIdForLabel('S'), 'gender' => 'MIXTE'],  // ligne cochée → appliqué
        ]);
        self::assertSame(['created' => 1, 'skipped' => 0, 'errors' => []], $result);

        $teams = $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]);
        self::assertCount(1, $teams, 'seule la ligne cochée est créée — l\'override d\'une ligne non cochée ne la crée pas');
        self::assertSame('SM2', $teams[0]->getName());
        self::assertSame($this->tierIdForLabel('S'), $teams[0]->getPriorityTierId(), 'le rang édité est appliqué');
        self::assertSame(Gender::MIXTE, $teams[0]->getGender(), 'le genre édité est appliqué');
    }

    public function testACheckedRowWithoutOverrideKeepsTheHistoricalDefaults(): void
    {
        // Compatibilité : sans override, une ligne cochée garde le défaut historique
        // (rang D = 5, genre vide). L'écran envoie TOUJOURS des overrides pré-remplis,
        // mais le service ne doit rien casser quand ils manquent.
        $org = self::CLUB_CODE . ' - MON CLUB';
        $file = $this->xlsx([['SM2', 'Seniors', '2', $org]]); // ligne 2

        $this->importer()->import($file, $this->club->getId(), $this->seasonId, [2], []);

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'SM2']);
        self::assertInstanceOf(Team::class, $team);
        self::assertSame(self::DEFAULT_TIER_ID, $team->getPriorityTierId(), 'sans override, le rang reste le défaut (D)');
        self::assertNull($team->getGender(), 'sans override, le genre reste vide');
    }

    public function testOverrideWithUnknownTierIsRejectedAndWritesNothing(): void
    {
        // Un override dont le rang n'existe pas → 400 nommé, tout-ou-rien (rien créé).
        $org = self::CLUB_CODE . ' - MON CLUB';
        $file = $this->xlsx([['SM2', 'Seniors', '2', $org]]); // ligne 2

        try {
            $this->importer()->import($file, $this->club->getId(), $this->seasonId, [2], [
                2 => ['tierId' => 999999, 'gender' => 'M'],
            ]);
            self::fail('un rang inexistant doit faire refuser l\'import');
        } catch (ImportRejectedException $e) {
            self::assertSame(400, $e->getStatusCode(), 'un override invalide est un 400 (requête mauvaise)');
        }

        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]), 'tout-ou-rien : rien créé');
    }

    public function testOverrideWithInvalidGenderIsRejectedAndWritesNothing(): void
    {
        // Un override dont le genre n'est pas M/F/MIXTE → 400 nommé, tout-ou-rien.
        $this->seedPriorityTiers();
        $org = self::CLUB_CODE . ' - MON CLUB';
        $file = $this->xlsx([['SM2', 'Seniors', '2', $org]]); // ligne 2

        try {
            $this->importer()->import($file, $this->club->getId(), $this->seasonId, [2], [
                2 => ['tierId' => $this->tierIdForLabel('A'), 'gender' => 'Z'],
            ]);
            self::fail('un genre hors M/F/MIXTE doit faire refuser l\'import');
        } catch (ImportRejectedException $e) {
            self::assertSame(400, $e->getStatusCode());
        }

        self::assertCount(0, $this->em->getRepository(Team::class)->findBy(['clubId' => $this->club->getId()]), 'tout-ou-rien : rien créé');
    }

    public function testAnalyzeSuggestsCtcWhenTheTeamLabelCarriesCtcOrEntente(): void
    {
        // Chantier D (retours Rillieux #6) — l'analyse SUGGÈRE l'entente (CTC) pour une
        // équipe dont le libellé porte « CTC » ou « ENTENTE », casse indifférente — sans
        // RIEN décider : le gestionnaire reste libre de décocher (jamais d'office, cf.
        // l'abus plus bas). Aucun marqueur → jamais suggérée.
        $org = self::CLUB_CODE . ' - MON CLUB';
        $result = $this->importer()->analyze($this->xlsx([
            ['U15F CTC RILLIEUX', 'U15', '1', $org],   // « CTC » (majuscules) → suggérée
            ['Entente Nord U13M', 'U13', '1', $org],   // « Entente » (casse mixte) → suggérée
            ['SM1', 'Seniors', '1', $org],             // aucun marqueur → non suggérée
        ]), $this->club->getId(), $this->seasonId);

        $byName = [];
        foreach ($result['rows'] as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertTrue($byName['U15F CTC RILLIEUX']['suggestedCtc'], '« CTC » dans le libellé → entente suggérée');
        self::assertTrue($byName['Entente Nord U13M']['suggestedCtc'], '« Entente » dans le libellé (casse indifférente) → entente suggérée');
        self::assertFalse($byName['SM1']['suggestedCtc'], 'aucun marqueur CTC/ENTENTE → entente jamais suggérée');
    }

    public function testImportCreatesACtcTeamWhenTheOverrideSaysSoWithoutGuessingThePartner(): void
    {
        // Chantier D — un override `ctc: true` sur une ligne cochée crée l'équipe EN
        // ENTENTE (isCtc=true). Le PARTENAIRE n'est jamais déduit du libellé : il naît
        // vide, le gestionnaire le renseignera lui-même (texte libre opaque).
        $this->seedPriorityTiers();
        $org = self::CLUB_CODE . ' - MON CLUB';
        $file = $this->xlsx([['U15F CTC RILLIEUX', 'U15', '1', $org]]); // ligne 2

        $this->importer()->import($file, $this->club->getId(), $this->seasonId, [2], [
            2 => ['tierId' => $this->tierIdForLabel('A'), 'gender' => 'F', 'ctc' => true],
        ]);

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'U15F CTC RILLIEUX']);
        self::assertInstanceOf(Team::class, $team);
        self::assertTrue($team->getIsCtc(), 'override ctc:true → l\'équipe naît en entente');
        self::assertNull($team->getCtcPartner(), 'le partenaire n\'est jamais déduit du libellé — il reste vide');
    }

    public function testImportNeverMarksCtcWithoutAnOverrideEvenWhenTheNameContainsCtc(): void
    {
        // Abus « jamais en silence » — une équipe importée SANS décision d'entente
        // (aucun override, ou override sans `ctc`) n'est JAMAIS déclarée en entente,
        // même si son libellé contient « CTC ». L'entente est une case que le
        // gestionnaire coche, jamais une déduction du nom à son insu.
        $org = self::CLUB_CODE . ' - MON CLUB';
        $file = $this->xlsx([['U15F CTC RILLIEUX', 'U15', '1', $org]]); // ligne 2

        $this->importer()->import($file, $this->club->getId(), $this->seasonId, [2], []);

        $team = $this->em->getRepository(Team::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => 'U15F CTC RILLIEUX']);
        self::assertInstanceOf(Team::class, $team);
        self::assertFalse($team->getIsCtc(), 'sans décision explicite, « CTC » dans le nom ne déclare JAMAIS l\'entente');
        self::assertNull($team->getCtcPartner());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Excel ' . $uid)->setSlug('xls-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(false)
            ->setFfbbClubCode(self::CLUB_CODE);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable);
        $season = (new Season)->setClubId($this->club->getId())->setName($year . '-' . ($year + 1))
            ->setStartDate(new DateTimeImmutable($year . '-07-15'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-14'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->seasonId = $season->getId();

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        foreach ([['Seniors', 22, 99, 1], ['U13', 12, 13, 2], ['U15', 14, 15, 3]] as [$name, $min, $max, $order]) {
            $category = (new SportCategory)->setClubId($this->club->getId())->setSportId($sport->getId())
                ->setName($name)->setAgeMin($min)->setAgeMax($max)->setSortOrder($order);
            $this->em->persist($category);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function importer(): FfbbExcelImporter
    {
        return new FfbbExcelImporter(
            $this->em,
            self::getContainer()->get(PlanEntitlements::class),
            self::getContainer()->get(SportCategoryResolver::class),
        );
    }

    private function categoryId(string $name): string
    {
        return (string) $this->em->getRepository(SportCategory::class)->findOneBy(['clubId' => $this->club->getId(), 'name' => $name])?->getId();
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Nom', 'Catégorie', 'Numéro', 'Organisme'], ...$rows]);
        $file = tempnam(sys_get_temp_dir(), 'ffbb-xlsx-');
        \assert(\is_string($file));
        new Xlsx($spreadsheet)->save($file);
        $this->files[] = $file;

        return $file;
    }

    /**
     * Les 5 rangs de référence (mapping canonique label→id du seed), idempotents :
     * `priority_tier` est GLOBAL. Permet de résoudre un rang PAR LABEL dans les
     * assertions (jamais un id en dur).
     */
    private function seedPriorityTiers(): void
    {
        foreach ([[1, 'S', 'Elite'], [2, 'A', 'Régional+'], [3, 'B', 'Régional'], [4, 'C', 'Départemental'], [5, 'D', 'Loisir']] as [$id, $label, $name]) {
            if ($this->em->getRepository(PriorityTier::class)->find($id) instanceof PriorityTier) {
                continue;
            }
            $tier = (new PriorityTier)->setId($id)->setLabel($label)->setName($name)
                ->setColor('#000000')->setOrToolsWeight(1)->setDefaultMinSessions(1);
            $this->em->persist($tier);
        }
        $this->em->flush();
    }

    private function tierIdForLabel(string $label): int
    {
        $tier = $this->em->getRepository(PriorityTier::class)->findOneBy(['label' => $label]);
        self::assertInstanceOf(PriorityTier::class, $tier, \sprintf('le rang « %s » doit être seedé', $label));

        return $tier->getId();
    }
}
