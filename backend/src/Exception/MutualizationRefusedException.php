<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Lot 9 — un refus NOMMÉ du geste « mutualiser depuis la génération » : la case est déjà occupée
 * (capacité), le total des séances communes dépasse le volume d'une équipe (Σ), une équipe est
 * inactive, l'ensemble d'équipes forme déjà un bloc, la cible est le socle de saison (période
 * seulement), ou une équipe déjà placée n'a pas désigné la séance que la commune remplace.
 *
 * Le contrôleur la mappe en 422 avec son message (déjà humain, aucun identifiant interne) ; comme
 * l'écriture vit dans une transaction, RIEN n'est écrit quand elle est levée.
 */
final class MutualizationRefusedException extends RuntimeException {}
