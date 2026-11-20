<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EngineRequestFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : une demande au moteur s'écrit sur disque sous une forme que `diff -r` compare ligne à
 * ligne, qui NOMME ce qui a changé, et qui ne laisse en clair aucun nom (équipe, entraîneur, gymnase,
 * club, adversaire, libellé libre).
 *
 * Le format (`EngineRequestFingerprint::render`) :
 *   - une ligne par feuille, `<chemin>\t<valeur>`, lignes triées (ordre d'octets), fichier terminé par
 *     un saut de ligne ;
 *   - chemin : clés séparées par `.` ; un élément de liste désigné par son `id` (`teams[<id>]`), par
 *     `<id>#1`, `<id>#2`… quand l'id se répète dans la liste, par son index (`[0]`) quand il n'en a pas ;
 *   - une ligne `<chemin>#order\t<désignations jointes par ,>` par liste (vide pour une liste vide) :
 *     l'ordre d'une liste se lit sur cette seule ligne ;
 *   - en clair : entiers, flottants (forme JSON, `1.0` garde sa décimale), `true`/`false`, `null`,
 *     UUID, heures (`18:00`, `18:00:00`), dates (`2026-01-31`), énumérations du contrat (`HARD`,
 *     `PREFERRED`, `TEAM_COACH`, `TO_PLACE`…), version du contrat ; toute autre chaîne devient
 *     `sha256:<empreinte hexadécimale de la chaîne>`.
 *
 * Jumeau : `Integration/Command/EngineRequestsRecordCommandTest` prouve que les fichiers écrits par
 * `app:engine-requests:record` sont ce format appliqué aux vraies demandes ; celui-ci en prouve les cas.
 */
final class EngineRequestFingerprintTest extends TestCase
{
    private const string TEAM_A = '7ea00000-0000-4000-8000-000000000001';
    private const string TEAM_B = '7ea00000-0000-4000-8000-000000000002';

    /** @return iterable<string, array{mixed, string}> */
    public static function clearValues(): iterable
    {
        yield 'entier' => [90, '90'];
        yield 'flottant' => [0.5, '0.5'];
        yield 'flottant rond' => [1.0, '1.0'];
        yield 'vrai' => [true, 'true'];
        yield 'faux' => [false, 'false'];
        yield 'null' => [null, 'null'];
        yield 'uuid' => ['be0e0000-0000-4000-8000-000000000001', 'be0e0000-0000-4000-8000-000000000001'];
        yield 'heure courte' => ['18:00', '18:00'];
        yield 'heure longue' => ['19:30:00', '19:30:00'];
        yield 'date' => ['2026-01-31', '2026-01-31'];
        yield 'verrou' => ['HARD', 'HARD'];
        yield 'type de règle' => ['PREFERRED', 'PREFERRED'];
        yield 'type de contrainte' => ['TEAM_COACH', 'TEAM_COACH'];
        yield 'type de lien' => ['NOT_SIMULTANEOUS', 'NOT_SIMULTANEOUS'];
        yield 'match à placer' => ['TO_PLACE', 'TO_PLACE'];
        yield 'rôle' => ['MAIN', 'MAIN'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'équipe' => ['name', 'U13 F'];
        // Un nom d'équipe en majuscules et chiffres a la forme d'une énumération : il reste un nom.
        yield 'équipe en capitales' => ['name', 'SM1'];
        yield 'prénom' => ['firstName', 'Alix'];
        yield 'nom accentué' => ['lastName', 'Entraîneur'];
        yield 'gymnase' => ['name', 'Gymnase Nord'];
        yield 'adversaire' => ['opponentLabel', 'Adversaire 1'];
        // Une clé que le format ne connaît pas : le texte libre s'efface par défaut.
        yield 'clé inconnue' => ['nouveauChamp', 'SF2'];
    }

    /** @return iterable<string, array{string}> */
    public static function idsThatCarryAName(): iterable
    {
        yield 'nom d\'équipe' => ['U13 F'];
        yield 'nom en capitales' => ['SM1'];
        yield 'nom accentué' => ['Entraîneur'];
        yield 'nom dans un id composé' => ['team-coach:Gymnase Nord'];
        // Un mot minuscule a la forme d'un mot d'identifiant (`team-coach`) : il reste un nom.
        yield 'prénom en minuscules' => ['alix'];
        yield 'catégorie en minuscules' => ['senior'];
        yield 'gymnase en minuscules, avec un tiret' => ['gymnase-nord'];
        yield 'préfixe connu suivi d\'un nom' => ['team-coach:alix'];
        yield 'préfixe inconnu suivi d\'un UUID' => ['gymnase-nord:' . self::TEAM_A];
        yield 'préfixe connu seul' => ['priority-tier'];
        yield 'UUID suivi d\'un nom' => [self::TEAM_A . ':alix'];
    }

    /**
     * Les seules formes d'id que les constructeurs de demande produisent aujourd'hui, et les seules qui
     * restent en clair (tout autre id est désigné par son empreinte, `idsThatCarryAName`) : UUID, nombre, `team-coach:<uuid>`, `coach-player-unavailability:<uuid>`,
     * `priority-tier:<n>` (`ScheduleConstraintBuilder.php:1563,1582,1608`), `<uuid>:<uuid>` (règle de
     * club développée par équipe, `:1677,1697`), `<uuid>:<uuid>:<jour>:<hh>:<mm>` (affectation
     * précédente, `:1139`).
     *
     * @return iterable<string, array{string}>
     */
    public static function idsOfTheRequestBuilders(): iterable
    {
        yield 'UUID' => [self::TEAM_A];
        yield 'nombre' => ['42'];
        yield 'entraîneur d\'équipe' => ['team-coach:' . self::TEAM_A];
        yield 'indisponibilité d\'entraîneur joueur' => ['coach-player-unavailability:' . self::TEAM_A];
        yield 'palier de priorité' => ['priority-tier:2'];
        yield 'règle de club développée par équipe' => [self::TEAM_A . ':' . self::TEAM_B];
        yield 'affectation précédente' => [self::TEAM_A . ':' . self::TEAM_B . ':1:19:30'];
    }

    #[DataProvider('idsOfTheRequestBuilders')]
    public function testTheIdFormsOfTheRequestBuildersStayInClear(string $id): void
    {
        $rendered = EngineRequestFingerprint::render(['items' => [['id' => $id, 'dayOfWeek' => 1]]]);

        self::assertStringStartsWith("items#order\t" . $id . "\n", $rendered, 'la ligne d\'ordre désigne l\'élément par son id en clair');
        self::assertStringContainsString("\nitems[" . $id . "].dayOfWeek\t1\n", $rendered, 'le chemin désigne l\'élément par son id en clair');
    }

    /**
     * La désignation d'un élément de liste (chemin ET ligne d'ordre) ne laisse aucun nom en clair : un
     * id qui n'est pas fait que d'UUID, de nombres et de mots d'identifiant est désigné par son empreinte.
     */
    #[DataProvider('idsThatCarryAName')]
    public function testAnIdThatCarriesANameIsDesignatedByItsSha256(string $id): void
    {
        $designation = 'sha256:' . hash('sha256', $id);

        $rendered = EngineRequestFingerprint::render(['coaches' => [['id' => $id, 'dayOfWeek' => 1]]]);

        self::assertSame(
            "coaches#order\t" . $designation . "\n"
            . 'coaches[' . $designation . "].dayOfWeek\t1\n"
            . 'coaches[' . $designation . "].id\t" . $designation . "\n",
            $rendered,
        );
        self::assertStringNotContainsString($id, $rendered);
    }

    public function testOneSortedLinePerLeafWithTabAndFinalNewline(): void
    {
        $rendered = EngineRequestFingerprint::render([
            'solverSeed' => 42,
            'clubId' => 'c10b0000-0000-4000-8000-000000000001',
            'implicitRules' => ['coachRestDay' => ['enabled' => true, 'weight' => 0.5]],
        ]);

        self::assertSame(
            "clubId\tc10b0000-0000-4000-8000-000000000001\n"
            . "implicitRules.coachRestDay.enabled\ttrue\n"
            . "implicitRules.coachRestDay.weight\t0.5\n"
            . "solverSeed\t42\n",
            $rendered,
        );
    }

    public function testTheOrderOfMapKeysIsNotARequestChange(): void
    {
        self::assertSame(
            EngineRequestFingerprint::render(['a' => 1, 'b' => ['x' => null, 'y' => false]]),
            EngineRequestFingerprint::render(['b' => ['y' => false, 'x' => null], 'a' => 1]),
        );
    }

    public function testAListElementIsNamedByItsIdAndTheListOrderLivesOnItsOwnLine(): void
    {
        $lines = $this->lines(EngineRequestFingerprint::render(['teams' => [
            ['id' => self::TEAM_B, 'sessionsPerWeek' => 2],
            ['id' => self::TEAM_A, 'sessionsPerWeek' => 3],
        ]]));

        self::assertSame([
            'teams#order' . "\t" . self::TEAM_B . ',' . self::TEAM_A,
            'teams[' . self::TEAM_A . '].id' . "\t" . self::TEAM_A,
            'teams[' . self::TEAM_A . '].sessionsPerWeek' . "\t3",
            'teams[' . self::TEAM_B . '].id' . "\t" . self::TEAM_B,
            'teams[' . self::TEAM_B . '].sessionsPerWeek' . "\t2",
        ], $lines);
    }

    /**
     * Le cœur de la comparaison : deux demandes qui ne diffèrent que par l'ordre d'une liste ne
     * diffèrent que par la ligne `#order` de cette liste — un changement de contenu n'est jamais noyé
     * dans un décalage d'index.
     */
    public function testReorderingAListChangesOnlyItsOrderLine(): void
    {
        $before = $this->lines(EngineRequestFingerprint::render(['teams' => [
            ['id' => self::TEAM_A, 'sessionsPerWeek' => 2],
            ['id' => self::TEAM_B, 'sessionsPerWeek' => 2],
        ]]));
        $after = $this->lines(EngineRequestFingerprint::render(['teams' => [
            ['id' => self::TEAM_B, 'sessionsPerWeek' => 2],
            ['id' => self::TEAM_A, 'sessionsPerWeek' => 2],
        ]]));

        self::assertSame(['teams#order' . "\t" . self::TEAM_A . ',' . self::TEAM_B], array_values(array_diff($before, $after)));
        self::assertSame(['teams#order' . "\t" . self::TEAM_B . ',' . self::TEAM_A], array_values(array_diff($after, $before)));
    }

    public function testARepeatedIdIsNumberedInListOrder(): void
    {
        $lines = $this->lines(EngineRequestFingerprint::render(['pins' => [
            ['id' => self::TEAM_A, 'dayOfWeek' => 1],
            ['id' => self::TEAM_A, 'dayOfWeek' => 3],
        ]]));

        self::assertSame([
            'pins#order' . "\t" . self::TEAM_A . '#1,' . self::TEAM_A . '#2',
            'pins[' . self::TEAM_A . '#1].dayOfWeek' . "\t1",
            'pins[' . self::TEAM_A . '#1].id' . "\t" . self::TEAM_A,
            'pins[' . self::TEAM_A . '#2].dayOfWeek' . "\t3",
            'pins[' . self::TEAM_A . '#2].id' . "\t" . self::TEAM_A,
        ], $lines);
    }

    public function testAnElementWithoutIdIsNamedByItsIndexAndAnEmptyListKeepsItsOrderLine(): void
    {
        $lines = $this->lines(EngineRequestFingerprint::render([
            'previousAssignments' => [['dayOfWeek' => 1], ['dayOfWeek' => 4]],
            'preferredDays' => [1, 3],
            'sharedBlocks' => [],
        ]));

        self::assertSame([
            "preferredDays#order\t0,1",
            "preferredDays[0]\t1",
            "preferredDays[1]\t3",
            "previousAssignments#order\t0,1",
            "previousAssignments[0].dayOfWeek\t1",
            "previousAssignments[1].dayOfWeek\t4",
            "sharedBlocks#order\t",
        ], $lines);
    }

    #[DataProvider('clearValues')]
    public function testValuesThatNameNoOneStayInClear(mixed $value, string $expected): void
    {
        self::assertSame("value\t" . $expected . "\n", EngineRequestFingerprint::render(['value' => $value]));
    }

    public function testTheContractVersionStaysInClear(): void
    {
        self::assertSame("version\t1.12\n", EngineRequestFingerprint::render(['version' => '1.12']));
    }

    #[DataProvider('names')]
    public function testEveryOtherStringIsReplacedByItsSha256(string $key, string $name): void
    {
        $rendered = EngineRequestFingerprint::render(['teams' => [['id' => self::TEAM_A, $key => $name]]]);

        self::assertStringContainsString('teams[' . self::TEAM_A . '].' . $key . "\tsha256:" . hash('sha256', $name) . "\n", $rendered);
        self::assertStringNotContainsString($name, $rendered);
    }

    /** @return list<string> */
    private function lines(string $rendered): array
    {
        self::assertStringEndsWith("\n", $rendered, 'un fichier d\'empreintes finit par un saut de ligne');

        return explode("\n", substr($rendered, 0, -1));
    }
}
