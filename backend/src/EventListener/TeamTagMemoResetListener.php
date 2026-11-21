<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Service\TeamTagResolver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Une écriture d'équipe ou de catégorie vide le mémo « nom → équipes » de
 * {@see TeamTagResolver} : les étiquettes se calculent depuis ces deux entités, et un mémo
 * gardé au-delà d'une édition rendrait les groupes d'avant. Complète la remise à zéro entre
 * deux messages du worker (`kernel.reset`) pour les écritures faites dans le même processus.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final readonly class TeamTagMemoResetListener
{
    public function __construct(private TeamTagResolver $resolver) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->resetOn($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->resetOn($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->resetOn($args->getObject());
    }

    private function resetOn(object $entity): void
    {
        if ($entity instanceof Team || $entity instanceof SportCategory) {
            $this->resolver->reset();
        }
    }
}
