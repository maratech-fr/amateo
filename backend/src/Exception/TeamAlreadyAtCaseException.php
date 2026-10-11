<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand une retouche de planning ferait siéger la MÊME équipe DEUX FOIS à la même case
 * (même version de plan + équipe + gymnase + jour + heure de début) : déplacer une séance sur une
 * autre séance de sa propre équipe SANS éviction, placer une séance là où l'équipe siège déjà, ou
 * déplacer une séance de bloc sur une case où un membre siège déjà par ailleurs.
 *
 * Le moteur fusionne les séances par (gymnase, jour, heure) et ne « voit » donc pas le doublon de
 * même équipe : la garde vit au BACKEND, AVANT l'appel moteur, et RIEN n'est écrit quand elle est
 * levée. Le contrôleur la mappe en 422 `team_already_at_case` avec son message (déjà humain, noms
 * réels, jour en toutes lettres, aucun identifiant interne).
 */
final class TeamAlreadyAtCaseException extends RuntimeException {}
