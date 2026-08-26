<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueTravelRuleSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueTravelRuleSetting>
 */
final class VenueTravelRuleSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueTravelRuleSetting::class);
    }

    /**
     * Le réglage stocké du club+saison, ou null si le gestionnaire n'a rien réglé (⇒ défaut
     * PREFERRED, résolu par l'appelant). Un SEUL réglage par club+saison (unique base).
     */
    public function findOneByClubSeason(string $clubId, string $seasonId): ?VenueTravelRuleSetting
    {
        return $this->findOneBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
    }
}
