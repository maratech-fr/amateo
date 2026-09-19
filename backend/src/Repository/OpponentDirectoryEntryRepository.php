<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpponentDirectoryEntry;
use App\Enum\OpponentLocationPrecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
     * Upsert a resolved opponent location, keyed on the organisme code. Écriture
     * NATIVE `INSERT … ON CONFLICT (ffbb_organisme_code) DO UPDATE` (immédiate, hors
     * unité de travail — même patron que {@see OpponentVenueSuggestionRepository}),
     * pour une raison de correction : deux observations de NOMS différents peuvent
     * résoudre le MÊME code dans un seul lot ; un `findOneBy` + `persist` applicatif ne
     * voyait pas la 1ʳᵉ entité persistée non flushée → double persist → violation
     * d'unicité au flush, qui FERMAIT l'EntityManager. `ON CONFLICT` fond les deux en
     * une ligne, sans jamais fermer le manager.
     *
     * Règle métier PORTÉE PAR LE SQL : une résolution PLUS précise (VENUE) remplace une
     * moins précise (CITY), JAMAIS l'inverse — le `WHERE NOT (…VENUE… AND EXCLUDED…CITY)`
     * saute la mise à jour quand elle dégraderait une salle connue en simple ville.
     *
     * @param array{name: string, city: ?string, postalCode: ?string, latitude: ?float, longitude: ?float, venueLabel: ?string} $data
     */
    public function upsert(string $ffbbOrganismeCode, OpponentLocationPrecision $precision, array $data): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO opponent_directory'
            . ' (id, ffbb_organisme_code, name, city, postal_code, latitude, longitude, precision, venue_label, resolved_at)'
            . ' VALUES (:id, :code, :name, :city, :pc, :lat, :lng, :precision, :venueLabel, now())'
            . ' ON CONFLICT (ffbb_organisme_code) DO UPDATE SET'
            . ' name = EXCLUDED.name, city = EXCLUDED.city, postal_code = EXCLUDED.postal_code,'
            . ' latitude = EXCLUDED.latitude, longitude = EXCLUDED.longitude,'
            . ' precision = EXCLUDED.precision, venue_label = EXCLUDED.venue_label, resolved_at = now()'
            . ' WHERE NOT (opponent_directory.precision = \'VENUE\' AND EXCLUDED.precision = \'CITY\')',
            [
                'id' => $this->newUuid(),
                'code' => mb_substr($ffbbOrganismeCode, 0, 64),
                'name' => mb_substr($data['name'], 0, 180),
                'city' => null === $data['city'] ? null : mb_substr($data['city'], 0, 180),
                'pc' => null === $data['postalCode'] ? null : mb_substr($data['postalCode'], 0, 16),
                'lat' => $data['latitude'],
                'lng' => $data['longitude'],
                'precision' => $precision->value,
                'venueLabel' => null === $data['venueLabel'] ? null : mb_substr($data['venueLabel'], 0, 180),
            ],
            ['lat' => ParameterType::STRING, 'lng' => ParameterType::STRING],
        );
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
