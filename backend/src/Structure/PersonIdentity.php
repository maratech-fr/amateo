<?php

declare(strict_types=1);

namespace App\Structure;

use App\Enum\CoachGender;

/**
 * L'identité d'une personne du club, telle qu'un lecteur la reçoit de {@see PersonDirectory} :
 * prénom, nom de famille, mail, téléphone, genre. Le nom de famille peut être vide (un coach
 * saisi par son seul prénom).
 */
final readonly class PersonIdentity
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public CoachGender $gender,
    ) {}

    /** « Prénom Nom », sans espace au bord quand l'un des deux est vide. */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
