<?php

declare(strict_types=1);

namespace App\Structure;

use Attribute;

/**
 * Une colonne `guid` qui désigne un objet d'une autre famille (jamais `id`, `clubId`, `seasonId` :
 * le niveau les porte). Répétable pour une colonne polymorphe (une déclaration par famille visée).
 *
 * `reason` est obligatoire pour KEEP et CUSTOM. `owner` : la famille se purge PAR cette cible
 * (elle n'a pas les colonnes club+saison qui la borneraient seule) — une seule par famille.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class RefersTo
{
    /** @param class-string $family */
    public function __construct(
        public string $family,
        public OnDelete $onDelete,
        public ?string $reason = null,
        public bool $owner = false,
    ) {}
}
