<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\ImplicitRuleSetting;
use App\Entity\PlanConstraint;
use App\Entity\PlanTeam;
use App\Entity\PlanVenue;
use App\Entity\Reservation;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\VenueOccupancy;
use App\Entity\VenueTrainingSlot;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SchedulePlanType;
use App\Structure\FamilyCatalog;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * La copie d'un plan vers un autre, lue sur le catalogue des familles (lot 4).
 *
 * - {@see copy} : la NAISSANCE d'un plan de période depuis le plan de saison, et « Reprendre la grille »
 *   d'un gymnase. Recopie les familles déclarées `copiedAtPlanBirth` ({@see FamilyCatalog::copiedAtPlanBirth}) :
 *   la grille sans libellé de groupe, les occupations d'un gymnase présent, les groupes mutualisés et leurs
 *   membres si la nature du plan cible le veut ({@see SchedulePlanType::copiesSharedBlocksAtBirth}, sans le nom
 *   du groupe), les règles de bien-être (copie totale). Jamais les réservations. Bornée à un gymnase : sa grille
 *   et ses occupations, rien d'autre.
 * - {@see copyAll} : la copie d'un plan de période vers un plan sœur. Toutes les familles que le plan porte
 *   ({@see FamilyCatalog::carriedByPlan}), telles quelles : libellés de grille et noms de groupe compris,
 *   réservations et lignes de composition comprises.
 *
 * Limite héritée, conservée telle quelle : la naissance ne copie pas le nom d'un groupe mutualisé, la copie
 * sœur le copie.
 *
 * Une famille du catalogue sans routine de copie ici lève : l'ajouter au catalogue sans dire comment elle se
 * copie ne passe pas en silence.
 *
 * À la naissance, la grille, les groupes et les règles de bien-être du plan de saison se lisent par la porte de
 * l'en vigueur ({@see InForce}). SQL brut pour écrire la grille et les règles, et pour les occupations : `season_filter`
 * épingle les lectures ORM à la saison ACTIVE de la requête, or un plan peut naître pour une autre saison
 * (transition). RLS scope le club. L'appelant flush.
 */
final readonly class PlanBirth
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FamilyCatalog $familyCatalog,
        private InForce $inForce,
    ) {}

    /**
     * La source est le plan de saison de la saison du plan cible (les deux appelants le passent) : la grille, les
     * groupes et les règles de bien-être s'en lisent par la porte de l'en vigueur ({@see InForce::rows}).
     *
     * @param string|null $venueId null = tout le plan ; sinon « Reprendre la grille » de ce gymnase
     */
    public function copy(string $sourcePlanId, string $targetPlanId, ?string $venueId = null): void
    {
        $target = $this->planRow($targetPlanId);
        foreach ($this->familyCatalog->copiedAtPlanBirth() as $family) {
            switch ($family) {
                case VenueTrainingSlot::class:
                    $this->copyGrid($target, $venueId);
                    break;
                case VenueOccupancy::class:
                    $this->copyOccupancies($sourcePlanId, $target, $venueId);
                    break;
                case SharedTrainingBlock::class:
                    if (null === $venueId && $target['type']->copiesSharedBlocksAtBirth()) {
                        $this->copySharedBlocks(
                            $target,
                            array_map(
                                static fn (SharedTrainingBlock $block): array => ['id' => $block->getId(), 'commonSessions' => $block->getCommonSessions(), 'label' => null],
                                $this->inForce->rows($target['seasonId'], SharedTrainingBlock::class),
                            ),
                            array_map(
                                static fn (SharedTrainingBlockTeam $member): array => ['blockId' => $member->getBlockId(), 'teamId' => $member->getTeamId()],
                                $this->inForce->rows($target['seasonId'], SharedTrainingBlockTeam::class),
                            ),
                            copyLabel: false,
                        );
                    }
                    break;
                case SharedTrainingBlockTeam::class:
                    break; // copiés avec leur groupe
                case ImplicitRuleSetting::class:
                    if (null === $venueId) {
                        // Copie TOTALE des règles de bien-être du plan de saison (valeur, sinon défaut ; opt-in à OFF) :
                        // un plan « tout au défaut » garde ses lignes, donc reste distinguable d'un plan sans copie.
                        $this->copyWellbeingRules($target, $this->inForce->rows($target['seasonId'], ImplicitRuleSetting::class));
                    }
                    break;
                default:
                    throw new LogicException(\sprintf('%s est déclarée copiée à la naissance d\'un plan, mais PlanBirth ne sait pas la copier.', $family));
            }
        }
    }

    /** La copie d'un plan de période vers un plan sœur : toutes les familles que le plan porte, telles quelles. */
    public function copyAll(string $sourcePlanId, string $targetPlanId): void
    {
        $target = $this->planRow($targetPlanId);
        $clubId = $target['clubId'];
        $seasonId = $target['seasonId'];
        foreach ($this->familyCatalog->carriedByPlan() as $family) {
            switch ($family) {
                case PlanTeam::class:
                    foreach ($this->entityManager->getRepository(PlanTeam::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new PlanTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setTeamId($row->getTeamId())->setParticipating($row->isParticipating())->setSessionsPerWeek($row->getSessionsPerWeek())->setMaxSessionsPerDay($row->getMaxSessionsPerDay())->setSource($row->getSource()),
                        );
                    }
                    break;
                case PlanConstraint::class:
                    foreach ($this->entityManager->getRepository(PlanConstraint::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new PlanConstraint)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setConstraintId($row->getConstraintId())->setApplies($row->applies()),
                        );
                    }
                    break;
                case PlanVenue::class:
                    foreach ($this->entityManager->getRepository(PlanVenue::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new PlanVenue)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setVenueId($row->getVenueId())->setParticipating($row->isParticipating())->setClosureLifted($row->isClosureLifted())->setDayOverrides($row->getDayOverrides()),
                        );
                    }
                    break;
                case VenueTrainingSlot::class:
                    foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setVenueId($row->getVenueId())->setDayOfWeek($row->getDayOfWeek())->setStartTime($row->getStartTime())
                                ->setDurationMinutes($row->getDurationMinutes())->setCapacity($row->getCapacity())->setGroupLabel($row->getGroupLabel()),
                        );
                    }
                    break;
                case Reservation::class:
                    foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setTeamId($row->getTeamId())->setLabel($row->getLabel())->setVenueId($row->getVenueId())
                                ->setDayOfWeek($row->getDayOfWeek())->setStartTime($row->getStartTime())->setDurationMinutes($row->getDurationMinutes())
                                ->setCoachLateToleranceMinutes($row->getCoachLateToleranceMinutes()),
                        );
                    }
                    break;
                case SharedTrainingBlock::class:
                    $this->copySharedBlocks(
                        $target,
                        array_map(
                            static fn (SharedTrainingBlock $block): array => ['id' => $block->getId(), 'commonSessions' => $block->getCommonSessions(), 'label' => $block->getLabel()],
                            $this->entityManager->getRepository(SharedTrainingBlock::class)->findBy(['schedulePlanId' => $sourcePlanId]),
                        ),
                        array_map(
                            static fn (SharedTrainingBlockTeam $member): array => ['blockId' => $member->getBlockId(), 'teamId' => $member->getTeamId()],
                            $this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['schedulePlanId' => $sourcePlanId]),
                        ),
                        copyLabel: true,
                    );
                    break;
                case SharedTrainingBlockTeam::class:
                    break; // copiés avec leur groupe
                case ImplicitRuleSetting::class:
                    foreach ($this->entityManager->getRepository(ImplicitRuleSetting::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new ImplicitRuleSetting)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setRuleKey($row->getRuleKey())->setIntensity($row->getIntensity())->setParams($row->getParams()),
                        );
                    }
                    break;
                case VenueOccupancy::class:
                    foreach ($this->entityManager->getRepository(VenueOccupancy::class)->findBy(['schedulePlanId' => $sourcePlanId]) as $row) {
                        $this->entityManager->persist(
                            (new VenueOccupancy)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($targetPlanId)
                                ->setVenueId($row->getVenueId())->setDayOfWeek($row->getDayOfWeek())->setStartTime($row->getStartTime())
                                ->setDurationMinutes($row->getDurationMinutes())->setLabel($row->getLabel()),
                        );
                    }
                    break;
                default:
                    throw new LogicException(\sprintf('Le plan porte la famille %s, mais PlanBirth ne sait pas la copier.', $family));
            }
        }
    }

    /**
     * La grille du plan de saison, lue par la porte de l'en vigueur, écrite ligne à ligne. Le libellé de groupe
     * appartient au planning de saison : une période naît SANS libellé. La colonne `group_label` reste dans l'INSERT
     * (sa place dans la copie est surveillée par PeriodSlotCopyKeepsEveryColumnTest), mais les VALUES émettent NULL
     * plutôt que la valeur du modèle.
     *
     * @param array{id: string, clubId: string, seasonId: string, type: SchedulePlanType} $target
     */
    private function copyGrid(array $target, ?string $venueId): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ($this->inForce->rows($target['seasonId'], VenueTrainingSlot::class) as $slot) {
            if (null !== $venueId && $slot->getVenueId() !== $venueId) {
                continue;
            }
            $connection->executeStatement(
                'INSERT INTO venue_training_slot (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, start_time, duration_minutes, capacity, group_label, schedule_plan_id) '
                . 'VALUES (gen_random_uuid(), 1, now(), now(), :clubId, :seasonId, :venueId, :dayOfWeek, :startTime, :durationMinutes, :capacity, NULL, :planId)',
                [
                    'clubId' => $slot->getClubId(),
                    'seasonId' => $slot->getSeasonId(),
                    'venueId' => $slot->getVenueId(),
                    'dayOfWeek' => $slot->getDayOfWeek(),
                    'startTime' => $slot->getStartTime(),
                    'durationMinutes' => $slot->getDurationMinutes(),
                    'capacity' => $slot->getCapacity(),
                    'planId' => $target['id'],
                ],
                ['dayOfWeek' => ParameterType::INTEGER, 'startTime' => Types::TIME_IMMUTABLE, 'durationMinutes' => ParameterType::INTEGER, 'capacity' => ParameterType::INTEGER],
            );
        }
    }

    /**
     * Les règles de bien-être du plan de saison, une ligne par règle : la valeur de la saison si elle existe, sinon le
     * défaut du contrat (HARD, opt-in à OFF, seuil au défaut = params NULL). Une ligne que le plan porte déjà n'est pas
     * réécrite.
     *
     * @param array{id: string, clubId: string, seasonId: string, type: SchedulePlanType} $target
     * @param list<ImplicitRuleSetting>                                                   $seasonRules
     */
    private function copyWellbeingRules(array $target, array $seasonRules): void
    {
        $byKey = [];
        foreach ($seasonRules as $rule) {
            $byKey[$rule->getRuleKey()->value] = $rule;
        }
        $connection = $this->entityManager->getConnection();
        foreach (ImplicitRuleKey::cases() as $key) {
            $rule = $byKey[$key->value] ?? null;
            $connection->executeStatement(
                'INSERT INTO implicit_rule_setting (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, rule_key, intensity, params) '
                . 'VALUES (gen_random_uuid(), 1, now(), now(), :clubId, :seasonId, :planId, :ruleKey, :intensity, :params) '
                . 'ON CONFLICT (club_id, season_id, schedule_plan_id, rule_key) DO NOTHING',
                [
                    'clubId' => $target['clubId'],
                    'seasonId' => $target['seasonId'],
                    'planId' => $target['id'],
                    'ruleKey' => $key->value,
                    'intensity' => ($rule?->getIntensity() ?? ($key->isOptIn() ? ImplicitRuleIntensity::OFF : ImplicitRuleIntensity::HARD))->value,
                    'params' => $rule?->getParams(),
                ],
                ['params' => Types::JSON],
            );
        }
    }

    /**
     * Les occupations du plan source, telles quelles et sans contrôle de chevauchement (un chevauchement de la
     * source est copié). Seules les occupations dont le gymnase EXISTE sont copiées : une occupation dormante
     * (gymnase absent de la version chargée) reste dans la source et revient avec son gymnase.
     *
     * @param array{id: string, clubId: string, seasonId: string, type: SchedulePlanType} $target
     */
    private function copyOccupancies(string $sourcePlanId, array $target, ?string $venueId): void
    {
        $sql = 'INSERT INTO venue_occupancy (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, start_time, duration_minutes, label, schedule_plan_id) '
            . 'SELECT gen_random_uuid(), 1, now(), now(), o.club_id, o.season_id, o.venue_id, o.day_of_week, o.start_time, o.duration_minutes, o.label, :planId '
            . 'FROM venue_occupancy o WHERE o.club_id = :clubId AND o.season_id = :seasonId AND o.schedule_plan_id = :sourcePlanId '
            . 'AND EXISTS (SELECT 1 FROM venue v WHERE v.id = o.venue_id)';
        $params = ['planId' => $target['id'], 'clubId' => $target['clubId'], 'seasonId' => $target['seasonId'], 'sourcePlanId' => $sourcePlanId];
        if (null !== $venueId) {
            $sql .= ' AND o.venue_id = :venueId';
            $params['venueId'] = $venueId;
        }
        $this->entityManager->getConnection()->executeStatement($sql, $params);
    }

    /**
     * Les groupes mutualisés du plan source et leurs membres, rattachés au groupe copié (nouvel UUID par groupe et
     * par membre). La garde Σ n'est PAS rejouée, aucun filtrage au roster non plus : un membre écarté de la période
     * est géré par la demande au moteur, qui abandonne un groupe tombé sous deux membres actifs.
     *
     * Les groupes et membres sont lus par l'appelant : à la naissance (`$copyLabel` faux), ceux du plan de saison par la
     * porte de l'en vigueur, et le nom du groupe n'est pas copié ; la copie sœur (`$copyLabel` vrai) lit le plan source
     * par l'ORM et copie le nom.
     *
     * @param array{id: string, clubId: string, seasonId: string, type: SchedulePlanType} $target
     * @param list<array{id: string, commonSessions: int, label: ?string}>                $blocks
     * @param list<array{blockId: string, teamId: string}>                                $members
     */
    private function copySharedBlocks(array $target, array $blocks, array $members, bool $copyLabel): void
    {
        $clubId = $target['clubId'];
        $seasonId = $target['seasonId'];

        $copyOf = [];
        foreach ($blocks as $block) {
            $copy = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($target['id'])
                ->setCommonSessions($block['commonSessions']);
            if ($copyLabel) {
                $copy->setLabel($block['label']);
            }
            $this->entityManager->persist($copy);
            $copyOf[$block['id']] = $copy->getId();
        }
        foreach ($members as $member) {
            $blockId = $copyOf[$member['blockId']] ?? null;
            if (null === $blockId) {
                continue;
            }
            $this->entityManager->persist(
                (new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($target['id'])
                    ->setBlockId($blockId)->setTeamId($member['teamId']),
            );
        }
    }

    /** @return array{id: string, clubId: string, seasonId: string, type: SchedulePlanType} */
    private function planRow(string $planId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT club_id, season_id, type FROM schedule_plan WHERE id = :pid',
            ['pid' => $planId],
        );
        if (false === $row) {
            throw new LogicException(\sprintf('Le plan %s n\'existe pas.', $planId));
        }

        return ['id' => $planId, 'clubId' => (string) $row['club_id'], 'seasonId' => (string) $row['season_id'], 'type' => SchedulePlanType::from((string) $row['type'])];
    }
}
