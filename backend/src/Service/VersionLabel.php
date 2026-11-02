<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Le libellé court d'une version (« V2 ») : son rang de création dans son plan.
 *
 * Miroir déclaré : `frontend/src/features/planning/lib/versions.ts::versionLabelOf`, mêmes cas
 * partagés (`versionLabel.parity.json`), gardé par `VersionLabelMirrorParityTest`.
 */
final class VersionLabel
{
    /**
     * @param list<array{id: string, createdAt: string}> $versions les versions d'UN plan
     *
     * @return string|null null si `$id` n'est pas dans le plan
     */
    public static function of(array $versions, string $id): ?string
    {
        usort(
            $versions,
            static fn (array $a, array $b): int => new DateTimeImmutable($a['createdAt']) <=> new DateTimeImmutable($b['createdAt']),
        );
        foreach ($versions as $index => $version) {
            if ($version['id'] === $id) {
                return 'V' . ($index + 1);
            }
        }

        return null;
    }
}
