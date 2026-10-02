<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleResource;
use App\Dto\ScheduleInput;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Service\ManagementAccessGuard;
use App\Service\OverlayManager;
use App\Service\ScheduleCapabilityResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @extends AbstractStateProcessor<Schedule, ScheduleInput, ScheduleResource>
 */
class ScheduleStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly OverlayManager $overlayManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleGuard $socleGuard,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    /** SEC-07: schedule create/rename/delete is cockpit management surface. */
    protected function requiresManagementRole(): bool
    {
        return true;
    }

    protected function getEntityClass(): string
    {
        return Schedule::class;
    }

    /**
     * ADR-0002 C4 : POST crée une version SOUS un plan nommé (`schedulePlanId`) — un overlay
     * de période — ou, si omis, sous le plan SEASON (le socle). On valide que le plan
     * appartient au club, on en dérive la saison, et pour un overlay on applique les gardes
     * de période (une génération en cours bloque ; le socle doit être pointé, inv. 13). La
     * version créée n'est PAS montrée tant qu'elle n'est pas validée (lot D-b : « actif » =
     * plan.chosenScheduleId, plus de pointeur inverse posé à la création).
     *
     * @param ScheduleInput $input
     *
     * @return ScheduleResource
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        // La version s'écrit dans la saison ACTIVE de la requête — celle dont le parent vient
        // de vérifier qu'elle est éditable (assertWritable, garde archive). On la résout AVANT
        // de valider le plan, pour exiger qu'il lui appartienne (voir plus bas).
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
        if (null === $resolvedSeasonId) {
            throw new UnprocessableEntityHttpException('Aucune saison n\'a pu être déterminée pour ce planning.');
        }

        $entry = null;
        $weekGuardEntryId = null;
        $planId = $input->schedulePlanId;
        // P2-7 : un POST « de saison » = pas de plan (→ plan SEASON par défaut) OU un plan
        // explicitement de type SEASON. C'est ce POST-là que la garde d'unicité sérialise
        // sous le verrou de plan-scope (dans la transaction), pour refuser une nouvelle
        // version tant que le socle de la saison est en vigueur (chosenOfSeasonPlan non-null).
        $isSeasonPost = null === $planId;
        if (null !== $planId) {
            $plan = $this->schedulePlanProvisioner->fetchPlanContext($planId);
            // Club ET saison : le plan doit être du club ET de la saison active. Nommer un plan
            // d'une AUTRE saison (archivée, N-1) contournerait la garde archive — le POST passe
            // assertWritable sur la saison active, puis s'estampillait de la saison du plan. Avant
            // C4, le find() season-filtré de l'entrée refusait déjà ce cas (422) ; fetchPlanContext
            // est en SQL brut (non filtré), on rétablit donc la garde explicitement. Check club
            // explicite aussi (l'identity map peut surfacer une ligne d'un autre club).
            if (null === $plan
                || (null !== $clubId && $plan['clubId'] !== $clubId)
                || $plan['seasonId'] !== $resolvedSeasonId) {
                throw new UnprocessableEntityHttpException('Ce planning n\'existe plus — rechargez la page.');
            }
            $isSeasonPost = SchedulePlanType::SEASON === $plan['type'];
            if (SchedulePlanType::SEASON !== $plan['type']) {
                // Overlay (plan CLOSURE/HOLIDAY). Une sœur en cours de solve bloque : on ne
                // réécrit jamais un solve en vol (miroir de la garde saison).
                $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
                    'clubId' => $plan['clubId'],
                    'seasonId' => $resolvedSeasonId,
                    'schedulePlanId' => $planId,
                    'status' => [ScheduleStatus::PENDING, ScheduleStatus::GENERATING],
                ]);
                if ($inFlight > 0) {
                    throw new ConflictHttpException('Une génération est déjà en cours pour cette période — attendez sa fin.');
                }
                // P2-5 E1 (exclusivité bloc/semaines) : gardée plus bas, SOUS le verrou du
                // plan-scope de l'entrée, dans la transaction de création — un verrou xact
                // pris ici, hors transaction, se relâcherait au statement suivant.
                $weekGuardEntryId = $plan['calendarEntryId'];
                // inv. 13 : un plan secondaire se bâtit SUR le calendrier de base pointé.
                $this->socleGuard->assertSeasonPlanChosen($resolvedSeasonId);
            }
        }

        /** @var Schedule $schedule */
        $schedule = $this->createEntityFromInput($input);
        if (null !== $clubId) {
            $schedule->setClubId($clubId);
        }
        $schedule->setSeasonId($resolvedSeasonId);

        // ADR-0002 C4 : le plan est explicite (overlay/période) ou, par défaut, le plan
        // SEASON de la saison (le socle). La version le porte AVANT linkSchedule, qui ne
        // fait plus que la numéroter.
        $resolvedPlanId = $planId ?? $this->schedulePlanProvisioner->ensureSeasonPlanId($resolvedSeasonId);
        if (null === $resolvedPlanId) {
            throw new UnprocessableEntityHttpException('No schedule plan could be resolved for this schedule.');
        }
        $schedule->setSchedulePlanId($resolvedPlanId);

        // ADR-0002 inv. 12 — le nom vit sur LE PLAN ; une version n'a pas d'identité
        // produit (le sélecteur l'étiquette « V2 — 20 oct. 14:32 »). Le client n'a donc
        // rien à nommer : quand il ne fournit pas de nom, la version hérite de celui de
        // son plan. Les trois appelants frontend en inventaient un chacun de leur côté —
        // « Version de période », « Plan de période », « Planning {date} » — et le
        // premier ressortait tel quel dans la liste des plannings et le nom du PDF.
        // Le chemin overlay a DÉJÀ la ligne du plan en main (validée plus haut) : la relire
        // ferait deux SELECT identiques par POST. Seul le chemin « de saison », qui résout son
        // plan à l'instant, doit interroger la base.
        if (null === $input->name) {
            $schedule->setName($plan['name'] ?? $this->schedulePlanProvisioner->versionNameFor($resolvedPlanId));
        }

        // Atomic (like RegenerateController): the row and its version number commit
        // together. A linkSchedule failure must never leave a committed-but-unnumbered
        // schedule occupying the period slot.
        $this->entityManager->wrapInTransaction(function () use ($schedule, $weekGuardEntryId, $isSeasonPost, $resolvedSeasonId): void {
            // P2-7 (planning lifecycle) : le socle en vigueur est unique. Sous le verrou de
            // plan-scope de la saison (tenu jusqu'au commit — un pg_advisory_xact_lock pris
            // hors transaction se relâcherait au statement suivant, cf. l.103-105), si le plan
            // SEASON pointe déjà une version choisie, on refuse d'en préparer une autre :
            // rouvrir (dépointer) est le geste explicite qui rouvre l'espace de travail.
            if ($isSeasonPost) {
                $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($resolvedSeasonId));
                $this->socleGuard->assertSeasonPlanNotChosen($resolvedSeasonId);
            }
            // P2-5 E1 (exclusivité bloc/semaines) : une période DÉCOUPÉE ne se génère
            // plus « d'un bloc ». SOUS le verrou du plan-scope de l'entrée (tenu
            // jusqu'au commit) : le POST d'un enfant prend le même verrou avant sa
            // garde symétrique (planHasVersions) — sans lui, un découpage et une
            // génération bloc concurrents passeraient tous deux (TOCTOU, revue #262).
            if (null !== $weekGuardEntryId) {
                $this->schedulePlanProvisioner->lockPlanScope($weekGuardEntryId);
                if ($this->entryHasWeekChildren($weekGuardEntryId)) {
                    throw new ConflictHttpException('Cette période est découpée en semaines : générez chaque semaine, pas la période d’un bloc.');
                }
            }
            $this->entityManager->persist($schedule);

            // ADR-0002 C4 : numérote la version dans son plan (déjà posé ci-dessus).
            // La version n'est pas « active » : elle le devient à la validation (le plan
            // pointe sa chosenScheduleId), jamais à la création (lot D-b).
            $this->schedulePlanProvisioner->linkSchedule($schedule);

            $this->entityManager->flush();
        });

        return $this->mapEntityToOutput($schedule);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        // Purge the schedule's slots/diagnostics (no FK cascade) and reset any
        // period entry pointing at it, before the parent removes the row.
        $id = $uriVariables['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
            if ($schedule instanceof Schedule && (null === $clubId || $schedule->getClubId() === $clubId)) {
                // ADR-0002 inv. 1 : la version CHOISIE ancre le plan — la rouvrir
                // (dépointer) avant de la supprimer. Les trois refus DÉLÈGUENT à
                // ScheduleCapabilityResolver (P2-8) : le bloc `capabilities` sérialisé
                // lit EXACTEMENT ces mêmes prédicats — canDelete false ⇔ ce 409.
                if ($this->capabilityResolver->isChosen($schedule)) {
                    throw new ConflictHttpException('La version choisie ne peut pas être supprimée. Rouvrez le planning d\'abord.');
                }
                // A version whose solve is still running cannot be deleted out
                // from under the worker (its import would resurrect artifacts).
                if ($this->capabilityResolver->isInFlight($schedule)) {
                    throw new ConflictHttpException('Ce planning est encore en cours de génération — attendez la fin avant de le supprimer.');
                }
                // La DERNIÈRE version terminée du plan de la saison ancre la saison :
                // la supprimer laisserait un club établi sans aucun calendrier, donc
                // sans cockpit ni matchs (inv. 8/16 le renverrait au wizard guidé, ses
                // matchs orphelins). Rouvrir dépointe (inv. 2) mais ne doit pas ouvrir
                // cette porte : le geste pour remplacer un planning, c'est régénérer.
                if ($this->capabilityResolver->isLastFinishedSeasonVersion($schedule)) {
                    throw new ConflictHttpException('C\'est le seul planning de la saison — régénérez-en un autre plutôt que de supprimer celui-ci.');
                }
                // Atomique : purgeScheduleArtifacts relâche le pointeur via un
                // UPDATE brut qui s'auto-commit. Sans transaction, un échec du
                // remove/flush du parent laisserait le pointeur vidé alors que la
                // version survit (même idiome que deleteOverlayForEntry).
                $this->entityManager->wrapInTransaction(function () use ($schedule, $uriVariables, $clubId): void {
                    $this->overlayManager->purgeScheduleArtifacts($schedule);
                    parent::processDelete($uriVariables, $clubId);
                });

                return;
            }
        }

        parent::processDelete($uriVariables, $clubId);
    }

    /**
     * @param ScheduleInput $input
     */
    protected function createEntityFromInput(object $input): Schedule
    {
        $entity = new Schedule;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        // SEC-07 review finding: a client-supplied status could fabricate a
        // COMPLETED/VALIDATED plan without ever running the solver (the PUT
        // path already forbids this — the POST path must match). Only DRAFT
        // may be set at creation; lifecycle transitions go through the
        // dedicated endpoints (generate/validate/reopen).
        if (null !== $input->status && ScheduleStatus::DRAFT->value !== $input->status) {
            throw new ConflictHttpException('A schedule is created as DRAFT; use the lifecycle endpoints to change its status.');
        }
        $entity->setStatus(ScheduleStatus::DRAFT);
        if (null !== $input->solverSeed) {
            $entity->setSolverSeed($input->solverSeed);
        }

        return $entity;
    }

    /**
     * PUT retiré de cette ressource (nettoyage API) — le renommage d'une version passe
     * désormais par PUT /api/schedule_plans/{id}, le cycle de vie par les routes dédiées.
     * L'abstraction impose seulement que ce foyer existe ; il n'est plus atteignable.
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        throw new LogicException('La modification (PUT) n\'est pas exposée pour les plannings.');
    }

    /**
     * @param Schedule $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleResource
    {
        return ScheduleResource::fromEntity($entity);
    }

    /** P2-5 E1 : cette période a-t-elle des semaines enfants ? SQL brut (hors season_filter), RLS scope le club. */
    private function entryHasWeekChildren(string $calendarEntryId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM calendar_entry WHERE parent_entry_id = :eid LIMIT 1',
            ['eid' => $calendarEntryId],
        );
    }
}
