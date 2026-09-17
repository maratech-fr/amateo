<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpponentDirectoryEntry;
use App\Enum\OpponentLocationPrecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpponentDirectoryEntry>
 */
final class OpponentDirectoryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpponentDirectoryEntry::class);
    }

    public function findOneByFfbbOrganismeCode(string $ffbbOrganismeCode): ?OpponentDirectoryEntry
    {
        return $this->findOneBy(['ffbbOrganismeCode' => $ffbbOrganismeCode]);
    }

    /**
     * Batch lookup keyed on the FFBB organisme code — the conflict radar resolves
     * an away opponent's place for MANY fixtures at once, so a per-fixture
     * `findOneBy` would be an N+1. Read-only over this GLOBAL reference table
     * (no club_id, outside RLS). An empty list returns [] without a query.
     *
     * @param list<string> $ffbbOrganismeCodes
     *
     * @return list<OpponentDirectoryEntry>
     */
    public function findByFfbbOrganismeCodes(array $ffbbOrganismeCodes): array
    {
        if ([] === $ffbbOrganismeCodes) {
            return [];
        }

        return $this->findBy(['ffbbOrganismeCode' => array_values(array_unique($ffbbOrganismeCodes))]);
    }

    /**
     * Upsert a resolved opponent location, keyed on the organisme code. A more
     * precise resolution (VENUE) replaces a less precise one (CITY), never the
     * reverse — a CITY resolution NEVER downgrades an existing VENUE row. The
     * caller flushes. Returns the managed entity.
     *
     * @param array{name: string, city: ?string, postalCode: ?string, latitude: ?float, longitude: ?float, venueLabel: ?string} $data
     */
    public function upsert(string $ffbbOrganismeCode, OpponentLocationPrecision $precision, array $data): OpponentDirectoryEntry
    {
        $entry = $this->findOneByFfbbOrganismeCode($ffbbOrganismeCode);
        if (!$entry instanceof OpponentDirectoryEntry) {
            $entry = new OpponentDirectoryEntry($ffbbOrganismeCode, $data['name'], $precision);
            $this->getEntityManager()->persist($entry);
        } elseif (OpponentLocationPrecision::VENUE === $entry->getPrecision() && OpponentLocationPrecision::CITY === $precision) {
            // Never downgrade a known venue to a mere city (défense en profondeur —
            // le résolveur saute déjà un VENUE existant avant tout appel réseau).
            return $entry;
        } else {
            $entry->setPrecision($precision);
        }

        $entry
            ->setName($data['name'])
            ->setCity($data['city'])
            ->setPostalCode($data['postalCode'])
            ->setLatitude($data['latitude'])
            ->setLongitude($data['longitude'])
            ->setVenueLabel($data['venueLabel'])
            ->touchResolvedAt();

        return $entry;
    }
}
