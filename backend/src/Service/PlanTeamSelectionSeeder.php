<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Repository\PriorityTierRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-63 PR 5 (D-e) — MATÉRIALISE CÔTÉ SERVEUR le tri d'équipes par défaut d'un plan de
 * période de REPRISE. MAISON UNIQUE du calcul (décision fondateur 2026-10-10).
 *
 * Le transfert au planning écrit des overrides (activer/désactiver une équipe). Or le wizard
 * ne sème son défaut « Fanion + importantes » qu'à la PREMIÈRE écriture d'override d'un plan
 * neuf, gardé par `schedule_plan.team_selection_initialized`
 * ({@see SchedulePlanProvisioner::markPlanTeamSelectionInitialized}). Un transfert qui
 * écrirait un override AVANT la première ouverture du wizard désarmerait ce seed en silence :
 * tout le club resterait « actif par défaut », au lieu de reprendre S+A seulement. On
 * matérialise donc le seed ICI, en amont de la 1re écriture du transfert.
 *
 * ⚠ MIROIR DÉCLARÉ du seed du wizard (frontend/src/features/wizard/steps/PeriodTeams.tsx) :
 * le wizard sème le MÊME défaut à l'ouverture de l'étape Équipes d'un plan neuf. Deux
 * producteurs du même tri — la règle ne doit diverger dans aucun des deux. La règle backend
 * est gardée par le test `PlanTeamSelectionSeederTest` ; la PARITÉ cross-stack avec le front
 * (extraction du calcul en lib pure + test de parité + entrée au registre
 * `FrontRederivationRegistryTest`) reste à câbler côté frontend. Règle (E3) :
 *  - REPRISE (holiday) : seuls les 2 premiers RANGS (par id de rang croissant : S puis A)
 *    reprennent ; REPLI sur le meilleur rang réellement présent si aucune équipe en S/A
 *    (un petit club tout en loisir ne reprend jamais « personne ») ;
 *  - FERMETURE (closure) : structure verrouillée — tout le club reste actif, aucun retrait.
 * Un override `isActive=false`, SOURCE NULLE (indistinguable d'un décochage manuel), est
 * écrit pour chaque équipe NON retenue ; les retenues gardent le défaut actif (zéro ligne).
 *
 * N'écrit RIEN pour un plan déjà initialisé (l'appelant garde) et ne flush pas : l'appelant
 * commite sa transaction.
 */
final class PlanTeamSelectionSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamRepository $teamRepository,
        private readonly PriorityTierRepository $priorityTierRepository,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Les ids d'équipe RETENUES (actives) par le défaut — source unique partagée par le seed
     * et son test de parité, sans toucher la base. Reçoit l'état déjà chargé pour rester pur.
     *
     * @param list<Team>         $teams toutes les équipes du club/saison
     * @param list<PriorityTier> $tiers tous les rangs (référentiel global)
     *
     * @return array<string, true> set des ids retenus
     */
    public static function keptTeamIds(array $teams, array $tiers, bool $isClosure): array
    {
        if ($isClosure) {
            // Fermeture : structure verrouillée, tout le club reste actif → tout est « retenu ».
            $all = [];
            foreach ($teams as $team) {
                $all[$team->getId()] = true;
            }

            return $all;
        }

        $tierIds = array_map(static fn (PriorityTier $t): int => $t->getId(), $tiers);
        sort($tierIds);
        // Les 2 premiers RANGS par id (S=1, A=2…), et non par occupation : un rang vide ne
        // doit pas promouvoir un rang inférieur. Parité stricte avec le slice(0, 2) du front.
        $topRankIds = array_fill_keys(\array_slice($tierIds, 0, 2), true);

        $keptByRank = [];
        foreach ($teams as $team) {
            if (isset($topRankIds[$team->getPriorityTierId()])) {
                $keptByRank[$team->getId()] = true;
            }
        }
        if ([] !== $keptByRank) {
            return $keptByRank;
        }

        // GARDE-FOU (parité front) : aucune équipe en S/A → repli sur le MEILLEUR rang
        // réellement présent (le plus petit id d'équipe présente), jamais « personne ».
        $bestPresentTierId = null;
        foreach ($teams as $team) {
            if (null === $bestPresentTierId || $team->getPriorityTierId() < $bestPresentTierId) {
                $bestPresentTierId = $team->getPriorityTierId();
            }
        }
        $kept = [];
        foreach ($teams as $team) {
            if ($team->getPriorityTierId() === $bestPresentTierId) {
                $kept[$team->getId()] = true;
            }
        }

        return $kept;
    }

    /**
     * Matérialise le seed : écrit un override `isActive=false` (source nulle) pour chaque
     * équipe non retenue, puis marque le plan comme initialisé. No-op si déjà initialisé.
     */
    public function seed(string $schedulePlanId, string $clubId, string $seasonId, bool $isClosure): void
    {
        if ($this->schedulePlanProvisioner->planTeamSelectionInitialized($schedulePlanId)) {
            return;
        }

        /** @var list<Team> $teams */
        $teams = $this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        /** @var list<PriorityTier> $tiers */
        $tiers = $this->priorityTierRepository->findAll();
        $keepIds = self::keptTeamIds($teams, $tiers, $isClosure);

        $overrideRepository = $this->entityManager->getRepository(TeamPeriodOverride::class);
        foreach ($teams as $team) {
            if (isset($keepIds[$team->getId()])) {
                continue; // retenue : le défaut actif suffit, aucune ligne.
            }
            $override = $overrideRepository->findOneBy(['schedulePlanId' => $schedulePlanId, 'teamId' => $team->getId()]);
            if (!$override instanceof TeamPeriodOverride) {
                $override = (new TeamPeriodOverride)
                    ->setClubId($clubId)
                    ->setSeasonId($seasonId)
                    ->setSchedulePlanId($schedulePlanId)
                    ->setTeamId($team->getId());
                $this->entityManager->persist($override);
            }
            // Source NULLE : le seed est indistinguable d'un décochage manuel (pas « via
            // doléances » — c'est le transfert, plus tard, qui estampille ce qu'il touche).
            $override->setIsActive(false);
        }

        // Le drapeau pose que « ce plan a été configuré » : le wizard ne re-sèmera plus.
        $this->schedulePlanProvisioner->markPlanTeamSelectionInitialized($schedulePlanId);
    }
}
