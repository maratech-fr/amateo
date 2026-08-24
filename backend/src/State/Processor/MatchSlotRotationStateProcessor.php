<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\MatchSlotRotationResource;
use App\Dto\MatchSlotRotationInput;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Team;
use App\Entity\Venue;
use DateTimeImmutable;

/**
 * RMM-5 (P2-49) — écriture d'un créneau de match partagé. Une rotation = un parent
 * {@see MatchSlotRotation} + N lignes ORDONNÉES {@see MatchSlotRotationTeam} (club/saison
 * dénormalisés). Écriture par REMPLACEMENT des membres (patron mutualisation).
 *
 * Wizard/module-matchs surface (VenueMatchWindow idiom): pas de garde management propre —
 * le régime par défaut du rail d'écriture (tenant + saison écrivable) suffit, comme les
 * habitudes/fenêtres. Lecture ouverte au Membre (le provider n'a aucune garde).
 *
 * Les 422 de FORME (2..10 équipes, doublon d'équipe, formats) vivent dans le DTO ; ceux qui
 * exigent la base vivent ici :
 *  - un gymnase inconnu du club → 422 sans écriture ;
 *  - une équipe inconnue du club+saison → 422 sans écriture ;
 *  - un créneau (gymnase, jour, heure) déjà pris → 422 lisible (l'unicité DB est le filet).
 *
 * @extends AbstractStateProcessor<MatchSlotRotation, MatchSlotRotationInput, MatchSlotRotationResource>
 */
class MatchSlotRotationStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return MatchSlotRotation::class;
    }

    /**
     * @param MatchSlotRotationInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);

            $rotation = new MatchSlotRotation;
            $this->applySlotFields($rotation, $input);
            if (null !== $clubId) {
                $rotation->setClubId($clubId);
            }
            if (null !== $resolvedSeasonId) {
                $rotation->setSeasonId($resolvedSeasonId);
            }

            $this->assertRotationValid($rotation, $clubId, $resolvedSeasonId, $input->teamIds, null);

            $this->entityManager->persist($rotation);
            $this->addMembers($rotation, $input->teamIds, $clubId, $resolvedSeasonId);
            $this->entityManager->flush();

            return $this->mapEntityToOutput($rotation);
        });
    }

    /**
     * Exigée par le contrat abstrait, mais la création réelle passe par {@see processPost}
     * (parent + membres, atomique). Ne bâtit que le parent.
     *
     * @param MatchSlotRotationInput $input
     */
    protected function createEntityFromInput(object $input): MatchSlotRotation
    {
        $rotation = new MatchSlotRotation;
        $this->applySlotFields($rotation, $input);

        return $rotation;
    }

    /**
     * @param MatchSlotRotation      $entity
     * @param MatchSlotRotationInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applySlotFields($entity, $input);
        $this->assertRotationValid($entity, $entity->getClubId(), $entity->getSeasonId(), $input->teamIds, $entity->getId());
        $entity->touchUpdatedAt();
        $this->replaceMembers($entity, $input->teamIds);
    }

    /**
     * @param MatchSlotRotation $entity
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        // Pas de cascade ORM : on purge les lignes membres à la main avant le parent.
        foreach ($this->membershipRows($entity->getId()) as $row) {
            $this->entityManager->remove($row);
        }
    }

    /**
     * @param MatchSlotRotation $entity
     */
    protected function mapEntityToOutput(object $entity): MatchSlotRotationResource
    {
        $teamIds = array_map(
            static fn (MatchSlotRotationTeam $row): string => $row->getTeamId(),
            $this->membershipRows($entity->getId()),
        );

        return MatchSlotRotationResource::fromEntity($entity, $teamIds);
    }

    private function applySlotFields(MatchSlotRotation $entity, MatchSlotRotationInput $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->kickoffTime) {
            $entity->setKickoffTime(new DateTimeImmutable($input->kickoffTime));
        }
    }

    /**
     * @param list<string> $teamIds
     */
    private function assertRotationValid(MatchSlotRotation $entity, ?string $clubId, ?string $seasonId, array $teamIds, ?string $excludeRotationId): void
    {
        if (null === $clubId || null === $seasonId) {
            return; // contexte sans tenant (non-HTTP) : rien à vérifier contre la base
        }

        // Foreign/unknown venue resolves to null through the tenant+season filters → 422.
        // `findOneBy`, NOT `find()`: a PK load can serve the identity map and skip the SQL
        // filters, and this check exists precisely to hit the filtered SQL path.
        if (!$this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $entity->getVenueId()]) instanceof Venue) {
            $this->refuse('Gymnase inconnu pour ce club.');
        }

        $teamRepo = $this->entityManager->getRepository(Team::class);
        foreach ($teamIds as $teamId) {
            if (!$teamRepo->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $seasonId]) instanceof Team) {
                $this->refuse('Une équipe du créneau partagé est inconnue de cette saison.');
            }
        }

        // One rotation per physical slot — the DB unique is the backstop, this gives the
        // manager a readable 422 instead of a 500.
        $existing = $this->entityManager->getRepository(MatchSlotRotation::class)->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'venueId' => $entity->getVenueId(),
            'dayOfWeek' => $entity->getDayOfWeek(),
            'kickoffTime' => $entity->getKickoffTime(),
        ]);
        if ($existing instanceof MatchSlotRotation && $existing->getId() !== $excludeRotationId) {
            $this->refuse('Un créneau de match partagé existe déjà à ce gymnase, ce jour et cette heure.');
        }
    }

    /**
     * @param list<string> $teamIds
     */
    private function addMembers(MatchSlotRotation $rotation, array $teamIds, ?string $clubId, ?string $seasonId): void
    {
        foreach ($teamIds as $position => $teamId) {
            $member = new MatchSlotRotationTeam;
            if (null !== $clubId) {
                $member->setClubId($clubId);
            }
            if (null !== $seasonId) {
                $member->setSeasonId($seasonId);
            }
            $member->setRotationId($rotation->getId());
            $member->setTeamId($teamId);
            $member->setPosition($position);
            $this->entityManager->persist($member);
        }
    }

    /**
     * Remplacement intégral des membres (l'ordre PEUT changer, donc les positions aussi) :
     * on supprime les lignes existantes puis on recrée. Le flush intermédiaire évite la
     * collision d'unicité `(rotation_id, team_id)` d'un delete+insert de même clé dans un
     * seul flush.
     *
     * @param list<string> $teamIds
     */
    private function replaceMembers(MatchSlotRotation $rotation, array $teamIds): void
    {
        foreach ($this->membershipRows($rotation->getId()) as $row) {
            $this->entityManager->remove($row);
        }
        $this->entityManager->flush();

        $this->addMembers($rotation, $teamIds, $rotation->getClubId(), $rotation->getSeasonId());
    }

    /**
     * @return list<MatchSlotRotationTeam>
     */
    private function membershipRows(string $rotationId): array
    {
        return $this->entityManager->getRepository(MatchSlotRotationTeam::class)
            ->findBy(['rotationId' => $rotationId], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
