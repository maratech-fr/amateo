<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Enum\ConstraintScope;
use App\Service\ConstraintConfigValidator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les contraintes de la saison dont un réglage NOMME le gymnase supprimé (« impose ce gymnase »,
 * « préfère », « au moins N fois à », « interdit ») : elles partent avec lui, annoncées. Laissée en
 * base, une telle règle pointait un gymnase mort et sortait l'équipe du planning.
 *
 * Les clés lues sont celles du validateur (`ConstraintConfigValidator::uuidKeys()`), liste unique
 * que le constructeur de la demande et le sélecteur de période lisent aussi.
 *
 * Une contrainte qui VISE déjà ce gymnase (portée FACILITY) reste à `ScopedConstraintStep` :
 * exclue ici, elle n'est ni comptée deux fois ni annoncée sous deux libellés.
 *
 * ⚠ Même ordre que `ScopedConstraintStep` : les lignes de règle des plans partent AVANT leurs
 * contraintes, sinon elles pendent sur un `constraintId` mort. Le compte annoncé est celui des
 * contraintes.
 *
 * `config` est une colonne `json` : `->>` rend NULL sur un tableau (le `[]` d'un config vide),
 * sans erreur.
 */
final readonly class VenueConfigConstraintStep implements CascadeStep
{
    public function __construct(
        private ImpactLabel $label,
    ) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "constraint" WHERE ' . $this->matching(),
            $this->parameters($target),
        );
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $connection = $entityManager->getConnection();
        $constraintIds = $connection->fetchFirstColumn(
            'SELECT id FROM "constraint" WHERE ' . $this->matching(),
            $this->parameters($target),
        );
        if ([] === $constraintIds) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM plan_constraint WHERE constraint_id IN (:ids)',
            ['ids' => $constraintIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM "constraint" WHERE id IN (:ids)',
            ['ids' => $constraintIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    private function matching(): string
    {
        $named = [];
        foreach (array_keys($this->venueKeys()) as $index) {
            $named[] = \sprintf('config->>CAST(:key%d AS text) = CAST(:venueId AS text)', $index);
        }

        return 'club_id = :clubId AND season_id = :seasonId'
            . ' AND (scope <> :facility OR scope_target_id IS DISTINCT FROM :venueId)'
            . ' AND (' . implode(' OR ', $named) . ')';
    }

    /** @return array<string, string> */
    private function parameters(DeletionTarget $target): array
    {
        $parameters = [
            'clubId' => $target->clubId,
            'seasonId' => $target->seasonId,
            'venueId' => $target->id,
            'facility' => ConstraintScope::FACILITY->value,
        ];
        foreach ($this->venueKeys() as $index => $key) {
            $parameters['key' . $index] = $key;
        }

        return $parameters;
    }

    /** @return list<string> */
    private function venueKeys(): array
    {
        return new ConstraintConfigValidator()->uuidKeys();
    }
}
