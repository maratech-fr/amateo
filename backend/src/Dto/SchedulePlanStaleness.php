<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * P4-173 — la péremption de la version que ce plan POINTE. Chaque drapeau dit qu'une
 * catégorie de données a changé depuis la génération de cette version : le planning
 * décrit alors un état antérieur (périmé, pas faux). Le cockpit l'affiche pour dire
 * « à régénérer » sans redériver la règle — le serveur la calcule, l'écran l'affiche.
 *
 * Ce bloc n'est présent que lorsqu'un plan pointe une version dont la fenêtre reste
 * devant ; quand le plan ne pointe rien encore, ou que sa fenêtre est déjà révolue,
 * `staleness` vaut `null` (voir le champ du plan). Les trois drapeaux peuvent tous
 * être faux : le bloc existe, mais rien n'est périmé.
 */
final class SchedulePlanStaleness
{
    public function __construct(
        #[Groups(['read'])]
        public bool $manuallyEdited,
        #[Groups(['read'])]
        public bool $constraintsChanged,
        #[Groups(['read'])]
        public bool $resourcesChanged,
    ) {}
}
