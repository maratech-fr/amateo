<?php

declare(strict_types=1);

namespace App\Access;

use Attribute;

/**
 * Une fonction de club servie par un contrôleur : son module et son public. Sur la classe ou sur
 * la méthode ; celle de la méthode l'emporte. Aucun défaut : le public s'écrit toujours, il ne se
 * déduit jamais de la méthode HTTP. Une fonction qui produit une sortie payante la déclare
 * (`output`) ; une fonction qui ne produit rien ne dit rien.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ClubFunction
{
    public function __construct(
        public Module $module,
        public Audience $audience,
        public ?Output $output = null,
    ) {}
}
