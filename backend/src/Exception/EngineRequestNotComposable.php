<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * La demande au moteur ne peut pas être composée pour cette version : sa période a disparu
 * (`overlay_entry_missing`) ou la version de départ nommée par la régénération n'existe plus
 * dans le même plan (`source_version_missing`). Levée par `EngineRequestComposer::compose()` ;
 * `GenerateScheduleHandler` la traduit en échec terminal nommé par la raison.
 */
final class EngineRequestNotComposable extends RuntimeException
{
    public const string OVERLAY_ENTRY_MISSING = 'overlay_entry_missing';
    public const string SOURCE_VERSION_MISSING = 'source_version_missing';

    /** @param self::OVERLAY_ENTRY_MISSING|self::SOURCE_VERSION_MISSING $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('Engine request not composable: %s.', $reason));
    }
}
