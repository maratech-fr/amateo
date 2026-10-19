<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\DeploymentStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le GEL des guichets de solve pendant une mise en production imminente (P4-350, axe
 * *generation pipeline*). Dès que l'heure H d'un déploiement ACTIF (`scheduled`|`dispatched`) est
 * atteinte, les quatre portes qui enfilent un run moteur refusent — un solve lancé pendant la
 * bascule de maintenance corromprait un run au moment du déploiement.
 *
 * `scheduled_deployment` est GLOBALE (sans RLS) : on la lit par la connexion APPLICATIVE (la
 * même que l'ORM des contrôleurs), pas par la connexion admin. Le message de refus est une
 * CONSTANTE serveur — jamais interpolé avec un détail du déploiement (version/acteur/« GitHub » :
 * un gestionnaire de club n'a pas à connaître la cuisine).
 */
final readonly class DeploymentGate
{
    /** Le refus EXACT servi par les guichets — zéro interpolation, zéro oracle de déploiement. */
    public const string FREEZE_MESSAGE = 'Une mise en production est imminente — réessayez après la maintenance.';

    public function __construct(
        private ManagerRegistry $registry,
        // Horloge RÉELLE : le gel est une bascule de maintenance INFRASTRUCTURE, pas un « aujourd'hui »
        // métier — l'horloge simulée d'un club démo ne doit jamais geler (ou dégeler) à tort (patron
        // AuditTrail). Un club démo dont l'horloge pointe le futur/passé compare sa date simulée à un
        // `scheduled_at` réel : seule l'horloge réelle donne le bon verdict.
        #[Autowire(service: 'app.clock.real')]
        private ClockInterface $clock,
    ) {}

    public function isFrozen(): bool
    {
        $count = (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM scheduled_deployment WHERE status IN (:active) AND scheduled_at <= :now',
            [
                'active' => DeploymentStatus::activeValues(),
                'now' => $this->clock->now()->format('Y-m-d H:i:sP'),
            ],
            ['active' => ArrayParameterType::STRING],
        );

        return $count > 0;
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
