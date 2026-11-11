<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * La file Messenger du transport `async` n'a pas pu être lue en entier (Redis injoignable, ou UNE
 * entrée indécodable). Le message nomme la raison et l'entrée en cause, jamais son contenu.
 * Le rattrapage saute alors son bras PENDING : on ne déclare jamais « interrompu » un placement
 * qu'on n'a pas pu chercher dans la file.
 */
final class PlacementQueueUnreadable extends RuntimeException {}
