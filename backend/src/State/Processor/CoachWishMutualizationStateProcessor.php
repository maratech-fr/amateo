<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachWishMutualizationResource;
use App\Dto\CoachWishMutualizationInput;
use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachWishMutualization;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;

/**
 * Demande de mutualisation coach (feature #10, lot D2). Écriture MANAGEMENT-ONLY (SEC-07) :
 * une demande pilote la négociation du plan de vacances, pas un coach lambda.
 *
 * Une demande est INFORMATIVE, jamais une contrainte : aucun effet solveur, aucun
 * pré-remplissage. Son ancre (entrée MÈRE des vacances + équipe) identifie la ligne ; le PUT
 * ne la remappe jamais. Parité CoachWish.
 *
 * @extends AbstractStateProcessor<CoachWishMutualization, CoachWishMutualizationInput, CoachWishMutualizationResource>
 */
class CoachWishMutualizationStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CoachWishMutualization::class;
    }

    protected function requiresManagementRole(): bool
    {
        return true; // SEC-07
    }

    /**
     * @param CoachWishMutualizationInput $input
     */
    protected function createEntityFromInput(object $input): CoachWishMutualization
    {
        $this->assertValidAnchor($input);

        // À la CRÉATION la demande est saisie « au nom d'un coach » : coachId requis ici
        // (le DTO l'a rendu nullable pour l'ÉDITION d'une demande dé-attribuée — parité CoachWish).
        if (null === $input->coachId) {
            $this->refuse('Une demande de mutualisation se saisit au nom d’un coach.');
        }

        // Une seule demande par (période, équipe) — l'index unique remonterait sinon en 500 sur
        // un double-submit ; on rend un 422 propre (l'édition passe par PUT).
        if (null !== $this->entityManager->getRepository(CoachWishMutualization::class)->findOneBy([
            'calendarEntryId' => $input->calendarEntryId,
            'teamId' => $input->teamId,
        ])) {
            $this->refuse('Une demande de mutualisation existe déjà pour cette équipe — modifiez-la.');
        }

        $entity = new CoachWishMutualization;
        $entity->setCalendarEntryId((string) $input->calendarEntryId);
        $entity->setTeamId((string) $input->teamId);
        $this->applyEditableFields($entity, $input);

        return $entity;
    }

    /**
     * @param CoachWishMutualization      $entity
     * @param CoachWishMutualizationInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // calendarEntryId + teamId identifient la ligne — jamais remappés.
        $this->applyEditableFields($entity, $input);
        // PUT défensif : un coachId qui ne désigne plus aucun coach (supprimé entre-temps,
        // cache client périmé re-poussé par un toggle) → DÉ-ATTRIBUTION, jamais une
        // résurrection d'un coach mort (parité CoachWish).
        if (null !== $input->coachId && null === $this->entityManager->getRepository(Coach::class)->find($input->coachId)) {
            $entity->setCoachId(null);
        }
        $entity->touch();
    }

    /**
     * @param CoachWishMutualization $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishMutualizationResource
    {
        return CoachWishMutualizationResource::fromEntity($entity);
    }

    private function applyEditableFields(CoachWishMutualization $entity, CoachWishMutualizationInput $input): void
    {
        $partners = $this->sanitizePartners($input, (string) $input->teamId);
        $entity->setCoachId($input->coachId);
        $entity->setPartnerTeamIds($partners);
        $entity->setSharedSlots($input->sharedSlots ?? 1);
        $entity->setDone($input->done);
    }

    /**
     * Partenaires : au moins un, distincts de l'équipe elle-même, dédupliqués, et chacun une
     * équipe EXISTANTE (RLS scope déjà au club). Une demande sans partenaire n'a pas de sens —
     * côté gestionnaire on la SUPPRIME (parité page publique, où 0 partenaire supprime la ligne).
     *
     * @return list<string>
     */
    private function sanitizePartners(CoachWishMutualizationInput $input, string $teamId): array
    {
        $partners = [];
        foreach ($input->partnerTeamIds as $p) {
            if ($p === $teamId) {
                $this->refuse('Une équipe ne peut pas se mutualiser avec elle-même.');
            }
            if (null === $this->entityManager->getRepository(Team::class)->find($p)) {
                $this->refuse('Équipe partenaire introuvable.');
            }
            if (!\in_array($p, $partners, true)) {
                $partners[] = $p;
            }
        }
        if ([] === $partners) {
            $this->refuse('Indiquez au moins une équipe partenaire, ou supprimez la demande.');
        }

        return $partners;
    }

    /**
     * La demande s'ancre à l'entrée MÈRE des vacances. Tenant scoppe déjà l'entrée au club
     * (tenant_filter) : introuvable = 422. Parité CoachWish.
     */
    private function assertValidAnchor(CoachWishMutualizationInput $input): void
    {
        $entry = null === $input->calendarEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($input->calendarEntryId);
        if (!$entry instanceof CalendarEntry) {
            $this->refuse('Période introuvable.');
        }
        if (CalendarEntryKind::PERIOD !== $entry->getKind() || CalendarEntryPeriodType::HOLIDAY !== $entry->getPeriodType()) {
            $this->refuse('Les mutualisations ne concernent que les périodes de vacances.');
        }
        if (null !== $entry->getParentEntryId()) {
            $this->refuse('Adressez la demande à la période mère, pas à une semaine isolée.');
        }
        if (null === $this->entityManager->getRepository(Team::class)->find($input->teamId)) {
            $this->refuse('Équipe introuvable.');
        }
        if (null !== $input->coachId && null === $this->entityManager->getRepository(Coach::class)->find($input->coachId)) {
            $this->refuse('Coach introuvable.');
        }
    }
}
