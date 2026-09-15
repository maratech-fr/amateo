<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpponentVenueSuggestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpponentVenueSuggestion>
 *
 * Les écritures sont NATIVES (upsert `ON CONFLICT`, incrément/décrément atomiques) :
 * la table est PARTAGÉE et concurrente entre clubs — un compteur partagé se tient
 * au niveau base, jamais par un read-modify-write applicatif qui perdrait des
 * écritures. Le partagé porte « un COMPTE, jamais un QUI » : aucune de ces requêtes
 * n'écrit la moindre identité de club ({@see OpponentVenueSuggestion}).
 */
final class OpponentVenueSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpponentVenueSuggestion::class);
    }

    /**
     * Les suggestions d'un adversaire, triées : `FFBB_API` d'abord (gymnases vus dans
     * le calendrier fédéral), puis les `MANUAL` par compte décroissant, puis par
     * libellé. (`'FFBB_API' < 'MANUAL'` en ordre alphabétique — l'ordre ASC de la
     * colonne source donne exactement la précédence voulue.).
     *
     * @return list<OpponentVenueSuggestion>
     */
    public function findByCode(string $ffbbOrganismeCode): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.ffbbOrganismeCode = :code')
            ->setParameter('code', $ffbbOrganismeCode)
            ->orderBy('s.source', 'ASC')
            ->addOrderBy('s.chosenByCount', 'DESC')
            ->addOrderBy('s.venueLabel', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Dépose/actualise une suggestion FFBB_API — un gymnase VU dans le calendrier
     * fédéral (canal API, best-effort). Sans référence de salle (le hit rencontre ne
     * porte pas le `numero`, sondé 2026-09-15) : dédupliquée par `(code, lower(libellé))`.
     * Le compte n'est PAS touché (une observation n'est pas un choix). L'appelant flush
     * son unité de travail à part — cette écriture native est immédiate.
     */
    public function upsertFromApi(string $ffbbOrganismeCode, string $venueLabel, ?string $city, ?string $postalCode, ?float $latitude, ?float $longitude): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO opponent_venue_suggestion'
            . ' (id, ffbb_organisme_code, venue_external_ref, venue_label, city, postal_code, latitude, longitude, source, chosen_by_count, last_chosen_at, created_at, updated_at)'
            . ' VALUES (:id, :code, NULL, :label, :city, :pc, :lat, :lng, \'FFBB_API\', 0, NULL, now(), now())'
            . ' ON CONFLICT (ffbb_organisme_code, lower(venue_label)) WHERE venue_external_ref IS NULL'
            . ' DO UPDATE SET city = EXCLUDED.city, postal_code = EXCLUDED.postal_code, latitude = EXCLUDED.latitude, longitude = EXCLUDED.longitude, updated_at = now()',
            [
                'id' => $this->newUuid(),
                'code' => mb_substr($ffbbOrganismeCode, 0, 64),
                'label' => mb_substr($venueLabel, 0, 180),
                'city' => null === $city ? null : mb_substr($city, 0, 180),
                'pc' => null === $postalCode ? null : mb_substr($postalCode, 0, 16),
                'lat' => $latitude,
                'lng' => $longitude,
            ],
            ['lat' => ParameterType::STRING, 'lng' => ParameterType::STRING],
        );
    }

    /**
     * Crée (à 0) ou retrouve une suggestion MANUAL — un gymnase CHOISI dans l'index
     * `/api/ffbb/salles`, keyée sur son `numero` fédéral (`venueExternalRef`). ⚠ Le
     * libellé/ville/CP/coordonnées passés DOIVENT être FÉDÉRAUX (re-résolus côté
     * serveur par {@see FfbbSalleResolver}) — jamais le texte d'un client. Sur CONFLIT
     * (un autre club a déjà cette salle), on CONSERVE les valeurs existantes (premier
     * writer fédéral) et on ne touche que `updated_at` : un second club au corps
     * différent ne réécrit RIEN. Le COMPTE est laissé intact ({@see increment} /
     * {@see decrement}). Écriture native immédiate.
     */
    public function upsertManual(string $ffbbOrganismeCode, string $venueExternalRef, string $venueLabel, ?string $city, ?string $postalCode, ?float $latitude, ?float $longitude): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO opponent_venue_suggestion'
            . ' (id, ffbb_organisme_code, venue_external_ref, venue_label, city, postal_code, latitude, longitude, source, chosen_by_count, last_chosen_at, created_at, updated_at)'
            . ' VALUES (:id, :code, :ref, :label, :city, :pc, :lat, :lng, \'MANUAL\', 0, NULL, now(), now())'
            . ' ON CONFLICT (ffbb_organisme_code, venue_external_ref) WHERE venue_external_ref IS NOT NULL'
            . ' DO UPDATE SET updated_at = now()',
            [
                'id' => $this->newUuid(),
                'code' => mb_substr($ffbbOrganismeCode, 0, 64),
                'ref' => mb_substr($venueExternalRef, 0, 64),
                'label' => mb_substr($venueLabel, 0, 180),
                'city' => null === $city ? null : mb_substr($city, 0, 180),
                'pc' => null === $postalCode ? null : mb_substr($postalCode, 0, 16),
                'lat' => $latitude,
                'lng' => $longitude,
            ],
            ['lat' => ParameterType::STRING, 'lng' => ParameterType::STRING],
        );
    }

    /**
     * Un club de plus a choisi ce gymnase (`+1`, horodaté). La ligne MANUAL existe déjà
     * ({@see upsertManual} l'a créée). Écriture native atomique.
     */
    public function increment(string $ffbbOrganismeCode, string $venueExternalRef): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE opponent_venue_suggestion SET chosen_by_count = chosen_by_count + 1, last_chosen_at = now(), updated_at = now()'
            . ' WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $ffbbOrganismeCode, 'ref' => $venueExternalRef],
        );
    }

    /**
     * Un club de moins choisit ce gymnase (`GREATEST(0, count - 1)` — le compte ne
     * descend JAMAIS sous 0, même si un décrément arrivait de trop). Écriture native
     * atomique. La ligne reste (compte 0), jamais supprimée (A2).
     */
    public function decrement(string $ffbbOrganismeCode, string $venueExternalRef): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE opponent_venue_suggestion SET chosen_by_count = GREATEST(0, chosen_by_count - 1), updated_at = now()'
            . ' WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $ffbbOrganismeCode, 'ref' => $venueExternalRef],
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
