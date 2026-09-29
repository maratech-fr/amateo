<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\MatchConstraintResource;
use App\Dto\MatchConstraintInput;
use App\Entity\MatchConstraint;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use DateTimeImmutable;

/**
 * P4-272 ③ — CRUD gestionnaire des règles de match du club. Écriture = management
 * par défaut (403 sinon, AbstractStateProcessor). Le club+saison sont estampillés
 * par le socle ; on n'ajoute ici que la validation métier croisée (au moins une
 * borne de coup d'envoi, min ≤ max si les deux). La sémantique du PUT est
 * full-replace (la ligne éditable renvoie tous ses champs).
 *
 * @extends AbstractStateProcessor<MatchConstraint, MatchConstraintInput, MatchConstraintResource>
 */
class MatchConstraintStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return MatchConstraint::class;
    }

    /**
     * @param MatchConstraintInput $input
     */
    protected function createEntityFromInput(object $input): MatchConstraint
    {
        $entity = new MatchConstraint;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param MatchConstraint      $entity
     * @param MatchConstraintInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param MatchConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): MatchConstraintResource
    {
        return MatchConstraintResource::fromEntity($entity);
    }

    private function applyInput(MatchConstraint $entity, MatchConstraintInput $input): void
    {
        // ③ ne saisit QUE des règles de club : le scope TEAM/COACH (et FACILITY) est
        // refusé tant que ④/⑤ ne sont pas livrés — un scope non honoré par le solveur
        // laisserait une règle inerte et illisible. Refus NOMMÉ (jamais muet).
        $scope = ConstraintScope::from($input->scope ?? ConstraintScope::CLUB->value);
        if (ConstraintScope::CLUB !== $scope) {
            $this->refuse('Seules les règles de club sont éditables pour l\'instant (une règle par équipe ou par entraîneur viendra plus tard).');
        }
        $entity->setScope($scope);
        // Une règle de club ne vise ni une équipe/un entraîneur (scopeTargetId) ni un
        // gymnase (venueId) : ces cibles sont réservées aux règles à venir. Une valeur
        // non nulle est donc refusée ici (le format UUID, lui, est gardé par l'input).
        if (null !== $input->scopeTargetId && '' !== $input->scopeTargetId) {
            $this->refuse('Une règle de club ne vise pas une équipe ou un entraîneur précis.');
        }
        $entity->setScopeTargetId(null);
        if (null !== $input->venueId && '' !== $input->venueId) {
            $this->refuse('Une règle de club ne vise pas un gymnase précis.');
        }
        $entity->setVenueId(null);
        if (null !== $input->ruleType) {
            $entity->setRuleType(ConstraintRuleType::from($input->ruleType));
        }
        // Full-replace : les jours de la règle sont toujours ré-émis en entier.
        $entity->setDaysOfWeek(array_map(intval(...), $input->daysOfWeek ?? []));
        $entity->setKickoffMin($this->parseTime($input->kickoffMin));
        $entity->setKickoffMax($this->parseTime($input->kickoffMax));

        $min = $entity->getKickoffMin();
        $max = $entity->getKickoffMax();
        if (!$min instanceof DateTimeImmutable && !$max instanceof DateTimeImmutable) {
            $this->refuse('Une règle doit borner le coup d\'envoi : renseignez au moins une heure (de et/ou à).');
        }
        if ($min instanceof DateTimeImmutable && $max instanceof DateTimeImmutable && $min > $max) {
            $this->refuse('L\'heure de début doit précéder l\'heure de fin.');
        }
    }

    private function parseTime(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
