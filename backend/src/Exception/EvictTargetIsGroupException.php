<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand le créneau à évincer, désigné par `evictSlotId` sur un déplacement de GROUPE, est
 * lui-même une SÉANCE DE GROUPE (`sharedTrainingBlockId` non NULL). Évincer/échanger un groupe
 * occupant est un geste distinct (deux lots mutualisés qui se disputent une case) qui arrive dans
 * un lot ultérieur — ici on refuse NOMMÉMENT avant tout appel moteur, RIEN n'est écrit ni supprimé.
 * Le contrôleur la mappe en 422 `evict_target_is_group`.
 *
 * ⚠ Ce refus est posé AVANT le contrôle de verrou : une séance de groupe porte son verrou
 * d'ANCRAGE (lockLevel ≠ NONE), qui n'est pas un verrou gestionnaire — on ne veut donc pas qu'elle
 * tombe en `target_locked`, mais qu'elle soit explicitement renvoyée comme « occupée par un groupe ».
 */
final class EvictTargetIsGroupException extends RuntimeException {}
