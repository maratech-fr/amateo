<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Service\Basketball\FfbbExcelImporter;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
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
        return new FfbbExcelImporter($this->em, self::getContainer()->get(PlanEntitlements::class));
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
}
