<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand un ÉCHANGE atomique (`POST /api/schedule-slots/swap`) désigne deux créneaux qui
 * N'APPARTIENNENT PAS AU MÊME PLANNING. Un échange ne vaut qu'à l'intérieur d'une version : deux
 * versions sœurs ont leurs propres grilles, les apparier n'aurait pas de sens et contournerait la
 * baseline figée par planning. Le contrôleur la mappe en 422 `swap_cross_schedule` — RIEN n'est
 * écrit, le moteur n'est pas appelé.
 */
final class SwapCrossScheduleException extends RuntimeException {}
