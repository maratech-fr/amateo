<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\Coach;
use Doctrine\DBAL\Connection;
use LogicException;

/**
 * La seule porte pour lire l'identité d'un coach (prénom, nom, mail, téléphone, genre). Tout
 * lecteur passe par elle, SQL brut compris, et reçoit un {@see PersonIdentity} : le jour où
 * l'identité quitte la ligne du coach pour une fiche de personne au club, seul ce fichier change.
 *
 * Aujourd'hui l'identité vit encore sur la ligne `coach` : `of` et `forCoaches` la lisent sur les
 * coachs déjà chargés (donc déjà bornés au club qui les a chargés, filtre de club actif ou non,
 * worker Messenger et page publique de doléances compris) ; `emailsOfClub` interroge `coach` avec
 * le club en clause explicite.
 *
 * La connexion n'est absente que dans les tests unitaires qui construisent un lecteur à la main
 * (patron des défauts de `MailFrom`) : en application, l'autowiring la fournit.
 */
final readonly class PersonDirectory
{
    public function __construct(
        private ?Connection $connection = null,
    ) {}

    public function of(Coach $coach): PersonIdentity
    {
        return new PersonIdentity(
            firstName: $coach->getFirstName(),
            lastName: $coach->getLastName(),
            email: $coach->getEmail(),
            phone: $coach->getPhone(),
            gender: $coach->getGender(),
        );
    }

    /**
     * @param iterable<Coach> $coaches
     *
     * @return array<string, PersonIdentity> clé = identifiant du coach
     */
    public function forCoaches(iterable $coaches): array
    {
        $identities = [];
        foreach ($coaches as $coach) {
            $identities[$coach->getId()] = $this->of($coach);
        }

        return $identities;
    }

    /**
     * Les adresses (telles que saisies) des coachs du club qui en ont une, bornées par le club en
     * clause explicite.
     *
     * @return list<string>
     */
    public function emailsOfClub(string $clubId): array
    {
        if (!$this->connection instanceof Connection) {
            throw new LogicException('PersonDirectory::emailsOfClub exige une connexion à la base.');
        }

        /** @var list<mixed> $emails */
        $emails = $this->connection->fetchFirstColumn(
            'SELECT email FROM coach WHERE club_id = ? AND email IS NOT NULL',
            [$clubId],
        );

        return array_values(array_filter($emails, \is_string(...)));
    }
}
