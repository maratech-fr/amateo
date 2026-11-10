<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

/**
 * Un corps riche de note (JSON ProseMirror) refusé par la liste blanche du serveur. Le message est
 * écrit pour être lu par la superadmin (400) ; le détail technique part en journal, jamais ici.
 */
final class InvalidRichDocException extends InvalidArgumentException {}
