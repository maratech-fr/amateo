<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Coach;
use App\Entity\Person;
use App\Entity\Place;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le décor des tests crée un coach comme l'application le fait depuis la PR 2b du lot 3 : une fiche
 * de personne au club (nom, mail, téléphone, genre), puis le rôle coach de la saison qui la pointe ;
 * et un gymnase comme depuis la PR 4 : un lieu au club (adresse, position), puis le gymnase de la
 * saison qui le pointe. Une seule maison pour ces gestes, que les tests reprennent au lieu de poser
 * l'identité sur `Coach` ou l'adresse sur `Venue`.
 *
 * Noyau démarré (`KernelTestCase`, `WebTestCase`) : la fiche et le coach sont confiés à
 * l'EntityManager (persist, sans flush), la fiche d'abord, pour que la clé étrangère composée
 * `(person_id, club_id)` la trouve au flush de l'appelant. Sans noyau (test unitaire) : les deux
 * objets restent en mémoire.
 */
trait CreatesPeopleAndPlacesTrait
{
    private function newCoach(string $clubId, string $seasonId, string $firstName = 'Test', string $lastName = 'Coach'): Coach
    {
        $person = (new Person)->setClubId($clubId)->setFirstName($firstName)->setLastName($lastName);
        $coach = new Coach($person)->setClubId($clubId)->setSeasonId($seasonId);

        if (static::$booted) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->persist($person);
            $em->persist($coach);
        }

        return $coach;
    }

    /**
     * Un gymnase de saison comme l'application le crée depuis la PR 4 du lot 3 : un lieu neuf au club
     * (adresse et position, vides ici), puis le gymnase de la saison qui en est l'usage.
     */
    private function newVenue(string $clubId, string $seasonId, string $name): Venue
    {
        $place = (new Place)->setClubId($clubId);
        $venue = new Venue($place)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');

        if (static::$booted) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->persist($place);
            $em->persist($venue);
        }

        return $venue;
    }

    /** Le lieu dont un gymnase est l'usage, relu en base (le test qui le modifie le flush lui-même). */
    private function placeOf(Venue $venue): Place
    {
        $place = static::getContainer()->get(EntityManagerInterface::class)->find(Place::class, $venue->getPlaceId());
        \assert($place instanceof Place, 'le gymnase pointe un lieu qui existe');

        return $place;
    }

    /** La fiche que pointe un coach, relue en base (le test qui la modifie la flush lui-même). */
    private function personOf(Coach $coach): Person
    {
        $person = static::getContainer()->get(EntityManagerInterface::class)->find(Person::class, $coach->getPersonId());
        \assert($person instanceof Person, 'le coach pointe une fiche qui existe');

        return $person;
    }
}
