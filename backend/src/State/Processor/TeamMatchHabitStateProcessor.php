<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamMatchHabitResource;
use App\Dto\TeamMatchHabitInput;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Enum\MatchWeek;
use DateTimeImmutable;

/**
 * Wizard-surface structure entity (VenueMatchWindow idiom): not management
 * gated — the page's socle lock is the UI gate, the data is club structure.
 *
 * @extends AbstractStateProcessor<TeamMatchHabit, TeamMatchHabitInput, TeamMatchHabitResource>
 */
class TeamMatchHabitStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamMatchHabit::class;
    }

    /**
     * @param TeamMatchHabitInput $input
     */
    protected function createEntityFromInput(object $input): TeamMatchHabit
    {
        $entity = new TeamMatchHabit;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param TeamMatchHabit      $entity
     * @param TeamMatchHabitInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
        // '' explicitly clears the venue (PUT full-replace idiom).
        if ('' === $input->venueId) {
            $entity->setVenueId(null);
        }
    }

    /**
     * @param TeamMatchHabit $entity
     */
    protected function mapEntityToOutput(object $entity): TeamMatchHabitResource
    {
        return TeamMatchHabitResource::fromEntity($entity);
    }

    private function applyInput(TeamMatchHabit $entity, TeamMatchHabitInput $input): void
    {
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->kickoffTime) {
            $entity->setKickoffTime(new DateTimeImmutable($input->kickoffTime));
        }
        if (null !== $input->venueId && '' !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        // Semaine d'alternance : omise ⇒ `ALL` (défaut), sur création comme sur
        // PUT (idiome full-replace, cf. venueId ci-dessus).
        $entity->setWeek(null !== $input->week ? MatchWeek::from($input->week) : MatchWeek::ALL);

        // Foreign/unknown references resolve to null through the tenant+season
        // filters → 422. `findOneBy`, NOT `find()`: a PK load can serve the
        // identity map and skip the SQL filters (leçon PR B).
        if (!$this->entityManager->getRepository(Team::class)->findOneBy(['id' => $entity->getTeamId()]) instanceof Team) {
            $this->refuse('Équipe inconnue pour ce club.');
        }
        if (null !== $entity->getVenueId() && !$this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $entity->getVenueId()]) instanceof Venue) {
            $this->refuse('Gymnase inconnu pour ce club.');
        }
        // ONE ideal slot per team (P4-271 — la possibilité d'en déclarer deux est
        // fermée) : le DB unique (club, saison, équipe) est le filet, ce contrôle
        // donne au gestionnaire un 422 lisible au lieu d'un 500.
        $existing = $this->entityManager->getRepository(TeamMatchHabit::class)->findOneBy([
            'teamId' => $entity->getTeamId(),
        ]);
        if ($existing instanceof TeamMatchHabit && $existing->getId() !== $entity->getId()) {
            $this->refuse('Cette équipe a déjà un créneau idéal de match — modifiez-le.');
        }
    }
}
