<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand un ÉCHANGE atomique (`POST /api/schedule-slots/swap`) ne porte pas sur deux côtés
 * DISTINCTS et sans équipe commune :
 *  - l'occupant désigné EST le créneau source lui-même (ou, source de GROUPE, l'un de ses membres) ;
 *  - l'occupant appartient à une équipe DÉJÀ engagée du côté source (source de groupe dont l'occupant
 *    est le créneau d'une équipe membre) — l'échange formerait un cycle d'écriture que l'index UNIQUE
 *    (schedule, équipe, gymnase, jour, heure) rejetterait, et le résultat serait de toute façon un
 *    non-geste (l'équipe garde ses mêmes séances).
 *
 * Le contrôleur la mappe en 422 `swap_with_self` — RIEN n'est écrit, le moteur n'est pas appelé.
 */
final class SwapWithSelfException extends RuntimeException {}
