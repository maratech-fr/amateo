<?php

declare(strict_types=1);

namespace App\Structure;

use App\Plans\PlanBirth;
use Attribute;

/**
 * La déclaration d'une famille, posée sur chaque entité Doctrine (balayage :
 * `FamilyDeclarationSweepTest`). Lue par {@see FamilyCatalog}.
 *
 * `keptAtSeasonPurge` / `keptAtErasure` : pourquoi la purge de saison / l'effacement du club
 * épargne la famille ; null = elle n'est pas épargnée. Une exclusion est une décision, la raison
 * la porte.
 *
 * `copiedAtPlanBirth` : une famille de niveau plan dit si la naissance d'un plan la recopie depuis le
 * plan de saison ({@see PlanBirth::copy}) ; null = elle ne le dit pas (les autres niveaux).
 * Le balayage refuse une famille de niveau plan qui ne le dit pas.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Family
{
    public function __construct(
        public Level $level,
        public ?string $keptAtSeasonPurge = null,
        public ?string $keptAtErasure = null,
        public ?bool $copiedAtPlanBirth = null,
    ) {}
}
