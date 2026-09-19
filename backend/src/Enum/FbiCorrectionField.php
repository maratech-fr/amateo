<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le champ d'une rencontre sur lequel l'appli et FBI divergent, et que le
 * gestionnaire doit reporter DANS FBI (registre « à corriger dans FBI »). Mêmes
 * trois champs que le périmètre de réconciliation ({@see App\Service\FbiFixtureImporter}
 * `DEVIATION_FIELDS`) et que `Fixture.pendingDeviations` — mais l'angle est INVERSE :
 * ici la valeur de l'APPLI fait foi (« garder l'appli »), FBI est en retard, il faut
 * le mettre à jour à la main.
 */
enum FbiCorrectionField: string
{
    use HasValues;

    case DATE = 'date';

    case KICKOFF = 'kickoff';

    case VENUE = 'venue';
}
