<?php

declare(strict_types=1);

namespace App\Deletion;

/**
 * Ce qu'une suppression VA faire, tel qu'on l'annonce au gestionnaire AVANT qu'il confirme.
 *
 * @phpstan-type ImpactLine array{key: string, count: int, one: string, many: string}
 */
final readonly class DeletionImpact
{
    /**
     * @param list<ImpactLine> $lines familles touchées, comptes à zéro déjà écartés
     */
    public function __construct(
        /** Le serveur REFUSERA le geste (périmètre engagé) : l'écran ne doit pas l'offrir. */
        public bool $blocked,
        public ?string $reason,
        public array $lines,
        /**
         * Combien des séances touchées vivent dans une version EN VIGUEUR — le planning que
         * le club distribue aujourd'hui. C'est une précision SUR une ligne d'impact, pas une
         * destruction de plus : la ligne dit combien de séances partent, celle-ci dit combien
         * font mal. Les plannings terminés du club se diront à régénérer (le geste déplace
         * l'empreinte de structure servie par plan), ce que l'écran annonce aussi.
         */
        public int $slotsInForce,
        /**
         * P4-270 — les matchs DÉJÀ PLACÉS dans ce gymnase (statut PLACED/SUBMITTED/VALIDATED, donc
         * ceux qui portent une salle) qui, à sa disparition, REDEVIENNENT « à placer »
         * (`FixtureVenueLossMarker` : venueId null, statut UNPLACED, raison `venue_lost`). Le geste
         * n'est PAS refusé — un gymnase qui ferme, ça arrive, et le match reste récupérable — mais
         * c'est annoncé. `declaredFixtures` en est le SOUS-ENSEMBLE déjà déposé à la fédération.
         * Zéro hors suppression de gymnase.
         */
        public int $placedFixtures,
        /**
         * DOC-2 — les matchs DÉJÀ DÉCLARÉS à la fédération (`SUBMITTED`/`VALIDATED`) qui vont
         * perdre leur salle. Le geste n'est PAS refusé (un gymnase qui ferme, ça arrive, et le
         * match redevient « à placer » donc récupérable) : il est ANNONCÉ, parce qu'il faudra
         * le re-soumettre. Sous-ensemble de `placedFixtures`. Zéro hors suppression de gymnase.
         */
        public int $declaredFixtures,
    ) {}
}
