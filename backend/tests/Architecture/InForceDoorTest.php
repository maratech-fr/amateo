<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\VenueOccupancy;
use App\Entity\VenueTrainingSlot;
use App\Structure\Family;
use App\Structure\Level;
use App\Tests\Integration\EngineRequestReferenceTest;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use SplFileInfo;

/**
 * Promesse : les lecteurs de l'en vigueur que le plan du lot 5 nomme (radar, placement des matchs, conflits de
 * personnes, demande d'une période, sélection des règles d'une période, naissance d'un plan, lignes des plans, et
 * les sept lectures du pointeur) ne lisent ni le pointeur du socle ni une famille froide autrement que par la porte
 * `App\Plans\InForce` — sans quoi « la porte lit la copie » (PR 5) laisserait un lecteur sur l'état de travail.
 *
 * Deux listes, chaque lecteur avec sa portée (une méthode et les méthodes de sa classe qu'elle appelle, `$this->m(`,
 * `self::m(`, `static::m(`, `$this->m(...)` ; ou la classe entière, constructeur excepté) :
 *  - les lecteurs du froid : aucune lecture directe des familles froides de leur ensemble, ni du pointeur ;
 *  - les sept lectures du pointeur : aucune lecture directe du pointeur du socle.
 *
 * Les ensembles se lisent sur les déclarations `#[Family]`, jamais sur une liste tenue ici :
 *  - `froid du socle` : niveau saison, `ofSocle`, `cold` non vide (équipe, gymnase, coach principal, passerelle,
 *    coach-joueur, contrainte permanente) ;
 *  - `froid copié à la naissance` : niveau plan, `copiedAtPlanBirth`, `cold` non vide (grille, groupes et leurs
 *    membres, bien-être ; les occupations, libres, n'y sont pas).
 *
 * Formes refusées dans la portée (commentaires retirés) :
 *  - `F::class` d'une famille de l'ensemble, sauf en argument de `rows(` (la porte) ou derrière `case` ;
 *  - une propriété typée par le dépôt d'une famille de l'ensemble (`TeamRepository $teamRepository`), utilisée
 *    par `$this->teamRepository` ;
 *  - une chaîne SQL `FROM` / `JOIN` sur la table d'une famille de l'ensemble, ou une chaîne DQL `App\Entity\F` ;
 *  - le pointeur du socle : un appel `chosenOfSeasonPlan(`, `chosenScheduleIdOfPlan(`, `getChosenScheduleId(`,
 *    une chaîne qui nomme `chosen_schedule_id` ou `.chosenScheduleId`.
 *
 * Exceptions, chacune pour une raison de structure :
 *  - le pointeur d'une période (`chosenOfPeriodPlan`, `chosenByPeriodPlans`) : la porte ne couvre que le socle
 *    (écart E4 du plan) ;
 *  - une famille dont les lignes froides se disent par une valeur nulle (`coldRows: ['calendarEntryId' => null]`,
 *    les contraintes permanentes) : une lecture dont l'instruction nomme cette clé sans `null` ni `IS NULL` lit
 *    les lignes datées, qui ne sont pas froides (`findBy(['calendarEntryId' => $ids])`).
 *
 * Limites écrites : le balayage ne suit pas un appel vers un autre service (`TeamTagResolver`,
 * `CoachDoubleBookingDetector`, `PlanVenueClosures`…), ni une classe passée par une variable
 * (`$f = Team::class; getRepository($f)`), ni la ligne d'équipe du socle qui sert de modèle à la ligne d'une période
 * dans `ensureLines` (`plan_team` est lue d'une requête pour tous les plans) ; une porte qui rend une autre ligne est
 * vue par les références de demande au moteur ({@see EngineRequestReferenceTest}) et, en
 * PR 5, par les tests de la porte.
 * Les derniers cas prouvent que chaque forme est vue sur un extrait positif et qu'une forme propre passe.
 */
#[Group('phase1')]
final class InForceDoorTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../src';

    private const string DOOR = 'App\Plans\InForce';

    private const string SOCLE = 'froid du socle';

    private const string SOCLE_AND_ITS_GROUPS = 'froid du socle et groupes du socle';

    private const string BORN = 'froid copié à la naissance';

    private const string POINTER = 'pointeur du socle lu en direct';

    private const string COLD = 'famille froide lue hors de la porte';

    /**
     * Les lecteurs du froid : fichier sous `src/` => [méthodes racines (null = la classe entière), ensemble].
     *
     * @var array<string, array{0: list<string>|null, 1: string}>
     */
    private const array COLD_READERS = [
        'Service/ConflictRadarLoader.php' => [['conflicts'], self::SOCLE],
        'Service/MatchPlacementPayloadBuilder.php' => [['build'], self::SOCLE],
        'Service/PlacedSessionPersonConflictDetector.php' => [null, self::SOCLE_AND_ITS_GROUPS],
        'Service/ScheduleConstraintBuilder.php' => [['buildForPeriod'], self::SOCLE],
        'Service/PeriodConstraintSelector.php' => [null, self::SOCLE],
        'Plans/PlanBirth.php' => [['copy'], self::BORN],
        'Plans/PlanComposition.php' => [['ensureLines'], self::SOCLE],
    ];

    /**
     * Les sept lectures du pointeur (plan du lot 5, §2) : la classe entière.
     *
     * @var list<string>
     */
    private const array POINTER_READERS = [
        'Service/PlacedSessionPersonConflictDetector.php',
        'Controller/CalendarEntryConflictsController.php',
        'Service/MatchModuleDeltaComputer.php',
        'Service/EngineRequestComposer.php',
        'Service/PeriodPlanTranscriber.php',
        'Controller/SocleDeviationController.php',
        'Service/EngineRequestRecorder.php',
    ];

    /** @var list<string> */
    private const array POINTER_CALLS = ['chosenOfSeasonPlan', 'chosenScheduleIdOfPlan', 'getChosenScheduleId'];

    /** @var list<string> */
    private const array POINTER_STRING_PATTERNS = ['/\bchosen_schedule_id\b/i', '/\.chosenScheduleId\b/'];

    /** @var array<string, array{level: Level, cold: list<string>|null, coldRows: array<string, mixed>, ofSocle: bool, copiedAtPlanBirth: ?bool, table: ?string, repository: ?string}>|null */
    private static ?array $families = null;

    /** @return iterable<string, array{0: string, 1: string, 2: string}> extrait, ensemble, forme attendue */
    public static function forbiddenForms(): iterable
    {
        yield 'dépôt par l\'EntityManager' => ['return $this->em->getRepository(Team::class)->findBy([]);', self::SOCLE, self::COLD . ' (Team)'];
        yield 'aide du lecteur' => ['return $this->findByClubSeason(TeamCoach::class, $c, $s, $em);', self::SOCLE, self::COLD . ' (TeamCoach)'];
        yield 'DQL par concaténation' => ['return $this->em->createQuery(\'SELECT t FROM \' . Team::class . \' t\')->getResult();', self::SOCLE, self::COLD . ' (Team)'];
        yield 'DQL en chaîne' => ['return $this->em->createQuery(\'SELECT l FROM App\Entity\TeamLink l\')->getResult();', self::SOCLE, self::COLD . ' (TeamLink)'];
        yield 'dépôt injecté' => ['return $this->teamRepository->findBy([\'clubId\' => $c]);', self::SOCLE, self::COLD . ' (Team)'];
        yield 'règles permanentes par le dépôt injecté' => ['return $this->constraintRepository->findPermanentByClubSeason($c, $s);', self::SOCLE, self::COLD . ' (Constraint)'];
        yield 'toutes les règles' => ['return $this->em->getRepository(Constraint::class)->findBy($scope);', self::SOCLE, self::COLD . ' (Constraint)'];
        yield 'SQL FROM' => ['return $this->connection->fetchAllAssociative(\'SELECT id FROM team WHERE season_id = :s\');', self::SOCLE, self::COLD . ' (Team)'];
        yield 'SQL JOIN' => ['return $this->connection->fetchAllAssociative(\'SELECT 1 FROM plan_team l JOIN team_coach c ON c.team_id = l.team_id\');', self::SOCLE, self::COLD . ' (TeamCoach)'];
        yield 'SQL règles permanentes' => ['return $this->connection->fetchAllAssociative(\'SELECT id FROM "constraint" WHERE calendar_entry_id IS NULL\');', self::SOCLE, self::COLD . ' (Constraint)'];
        yield 'SQL en heredoc' => ["return \$this->connection->fetchAllAssociative(<<<SQL\n  SELECT id FROM venue WHERE season_id = {\$s}\n  SQL);", self::SOCLE, self::COLD . ' (Venue)'];
        yield 'grille copiée en SQL' => ['$this->connection->executeStatement(\'INSERT INTO venue_training_slot (id) SELECT gen_random_uuid() FROM venue_training_slot WHERE schedule_plan_id = :p\'); return [];', self::BORN, self::COLD . ' (VenueTrainingSlot)'];
        yield 'aide privée appelée' => ['return $this->helper();', self::SOCLE, self::COLD . ' (CoachPlayerMembership)'];
        yield 'aide privée en callable' => ['return array_map($this->helper(...), []);', self::SOCLE, self::COLD . ' (CoachPlayerMembership)'];
        yield 'pointeur par le provisionneur' => ['return [$this->provisioner->chosenOfSeasonPlan($s)];', self::SOCLE, self::POINTER];
        yield 'pointeur d\'un plan' => ['return [$this->provisioner->chosenScheduleIdOfPlan($p)];', self::SOCLE, self::POINTER];
        yield 'pointeur par l\'entité' => ['return [$plan?->getChosenScheduleId()];', self::SOCLE, self::POINTER];
        yield 'pointeur en SQL' => ['return [$this->connection->fetchOne(\'SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :s\')];', self::SOCLE, self::POINTER];
        yield 'pointeur en DQL' => ['return $this->em->createQuery(\'SELECT p.chosenScheduleId FROM App\Entity\SchedulePlan p\')->getResult();', self::SOCLE, self::POINTER];
    }

    /** @return iterable<string, array{0: string, 1: string}> extrait, ensemble */
    public static function allowedForms(): iterable
    {
        yield 'par la porte' => ['return $this->inForce->rows($s, Team::class);', self::SOCLE];
        yield 'par la porte, imbriquée' => ['return array_map(static fn (object $t): object => $t, $this->inForce->rows($s, TeamCoach::class));', self::SOCLE];
        yield 'pointeur par la porte' => ['return [$this->inForce->socleVersionId($s)];', self::SOCLE];
        yield 'aiguillage par famille' => ["switch (\$f) {\n    case VenueTrainingSlot::class:\n        break;\n}\nreturn [];", self::BORN];
        yield 'règles datées' => ['return $this->constraintRepository->findBy([\'calendarEntryId\' => $ids, \'clubId\' => $c]);', self::SOCLE];
        yield 'toutes les règles datées' => ['return $this->em->createQuery(\'SELECT c FROM \' . Constraint::class . \' c WHERE c.calendarEntryId IS NOT NULL\')->getResult();', self::SOCLE];
        yield 'famille libre' => ['return $this->em->getRepository(Coach::class)->findBy([]);', self::SOCLE];
        yield 'lignes d\'un plan hors de l\'ensemble' => ['return $this->connection->fetchAllAssociative(\'SELECT team_id FROM plan_team WHERE season_id = :s\');', self::SOCLE];
        yield 'famille hors de l\'ensemble' => ['return $this->em->getRepository(Team::class)->findBy([]);', self::BORN];
        yield 'pointeur d\'une période' => ['return [$this->provisioner->chosenOfPeriodPlan($e), $this->provisioner->chosenByPeriodPlans([$e])];', self::SOCLE];
        yield 'clé de photo' => ['return [\'chosenScheduleId\' => $this->inForce->socleVersionId($s)];', self::SOCLE];
        yield 'commentaire' => ["// l'ancienne lecture : chosenOfSeasonPlan(\$s), getRepository(Team::class), FROM team\nreturn [];", self::SOCLE];
    }

    public function testTheDoorExistsWithItsContract(): void
    {
        self::assertTrue(class_exists(self::DOOR), 'la porte App\Plans\InForce existe (plan du lot 5, §5, PR 4)');

        $socleVersionId = new ReflectionMethod(self::DOOR, 'socleVersionId');
        self::assertTrue($socleVersionId->isPublic());
        self::assertSame(['?string'], $this->parameterTypes($socleVersionId), 'socleVersionId(?string $seasonId)');
        self::assertSame('?string', (string) $socleVersionId->getReturnType(), 'socleVersionId(…): ?string');

        $rows = new ReflectionMethod(self::DOOR, 'rows');
        self::assertTrue($rows->isPublic());
        self::assertSame(['string', 'string'], $this->parameterTypes($rows), 'rows(string $seasonId, string $familyClass)');
        self::assertSame('array', (string) $rows->getReturnType(), 'rows(…): list<object>');
    }

    public function testTheFamilySetsAreReadOnTheDeclarations(): void
    {
        $socle = $this->familiesOf(self::SOCLE);
        self::assertContains(Team::class, $socle, 'témoin : une famille froide du socle est vue');
        self::assertContains(Constraint::class, $socle, 'témoin : les contraintes permanentes sont froides du socle');
        self::assertNotContains(Coach::class, $socle, 'témoin : une famille libre n\'y est pas');

        $born = $this->familiesOf(self::BORN);
        self::assertContains(VenueTrainingSlot::class, $born, 'témoin : la grille est copiée à la naissance');
        self::assertNotContains(VenueOccupancy::class, $born, 'témoin : les occupations, libres, n\'y sont pas');

        self::assertContains(SharedTrainingBlockTeam::class, $this->familiesOf(self::SOCLE_AND_ITS_GROUPS));
    }

    public function testNoNamedReaderReadsThePointerOrAColdFamilyOutsideTheDoor(): void
    {
        $found = [];
        foreach (self::COLD_READERS as $relative => [$roots, $set]) {
            foreach ($this->violationsIn($this->sourceOf($relative), $this->familiesOf($set), $roots) as [$line, $form, $where]) {
                $found[] = \sprintf('src/%s:%d (%s) — %s', $relative, $line, $where, $form);
            }
        }
        foreach (self::POINTER_READERS as $relative) {
            foreach ($this->violationsIn($this->sourceOf($relative), [], null) as [$line, $form, $where]) {
                $found[] = \sprintf('src/%s:%d (%s) — %s', $relative, $line, $where, $form);
            }
        }
        $found = array_values(array_unique($found));

        self::assertSame([], $found, \sprintf(
            "Ces lecteurs de l'en vigueur lisent encore le pointeur ou une famille froide hors de la porte :\n  - %s\n"
            . "Lire par App\\Plans\\InForce : socleVersionId(\$seasonId) pour le pointeur, rows(\$seasonId, F::class)\n"
            . 'pour une famille froide. Sinon, en PR 5, ce lecteur resterait sur l\'état de travail.',
            implode("\n  - ", $found),
        ));
    }

    #[DataProvider('forbiddenForms')]
    public function testTheSweepSeesEveryForbiddenForm(string $body, string $set, string $form): void
    {
        self::assertContains($form, array_column($this->violationsIn($this->example($body), $this->familiesOf($set), ['read']), 1), 'la forme interdite est vue');
    }

    #[DataProvider('allowedForms')]
    public function testTheSweepLetsCleanFormsThrough(string $body, string $set): void
    {
        self::assertSame([], $this->violationsIn($this->example($body), $this->familiesOf($set), ['read']), 'la forme propre passe');
    }

    public function testAMethodOutsideTheReaderIsNotSwept(): void
    {
        $code = $this->example('return [];', 'public function elsewhere(): array { return $this->em->getRepository(Team::class)->findBy([]); }');

        self::assertSame([], $this->violationsIn($code, $this->familiesOf(self::SOCLE), ['read']), 'une méthode que le lecteur n\'appelle pas n\'est pas balayée');
        self::assertNotSame([], $this->violationsIn($code, $this->familiesOf(self::SOCLE), null), 'la classe entière la balaie');
    }

    public function testARenamedRootIsSeen(): void
    {
        self::assertContains('méthode racine absente : gone', array_column($this->violationsIn($this->example('return [];'), [], ['gone']), 1));
    }

    /**
     * Les déclarations des entités de `src/Entity` : famille, table, dépôt.
     *
     * @return array<string, array{level: Level, cold: list<string>|null, coldRows: array<string, mixed>, ofSocle: bool, copiedAtPlanBirth: ?bool, table: ?string, repository: ?string}>
     */
    private function families(): array
    {
        if (null !== self::$families) {
            return self::$families;
        }

        $root = realpath(self::SRC . '/Entity');
        self::assertIsString($root);
        $families = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $class = 'App\Entity\\' . str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $declared = $reflection->getAttributes(Family::class);
            if ([] === $declared) {
                continue;
            }
            $family = $declared[0]->newInstance();
            $table = $reflection->getAttributes(ORM\Table::class)[0] ?? null;
            $entity = $reflection->getAttributes(ORM\Entity::class)[0] ?? null;
            $tableName = $table?->getArguments()['name'] ?? null;
            $repository = $entity?->getArguments()['repositoryClass'] ?? null;
            $families[$class] = [
                'level' => $family->level,
                'cold' => $family->cold,
                'coldRows' => $family->coldRows,
                'ofSocle' => $family->ofSocle,
                'copiedAtPlanBirth' => $family->copiedAtPlanBirth,
                'table' => \is_string($tableName) ? trim($tableName, '`"') : null,
                'repository' => \is_string($repository) ? $repository : null,
            ];
        }
        self::assertGreaterThan(30, \count($families), 'témoin : les déclarations de src/Entity sont lues');

        return self::$families = $families;
    }

    /**
     * Une classe d'exemple : `read` est la racine, `helper` une aide privée qui lit un coach-joueur.
     */
    private function example(string $body, string $extra = ''): string
    {
        return <<<PHP
            <?php
            namespace App\\Service;

            use App\\Entity\\Coach;
            use App\\Entity\\CoachPlayerMembership;
            use App\\Entity\\Constraint;
            use App\\Entity\\Team;
            use App\\Entity\\TeamCoach;
            use App\\Entity\\VenueTrainingSlot;
            use App\\Repository\\ConstraintRepository;
            use App\\Repository\\TeamRepository;

            final class Exemple
            {
                public function __construct(
                    private readonly TeamRepository \$teamRepository,
                    private readonly ConstraintRepository \$constraintRepository,
                ) {}

                public function read(string \$c, string \$s, string \$p, string \$e, string \$f, array \$ids, array \$scope, ?object \$plan, object \$em): array
                {
                    {$body}
                }

                private function helper(): array
                {
                    return \$this->em->getRepository(CoachPlayerMembership::class)->findBy([]);
                }

                {$extra}
            }
            PHP;
    }

    /**
     * Les violations d'un fichier dans la portée des racines : [ligne, forme, méthode].
     *
     * @param list<class-string> $families
     * @param list<string>|null  $roots    null = toutes les méthodes, constructeur excepté
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function violationsIn(string $code, array $families, ?array $roots): array
    {
        $tokens = $this->significantTokens($code);
        $uses = $this->useMap($tokens);
        $methods = $this->methodsOf($tokens);

        $violations = [];
        $scope = [];
        if (null === $roots) {
            $scope = array_values(array_diff(array_keys($methods), ['__construct']));
        } else {
            foreach ($roots as $root) {
                if (!isset($methods[$root])) {
                    $violations[] = [0, 'méthode racine absente : ' . $root, $root];
                }
            }
            $scope = $this->closureOf(array_values(array_intersect($roots, array_keys($methods))), $methods, $tokens);
        }

        $repositories = $this->repositoryProperties($tokens, $uses, $families);
        $statements = $this->statementsOf($tokens);

        foreach ($scope as $method) {
            [$start, $end] = $methods[$method];
            for ($i = $start; $i <= $end; ++$i) {
                [$id, $text, $line] = $tokens[$i];

                if ($this->isPointerCall($tokens, $i)) {
                    $violations[] = [$line, self::POINTER, $method];
                }
                if (\in_array($id, [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    foreach (self::POINTER_STRING_PATTERNS as $pattern) {
                        if (1 === preg_match($pattern, $text)) {
                            $violations[] = [$line, self::POINTER, $method];
                        }
                    }
                    foreach ($families as $family) {
                        if ($this->stringReads($text, $family) && !$this->readsNonColdRows($family, $statements[$i])) {
                            $violations[] = [$line, $this->coldForm($family), $method];
                        }
                    }
                }

                $class = $this->classConstantAt($tokens, $i, $uses);
                if (null !== $class && \in_array($class, $families, true)
                    && \T_CASE !== ($tokens[$i - 1][0] ?? null)
                    && 'rows' !== $this->calleeAround($tokens, $i)
                    && !$this->readsNonColdRows($class, $statements[$i])) {
                    $violations[] = [$line, $this->coldForm($class), $method];
                }

                if (\T_VARIABLE === $id && '$this' === $text
                    && \in_array($tokens[$i + 1][0] ?? null, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)
                    && isset($tokens[$i + 2], $repositories[$tokens[$i + 2][1]])
                    && '(' !== ($tokens[$i + 3][1] ?? null)
                    && !$this->readsNonColdRows($repositories[$tokens[$i + 2][1]], $statements[$i])) {
                    $violations[] = [$line, $this->coldForm($repositories[$tokens[$i + 2][1]]), $method];
                }
            }
        }

        usort($violations, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $violations;
    }

    /** @param class-string $family */
    private function coldForm(string $family): string
    {
        return \sprintf('%s (%s)', self::COLD, substr($family, (int) strrpos($family, '\\') + 1));
    }

    /**
     * Une chaîne SQL qui lit la table de la famille (`FROM` / `JOIN`), ou une chaîne DQL qui la nomme.
     *
     * @param class-string $family
     */
    private function stringReads(string $text, string $family): bool
    {
        $declaration = $this->families()[$family];
        if (null !== $declaration['table']
            && 1 === preg_match('/\b(?:FROM|JOIN)\s+["`]?' . preg_quote($declaration['table'], '/') . '["`]?(?!\w)/i', $text)) {
            return true;
        }

        return 1 === preg_match('/' . preg_quote($family, '/') . '(?![\w\\\\])/', $text);
    }

    /**
     * L'instruction lit-elle des lignes qui ne sont pas froides ? Seulement pour une famille dont les lignes froides
     * se disent par une valeur nulle : l'instruction nomme la clé, sans `null` ni `IS NULL`.
     *
     * @param class-string                       $family
     * @param array{text: string, hasNull: bool} $statement
     */
    private function readsNonColdRows(string $family, array $statement): bool
    {
        if ($statement['hasNull']) {
            return false;
        }
        foreach ($this->families()[$family]['coldRows'] as $key => $value) {
            if (null !== $value) {
                continue;
            }
            $snake = strtolower((string) preg_replace('/[A-Z]/', '_$0', $key));
            if (1 === preg_match('/\b(?:' . preg_quote($key, '/') . '|' . preg_quote($snake, '/') . ')\b/', $statement['text'])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: int, 1: string, 2: int}> $tokens */
    private function isPointerCall(array $tokens, int $i): bool
    {
        return \T_STRING === $tokens[$i][0]
            && \in_array($tokens[$i][1], self::POINTER_CALLS, true)
            && \in_array($tokens[$i - 1][0] ?? null, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)
            && '(' === ($tokens[$i + 1][1] ?? null);
    }

    /**
     * La classe nommée par `X::class` en position `$i`, résolue sur les `use` du fichier ; null sinon.
     *
     * @param list<array{0: int, 1: string, 2: int}>                   $tokens
     * @param array{namespace: string, aliases: array<string, string>} $uses
     */
    private function classConstantAt(array $tokens, int $i, array $uses): ?string
    {
        if (!\in_array($tokens[$i][0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
            || \T_DOUBLE_COLON !== ($tokens[$i + 1][0] ?? null)
            || \T_CLASS !== ($tokens[$i + 2][0] ?? null)) {
            return null;
        }

        return $this->resolve($tokens[$i][1], $uses);
    }

    /**
     * Le nom de la fonction dont la position `$i` est un argument (parenthèse ouvrante la plus proche, les crochets
     * d'un tableau traversés) ; null hors de tout appel.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private function calleeAround(array $tokens, int $i): ?string
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; --$j) {
            $text = $tokens[$j][1];
            if (')' === $text || ']' === $text) {
                ++$depth;
            } elseif ('(' === $text || '[' === $text) {
                if ($depth > 0) {
                    --$depth;
                } elseif ('(' === $text) {
                    return \T_STRING === ($tokens[$j - 1][0] ?? null) ? $tokens[$j - 1][1] : null;
                }
            } elseif (0 === $depth && (';' === $text || '{' === $text || '}' === $text)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Les propriétés typées par le dépôt d'une famille de l'ensemble : nom => famille.
     *
     * @param list<array{0: int, 1: string, 2: int}>                   $tokens
     * @param array{namespace: string, aliases: array<string, string>} $uses
     * @param list<class-string>                                       $families
     *
     * @return array<string, class-string>
     */
    private function repositoryProperties(array $tokens, array $uses, array $families): array
    {
        $familyOfRepository = [];
        foreach ($families as $family) {
            $repository = $this->families()[$family]['repository'];
            if (null !== $repository) {
                $familyOfRepository[$repository] = $family;
            }
        }

        $properties = [];
        foreach ($tokens as $i => [$id, $text]) {
            if (\T_VARIABLE !== $id || 0 === $i
                || !\in_array($tokens[$i - 1][0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $family = $familyOfRepository[$this->resolve($tokens[$i - 1][1], $uses)] ?? null;
            if (null !== $family) {
                $properties[substr($text, 1)] = $family;
            }
        }

        return $properties;
    }

    /**
     * Les méthodes de la première classe du fichier : nom => [premier, dernier jeton du corps].
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function methodsOf(array $tokens): array
    {
        $methods = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (\T_FUNCTION !== $tokens[$i][0] || \T_STRING !== ($tokens[$i + 1][0] ?? null)) {
                continue;
            }
            $name = $tokens[$i + 1][1];
            $j = $i + 2;
            while ($j < $count && '{' !== $tokens[$j][1] && ';' !== $tokens[$j][1]) {
                ++$j;
            }
            if ($j >= $count || ';' === $tokens[$j][1]) {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < $count; ++$k) {
                if ('{' === $tokens[$k][1] || \in_array($tokens[$k][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    ++$depth;
                } elseif ('}' === $tokens[$k][1] && 0 === --$depth) {
                    break;
                }
            }
            $methods[$name] ??= [$j, min($k, $count - 1)];
            $i = $k;
        }

        return $methods;
    }

    /**
     * Les racines et les méthodes de la classe qu'elles appellent, de proche en proche.
     *
     * @param list<string>                           $roots
     * @param array<string, array{0: int, 1: int}>   $methods
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     *
     * @return list<string>
     */
    private function closureOf(array $roots, array $methods, array $tokens): array
    {
        $seen = array_fill_keys($roots, true);
        $pending = $roots;
        while ([] !== $pending) {
            [$start, $end] = $methods[array_pop($pending)];
            for ($i = $start; $i <= $end; ++$i) {
                $receiver = $tokens[$i];
                $isThis = \T_VARIABLE === $receiver[0] && '$this' === $receiver[1]
                    && \in_array($tokens[$i + 1][0] ?? null, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);
                $isSelf = (\T_STATIC === $receiver[0] || (\T_STRING === $receiver[0] && 'self' === $receiver[1]))
                    && \T_DOUBLE_COLON === ($tokens[$i + 1][0] ?? null);
                if (!$isThis && !$isSelf) {
                    continue;
                }
                $name = $tokens[$i + 2][1] ?? '';
                if (\T_STRING === ($tokens[$i + 2][0] ?? null) && '(' === ($tokens[$i + 3][1] ?? null)
                    && isset($methods[$name]) && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $pending[] = $name;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Pour chaque jeton, le texte de son instruction (coupée aux `;`, `{`, `}`) et si elle porte `null` ou `IS NULL`.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     *
     * @return array<int, array{text: string, hasNull: bool}>
     */
    private function statementsOf(array $tokens): array
    {
        $byToken = [];
        $current = [];
        $flush = static function (array $indexes) use (&$byToken, $tokens): void {
            $text = implode(' ', array_map(static fn (int $k): string => $tokens[$k][1], $indexes));
            $hasNull = 1 === preg_match('/\bIS\s+NULL\b/i', $text);
            foreach ($indexes as $k) {
                if (\T_STRING === $tokens[$k][0] && 'null' === strtolower($tokens[$k][1])) {
                    $hasNull = true;
                }
            }
            foreach ($indexes as $k) {
                $byToken[$k] = ['text' => $text, 'hasNull' => $hasNull];
            }
        };
        foreach ($tokens as $i => [$id, $text]) {
            $current[] = $i;
            if (';' === $text || '{' === $text || '}' === $text || \in_array($id, [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $flush($current);
                $current = [];
            }
        }
        $flush($current);

        return $byToken;
    }

    /**
     * L'espace de noms et les `use` de premier niveau, lus avant la classe.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     *
     * @return array{namespace: string, aliases: array<string, string>}
     */
    private function useMap(array $tokens): array
    {
        $namespace = '';
        $aliases = [];
        foreach ($tokens as $i => [$id]) {
            if (\T_CLASS === $id && \T_DOUBLE_COLON !== ($tokens[$i - 1][0] ?? null)) {
                break;
            }
            if (\T_NAMESPACE === $id) {
                $namespace = $tokens[$i + 1][1] ?? '';
            }
            if (\T_USE === $id && \in_array($tokens[$i + 1][0] ?? null, [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_STRING], true)) {
                $name = ltrim($tokens[$i + 1][1], '\\');
                $alias = \T_AS === ($tokens[$i + 2][0] ?? null) ? $tokens[$i + 3][1] : substr($name, (int) strrpos('\\' . $name, '\\'));
                $aliases[$alias] = $name;
            }
        }

        return ['namespace' => $namespace, 'aliases' => $aliases];
    }

    /** @param array{namespace: string, aliases: array<string, string>} $uses */
    private function resolve(string $name, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = explode('\\', $name, 2);
        if (isset($uses['aliases'][$first[0]])) {
            return $uses['aliases'][$first[0]] . (isset($first[1]) ? '\\' . $first[1] : '');
        }

        return '' === $uses['namespace'] ? $name : $uses['namespace'] . '\\' . $name;
    }

    /**
     * Les jetons du code, blancs et commentaires retirés : [id (0 pour un caractère), texte, ligne].
     *
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private function significantTokens(string $code): array
    {
        $line = 1;
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            [$id, $text] = \is_array($token) ? [$token[0], $token[1]] : [0, $token];
            if (\is_array($token)) {
                $line = $token[2];
            }
            if (!\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $tokens[] = [$id, $text, $line];
            }
            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    /** @return list<string> */
    private function parameterTypes(ReflectionMethod $method): array
    {
        return array_map(static function (ReflectionParameter $parameter): string {
            $type = $parameter->getType();

            return $type instanceof ReflectionNamedType ? ($type->allowsNull() ? '?' : '') . $type->getName() : (string) $type;
        }, $method->getParameters());
    }

    /** @return list<class-string> */
    private function familiesOf(string $set): array
    {
        $found = [];
        foreach ($this->families() as $class => $family) {
            $hasCold = null !== $family['cold'] && [] !== $family['cold'];
            $inSet = match ($set) {
                self::SOCLE => $hasCold && Level::SEASON === $family['level'] && $family['ofSocle'],
                self::SOCLE_AND_ITS_GROUPS => ($hasCold && Level::SEASON === $family['level'] && $family['ofSocle']) || SharedTrainingBlockTeam::class === $class,
                self::BORN => $hasCold && Level::PLAN === $family['level'] && true === $family['copiedAtPlanBirth'],
                default => false,
            };
            if ($inSet) {
                $found[] = $class;
            }
        }

        return $found;
    }

    private function sourceOf(string $relative): string
    {
        $path = self::SRC . '/' . $relative;
        self::assertFileExists($path, 'le lecteur nommé existe : ' . $relative);

        return (string) file_get_contents($path);
    }
}
