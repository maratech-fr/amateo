<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\State\Provider\UserStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-02: self-only. No GetCollection (email enumeration) and no bare Post
// (registration is /api/register). Get is restricted to the authenticated user's
// own record in the provider. PUT retiré (nettoyage API) : le front édite le
// profil via PATCH /api/me, jamais cette ressource. No Delete: club_user has no FK
// cascade, so deleting a User would orphan its memberships and a sole admin
// could lock its club out of management — account erasure (GDPR) needs a
// dedicated cascade flow, not open CRUD.
#[ApiResource(shortName: 'User', operations: [
    new Get,
], provider: UserStateProvider::class)]
class UserResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $email = '';

    #[Groups(['read'])]
    public string $firstName = '';

    #[Groups(['read'])]
    public string $lastName = '';

    #[Groups(['read'])]
    public ?DateTimeImmutable $emailVerifiedAt = null;

    public static function fromEntity(User $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->email = $entity->getEmail();
        $dto->firstName = $entity->getFirstName();
        $dto->lastName = $entity->getLastName();
        $dto->emailVerifiedAt = $entity->getEmailVerifiedAt();

        return $dto;
    }
}
