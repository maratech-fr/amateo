<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Enum\TeamTagAxis;
use App\Service\TeamTagService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[Group('unit')]
final class TeamTagServiceTest extends TestCase
{
    private TeamTagService $service;

    /** @var EntityManagerInterface&MockObject */
    private MockObject $entityManager;

    /** @var EntityRepository<TeamTagAssignment>&MockObject */
    private MockObject $assignmentRepository;

    /** @var EntityRepository<TeamTag>&MockObject */
    private MockObject $teamTagRepository;

    /** @var EntityRepository<SportCategory>&MockObject */
    private MockObject $sportCategoryRepository;

    /** @var Connection&MockObject */
    private MockObject $connection;

    /** @var list<string> le SQL d'insertion capturé — c'est là que vivent désormais les tags semés */
    private array $insertStatements = [];

    /** @var list<array<string, mixed>> les paramètres liés de ces insertions */
    private array $insertParams = [];

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

    public function testSyncTeamTagsForU15F(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-u15');
        $team->setGender(Gender::F);
        $team->setLevel(TeamLevel::REGIONAL);

        $sportCategory = new SportCategory;
        $sportCategory->setName('U15F');
        $sportCategory->setAgeMin(13);
        $sportCategory->setAgeMax(15);

        $this->assignmentRepository->method('findBy')
            ->with(['teamId' => $team->getId(), 'seasonId' => 'season-1'])
            ->willReturn([]);

        // Base vide au premier passage, puis peuplée de CE QUI A RÉELLEMENT ÉTÉ INSÉRÉ.
        //
        // ⚠ La relecture rendait d'abord le catalogue complet quoi qu'il arrive (revue #356) :
        // lier le mauvais `club_id` dans l'insertion laissait ces tests VERTS. En dérivant la
        // réponse des paramètres capturés, le mock se comporte comme une base — il ne rend que
        // ce qu'on lui a donné, pour le club qu'on lui a nommé.
        $this->teamTagRepository->method('findBy')
            ->willReturnCallback(fn (array $criteres): array => $this->tagsInsertedFor($criteres));

        $this->sportCategoryRepository->method('find')
            ->with('cat-u15')
            ->willReturn($sportCategory);

        $persistedAssignments = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedAssignments): void {
                if ($entity instanceof TeamTagAssignment) {
                    $persistedAssignments[] = $entity;
                }
            });

        // P2-13 — `syncTeamTags` ne flushe PLUS de lui-même : le seul flush du chemin
        // servait au backfill d'axe (aucun ici, les tags du mock en ont un). Les
        // assignations partent avec le flush de fin de lot du `TeamTagSyncListener`,
        // celui que son commentaire déclare indispensable.
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        // ⚠ On assert e les NOMS, pas seulement le compte (revue #356). Le mock pose
        // `id = name`, donc `getTagId()` rend le nom du tag. Avec un simple `assertCount(4)`,
        // inverser les branches Gender::F / Gender::M laissait ce test VERT : une équipe
        // féminine recevait MASCULINE, et une contrainte visant les filles atteignait les
        // garçons sans qu'aucun test rougisse.
        $tagNames = array_map(static fn (TeamTagAssignment $a): string => $a->getTagId(), $persistedAssignments);
        sort($tagNames);
        // REGIONAL est un niveau NON loisir → l'équipe est aussi taguée COMPETITION (Volet A).
        self::assertSame(['COMPETITION', 'FEMININE', 'JEUNE', 'REGIONAL', 'U15'], $tagNames);
    }

    public function testSyncTeamTagsForSeniorLoisir(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-senior');
        $team->setGender(Gender::M);
        $team->setLevel(TeamLevel::LOISIR_ADULTE);

        $sportCategory = new SportCategory;
        $sportCategory->setName('Senior');
        // ageMin 19 : ADULTE (= +18) mais PAS SENIOR (= +22). Niveau loisir → pas COMPETITION.
        $sportCategory->setAgeMin(19);
        $sportCategory->setAgeMax(99);

        $this->assignmentRepository->method('findBy')
            ->with(['teamId' => $team->getId(), 'seasonId' => 'season-1'])
            ->willReturn([]);

        // Base vide au premier passage, puis peuplée de CE QUI A RÉELLEMENT ÉTÉ INSÉRÉ.
        //
        // ⚠ La relecture rendait d'abord le catalogue complet quoi qu'il arrive (revue #356) :
        // lier le mauvais `club_id` dans l'insertion laissait ces tests VERTS. En dérivant la
        // réponse des paramètres capturés, le mock se comporte comme une base — il ne rend que
        // ce qu'on lui a donné, pour le club qu'on lui a nommé.
        $this->teamTagRepository->method('findBy')
            ->willReturnCallback(fn (array $criteres): array => $this->tagsInsertedFor($criteres));

        $this->sportCategoryRepository->method('find')
            ->with('cat-senior')
            ->willReturn($sportCategory);

        $persistedAssignments = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedAssignments): void {
                if ($entity instanceof TeamTagAssignment) {
                    $persistedAssignments[] = $entity;
                }
            });

        // P2-13 — `syncTeamTags` ne flushe PLUS de lui-même : le seul flush du chemin
        // servait au backfill d'axe (aucun ici, les tags du mock en ont un). Les
        // assignations partent avec le flush de fin de lot du `TeamTagSyncListener`,
        // celui que son commentaire déclare indispensable.
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        // Idem : les noms, pas le compte (revue #356).
        $tagNames = array_map(static fn (TeamTagAssignment $a): string => $a->getTagId(), $persistedAssignments);
        sort($tagNames);
        self::assertSame(['ADULTE', 'LOISIR_ADULTE', 'MASCULINE'], $tagNames);
    }

    public function testSyncTeamTagsRemovesExistingAssignments(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-1');
        $team->setGender(Gender::MIXTE);

        $existingAssignment = new TeamTagAssignment;
        $existingAssignment->setTeamId($team->getId());
        $existingAssignment->setSeasonId('season-1');
        // `tag_id` est NOT NULL en base : une assignation réelle porte toujours son tag. On pose ici
        // un id PÉRIMÉ (jamais dans l'ensemble re-dérivé) pour que le court-circuit « ensemble
        // identique » (P4/2026-09-27) NE s'active PAS — le delete+recreate reste bien exercé.
        $existingAssignment->setTagId('stale-tag-id');

        $this->assignmentRepository->method('findBy')
            ->willReturn([$existingAssignment]);

        // Doit simuler une vraie base : depuis la revue #356 le service ÉCHOUE FORT si la
        // relecture ne rend pas les tags qu'il vient d'insérer — un `willReturn([])` nu
        // décrirait une base qui perd les écritures, pas un scénario réel.
        $this->teamTagRepository->method('findBy')
            ->willReturnCallback(fn (array $criteres): array => $this->tagsInsertedFor($criteres));

        $this->sportCategoryRepository->method('find')
            ->willReturn(null);

        $removedEntities = [];
        $this->entityManager->method('remove')
            ->willReturnCallback(function ($entity) use (&$removedEntities): void {
                $removedEntities[] = $entity;
            });

        // P2-13 — `syncTeamTags` ne flushe PLUS de lui-même : le seul flush du chemin
        // servait au backfill d'axe (aucun ici, les tags du mock en ont un). Les
        // assignations partent avec le flush de fin de lot du `TeamTagSyncListener`,
        // celui que son commentaire déclare indispensable.
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        self::assertCount(1, $removedEntities);
        self::assertSame($existingAssignment, $removedEntities[0]);
    }

    public function testSyncTeamTagsCreatesMissingSystemTags(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-1');
        $team->setGender(Gender::M);

        $this->assignmentRepository->method('findBy')
            ->willReturn([]);

        // Doit simuler une vraie base : depuis la revue #356 le service ÉCHOUE FORT si la
        // relecture ne rend pas les tags qu'il vient d'insérer — un `willReturn([])` nu
        // décrirait une base qui perd les écritures, pas un scénario réel.
        $this->teamTagRepository->method('findBy')
            ->willReturnCallback(fn (array $criteres): array => $this->tagsInsertedFor($criteres));

        $this->sportCategoryRepository->method('find')
            ->willReturn(null);

        // P2-13 — `syncTeamTags` ne flushe PLUS de lui-même : le seul flush du chemin
        // servait au backfill d'axe (aucun ici, les tags du mock en ont un). Les
        // assignations partent avec le flush de fin de lot du `TeamTagSyncListener`,
        // celui que son commentaire déclare indispensable.
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        // ⚠ Les tags système ne sont plus `persist`és : depuis P4-64 ils partent en UNE
        // requête `INSERT … ON CONFLICT DO NOTHING` (`insertMissingSystemTags`), parce qu'un
        // `flush()` en violation de contrainte fermerait l'EntityManager. L'observable a donc
        // changé de nature — c'est le SQL — mais l'invariant gardé est le MÊME.
        self::assertCount(1, $this->insertStatements, 'une seule requête, pas une par tag');
        $sql = $this->insertStatements[0];

        self::assertStringContainsString('ON CONFLICT (club_id, name) DO NOTHING', $sql, 'la course doit être un no-op, pas une erreur');
        self::assertSame(
            \count(new ReflectionClass(TeamTagService::class)->getConstant('SYSTEM_TAG_AXES')),
            substr_count($sql, '(:id'),
            'un tuple par tag système déclaré — ni un de moins, ni un doublon',
        );

        // Le catalogue de tags système est semé EN ENTIER sur un club qui n'en a aucun.
        //
        // Il était asserté par un compte en dur (21) : ajouter BABY le faisait rougir pour la
        // seule raison qu'il avait bougé. Mais un premier remplacement — unicité + les quatre
        // tranches — était STRICTEMENT PLUS FAIBLE : retirer 'U21' de `$requiredTags` le
        // laissait vert (revue #352). Un tag manquant n'est pas un détail :
        // `TeamTagResolver` ne le trouve pas, log un warning et rend une liste vide — la
        // contrainte qui le cible est SILENCIEUSEMENT ignorée.
        //
        // L'invariant sans nombre magique : semer exactement les tags dont l'axe est déclaré.
        // Les deux listes vivent dans le même fichier et doivent rester jumelles.
        $params = $this->insertParams[0];

        /** @var array<string, string> $axeParNom nom du tag → axe envoyé */
        $axeParNom = [];
        foreach ($params as $cle => $valeur) {
            if (str_starts_with((string) $cle, 'name')) {
                $axeParNom[(string) $valeur] = (string) $params['axis' . substr((string) $cle, 4)];
            }
        }

        $declaredAxes = new ReflectionClass(TeamTagService::class)->getConstant('SYSTEM_TAG_AXES');
        $attendus = array_keys($declaredAxes);
        $semes = array_keys($axeParNom);
        sort($attendus);
        sort($semes);
        self::assertSame($attendus, $semes, 'on sème exactement les tags système dont l\'axe est déclaré');

        // Et chacun part avec SON axe — un tag sans axe, ou avec le mauvais, tomberait dans
        // « Autres » au sélecteur de cible de contrainte.
        foreach ($declaredAxes as $nom => $axe) {
            self::assertSame($axe->value, $axeParNom[$nom], \sprintf('axe de %s', $nom));
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('ageBracketCases')]
    public function testAgeBracketTagPerCategory(string $categoryName, ?int $ageMin, ?int $ageMax, array $expected): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-1');

        $category = new SportCategory;
        $category->setName($categoryName);
        $category->setAgeMin($ageMin);
        $category->setAgeMax($ageMax);

        $this->assignmentRepository->method('findBy')->willReturn([]);
        // Les tags système préexistent, chacun avec son NOM pour id : `syncTeamTags` écrit
        // `$systemTags[$name]->getId()` dans l'assignation, donc lire le tagId revient à
        // lire le nom du tag posé — ce que le mock ne permettait pas jusqu'ici.
        $this->teamTagRepository->method('findBy')->willReturn($this->systemTagsKeyedByName());

        $this->sportCategoryRepository->method('find')->with('cat-1')->willReturn($category);

        $assigned = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function ($entity) use (&$assigned): void {
                if ($entity instanceof TeamTagAssignment) {
                    $assigned[] = $entity->getTagId();
                }
            });

        $this->service->syncTeamTags($team, 'season-1');

        $brackets = array_values(array_intersect($assigned, ['BABY', 'EMB', 'JEUNE', 'ADULTE', 'SENIOR']));
        self::assertSame($expected, $brackets, \sprintf('tranche(s) d\'âge de « %s »', $categoryName));
    }

    /**
     * P2-13 — le flush restant a UNE seule raison d'être : le backfill d'axe sur un tag
     * antérieur au lot B (`insertMissingSystemTags` écrit en SQL brut, hors unit of work).
     * Il doit donc partir quand un axe manque, et ne plus partir sinon — sans quoi on
     * flushait une fois PAR ÉQUIPE à chaque import ou bascule de catégorie.
     */
    public function testFlushesOnlyWhenAnAxisIsBackfilled(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-u15');
        $team->setGender(Gender::F);

        // Catalogue complet, mais UN tag privé de son axe (ligne d'avant le lot B).
        $tags = $this->systemTagsKeyedByName();
        $tags[0]->setAxis(null);

        $this->assignmentRepository->method('findBy')->willReturn([]);
        $this->teamTagRepository->method('findBy')->willReturn($tags);
        $this->sportCategoryRepository->method('find')->willReturn(null);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->syncTeamTags($team, 'season-1');

        self::assertNotNull($tags[0]->getAxis(), 'l\'axe manquant doit être backfillé');
    }

    /**
     * P2-13 — l'échec fort de la relecture des tags doit précéder TOUTE destruction.
     *
     * Le `RuntimeException` de `getOrCreateSystemTags` existe pour ne pas laisser une équipe
     * sans assignations. Tant qu'il partait APRÈS la boucle de `remove()`, il laissait
     * exactement l'état qu'il prétend éviter — commité sous l'ancien flush inconditionnel,
     * simplement en attente depuis. Ce test épingle l'ORDRE : inverser les deux blocs de
     * `syncTeamTags` le fait rougir.
     */
    public function testFailsBeforeRemovingAnythingWhenSystemTagsCannotBeReadBack(): void
    {
        $team = new Team;
        $team->setClubId('club-1');
        $team->setSeasonId('season-1');
        $team->setSportCategoryId('cat-u15');
        $team->setGender(Gender::F);

        $existing = new TeamTagAssignment;
        $existing->setTeamId($team->getId());
        $existing->setSeasonId('season-1');
        $this->assignmentRepository->method('findBy')->willReturn([$existing]);

        // Une base qui PERD ses écritures : l'insertion passe, la relecture ne rend rien.
        // C'est le seul scénario qui arme la garde.
        $this->teamTagRepository->method('findBy')->willReturn([]);
        $this->sportCategoryRepository->method('find')->willReturn(null);

        $this->entityManager->expects(self::never())->method('remove');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rien n\'a encore été supprimé');

        $this->service->syncTeamTags($team, 'season-1');
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->assignmentRepository = $this->createMock(EntityRepository::class);
        $this->teamTagRepository = $this->createMock(EntityRepository::class);
        $this->sportCategoryRepository = $this->createMock(EntityRepository::class);

        // P4-64 : les tags système manquants sont désormais insérés en SQL natif
        // (`ON CONFLICT DO NOTHING`, cf. `insertMissingSystemTags`) et non plus par `persist`,
        // parce qu'un `flush()` en violation de contrainte FERMERAIT l'EntityManager. Le
        // double mock ci-dessous simule ce que fait une vraie base : la requête est capturée,
        // puis la RELECTURE rend les tags. Sans lui, le service verrait une base restée vide
        // et n'assignerait rien.
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->insertStatements[] = $sql;
                $this->insertParams[] = $params;

                return 1;
            });
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [TeamTagAssignment::class, $this->assignmentRepository],
                [TeamTag::class, $this->teamTagRepository],
                [SportCategory::class, $this->sportCategoryRepository],
            ]);

        $this->service = new TeamTagService($this->entityManager);
    }

    /**
     * Ce qu'une vraie base rendrait : les tags RÉELLEMENT insérés, pour le club demandé.
     *
     * Se substitue au retour inconditionnel du catalogue complet (revue #356) — celui-ci
     * rendait les tests aveugles à ce que l'insertion contenait vraiment, tenant compris.
     *
     * @param array<string, mixed> $criteres
     *
     * @return list<TeamTag>
     */
    private function tagsInsertedFor(array $criteres): array
    {
        $club = $criteres['clubId'] ?? null;
        $noms = null;
        if (isset($criteres['name'])) {
            $noms = \is_array($criteres['name']) ? $criteres['name'] : [$criteres['name']];
        }

        /** @var array<string, TeamTagAxis> $axes */
        $axes = new ReflectionClass(TeamTagService::class)->getConstant('SYSTEM_TAG_AXES');
        $tags = [];

        foreach ($this->insertParams as $params) {
            if (($params['club'] ?? null) !== $club) {
                continue; // insertion faite pour un AUTRE club : elle ne doit rien rendre ici
            }
            foreach ($params as $cle => $valeur) {
                if (!str_starts_with((string) $cle, 'name')) {
                    continue;
                }
                $nom = (string) $valeur;
                if (null !== $noms && !\in_array($nom, $noms, true)) {
                    continue;
                }
                $tag = new TeamTag;
                $tag->setId($nom);
                $tag->setName($nom);
                $tag->setClubId((string) $club);
                $tag->setIsSystem(true);
                $tag->setAxis($axes[$nom] ?? TeamTagAxis::AGE);
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * TOUS les tags système, id = nom pour que l'assignation soit lisible.
     *
     * ⚠ Dérivés de `SYSTEM_TAG_AXES`, jamais d'une liste écrite ici. La liste manuelle
     * précédente omettait les tags de NIVEAU (`REGIONAL`, `LOISIR_ADULTE`…) : les assignations
     * correspondantes étaient donc silencieusement sautées, et les tests comptaient une
     * assignation de moins sans que rien ne dise pourquoi. Un fixture qui recopie une liste
     * du code finit toujours par en diverger.
     *
     * @return list<TeamTag>
     */
    private function systemTagsKeyedByName(): array
    {
        $tags = [];
        /** @var array<string, TeamTagAxis> $axes */
        $axes = new ReflectionClass(TeamTagService::class)->getConstant('SYSTEM_TAG_AXES');

        foreach ($axes as $name => $axis) {
            $tag = new TeamTag;
            $tag->setId($name);
            $tag->setName($name);
            $tag->setClubId('club-1');
            $tag->setIsSystem(true);
            $tag->setAxis($axis);
            $tags[] = $tag;
        }

        return $tags;
    }
}
