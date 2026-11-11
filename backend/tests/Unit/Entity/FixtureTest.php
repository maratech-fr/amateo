<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Les règles d'état portées par l'entité rencontre elle-même : le libellé de salle gardé, et ce qui
 * rend une rencontre arbitrable (donc hors de tout geste « marquer comme vu » en masse).
 */
#[Group('unit')]
final class FixtureTest extends TestCase
{
    public function testKeptVenueLabelDefaultsToNull(): void
    {
        self::assertNull(new Fixture()->getKeptVenueLabel());
    }

    public function testSettingANonNullVenueClearsTheKeptVenueLabel(): void
    {
        // « Garder l'appli » pose keptVenueLabel ; re-placer le match (salle non nulle)
        // éteint ce pense-bête — même maison que unplacedReason.
        $fixture = new Fixture;
        $fixture->setKeptVenueLabel('salle raphael de barros');
        self::assertSame('salle raphael de barros', $fixture->getKeptVenueLabel());

        $fixture->setVenueId('11111111-1111-4111-8111-111111111111');
        self::assertNull($fixture->getKeptVenueLabel(), 'poser une salle efface le libellé gardé');
    }

    public function testSettingANullVenueKeepsTheKeptVenueLabel(): void
    {
        // Un dépointage (venueId null) NE touche PAS l'idempotence : seul un
        // re-placement (venueId non null) l'éteint.
        $fixture = new Fixture;
        $fixture->setKeptVenueLabel('salle raphael de barros');
        $fixture->setVenueId(null);
        self::assertSame('salle raphael de barros', $fixture->getKeptVenueLabel());
    }

    /**
     * Un écart ARBITRABLE (une entrée non `autoApplied`) est ce qu'aucun geste en masse ne tranche :
     * une rencontre qui n'en porte aucun s'acquitte d'un geste, celle qui en porte un — seul ou mêlé
     * à une valeur appliquée d'office — reste à trancher ligne par ligne. `hasPendingDeviations()`
     * garde son sens (toute entrée, appliquée d'office comprise).
     */
    public function testOnlyANonAutoAppliedEntryMakesTheFixtureArbitrable(): void
    {
        $clean = new Fixture;
        self::assertFalse($clean->hasArbitrableDeviations(), 'sans entrée : rien à trancher');
        self::assertFalse($clean->hasPendingDeviations());

        $autoOnly = new Fixture;
        $autoOnly->putPendingDeviation($this->entry('date', true));
        $autoOnly->putPendingDeviation($this->entry('kickoff', true));
        self::assertFalse($autoOnly->hasArbitrableDeviations(), 'des valeurs appliquées d\'office ne s\'arbitrent pas');
        self::assertTrue($autoOnly->hasPendingDeviations(), 'hasPendingDeviations compte toujours une entrée appliquée d\'office');

        $arbitrable = new Fixture;
        $arbitrable->putPendingDeviation($this->entry('date', false));
        self::assertTrue($arbitrable->hasArbitrableDeviations(), 'un écart à trancher est arbitrable');

        $mixed = new Fixture;
        $mixed->putPendingDeviation($this->entry('date', true));
        $mixed->putPendingDeviation($this->entry('kickoff', false));
        self::assertTrue($mixed->hasArbitrableDeviations(), 'une entrée à trancher suffit, même à côté d\'une valeur appliquée d\'office');
    }

    /**
     * Une entrée relue de la colonne JSON SANS la clé `autoApplied` compte comme ARBITRABLE : dans le
     * doute, aucun geste en masse (équipe, extérieurs) ne la vide. Posée par réflexion, comme Doctrine
     * hydrate la colonne — `putPendingDeviation()` exige la clé. Jumeau HTTP :
     * `FixtureReviewApiTest::testAwayScopeLeavesAnAwayWhoseStoredEntryLacksTheAutoAppliedKey`.
     */
    public function testAStoredEntryWithoutTheAutoAppliedKeyCountsAsArbitrable(): void
    {
        $entry = $this->entry('date', true);
        unset($entry['autoApplied']);
        $fixture = new Fixture;
        new ReflectionProperty(Fixture::class, 'pendingDeviations')->setValue($fixture, [$entry]);

        self::assertTrue($fixture->hasArbitrableDeviations(), 'sans la clé, l\'entrée reste à trancher');
        self::assertTrue($fixture->hasPendingDeviations());
    }

    /**
     * @param 'date'|'kickoff'|'venue' $field
     *
     * @return array{field: 'date'|'kickoff'|'venue', appValue: string|null, sourceValue: string|null, channel: 'FBI_XLSX'|'FFBB_API', seenAt: string, autoApplied: bool}
     */
    private function entry(string $field, bool $autoApplied): array
    {
        return [
            'field' => $field,
            'appValue' => 'app',
            'sourceValue' => 'source',
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => $autoApplied,
        ];
    }
}
