<?php

declare(strict_types=1);

namespace App\Access;

use Attribute;

/**
 * Une fonction du compte de l'utilisateur connecté (son profil, ses nouveautés, l'effacement de
 * son compte), hors de tout module de club. Sur la classe ou sur la méthode.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AccountFunction {}
