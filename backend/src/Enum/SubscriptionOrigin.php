<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\ClubSubscription;

/**
 * D'où vient la ligne d'abonnement d'une saison ({@see ClubSubscription}) : le geste « Offre » du
 * support (et son relais de développement), la conversion des deux anciens champs du club, ou un
 * décor semé. Fermée en base par un CHECK.
 */
enum SubscriptionOrigin: string
{
    use HasValues;

    case SUPPORT = 'support';

    case CONVERSION = 'conversion';

    case SEED = 'seed';
}
