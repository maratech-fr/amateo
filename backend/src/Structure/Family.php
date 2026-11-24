<?php

declare(strict_types=1);

namespace App\Structure;

use App\Plans\PlanBirth;
use App\Plans\PlanStates;
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
 *
 * `cold` : ce que la copie d'une version emporte ({@see PlanStates}) — `[]` = libre (rien n'est copié),
 * {@see self::ALL} = tous ses champs hors identité et métadonnées, ou la liste de ses champs froids.
 * `coldRows` : le filtre des lignes froides (champ => valeur ; null = « vide »), ex. les seules lignes de
 * coach principal, les seules contraintes permanentes. `ofSocle` : famille de niveau saison dont le froid
 * appartient au socle (copié avec les versions du plan de saison seul) ; une famille froide de niveau saison
 * sans `ofSocle` est lue par la demande de tout plan et copiée avec toute version. `copiedToNextSeason` : « Préparer
 * la saison suivante » la reprend. `cold` et `copiedToNextSeason` sont obligatoires aux niveaux saison et
 * plan : le balayage refuse une famille qui ne les dit pas.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Family
{
    /** Tous les champs de la famille sont froids (identité, club, saison, plan et horodatage exceptés). */
    public const array ALL = ['*'];

    /**
     * @param list<string>|null    $cold
     * @param array<string, mixed> $coldRows
     */
    public function __construct(
        public Level $level,
        public ?string $keptAtSeasonPurge = null,
        public ?string $keptAtErasure = null,
        public ?bool $copiedAtPlanBirth = null,
        public ?array $cold = null,
        public array $coldRows = [],
        public bool $ofSocle = false,
        public ?bool $copiedToNextSeason = null,
    ) {}
}
