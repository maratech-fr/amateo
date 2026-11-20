<?php

declare(strict_types=1);

namespace App\Access;

use Attribute;

/**
 * Une ressource d'API de club (classe de `ApiResource/`) : son module, le public de ses lectures
 * (GET) et celui de ses écritures. Ne vaut que pour les opérations servies par API Platform ; une
 * opération à contrôleur maison sert la déclaration de son contrôleur. Aucun défaut.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ClubResource
{
    public function __construct(
        public Module $module,
        public Audience $read,
        public Audience $write,
    ) {}
}
