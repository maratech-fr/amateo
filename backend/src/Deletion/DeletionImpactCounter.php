<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Coach;
use App\Entity\Fixture;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\FixtureStatus;
use App\Service\DisablesTenantFilters;
use App\Service\TeamEngagementGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P3-16 — compte ce qu'une suppression emporterait, **sans rien modifier**.
 *
 * ⚑ Il ne connaît AUCUNE liste à lui : il parcourt le plan de {@see CascadePlan}, celui-là
 * même qu'`EntityCascadeDeleter` exécute. C'est tout l'objet du lot : avant, l'écran comptait
 * depuis son cache react-query et annonçait 2 ou 3 familles quand la cascade en emportait
 * dix — un gestionnaire confirmait une destruction qu'on ne lui avait jamais montrée.
 *
 * Filtres Doctrine désactivés comme dans le deleter (ils aliasent le nom de table, invalide
 * pour le mot réservé `constraint`) : la borne club+saison explicite de chaque étape EST la
 * frontière, RLS la double en base.
 */
final readonly class DeletionImpactCounter
{
    use DisablesTenantFilters;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamEngagementGuard $teamEngagementGuard,
    ) {}

    public function forTeam(Team $team): DeletionImpact
    {
        $target = new DeletionTarget($team->getId(), $team->getClubId(), $team->getSeasonId());
        // Le périmètre engagé refuse la suppression AVANT tout cascade
        // (`TeamStateProcessor::cascadeBeforeDelete`) : on l'annonce plutôt que de laisser
        // l'écran proposer un geste qui rendra 409.
        $engaged = $this->teamEngagementGuard->isEngaged($team->getId());

        return $this->build(
            CascadePlan::forTeam(),
            $target,
            blocked: $engaged,
            reason: $engaged ? 'Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération. Elle ne peut plus être supprimée.' : null,
            slotField: 'teamId',
            placedFixtures: 0,
            declaredFixtures: 0,
        );
    }

    public function forVenue(Venue $venue): DeletionImpact
    {
        $target = new DeletionTarget($venue->getId(), $venue->getClubId(), $venue->getSeasonId());

        return $this->build(
            CascadePlan::forVenue(),
            $target,
            blocked: false,
            reason: null,
            slotField: 'venueId',
            placedFixtures: $this->placedFixturesOnVenue($target),
            declaredFixtures: $this->declaredFixturesOnVenue($target),
        );
    }

    public function forCoach(Coach $coach): DeletionImpact
    {
        $target = new DeletionTarget($coach->getId(), $coach->getClubId(), $coach->getSeasonId());

        return $this->build(
            CascadePlan::forCoach(),
            $target,
            blocked: false,
            reason: null,
            // Une séance ne meurt pas avec son coach, elle le perd (`ClearFieldStep`) — mais
            // c'est bien la même colonne qu'on compte pour savoir si le planning en vigueur
            // est touché.
            slotField: 'coachId',
            placedFixtures: 0,
            declaredFixtures: 0,
        );
    }

    /**
     * Le CRÉNEAU de disponibilité. Deux différences avec les trois entités saison-scopées :
     * sa cible porte le triplet et la couche (ses enfants ne citent jamais son id), et il n'a
     * ni séance « en vigueur » propre ni match — le verrou HARD qu'il emporte est déjà une
     * ligne d'impact à part entière, la nommer deux fois n'aiderait personne.
     */
    public function forSlot(VenueTrainingSlot $slot): DeletionImpact
    {
        $this->disableTenantFilters($this->entityManager);
        $target = SlotDeletionTarget::of($slot);

        /** @var list<array{key: string, count: int, one: string, many: string}> $lines */
        $lines = [];
        foreach (CascadePlan::forSlot() as $step) {
            $label = $step->label();
            if (null === $label) {
                continue;
            }
            $count = $step->count($this->entityManager, $target);
            if (0 === $count) {
                continue;
            }
            $lines[] = ['key' => $label->key, 'count' => $count, 'one' => $label->one, 'many' => $label->many];
        }

        return new DeletionImpact(blocked: false, reason: null, lines: $lines, slotsInForce: 0, placedFixtures: 0, declaredFixtures: 0);
    }

    /**
     * @param list<CascadeStep> $steps
     */
    private function build(array $steps, DeletionTarget $target, bool $blocked, ?string $reason, string $slotField, int $placedFixtures, int $declaredFixtures): DeletionImpact
    {
        $this->disableTenantFilters($this->entityManager);

        /** @var list<array{key: string, count: int, one: string, many: string}> $lines */
        $lines = [];
        foreach ($steps as $step) {
            $label = $step->label();
            if (null === $label) {
                continue; // étape délibérément silencieuse (cf. CascadeStep::label)
            }
            $count = $step->count($this->entityManager, $target);
            if (0 === $count) {
                continue; // ne jamais annoncer un dégât qui n'existe pas
            }
            $lines[] = ['key' => $label->key, 'count' => $count, 'one' => $label->one, 'many' => $label->many];
        }

        return new DeletionImpact($blocked, $reason, $lines, $this->slotsInForce($target, $slotField), $placedFixtures, $declaredFixtures);
    }

    /**
     * Les séances touchées qui vivent dans une version EN VIGUEUR — celle que le plan POINTE
     * (ADR-0002), saison comme période. Aucun pointeur → aucune séance en vigueur.
     */
    private function slotsInForce(DeletionTarget $target, string $slotField): int
    {
        /** @var list<string> $chosen */
        $chosen = array_values(array_filter(array_map(
            static fn (SchedulePlan $plan): ?string => $plan->getChosenScheduleId(),
            // AUD-BCK-17 — clubId EXPLICITE, comme partout ailleurs dans ce compteur. Les filtres
            // Doctrine sont désactivés ici (cf. docblock de classe) : la borne club+saison portée
            // par chaque étape EST la frontière, ce n'est pas une ceinture de plus. Sans effet
            // pratique aujourd'hui (une saison appartient à un club, et le COUNT final re-borne
            // club+saison), mais c'était le SEUL endroit du lot où la convention cassait — et une
            // convention qui souffre une exception cesse d'en être une.
            $this->entityManager->getRepository(SchedulePlan::class)->findBy([
                'clubId' => $target->clubId,
                'seasonId' => $target->seasonId,
            ]),
        )));
        if ([] === $chosen) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(ScheduleSlotTemplate::class, 'e')
            ->where(\sprintf('e.%s = :value', $slotField))
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scheduleId IN (:chosen)')
            ->setParameter('value', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('chosen', $chosen)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * P4-270 — les matchs DÉJÀ PLACÉS dans ce gymnase (statut PLACED/SUBMITTED/VALIDATED : ceux
     * qui portent une salle). À la disparition du gymnase ils REDEVIENNENT « à placer »
     * ({@see FixtureVenueLossStep}) — un compteur pour le dire, sur-ensemble de `declaredFixtures`.
     * Même patron que {@see declaredFixturesOnVenue} : borne club+saison explicite, filtres
     * Doctrine désactivés dans ce compteur (RLS double en base).
     */
    private function placedFixturesOnVenue(DeletionTarget $target): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Fixture::class, 'e')
            ->where('e.venueId = :venueId')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.status IN (:placed)')
            ->setParameter('venueId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('placed', [FixtureStatus::PLACED, FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** DOC-2 — les matchs déjà déposés à la fédération qui perdront cette salle. */
    private function declaredFixturesOnVenue(DeletionTarget $target): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Fixture::class, 'e')
            ->where('e.venueId = :venueId')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.status IN (:declared)')
            ->setParameter('venueId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->setParameter('declared', [FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
