<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SchedulePlanResource;
use App\Dto\CreatePeriodPlanInput;
use App\Dto\SchedulePlanInput;
use App\Entity\SchedulePlan;
use App\Service\ManagementAccessGuard;
use App\Service\PeriodWindowUniquenessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Écritures client sur un plan : renommer (PUT, inv. 12 — le nom public vit sur le
 * plan), et depuis l'amendement ADR-0002 du 2026-07-24, CRÉER le plan d'une période
 * par le geste explicite « Adapter » (POST {calendarEntryId}).
 *
 * Le plan de saison reste provisionné par le serveur ; le pointeur (version choisie)
 * se désigne en validant une version — jamais ici.
 *
 * @extends AbstractStateProcessor<SchedulePlan, SchedulePlanInput|CreatePeriodPlanInput, SchedulePlanResource>
 */
class SchedulePlanStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PeriodWindowUniquenessGuard $windowUniquenessGuard,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    /** SEC-07 : créer/renommer un planning est une écriture cockpit (management). */
    protected function requiresManagementRole(): bool
    {
        return true;
    }

    protected function getEntityClass(): string
    {
        return SchedulePlan::class;
    }

    /**
     * ADR-0002 (amendement 2026-07-24) — LE PLAN NAÎT DU GESTE D'ADAPTER.
     *
     * Gardes, puis provisioning idempotent (le verrou de plan-scope sérialise avec
     * un POST d'entrée-semaine concurrent — exclusivité bloc/semaines) :
     * - l'entrée doit exister, dans CE club et CETTE saison (lecture SQL brute,
     *   même rétablissement explicite de garde que ScheduleStateProcessor) ;
     * - closure/holiday uniquement (inv. 9 : cutoff/mutualisation ne portent pas
     *   de plan) ;
     * - une mère DÉCOUPÉE ne reporte jamais de plan-bloc : 422 tant que des
     *   semaines-enfants existent. État, pas verrou définitif — supprimer toutes
     *   les semaines rouvre le geste bloc (symétrie fondateur : on ne bascule
     *   jamais automatiquement, on supprime puis on recrée).
     *
     * @param SchedulePlanInput|CreatePeriodPlanInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        if (!$input instanceof CreatePeriodPlanInput) {
            throw new UnprocessableEntityHttpException('calendarEntryId est requis.');
        }
        $entryId = $input->calendarEntryId;
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);

        /** @var array{club_id: string, season_id: string, kind: string, period_type: ?string, start_date: string, end_date: string, parent_entry_id: ?string}|false $entry */
        $entry = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT club_id, season_id, kind, period_type, start_date, end_date, parent_entry_id FROM calendar_entry WHERE id = :eid',
            ['eid' => $entryId],
        );
        if (false === $entry
            || (null !== $clubId && $entry['club_id'] !== $clubId)
            || (null !== $resolvedSeasonId && $entry['season_id'] !== $resolvedSeasonId)) {
            throw new UnprocessableEntityHttpException('Unknown calendar entry.');
        }
        if ('period' !== $entry['kind'] || !\in_array($entry['period_type'], ['closure', 'holiday'], true)) {
            throw new UnprocessableEntityHttpException('Seule une période de fermeture ou de vacances porte un planning.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($entry, $entryId): SchedulePlanResource {
            // P4-172 — verrou club+saison AVANT le verrou d'entrée (club d'abord, entrée
            // ensuite) : deux gestes « Adapter » concurrents sur des entrées DIFFÉRENTES du
            // même club se sérialisent ici, sinon chacun passe la garde d'unicité (qui compare
            // des fenêtres du club entier) avant que l'autre commite → deux plans sur la même
            // semaine. L'ordre club→entrée est le même sur les trois chemins (pas d'ABBA).
            $this->schedulePlanProvisioner->lockClubWindows($entry['club_id'], $entry['season_id']);
            // Verrou AVANT la lecture des enfants : un POST de semaine concurrent
            // (même scope) est sérialisé — pas de plan-bloc minté pendant une découpe.
            $this->schedulePlanProvisioner->lockPlanScope($entryId);

            $hasChildren = false !== $this->entityManager->getConnection()->fetchOne(
                'SELECT 1 FROM calendar_entry WHERE parent_entry_id = :eid LIMIT 1',
                ['eid' => $entryId],
            );
            if ($hasChildren) {
                throw new UnprocessableEntityHttpException('Cette période est découpée en semaines : adaptez chaque semaine.');
            }

            // ADR-0002 inv. 4 (P2-38) — une seule planification par fenêtre. DANS le verrou :
            // deux gestes « Adapter » concurrents sur des fenêtres qui se recoupent seraient
            // sinon tous deux passés par un contrôle vide. La famille (même ancêtre racine) est
            // exclue par la garde : ré-adapter la MÊME entrée reste idempotent.
            $this->windowUniquenessGuard->assertWindowFree(
                $entry['club_id'],
                $entry['season_id'],
                $entry['parent_entry_id'] ?? $entryId,
                mb_substr($entry['start_date'], 0, 10),
                mb_substr($entry['end_date'], 0, 10),
            );

            $planId = $this->schedulePlanProvisioner->provisionPeriodPlan($entryId);
            if (null === $planId) {
                throw new UnprocessableEntityHttpException('Seule une période de fermeture ou de vacances porte un planning.');
            }

            $plan = $this->entityManager->getRepository(SchedulePlan::class)->find($planId);
            if (!$plan instanceof SchedulePlan) {
                throw new UnprocessableEntityHttpException('Unknown schedule plan.');
            }

            return $this->mapEntityToOutput($plan);
        });
    }

    /**
     * @param SchedulePlanInput|CreatePeriodPlanInput $input
     */
    protected function createEntityFromInput(object $input): SchedulePlan
    {
        // processPost est surchargé — ce chemin du parent n'est plus atteint.
        throw new LogicException('SchedulePlanStateProcessor::processPost est la seule porte de création.');
    }

    /**
     * @param SchedulePlan                            $entity
     * @param SchedulePlanInput|CreatePeriodPlanInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (!$input instanceof SchedulePlanInput) {
            throw new UnprocessableEntityHttpException('Le nom du planning ne peut pas être vide.');
        }
        // `name` UNIQUEMENT.
        $entity->setName(trim($input->name));
    }

    /**
     * @param SchedulePlan $entity
     */
    protected function mapEntityToOutput(object $entity): SchedulePlanResource
    {
        return SchedulePlanResource::fromEntity($entity);
    }
}
