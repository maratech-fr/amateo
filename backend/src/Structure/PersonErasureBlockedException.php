<?php

declare(strict_types=1);

namespace App\Structure;

use RuntimeException;

/**
 * Une génération ou un placement de matchs tient le verrou du club : l'effacement est refusé, rien
 * n'a bougé (le run en cours réécrirait son nom après la validation).
 */
final class PersonErasureBlockedException extends RuntimeException {}
