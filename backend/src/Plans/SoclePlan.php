<?php

declare(strict_types=1);

namespace App\Plans;

use App\Enum\SchedulePlanType;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le socle a son plan (lot 4, PR 2) : chaque ligne de planning du socle — grille, occupations,
 * réservations, groupes mutualisés et leurs membres, règles de bien-être — porte l'identifiant du plan
 * de saison de sa saison. « Plan nul » ne veut plus rien dire en base ; une recherche à nul rendrait
 * une liste vide sans erreur (balayage `NoNullPlanBranchTest`, fil-piège en test).
 *
 * Seule exception, la frontière de l'API (jusqu'au lot 6) : l'écran envoie et lit encore
 * `schedulePlanId: null` pour le socle. {@see fromApi} et {@see toApi} sont les deux seuls endroits
 * qui traduisent.
 *
 * SQL brut : le plan d'une saison se lit hors du `season_filter` (la saison demandée n'est pas
 * forcément l'active) ; la RLS borne le club.
 */
final class SoclePlan
{
    /** @var array<string, bool> plan => est-ce un plan de saison ? (la nature d'un plan ne change jamais) */
    private array $isSeasonPlan = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        // Paresseux : le provisioner dépend du constructeur de demandes, qui lit le plan du socle.
        #[Autowire(lazy: true)]
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Le plan de saison d'une saison. Une saison née avant les plans (donnée de test, saison écrite à
     * la main) le reçoit ici, comme à la conversion ; lève si la saison n'existe pas.
     */
    public function idOf(string $seasonId): string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE season_id = :sid AND type = :season',
            ['sid' => $seasonId, 'season' => SchedulePlanType::SEASON->value],
        );
        if (\is_string($id)) {
            return $id;
        }

        return $this->schedulePlanProvisioner->ensureSeasonPlanId($seasonId)
            ?? throw new LogicException(\sprintf('La saison %s n\'existe pas : elle n\'a pas de plan de saison.', $seasonId));
    }

    /** Ce plan est-il le plan de saison (le socle) ? Un plan inconnu ne l'est pas. */
    public function isSeasonPlan(string $planId): bool
    {
        return $this->isSeasonPlan[$planId] ??= SchedulePlanType::SEASON->value === $this->entityManager->getConnection()->fetchOne(
            'SELECT type FROM schedule_plan WHERE id = :pid',
            ['pid' => $planId],
        );
    }

    /** Entrée de l'API : `null` = le socle, rangé sur le plan de saison. */
    public function fromApi(?string $requested, string $seasonId): string
    {
        return $requested ?? $this->idOf($seasonId);
    }

    /** Sortie de l'API : une ligne du socle se lit encore `schedulePlanId: null` (jusqu'au lot 6). */
    public function toApi(string $planId, string $seasonId): ?string
    {
        return $this->isSeasonPlan($planId) ? null : $planId;
    }
}
