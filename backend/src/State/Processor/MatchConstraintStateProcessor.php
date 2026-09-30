<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\MatchConstraintResource;
use App\Dto\MatchConstraintInput;
use App\Entity\Coach;
use App\Entity\MatchConstraint;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use DateTimeImmutable;

/**
 * P4-272 ③+④+⑤ — CRUD gestionnaire des règles de match. Écriture = management par
 * défaut (403 sinon, AbstractStateProcessor). Le club+saison sont estampillés par le
 * socle ; on n'ajoute ici que la validation métier croisée, DIFFÉRENTE par scope :
 *  - CLUB (③) : une fourchette de coup d'envoi sur des jours — au moins un jour, au
 *    moins une borne, min ≤ max si les deux ; ni cible ni gymnase ;
 *  - TEAM (④) : une INTERDICTION de gymnase pour une équipe — équipe (scopeTargetId)
 *    ET gymnase (venueId) obligatoires et DU CLUB (lookup tenant-filtré, 422 sinon),
 *    ruleType HARD seulement (PREFERRED refusé), jours/coup d'envoi non pertinents
 *    (refusés s'ils sont renseignés) ;
 *  - COACH (⑤) : une INDISPONIBILITÉ d'entraîneur — coach (scopeTargetId) obligatoire
 *    et DU CLUB, jours + fourchette de coup d'envoi comme une règle CLUB (plusieurs
 *    plages par coach ET par jour légitimes), TOUJOURS SOFT (PREFERRED forcé, HARD
 *    refusé — le placement l'évite, ne l'interdit jamais), sans gymnase.
 * FACILITY reste refusé (une règle inerte serait illisible). La sémantique du PUT est
 * full-replace.
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
        // Le scope tranche la forme. CLUB (③), TEAM (④) et COACH (⑤) sont éditables ;
        // FACILITY reste refusé — un scope non honoré par le solveur laisserait une règle
        // inerte et illisible. Refus NOMMÉ (jamais muet).
        $scope = ConstraintScope::from($input->scope ?? ConstraintScope::CLUB->value);
        $entity->setScope($scope);
        match ($scope) {
            ConstraintScope::CLUB => $this->applyClubRule($entity, $input),
            ConstraintScope::TEAM => $this->applyTeamVenueBan($entity, $input),
            ConstraintScope::COACH => $this->applyCoachUnavailability($entity, $input),
            default => $this->refuse('Cette règle n\'est pas encore éditable.'),
        };
    }

    /**
     * ③ — une règle de club (fourchette de coup d'envoi sur des jours). Elle ne vise
     * ni une équipe/un entraîneur (scopeTargetId) ni un gymnase (venueId) : une valeur
     * non nulle est refusée. Au moins un jour, au moins une borne, min ≤ max si les deux.
     */
    private function applyClubRule(MatchConstraint $entity, MatchConstraintInput $input): void
    {
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
        // Full-replace : les jours de la règle sont toujours ré-émis en entier. Au moins
        // un jour (le format 1..7 est gardé par l'input ; la présence l'est ici, car une
        // règle TEAM n'en porte pas — l'assert d'entrée ne peut plus l'exiger).
        $days = array_map(intval(...), $input->daysOfWeek ?? []);
        if ([] === $days) {
            $this->refuse('Une règle de club couvre au moins un jour.');
        }
        $entity->setDaysOfWeek($days);
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

    /**
     * ④ — une INTERDICTION de gymnase pour une équipe. `scopeTargetId` (l'équipe) et
     * `venueId` (le gymnase) sont obligatoires et DOIVENT appartenir au club+saison :
     * un lookup tenant-filtré (`findOneBy`, jamais `find()` qui sert l'identity map et
     * saute les filtres — leçon TeamMatchHabit) rend null pour une entité étrangère →
     * 422. HARD seulement (une interdiction n'est jamais une simple préférence — celle-ci
     * vit dans l'habitude de semaine type). Jours et coup d'envoi ne sont pas pertinents
     * (l'interdiction vaut tous les jours) : refusés s'ils sont renseignés, forcés à vide.
     */
    private function applyTeamVenueBan(MatchConstraint $entity, MatchConstraintInput $input): void
    {
        $teamId = $input->scopeTargetId;
        if (null === $teamId || '' === $teamId) {
            $this->refuse('Choisissez l\'équipe concernée par l\'interdiction.');
        }
        $venueId = $input->venueId;
        if (null === $venueId || '' === $venueId) {
            $this->refuse('Choisissez le gymnase interdit à cette équipe.');
        }
        // Références étrangères/inconnues : résolues à null par les filtres tenant+saison
        // → 422 (jamais un pointeur mort en base).
        if (!$this->entityManager->getRepository(Team::class)->findOneBy(['id' => $teamId]) instanceof Team) {
            $this->refuse('Équipe inconnue pour ce club.');
        }
        if (!$this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $venueId]) instanceof Venue) {
            $this->refuse('Gymnase inconnu pour ce club.');
        }
        $entity->setScopeTargetId($teamId);
        $entity->setVenueId($venueId);

        if (null !== $input->ruleType && ConstraintRuleType::HARD !== ConstraintRuleType::from($input->ruleType)) {
            $this->refuse('Une interdiction de gymnase est toujours obligatoire — la préférence de gymnase se règle dans la semaine type.');
        }
        $entity->setRuleType(ConstraintRuleType::HARD);

        // Jours / coup d'envoi non pertinents : une interdiction vaut tous les jours,
        // à toute heure. Renseignés = refus nommé (jamais une règle à moitié comprise).
        if (null !== $input->daysOfWeek && [] !== $input->daysOfWeek) {
            $this->refuse('Une interdiction de gymnase vaut tous les jours : ne précisez pas de jour.');
        }
        if ((null !== $input->kickoffMin && '' !== $input->kickoffMin) || (null !== $input->kickoffMax && '' !== $input->kickoffMax)) {
            $this->refuse('Une interdiction de gymnase vaut à toute heure : ne précisez pas d\'horaire.');
        }
        $entity->setDaysOfWeek([]);
        $entity->setKickoffMin(null);
        $entity->setKickoffMax(null);
    }

    /**
     * ⑤ — une INDISPONIBILITÉ d'entraîneur (une plage de coup d'envoi où le coach ne peut
     * pas être là). `scopeTargetId` = le coach, OBLIGATOIRE et DU CLUB : un lookup
     * tenant-filtré (`findOneBy`, jamais `find()` qui sert l'identity map et saute les
     * filtres — leçon TeamMatchHabit) rend null pour un coach étranger → 422. Jours +
     * fourchette de coup d'envoi comme une règle CLUB (au moins un jour, au moins une
     * borne, min ≤ max si les deux) ; PLUSIEURS plages par coach ET par jour sont
     * légitimes (aucune unicité). TOUJOURS SOFT : une indisponibilité PÈSE au solveur
     * (pénalité), elle ne bloque jamais — PREFERRED forcé, HARD refusé. Aucun gymnase.
     */
    private function applyCoachUnavailability(MatchConstraint $entity, MatchConstraintInput $input): void
    {
        $coachId = $input->scopeTargetId;
        if (null === $coachId || '' === $coachId) {
            $this->refuse('Choisissez l\'entraîneur concerné par l\'indisponibilité.');
        }
        // Référence étrangère/inconnue : résolue à null par les filtres tenant+saison → 422.
        if (!$this->entityManager->getRepository(Coach::class)->findOneBy(['id' => $coachId]) instanceof Coach) {
            $this->refuse('Entraîneur inconnu pour ce club.');
        }
        $entity->setScopeTargetId($coachId);

        // Une indisponibilité ne vise pas un gymnase : venueId non pertinent, refusé.
        if (null !== $input->venueId && '' !== $input->venueId) {
            $this->refuse('Une indisponibilité d\'entraîneur ne vise pas un gymnase.');
        }
        $entity->setVenueId(null);

        // TOUJOURS une préférence : le placement l'évite quand il le peut, sans jamais
        // l'interdire (jamais de match « impossible » faute d'un coach). HARD refusé.
        if (null !== $input->ruleType && ConstraintRuleType::PREFERRED !== ConstraintRuleType::from($input->ruleType)) {
            $this->refuse('Une indisponibilité d\'entraîneur est une préférence : le placement l\'évite sans jamais l\'interdire.');
        }
        $entity->setRuleType(ConstraintRuleType::PREFERRED);

        // Full-replace : les jours sont ré-émis en entier. Au moins un jour (le format 1..7
        // est gardé par l'input ; la présence l'est ici).
        $days = array_map(intval(...), $input->daysOfWeek ?? []);
        if ([] === $days) {
            $this->refuse('Une indisponibilité couvre au moins un jour.');
        }
        $entity->setDaysOfWeek($days);
        $entity->setKickoffMin($this->parseTime($input->kickoffMin));
        $entity->setKickoffMax($this->parseTime($input->kickoffMax));

        $min = $entity->getKickoffMin();
        $max = $entity->getKickoffMax();
        if (!$min instanceof DateTimeImmutable && !$max instanceof DateTimeImmutable) {
            $this->refuse('Une indisponibilité doit borner une plage horaire : renseignez au moins une heure (de et/ou à).');
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
