<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueTrainingSlotResource;
use App\Dto\VenueTrainingSlotInput;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<VenueTrainingSlot, VenueTrainingSlotInput, VenueTrainingSlotResource>
 */
class VenueTrainingSlotStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    protected function getEntityClass(): string
    {
        return VenueTrainingSlot::class;
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof VenueTrainingSlot) {
            $this->cascadeDeleter?->purgeChildrenOfSlot($entity);
        }
    }

    /**
     * @param VenueTrainingSlotInput $input
     */

    /**
     * Le flush a lieu dans le socle : la violation de FK d'une suppression de plan
     * CONCURRENTE ne peut être rattrapée qu'ici, autour de l'appel parent.
     *
     * @param VenueTrainingSlotInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * @param array<string, mixed>   $uriVariables
     * @param VenueTrainingSlotInput $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        // P4-44 — le triplet AVANT modification, capturé en valeurs (pas en référence :
        // l'entité gérée sera mutée par le parent, et un clone tardif rendrait le
        // nouveau triplet). Les réservations désignent leur créneau par ce triplet :
        // sans ce relevé, corriger un horaire les laissait sur un horaire mort — que
        // le moteur place quand même, en silence, sur le socle.
        $id = $uriVariables['id'] ?? null;
        $before = \is_string($id) ? $this->entityManager->getRepository(VenueTrainingSlot::class)->find($id) : null;
        $snapshot = $before instanceof VenueTrainingSlot
            ? (new VenueTrainingSlot)
                ->setClubId($before->getClubId())->setSeasonId($before->getSeasonId())
                ->setVenueId($before->getVenueId())->setDayOfWeek($before->getDayOfWeek())
                ->setStartTime($before->getStartTime())->setDurationMinutes($before->getDurationMinutes())
                ->setSchedulePlanId($before->getSchedulePlanId())
            : null;

        $output = $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));

        if ($snapshot instanceof VenueTrainingSlot) {
            // `$before` est l'entité GÉRÉE : le parent vient de la muter, elle porte donc
            // déjà le nouveau triplet. C'est bien (avant → après) qu'on passe.
            $this->cascadeDeleter?->moveChildrenOfSlot($snapshot, $before);
        }

        return $output;
    }

    protected function createEntityFromInput(object $input): VenueTrainingSlot
    {
        $entity = new VenueTrainingSlot;
        // Period slot (schedulePlanId set) vs seasonal (null) — set only on create;
        // a slot never migrates between the seasonal and a period layer.
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        $entity->setSchedulePlanId($input->schedulePlanId);
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->capacity) {
            $entity->setCapacity($input->capacity);
        }
        // Posé inconditionnellement (contrairement aux autres champs en « null = ne pas toucher ») :
        // l'ABSENCE de libellé EST un état voulu — « ce créneau n'est plus un groupe ». Le triplet
        // du PUT décrit l'état final. Normalisé (trim, chaîne vide → null) une seule fois ici.
        $entity->setGroupLabel($this->normalizeGroupLabel($input->groupLabel));

        $this->validateEndsWithinDay($entity);
        $this->validateCapacityForVenue($entity);
        $this->validateGroupLabel($entity);
        $this->validateNoOverlap($entity);

        return $entity;
    }

    /**
     * @param VenueTrainingSlot      $entity
     * @param VenueTrainingSlotInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->capacity) {
            $entity->setCapacity($input->capacity);
        }
        // Voir createEntityFromInput : posé inconditionnellement, l'absence de libellé est un
        // état voulu. Normalisation (trim, vide → null) au même endroit.
        $entity->setGroupLabel($this->normalizeGroupLabel($input->groupLabel));

        $this->validateEndsWithinDay($entity);
        $this->validateCapacityForVenue($entity);
        $this->validateGroupLabel($entity);
        $this->validateNoOverlap($entity);
    }

    /**
     * @param VenueTrainingSlot $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTrainingSlotResource
    {
        return VenueTrainingSlotResource::fromEntity($entity);
    }

    /**
     * Un créneau doit finir dans SA journée (P4-61).
     *
     * `VenueTrainingSlotInput` ne porte qu'un `Range(min: 15)` sur la durée : aucune borne de
     * fin de journée. `22:45 + 150 min` était donc accepté, persisté, et ressortait
     * **« 25:15 »** partout où une fin d'horaire s'affiche — `_format_time` côté engine fait
     * un `divmod` sans modulo 24 h, et ne s'en plaint pas.
     *
     * Le geste est fermé côté front depuis P4-37 (`slotPlacementError`), mais un import, un
     * script ou un appel direct passait toujours : l'invariant n'était gardé nulle part où il
     * doit l'être. C'est ce que ferme cette méthode.
     *
     * ⚠ La borne est **minuit**, pas la fin de la grille de saisie : un créneau du soir
     * (22:00–23:30) est légitime et doit rester acceptable. Même règle que le front.
     *
     * ⚠ On ne pose délibérément **aucune durée maximale** : elle serait arbitraire (2h30 est
     * ce que la liste OFFRE, pas ce que le métier interdit) et rendrait l'API plus stricte
     * que la réalité — un stage, un tournoi. La borne de journée suffit à fermer le trou.
     */
    private function validateEndsWithinDay(VenueTrainingSlot $entity): void
    {
        // `minutesOf` plutôt qu'un second calcul : `validateNoOverlap` lit déjà l'heure de
        // début par ce chemin, et deux façons de convertir la même valeur finissent par
        // diverger — c'est le motif de duplication que P4-37 a soldé côté front.
        if ($this->minutesOf($entity) + $entity->getDurationMinutes() > 24 * 60) {
            $this->refuse('Un créneau doit se terminer dans sa journée : celui-ci déborderait après minuit.');
        }
    }

    private function validateCapacityForVenue(VenueTrainingSlot $entity): void
    {
        if ($entity->getCapacity() <= 1) {
            return;
        }

        $venue = $this->entityManager->find(Venue::class, $entity->getVenueId());

        if ($venue instanceof Venue && false === $venue->getCanSplit()) {
            $this->refuse('Un créneau ne peut accueillir 2 équipes ou plus que si le gymnase est déclaré divisible : cochez « terrain divisible » dans l\'étape Gymnases, ou laissez la capacité à 1.');
        }
    }

    /**
     * Un libellé de groupe (« CEC3 ») NOMME une mutualisation : deux équipes sur le même créneau.
     * Le poser sur un créneau qui n'accueille qu'une équipe (capacité < 2) n'a pas de sens —
     * il n'y a personne avec qui fusionner. On refuse plutôt que d'accepter un état incohérent.
     * Même patron que validateCapacityForVenue (garde métier, 422 au processeur).
     */
    private function validateGroupLabel(VenueTrainingSlot $entity): void
    {
        if (null !== $entity->getGroupLabel() && $entity->getCapacity() < 2) {
            $this->refuse('Un libellé de groupe suppose au moins deux équipes sur le créneau : augmentez la capacité à 2 ou retirez le libellé.');
        }
    }

    /** Trim, et la chaîne vide (ou blanche) devient null : « pas de libellé » a une seule forme en base. */
    private function normalizeGroupLabel(?string $label): ?string
    {
        if (null === $label) {
            return null;
        }

        $trimmed = trim($label);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Two slots of the SAME gym may never share any time on the same weekday —
     * divisibility is a within-slot capacity, not a licence to stack slots. The
     * wizard guards this client-side too; this is the server backstop so no
     * client can ever persist overlapping availability.
     */
    private function validateNoOverlap(VenueTrainingSlot $entity): void
    {
        $start = $this->minutesOf($entity);
        $end = $start + $entity->getDurationMinutes();

        /** @var list<VenueTrainingSlot> $sameDay */
        $sameDay = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy([
            'venueId' => $entity->getVenueId(),
            'dayOfWeek' => $entity->getDayOfWeek(),
        ]);

        foreach ($sameDay as $other) {
            if ($other->getId() === $entity->getId()) {
                continue; // an edit does not conflict with itself
            }
            // Layers that are NEVER generated together may share a time: two DIFFERENT
            // periods (both schedulePlanId set and distinct) never union. But the
            // overlay build unions SEASONAL ∪ one period, so a period slot must not
            // overlap a seasonal one (else the same court is double-booked at solve).
            if ($this->neverGeneratedTogether($entity, $other)) {
                continue;
            }
            $otherStart = $this->minutesOf($other);
            if ($start < $otherStart + $other->getDurationMinutes() && $otherStart < $end) {
                $this->refuse('Ce créneau en chevauche un autre du même gymnase ce jour-là.');
            }
        }
    }

    /**
     * Deux créneaux de PLANS DIFFÉRENTS ne sont jamais servis ensemble, donc ne peuvent
     * pas se télescoper — y compris un créneau de saison face à la COPIE qu'une période
     * en détient (#8) : depuis que la période possède sa grille, le socle est bâti sur
     * les créneaux de saison seuls et une période sur les siens seuls, sans union.
     *
     * Exiger (comme avant) que les DEUX portent un plan rendait la grille de saison
     * inéditable dès qu'une période existait : sa copie, identique heure pour heure,
     * était vue comme un chevauchement (revue #8). Le chevauchement RÉEL — deux créneaux
     * d'une même couche — reste refusé.
     */
    private function neverGeneratedTogether(VenueTrainingSlot $a, VenueTrainingSlot $b): bool
    {
        return $a->getSchedulePlanId() !== $b->getSchedulePlanId();
    }

    private function minutesOf(VenueTrainingSlot $slot): int
    {
        return (int) $slot->getStartTime()->format('H') * 60 + (int) $slot->getStartTime()->format('i');
    }
}
