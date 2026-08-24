<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FbiIngestion;
use App\Enum\FbiIngestionSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FbiIngestion>
 */
final class FbiIngestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FbiIngestion::class);
    }

    /**
     * Le dernier dépôt xlsx du club+saison courants — la source de la fraîcheur ET
     * de la relecture des traces au dépôt suivant. Les filtres Doctrine (club_id +
     * season_id) bornent déjà la requête ; `source` isole le canal FBI (une
     * ingestion API future ne compte pas comme un dépôt, cf. FbiIngestionSource).
     */
    public function latestXlsx(): ?FbiIngestion
    {
        return $this->findOneBy(['source' => FbiIngestionSource::FBI_XLSX], ['depositedAt' => 'DESC']);
    }
}
