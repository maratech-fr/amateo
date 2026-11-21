<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Enum\TeamTagAxis;
use App\Service\TeamTagService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * La règle des étiquettes, sans base : ce qu'une équipe porte (`tagNamesOf`) et le catalogue
 * fermé que les routes de lecture servent (`catalog`). Les étiquettes se calculent à la lecture
 * depuis la PR 3 du lot conception 1 — plus rien ne les écrit. Plus haut : `TeamTagScopeTest`
 * (portée de chaque groupe, base réelle) et `TagLabelsMatchTheRuleTest` (libellés de l'écran).
 */
#[Group('unit')]
final class TeamTagServiceTest extends TestCase
{
    private TeamTagService $service;

    /** @var EntityManagerInterface&MockObject */
    private MockObject $entityManager;

    /** @var EntityRepository<SportCategory>&MockObject */
    private MockObject $sportCategoryRepository;

    /**
     * La tranche d'âge posée par catégorie, aux âges RÉELS du catalogue
     * (`Service\Basketball\CategoryCatalog`) — inventer un jeu d'âges testerait une
     * frontière qui n'existe nulle part.
     *
     * Complément rapide au NR d'intégration `TeamTagScopeTest` : ici on lit le NOM du tag
     * posé, catégorie par catégorie, sans base.
     *
     * @return iterable<string, array{0: string, 1: int|null, 2: int|null, 3: list<string>}>
     */
    public static function ageBracketCases(): iterable
    {
        yield 'U5 → BABY' => ['U5', 3, 5, ['BABY']];
        yield 'U7 → BABY (dernière année de la tranche)' => ['U7', 6, 7, ['BABY']];
        yield 'U9 → EMB (première année d\'EMB)' => ['U9', 8, 9, ['EMB']];
        yield 'U11 → EMB' => ['U11', 10, 11, ['EMB']];
        yield 'U13 → JEUNE, jamais EMB' => ['U13', 12, 13, ['JEUNE']];
        // Volet A — ADULTE (= +18, ageMin >= 19) et SENIOR (= +22) se CHEVAUCHENT.
        yield 'U21 → ADULTE SEUL (19-21, sous 22)' => ['U21', 19, 21, ['ADULTE']];
        yield 'Senior → ADULTE + SENIOR (22-99)' => ['Senior', 22, 99, ['ADULTE', 'SENIOR']];
        yield 'Vétéran → ADULTE + SENIOR (35-99)' => ['Vétéran', 35, 99, ['ADULTE', 'SENIOR']];
        // Sans âge : seul le NOM peut trancher. « Baby basket » entre, « Loisir » non —
        // c'est la paire qui prouve que la règle vise « baby » et n'avale pas toute
        // catégorie sans âge.
        yield 'Baby basket → BABY par son nom, faute d\'âges' => ['Baby basket', null, null, ['BABY']];
        yield 'Loisir → aucune tranche' => ['Loisir', null, null, []];
        // LA frontière de la règle, et le SEUL endroit où elle peut dériver : quand le nom
        // dit « baby » mais que l'âge dit autre chose, c'est l'ÂGE qui tranche. Sans ce
        // cas, inverser la précédence laissait toute la suite verte (revue #352).
        yield 'nom « Baby » mais âges de U9 → EMB, l\'âge prime' => ['Baby élite', 8, 9, ['EMB']];
        yield 'nom « Baby » mais âges de U15 → JEUNE, l\'âge prime' => ['Baby compétition', 14, 15, ['JEUNE']];
        // `ageMin` seul renseigné : l'API les rend indépendamment optionnels
        // (`SportCategoryInput`). Exiger les DEUX bornes nulles faisait retomber ce cas
        // hors de toutes les branches — le trou que le lot vient boucher (revue #352).
        yield 'Baby basket avec le seul ageMin → BABY quand même' => ['Baby basket', 3, null, ['BABY']];
    }

    /**
     * Une équipe U15 féminine régionale porte sa catégorie, sa tranche, son sexe, son niveau et
     * COMPETITION. ⚠ Les NOMS, pas le compte (revue #356) : inverser les branches F / M
     * laisserait un compte juste, et une règle visant les filles atteindrait les garçons.
     */
    public function testAYoungRegionalGirlsTeamCarriesItsFiveTags(): void
    {
        $team = $this->team('cat-u15', Gender::F, TeamLevel::REGIONAL);
        $this->categoryIs('cat-u15', 'U15F', 13, 15);

        self::assertSame(['COMPETITION', 'FEMININE', 'JEUNE', 'REGIONAL', 'U15'], $this->sorted($this->service->tagNamesOf($team)));
    }

    /** Une équipe senior loisir est ADULTE sans SENIOR (âge minimum 19) et jamais COMPETITION. */
    public function testASeniorLeisureTeamIsAdultButNeverCompeting(): void
    {
        $team = $this->team('cat-senior', Gender::M, TeamLevel::LOISIR_ADULTE);
        $this->categoryIs('cat-senior', 'Senior', 19, 99);

        self::assertSame(['ADULTE', 'LOISIR_ADULTE', 'MASCULINE'], $this->sorted($this->service->tagNamesOf($team)));
    }

    /**
     * Une équipe dont la catégorie n'existe plus n'a aucun groupe d'âge, sans erreur (limite
     * écrite du lot conception 1, §14) : elle garde ses étiquettes de sexe et de niveau.
     */
    public function testATeamWhoseCategoryIsGoneKeepsOnlyGenderAndLevel(): void
    {
        $team = $this->team('cat-disparue', Gender::MIXTE, null);
        $this->sportCategoryRepository->method('find')->willReturn(null);

        self::assertSame(['MIXTE'], $this->service->tagNamesOf($team));
    }

    /**
     * Le catalogue que `GET /api/team_tags` sert : 24 noms fermés, identiques pour tous les clubs,
     * chacun avec son axe (l'axe range le sélecteur de cible d'une règle). La commande de contrôle
     * de la copie de production compare l'enregistré à ce catalogue : un nom de plus ou de moins
     * ferait sortir de leur groupe les équipes d'un club réel.
     */
    public function testTheCatalogHoldsTheTwentyFourSystemTagsWithTheirAxis(): void
    {
        $axes = [];
        foreach ($this->service->catalog() as $entry) {
            self::assertArrayNotHasKey($entry['name'], $axes, \sprintf('« %s » en double au catalogue', $entry['name']));
            self::assertNotSame('', $entry['color'], \sprintf('« %s » sans couleur', $entry['name']));
            $axes[$entry['name']] = $entry['axis'];
        }

        self::assertSame([
            'ADULTE' => TeamTagAxis::AGE, 'BABY' => TeamTagAxis::AGE, 'COMPETITION' => TeamTagAxis::NIVEAU,
            'DEPARTEMENTAL' => TeamTagAxis::NIVEAU, 'ELITE' => TeamTagAxis::NIVEAU, 'EMB' => TeamTagAxis::AGE,
            'FEMININE' => TeamTagAxis::GENRE, 'HONNEUR' => TeamTagAxis::NIVEAU, 'JEUNE' => TeamTagAxis::AGE,
            'LOISIR_ADULTE' => TeamTagAxis::NIVEAU, 'LOISIR_JEUNE' => TeamTagAxis::NIVEAU, 'MASCULINE' => TeamTagAxis::GENRE,
            'MIXTE' => TeamTagAxis::GENRE, 'NATIONAL' => TeamTagAxis::NIVEAU, 'PRE_REGION' => TeamTagAxis::NIVEAU,
            'PROMOTION' => TeamTagAxis::NIVEAU, 'REGIONAL' => TeamTagAxis::NIVEAU, 'SENIOR' => TeamTagAxis::AGE,
            'U11' => TeamTagAxis::AGE, 'U13' => TeamTagAxis::AGE, 'U15' => TeamTagAxis::AGE,
            'U18' => TeamTagAxis::AGE, 'U21' => TeamTagAxis::AGE, 'U9' => TeamTagAxis::AGE,
        ], (static function (array $byName): array {
            ksort($byName);

            return $byName;
        })($axes));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('ageBracketCases')]
    public function testAgeBracketTagPerCategory(string $categoryName, ?int $ageMin, ?int $ageMax, array $expected): void
    {
        $team = $this->team('cat-1', null, null);
        $this->categoryIs('cat-1', $categoryName, $ageMin, $ageMax);

        $brackets = array_values(array_intersect($this->service->tagNamesOf($team), ['BABY', 'EMB', 'JEUNE', 'ADULTE', 'SENIOR']));
        self::assertSame($expected, $brackets, \sprintf('tranche(s) d\'âge de « %s »', $categoryName));
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->sportCategoryRepository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [SportCategory::class, $this->sportCategoryRepository],
            ]);
        // Calculer ne doit rien écrire.
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service = new TeamTagService($this->entityManager);
    }

    private function team(string $categoryId, ?Gender $gender, ?TeamLevel $level): Team
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId($categoryId);
        $team->setGender($gender);
        $team->setLevel($level);

        return $team;
    }

    private function categoryIs(string $id, string $name, ?int $ageMin, ?int $ageMax): void
    {
        $category = new SportCategory;
        $category->setName($name);
        $category->setAgeMin($ageMin);
        $category->setAgeMax($ageMax);
        $this->sportCategoryRepository->method('find')->with($id)->willReturn($category);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
