<?php

declare(strict_types=1);

namespace App\Export;

/** Un lieu CERTAIN d'un match : jamais un gymnase supposé ni une ville seule. */
final readonly class MatchesExportPlace
{
    public function __construct(
        public string $name,
        /** Déjà assemblée : « 12 rue X, 69500 Bron ». */
        public ?string $address,
        /** Aller simple en voiture depuis le siège du club — extérieur seulement. */
        public ?int $travelMinutes,
    ) {}
}
