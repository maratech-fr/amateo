<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Trace « génération lancée » de la « Dernière activité » d'un club (console superadmin).
 *
 * Appelée DANS la transaction de chaque guichet qui met une génération en file (`/generate`,
 * `/regenerate`, `/fill`), APRÈS ses gardes : une génération refusée ne marque jamais. Horloge
 * RÉELLE : la console mesure l'activité réelle, jamais la date simulée d'un club démo. Aucune
 * liste d'exclusion ici — une génération lancée par un compte interne compte (seule la
 * connexion, `LoginSuccessListener`, l'ignore). Pas de try/catch : la transaction est l'unité.
 *
 * UPDATE DBAL ciblé : le guichet tient peut-être l'entité `Club` managée ; ce champ n'y est pas
 * dirty, le flush ne l'écrase pas.
 */
final readonly class ClubActivityTrace
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'app.clock.real')]
        private ClockInterface $clock,
    ) {}

    public function markGeneration(string $clubId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE club SET last_activity_at = :now WHERE id = :id',
            ['now' => $this->clock->now()->format(\DATE_ATOM), 'id' => $clubId],
        );
    }
}
