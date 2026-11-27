<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Entity\Fixture;
use App\Matches\FederalFollowUp;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestRunnerTrait;
use App\Tests\FrozenSchemaDatabaseTrait;
use App\Tests\Integration\EngineRequestReferenceTest;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : les matchs d'un club écrits avant la séparation « compétition et engagement » (lot 7, PR 3b),
 * une fois la migration jouée, donnent au moteur la même demande de placement, à l'octet (axe *chaîne de
 * génération*, décision de l'orchestrateur sur la limite (f) du plan du lot 7).
 *
 * Pour chaque référence 16 à 19 de {@see EngineRequestReferenceTest} : une base neuve, copiée d'un modèle chargé
 * depuis le schéma figé d'avant la PR 3b (`tests/Fixtures/lot-7/schema-avant.sql`, table des versions comprise),
 * reçoit le décor de cette référence écrit dans ce schéma (`tests/Fixtures/lot-7/decor-avant.sql`, capturé par
 * `LOT7_DECOR_CAPTURE=1` sur le code d'avant la PR 3b) ; `doctrine:migrations:migrate` y joue ce qui manque ; puis
 * le code de la tête compose la demande de placement par le rail réel et la compare au même fichier de référence.
 *
 * La demande ne transporte aucun identifiant de compétition (`MatchPlacementPayloadBuilder`) : cette preuve dit que
 * la conversion ne perd ni ne déplace aucun match à placer. Elle ne voit pas le risque de l'import et du radar
 * (plan §8) : `CompetitionEngagementMigrationTest` garde la conversion ligne à ligne.
 *
 * Depuis la PR 4a, la chaîne joue aussi la conversion de la nature : « Amicaux » naît dans chaque saison, un amical
 * sans compétition y est versé, « Amical PNF » devient amicale — la référence 17 change en conséquence (le domicile
 * non placé de « Amical PNF » sort de la demande, celui posé par le système devient une ancre), les autres non.
 *
 * Depuis la PR 4b, un domicile de coupe n'est plus confié au moteur (Q7) : la conversion range « Coupe Territoriale
 * U13 », stockée championnat avant la PR 4a, en coupe, si bien que son domicile non placé sort AUSSI de la demande. Le
 * décor de {@see EngineRequestReferenceTest}, écrit après la conversion, la garde championnat (le nom ne décide pas) :
 * la référence 19 a donc ici son propre fichier, `placement-19-coupes-converties`, qui ne diffère de `placement-19-coupes`
 * que par ce match.
 *
 * Depuis la PR 6b, la chaîne joue aussi la conversion du suivi fédéral : le stade servi de chaque match (lu par
 * `FederalFollowUp::stageOf`) ne change pas, et les quatre références restent à l'octet — la 18, qui porte saisi et
 * validé, domicile et extérieur, avec et sans gymnase, est la preuve du point sensible (un validé sans gymnase n'est
 * jamais rendu au moteur ; un validé posé par le système de « Championnat 1 », compétition sans identifiant fédéral
 * ni match importé, non plus : voir l'écart nommé dans `FederalTrackingMigrationTest`).
 *
 * Jumeau : `Lot4MigrationChainTest` (références 1 à 15, schéma d'avant le lot 4) joue aussi cette migration ; ses
 * références 5 et 6 (placement) n'y portent que des matchs à placer ou placés.
 *
 * Bloquant (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`) : le groupe `phase1` est requis.
 */
#[Group('phase1')]
#[Group('integration')]
#[SkipDatabaseRollback]
final class Lot7MigrationChainTest extends KernelTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestRunnerTrait;
    use FrozenSchemaDatabaseTrait;

    private const string FROZEN_SCHEMA_FILE = __DIR__ . '/../../Fixtures/lot-7/schema-avant.sql';

    private const string FROZEN_DECOR_FILE = __DIR__ . '/../../Fixtures/lot-7/decor-avant.sql';

    private const string FROZEN_DATABASE_SUFFIX = 'lot7';

    /**
     * Les références du filet des matchs, et ce que la conversion doit avoir fait de leurs compétitions : par
     * compétition du décor, l'équipe qui y est engagée (une seule, Q8), et le fichier de référence attendu quand il
     * diffère du nom de la référence.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>, 2?: string}>
     */
    public static function references(): iterable
    {
        $u13 = '7ea00000-0000-4000-8000-000000000001';
        $u15 = '7ea00000-0000-4000-8000-000000000002';
        $sm1 = '7ea00000-0000-4000-8000-000000000003';
        $u13f2 = '7ea00000-0000-4000-8000-000000000016';
        $base = ['c0e90000-0000-4000-8000-000000000001' => $u13, 'c0e90000-0000-4000-8000-000000000002' => $u15, 'c0e90000-0000-4000-8000-000000000003' => $sm1];

        yield 'placement-16-deux-equipes-une-division' => ['placement-16-deux-equipes-une-division', $base + ['c0e90000-0000-4000-8000-000000000161' => $u13, 'c0e90000-0000-4000-8000-000000000162' => $u13f2]];
        yield 'placement-17-amicaux' => ['placement-17-amicaux', $base + ['c0e90000-0000-4000-8000-000000000171' => $sm1]];
        yield 'placement-18-suivi-federal' => ['placement-18-suivi-federal', $base];
        yield 'placement-19-coupes' => ['placement-19-coupes', $base + ['c0e90000-0000-4000-8000-000000000191' => $u13, 'c0e90000-0000-4000-8000-000000000192' => $u15, 'c0e90000-0000-4000-8000-000000000193' => $u13], 'placement-19-coupes-converties'];
    }

    /** @param array<string, string> $engagedTeamByCompetition */
    #[DataProvider('references')]
    public function testTheMigratedDecorGivesTheReferencePlacementRequest(string $reference, array $engagedTeamByCompetition, ?string $expectedFile = null): void
    {
        $admin = self::frozenCaseConnection();
        $fixturesBefore = $admin->fetchAllKeyValue('SELECT id, concat_ws(\'|\', team_id, competition_id) FROM fixture ORDER BY id');
        $competitionsBefore = $admin->fetchAllKeyValue('SELECT id, name FROM competition ORDER BY id');
        $stagesBefore = $admin->fetchAllKeyValue('SELECT id, status FROM fixture ORDER BY id');
        self::assertSame(array_keys($engagedTeamByCompetition), array_map(strval(...), array_keys($competitionsBefore)), 'témoin : les compétitions du décor figé sont celles attendues');

        [$code, $output, $played] = $this->runFrozenMigrations();
        self::assertSame(0, $code, 'la migration se joue sur le décor d\'avant la PR 3b : ' . $output);
        self::assertNotSame([], $played, 'la chaîne joue la migration de la PR 3b');
        self::assertNotNull($admin->fetchOne('SELECT to_regclass(\'public.engagement\')'), 'la table des engagements existe après la chaîne');

        $competitionsAfter = $admin->fetchAllKeyValue('SELECT id, name FROM competition ORDER BY id');
        self::assertSame($competitionsBefore, array_intersect_key($competitionsAfter, $competitionsBefore), 'aucune compétition ne change d\'identifiant ni de nom, aucune ne part');
        // Lot 7, PR 4a : la seule compétition qui s'ajoute est « Amicaux », la compétition d'office, une par saison.
        $added = array_diff_key($competitionsAfter, $competitionsBefore);
        self::assertSame(
            (int) $admin->fetchOne('SELECT COUNT(*) FROM season'),
            (int) $admin->fetchOne('SELECT COUNT(*) FROM competition WHERE default_friendly AND name = \'Amicaux\' AND competition_type = \'FRIENDLY\''),
            'une compétition d\'office « Amicaux » par saison',
        );
        self::assertSame(array_fill_keys(array_keys($added), 'Amicaux'), $added, 'la conversion n\'ajoute que la compétition d\'office');
        $engagements = array_map(strval(...), $admin->fetchAllKeyValue('SELECT competition_id, team_id FROM engagement WHERE competition_id NOT IN (SELECT id FROM competition WHERE default_friendly) ORDER BY competition_id'));
        self::assertSame($engagedTeamByCompetition, $engagements, 'un engagement par compétition du décor, celui de son ancienne équipe');
        $amicaux = $admin->fetchOne('SELECT id FROM competition WHERE default_friendly LIMIT 1');
        $fixturesAfter = $admin->fetchAllKeyValue('SELECT id, concat_ws(\'|\', team_id, competition_id) FROM fixture ORDER BY id');
        foreach ($fixturesBefore as $id => $teamAndCompetition) {
            // Avant la PR 4a, un amical n'a pas de compétition (`concat_ws` saute le nul) : il est versé dans « Amicaux ».
            $expected = str_contains((string) $teamAndCompetition, '|') ? $teamAndCompetition : $teamAndCompetition . '|' . $amicaux;
            self::assertSame($expected, $fixturesAfter[$id] ?? null, \sprintf('le match %s garde son équipe et sa compétition ; un amical sans compétition est versé dans « Amicaux »', $id));
        }
        self::assertSame(array_keys($fixturesBefore), array_keys($fixturesAfter), 'aucun match ne s\'ajoute ni ne part');

        // Lot 7, PR 6b : le suivi fédéral quitte le match ; le stade que l'API sert, lu par la porte, ne change pour
        // aucun match du décor — la référence 18 porte les quatre stades, domicile et extérieur.
        $this->scopeGucToClub(self::ER_CLUB);
        $followUp = self::getContainer()->get(FederalFollowUp::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($stagesBefore as $id => $stage) {
            $fixture = $em->find(Fixture::class, (string) $id);
            self::assertInstanceOf(Fixture::class, $fixture, \sprintf('le match %s est lisible', $id));
            self::assertSame($stage, $followUp->stageOf($fixture)->value, \sprintf('le match %s garde le stade servi', $id));
        }

        $this->scopeGucToClub(self::ER_CLUB);
        $this->assertReference($expectedFile ?? $reference, $this->runPlacement(null));
    }

    protected function setUp(): void
    {
        $reference = $this->providedData()[0] ?? null;
        self::assertIsString($reference);
        $this->setUpFrozenCase($reference);
        $this->pinEngineRequestClock();
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->unpinEngineRequestClock();
        }
        $this->tearDownFrozenCase();
        parent::tearDown();
    }
}
