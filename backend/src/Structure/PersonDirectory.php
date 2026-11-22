<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\Coach;
use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * La seule porte pour lire l'identité d'un coach (prénom, nom, mail, téléphone, genre). Tout
 * lecteur passe par elle, SQL brut compris, et reçoit un {@see PersonIdentity}.
 *
 * L'identité vit sur la fiche de personne au club ({@see Person}) que le coach désigne par
 * `personId`. Toute lecture est bornée par le club du coach : une fiche d'un autre club n'est
 * jamais rendue (elle ne peut d'ailleurs pas être désignée : clé étrangère composée avec le club).
 * `emailsOfClub` interroge `person` sur la connexion de l'application, sous RLS, avec le club en
 * clause explicite.
 *
 * Un coach tout juste construit (`new Coach($person)`) porte sa fiche en mémoire : elle est lue sans
 * requête. L'EntityManager n'est absent que dans les tests unitaires qui construisent un lecteur à
 * la main (défaut `new PersonDirectory` de cinq classes) : un coach relu de la base y lève une
 * `LogicException`, jamais une identité inventée ; en application, l'autowiring le fournit.
 */
final readonly class PersonDirectory
{
    public function __construct(
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    public function of(Coach $coach): PersonIdentity
    {
        return $this->identityOf($this->personOf($coach));
    }

    /**
     * La fiche que le coach désigne, dans son club. Pour l'écrire (correction d'identité) ou en
     * créer le rôle d'une autre saison ; un lecteur d'identité passe par {@see of()}.
     */
    public function personOf(Coach $coach): Person
    {
        $given = $coach->personGivenAtCreation();
        if ($given instanceof Person) {
            return $given;
        }
        $person = $this->entityManager()->find(Person::class, $coach->getPersonId());
        if (!$person instanceof Person || $person->getClubId() !== $coach->getClubId()) {
            throw new LogicException(\sprintf('Le coach %s ne désigne aucune fiche de personne de son club.', $coach->getId()));
        }

        return $person;
    }

    /**
     * Une requête pour toutes les fiches pas encore chargées, puis la même règle que {@see of()}.
     *
     * @param iterable<Coach> $coaches
     *
     * @return array<string, PersonIdentity> clé = identifiant du coach
     */
    public function forCoaches(iterable $coaches): array
    {
        $list = [];
        $pending = [];
        foreach ($coaches as $coach) {
            if (!$coach->personGivenAtCreation() instanceof Person) {
                $pending[$coach->getPersonId()] = true;
            }
            $list[] = $coach;
        }
        if ([] !== $pending) {
            // Charge les fiches dans la carte d'identité de l'EntityManager : `personOf` les y relit sans requête.
            $this->entityManager()->createQuery('SELECT p FROM App\Entity\Person p WHERE p.id IN (:ids)')
                ->setParameter('ids', array_keys($pending))
                ->getResult();
        }

        $identities = [];
        foreach ($list as $coach) {
            $identities[$coach->getId()] = $this->of($coach);
        }

        return $identities;
    }

    /**
     * Les adresses (telles que saisies) des personnes du club qui en ont une, bornées par le club en
     * clause explicite, sous RLS.
     *
     * @return list<string>
     */
    public function emailsOfClub(string $clubId): array
    {
        /** @var list<mixed> $emails */
        $emails = $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT email FROM person WHERE club_id = ? AND email IS NOT NULL',
            [$clubId],
        );

        return array_values(array_filter($emails, \is_string(...)));
    }

    private function identityOf(Person $person): PersonIdentity
    {
        return new PersonIdentity(
            firstName: $person->getFirstName(),
            lastName: $person->getLastName(),
            email: $person->getEmail(),
            phone: $person->getPhone(),
            gender: $person->getGender(),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManager ?? throw new LogicException('PersonDirectory sans base : seul un coach construit avec sa fiche (new Coach($person)) se lit sans EntityManager.');
    }
}
