<?php

declare(strict_types=1);

namespace App\AdminJob;

/**
 * Closed catalog of club support actions (SA4) — the "act without building a
 * feature" lever. Adding an action = one entry here once the CLI command
 * exists. NEVER accept a command or arguments from the request: this list is
 * the entire allowlist (same doctrine as AdminJobCatalog, SA3).
 *
 * Suspension / approbation fallback : volontairement ABSENTES (décision
 * fondateur 2026-07-18) — différées au premier cas réel, leur métier n'est
 * pas tranché (effet exact d'une suspension, périmètre du fallback).
 */
final readonly class AdminActionCatalog
{
    /** @return list<AdminActionDefinition> */
    public function all(): array
    {
        return [
            new AdminActionDefinition(
                'ffbb-resync',
                'Resynchroniser depuis la FFBB',
                'Ré-importe l\'identité FFBB du club (nom, coordonnées, logo, comité/ligue) — le même ré-import que le bouton de la fiche club, déclenché par le support.',
                'app:clubs:ffbb-resync',
                dangerous: false,
            ),
            new AdminActionDefinition(
                'mark-next-season-paid',
                'Marquer la saison suivante payée',
                'Enregistre le paiement de la SAISON SUIVANTE du club (abonnement par saison) : ouvre le gate de bascule. Idempotent — le marqueur ne recule jamais.',
                'app:clubs:mark-next-season-paid',
                dangerous: false,
            ),
            new AdminActionDefinition(
                'set-plan',
                'Offre',
                'Attribue une offre au club et, pour toute offre payante, marque la saison encaissée — une offre payante retombe sur Découverte tant que la saison n\'est pas réglée. Découverte se pose seule (aucune saison à encaisser).',
                'app:clubs:set-plan',
                dangerous: false,
                // Schéma FERMÉ : le plan (enum des codes d'offre, miroir de SetClubPlanCommand)
                // et la saison encaissée (enum current|next). Règle fondateur portée par le
                // schéma : `paidSeason` est REQUIS pour toute offre payante (Bêta comprise —
                // sans marqueur elle naît expirée) et INTERDIT sur Découverte.
                argumentSchema: new AdminActionArgumentSchema([
                    new AdminActionArgument(
                        'plan',
                        'Offre',
                        '--plan',
                        [
                            'decouverte' => 'Découverte',
                            'essentiel' => 'Essentiel',
                            'club' => 'Club',
                            'grand-club' => 'Grand club',
                            'beta' => 'Bêta',
                        ],
                    ),
                    new AdminActionArgument(
                        'paidSeason',
                        'Saison encaissée',
                        '--paid-season',
                        [
                            'current' => 'Saison en cours (le club a réglé)',
                            'next' => 'Saison suivante',
                        ],
                        gateArgument: 'plan',
                        forbiddenWhenGateIn: ['decouverte'],
                    ),
                ]),
            ),
            new AdminActionDefinition(
                'reset-credits',
                'Réinitialiser les crédits de sortie',
                'Remet à zéro le pool de crédits de sortie du club (offre Découverte). Le pool n\'est pas rechargeable par l\'utilisateur.',
                'app:clubs:reset-credits',
                dangerous: false,
            ),
            new AdminActionDefinition(
                'reset-current-season',
                'Réinitialiser la saison courante',
                'Vide toutes les données de la saison courante du club (structure, calendrier, plannings). La saison et le club survivent — le club repart au wizard.',
                'app:clubs:reset-season',
                dangerous: true,
            ),
            new AdminActionDefinition(
                'purge-old-seasons',
                'Purger les anciennes saisons',
                'Supprime les saisons au-delà de la rétention (N-2 et plus anciennes) de ce club.',
                'app:seasons:purge',
                dangerous: true,
                // Geste humain explicite : la grâce post-pivot du cron ne s'applique pas
                // (sinon l'action confirmée serait un no-op silencieux tout l'été).
                arguments: ['--no-grace' => true],
                // Même clé de verrou/historique que le job planifié : le geste manuel et
                // le cron de 03:00 balaient les mêmes tables — ils DOIVENT se sérialiser.
                runKey: 'purge-seasons',
            ),
        ];
    }

    public function find(string $key): ?AdminActionDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }
}
