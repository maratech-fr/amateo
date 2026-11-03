<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand une retouche de planning donnerait à une équipe DEUX séances le même jour, chevauchement
 * ou non. Refus AVANT le moteur, RIEN n'est écrit. Le contrôleur la mappe en 422
 * `team_already_trains_that_day` avec son message (déjà humain, noms réels).
 */
final class TeamAlreadyTrainsThatDayException extends RuntimeException {}
