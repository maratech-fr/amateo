<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UserInput
{
    #[Assert\Email]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'L’adresse e-mail ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 120, maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $firstName = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 120, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $lastName = null;
}
