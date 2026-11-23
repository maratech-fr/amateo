<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\Place;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * La seule porte pour lire l'adresse et la position d'un gymnase : le lieu au club ({@see Place}) que
 * le gymnase désigne par `placeId`. Toute lecture est bornée par le club du gymnase : un lieu d'un
 * autre club n'est jamais rendu (il ne peut d'ailleurs pas être désigné : clé étrangère composée avec
 * le club). Le lieu rendu est l'entité gérée : l'écrire corrige l'adresse pour toutes les saisons.
 *
 * Un gymnase tout juste construit (`new Venue($place)`) porte son lieu en mémoire : il est lu sans
 * requête, sous la même condition de club. L'EntityManager n'est absent que dans les tests unitaires
 * qui construisent un lecteur à la main : un gymnase relu de la base y lève une `LogicException`.
 */
final readonly class PlaceDirectory
{
    public function __construct(
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    public function of(Venue $venue): Place
    {
        $given = $venue->placeGivenAtCreation();
        $place = $given instanceof Place ? $given : $this->entityManager()->find(Place::class, $venue->getPlaceId());
        if (!$place instanceof Place || $place->getClubId() !== $venue->getClubId()) {
            throw new LogicException(\sprintf('Le gymnase %s ne désigne aucun lieu de son club.', $venue->getId()));
        }

        return $place;
    }

    /**
     * Une requête pour tous les lieux pas encore chargés, puis la même règle que {@see of()}.
     *
     * @param iterable<Venue> $venues
     *
     * @return array<string, Place> clé = identifiant du gymnase
     */
    public function forVenues(iterable $venues): array
    {
        $list = [];
        $pending = [];
        foreach ($venues as $venue) {
            if (!$venue->placeGivenAtCreation() instanceof Place) {
                $pending[$venue->getPlaceId()] = true;
            }
            $list[] = $venue;
        }
        if ([] !== $pending) {
            // Charge les lieux dans la carte d'identité de l'EntityManager : `of` les y relit sans requête.
            $this->entityManager()->createQuery('SELECT p FROM App\Entity\Place p WHERE p.id IN (:ids)')
                ->setParameter('ids', array_keys($pending))
                ->getResult();
        }

        $places = [];
        foreach ($list as $venue) {
            $places[$venue->getId()] = $this->of($venue);
        }

        return $places;
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManager ?? throw new LogicException('PlaceDirectory sans base : seul un gymnase construit avec son lieu (new Venue($place)) se lit sans EntityManager.');
    }
}
