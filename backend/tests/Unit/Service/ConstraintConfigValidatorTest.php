<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\ConstraintFamily;
use App\Service\ConstraintConfigValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SEC-13 — la liste blanche du `config`, éprouvée sur les cas qui l'ont motivée.
 *
 * Chaque cas de refus ici a été MESURÉ sur l'API réelle le 2026-08-07 : les trois
 * premiers rendaient **201** et entraient en base, et le solveur les ignorait en
 * silence. Le plus coûteux est la faute de frappe : la contrainte s'affichait
 * « Rien après 19h · HARD · active » pendant que le moteur plaçait la séance à
 * 20:00 — le gestionnaire distribue un planning en croyant une règle appliquée.
 *
 * Les TÉMOINS comptent autant que les refus : un validateur qui refuse tout
 * passerait la moitié de ce fichier au vert en cassant l'application.
 */
#[Group('phase1')]
final class ConstraintConfigValidatorTest extends TestCase
{
    private const string VENUE = '11111111-1111-4111-8111-111111111111';

    private ConstraintConfigValidator $validator;

    /** @return iterable<string, array{ConstraintFamily, array<string, mixed>}> */
    public static function acceptedProvider(): iterable
    {
        yield 'TIME — les trois bornes' => [ConstraintFamily::TIME, ['minStartTime' => '17:00', 'maxStartTime' => '19:00', 'maxEndTime' => '20:30']];
        yield 'DAY — whitelist' => [ConstraintFamily::DAY, ['allowedDays' => [2, 4]]];
        yield 'DAY — jours évités' => [ConstraintFamily::DAY, ['forbiddenDays' => [7]]];
        yield 'FACILITY — au moins N dans ce gymnase' => [ConstraintFamily::FACILITY, ['minAtVenueId' => self::VENUE, 'minAtVenueCount' => 2]];
        yield 'FACILITY — fermeture datée (backend seul)' => [ConstraintFamily::FACILITY, ['type' => 'venue_closed', 'startDate' => '2026-12-24', 'endDate' => '2027-01-02']];
        yield 'COACH_AVAILABILITY — jours + fenêtre' => [ConstraintFamily::COACH_AVAILABILITY, ['unavailableDays' => [5], 'fromTime' => '18:00', 'untilTime' => '20:00']];
        yield 'cible de groupe, toutes familles' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTag' => 'JEUNE']];
        // P2-29 — cibles multiples (intersection) et exclusions.
        yield 'plusieurs groupes ciblés (intersection)' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTags' => ['ADULTE', 'COMPETITION']]];
        yield 'cible + exclusion' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTags' => ['ADULTE'], 'excludeTags' => ['LOISIR_ADULTE']]];
        yield 'exclusion sans cible (D8)' => [ConstraintFamily::DAY, ['forbiddenDays' => [7], 'excludeTags' => ['BABY']]];
        yield 'config vide' => [ConstraintFamily::DAY, []];
    }

    /** @return iterable<string, array{ConstraintFamily, array<string, mixed>, string}> */
    public static function refusedProvider(): iterable
    {
        // LE cas fondateur : une lettre en moins, 201 hier, règle morte.
        yield 'faute de frappe' => [ConstraintFamily::TIME, ['maxStartTme' => '19:00'], 'maxStartTme'];
        yield 'clé hors sujet' => [ConstraintFamily::DAY, ['quiche' => 'lorraine'], 'quiche'];
        // La bonne clé, mais dans la mauvaise famille : le moteur ne la lira
        // jamais là. C'est le trou qu'un contrôle par NOM seul laisserait ouvert.
        yield 'bonne clé, mauvaise famille' => [ConstraintFamily::TIME, ['forbiddenDays' => [3]], 'forbiddenDays'];
        yield 'heure impossible' => [ConstraintFamily::TIME, ['maxStartTime' => '25:99'], 'HH:MM'];
        yield 'heure en toutes lettres' => [ConstraintFamily::TIME, ['maxStartTime' => '19h'], 'HH:MM'];
        yield 'jour hors semaine' => [ConstraintFamily::DAY, ['forbiddenDays' => [0, 8]], 'jours'];
        yield 'jour en chaîne' => [ConstraintFamily::DAY, ['forbiddenDays' => ['3']], 'jours'];
        yield 'jours non-liste' => [ConstraintFamily::DAY, ['forbiddenDays' => 3], 'jours'];
        yield 'gymnase qui n\'est pas un uuid' => [ConstraintFamily::FACILITY, ['forcedVenueId' => 'Barros'], 'gymnase'];
        yield 'compteur à zéro' => [ConstraintFamily::FACILITY, ['minAtVenueId' => self::VENUE, 'minAtVenueCount' => 0], 'au moins 1'];
        yield 'compteur en tableau' => [ConstraintFamily::FACILITY, ['minAtVenueId' => self::VENUE, 'minAtVenueCount' => ['trois']], 'au moins 1'];
        yield 'date inexistante' => [ConstraintFamily::FACILITY, ['type' => 'venue_closed', 'startDate' => '2026-02-31'], 'AAAA-MM-JJ'];
        yield 'type de fermeture inventé' => [ConstraintFamily::FACILITY, ['type' => 'gymnase_ferme'], 'venue_closed'];
        yield 'groupe vide' => [ConstraintFamily::TIME, ['targetTag' => '   '], 'groupe'];
        // P2-29 D7/D10 — la forme des cibles multiples et exclusions.
        yield 'targetTags non-liste' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTags' => 'ADULTE'], 'liste'];
        yield 'targetTags avec un élément vide' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTags' => ['ADULTE', '  ']], 'liste'];
        yield 'excludeTags vide' => [ConstraintFamily::DAY, ['forbiddenDays' => [7], 'excludeTags' => []], 'liste'];
        yield 'mélange targetTag + targetTags (D7)' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTag' => 'ADULTE', 'targetTags' => ['SENIOR']], 'mélanger'];
        yield 'mélange targetTag + excludeTags (D7)' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTag' => 'ADULTE', 'excludeTags' => ['LOISIR_ADULTE']], 'mélanger'];
        yield 'groupe ciblé ET exclu (D10)' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'targetTags' => ['ADULTE', 'SENIOR'], 'excludeTags' => ['SENIOR']], 'ciblé et exclu'];
        // Retirées par décision : le doublon du scope, et deux dates que
        // personne ne lit (le moteur les ignore, `extra="ignore"`).
        yield 'coachId, doublon du scope (PR B)' => [ConstraintFamily::COACH_AVAILABILITY, ['coachId' => self::VENUE], 'coachId'];
        yield 'dateStart, lue par personne' => [ConstraintFamily::TIME, ['maxStartTime' => '19:00', 'dateStart' => '2026-09-01'], 'dateStart'];
        // Alias snake_case : le moteur les lisait, ils en sont retirés dans la
        // même PR — une seule orthographe partout (décision fondateur).
        yield 'alias snake_case' => [ConstraintFamily::DAY, ['forbidden_days' => [3]], 'forbidden_days'];
        // ALIGN-21 — le ciblage par groupe ne résout que des lignes TEAM : sur une
        // disponibilité de COACH il serait accepté puis sans effet. Refusé à l'écriture.
        yield 'targetTag sur une disponibilité (sans effet)' => [ConstraintFamily::COACH_AVAILABILITY, ['unavailableDays' => [5], 'targetTag' => 'JEUNE'], 'disponibilité'];
        yield 'targetTags sur une disponibilité' => [ConstraintFamily::COACH_AVAILABILITY, ['unavailableDays' => [5], 'targetTags' => ['JEUNE']], 'disponibilité'];
        yield 'excludeTags sur une disponibilité' => [ConstraintFamily::COACH_AVAILABILITY, ['unavailableDays' => [5], 'excludeTags' => ['JEUNE']], 'disponibilité'];
    }

    #[DataProvider('acceptedProvider')]
    public function testAValidConfigIsAccepted(ConstraintFamily $family, array $config): void
    {
        self::assertSame([], $this->validator->errors($family, $config));
    }

    #[DataProvider('refusedProvider')]
    public function testAnInvalidConfigIsRefusedWithAMessageThatNamesTheProblem(ConstraintFamily $family, array $config, string $expectedInMessage): void
    {
        $errors = $this->validator->errors($family, $config);

        self::assertNotSame([], $errors, 'ce config doit être refusé');
        self::assertStringContainsString(
            $expectedInMessage,
            implode(' ', $errors),
            'le message doit NOMMER ce qui cloche — sinon le gestionnaire ne peut pas corriger sans ouvrir le code',
        );
    }

    public function testEngineKeysExcludeTheBackendOnlyOnes(): void
    {
        // `type`/`startDate`/`endDate` pilotent une fermeture datée côté backend
        // (`VenueClosureDays`) et ne produisent AUCUNE ligne de payload. Les
        // exiger du moteur ferait rougir le job « Engine semantics » à tort.
        $engineKeys = $this->validator->engineKeysByFamily();

        self::assertNotContains('type', $engineKeys['FACILITY']);
        self::assertNotContains('startDate', $engineKeys['FACILITY']);
        self::assertNotContains('endDate', $engineKeys['FACILITY']);
        self::assertContains('minAtVenueId', $engineKeys['FACILITY']);
    }

    protected function setUp(): void
    {
        $this->validator = new ConstraintConfigValidator;
    }
}
