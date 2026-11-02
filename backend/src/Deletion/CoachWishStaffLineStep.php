<?php

declare(strict_types=1);

namespace App\Deletion;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Coach supprimé : sa ligne de jours d'absence quitte les doléances du club, y compris l'export
 * RGPD qui les sert brutes. La doléance et les lignes des autres survivent.
 *
 * Muette, et déclarée telle dans le test de parité : la doléance reste, seule cette personne en
 * sort ; l'annoncer n'aiderait personne à décider, la ligne « demande qui perdra son auteur »
 * parle déjà de la doléance.
 */
final readonly class CoachWishStaffLineStep implements CascadeStep
{
    private const string MATCHING = 'club_id = :clubId AND season_id = :seasonId AND staff_unavailability::jsonb @> jsonb_build_array(jsonb_build_object(\'coachId\', CAST(:coachId AS text)))';

    public function label(): ?ImpactLabel
    {
        return null;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM coach_wish WHERE ' . self::MATCHING,
            $this->parameters($target),
        );
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $entityManager->getConnection()->executeStatement(
            'UPDATE coach_wish SET staff_unavailability = COALESCE((SELECT json_agg(line) FROM json_array_elements(staff_unavailability) AS line WHERE line->>\'coachId\' <> CAST(:coachId AS text)), \'[]\'::json) WHERE ' . self::MATCHING,
            $this->parameters($target),
        );
    }

    /** @return array<string, string> */
    private function parameters(DeletionTarget $target): array
    {
        return ['clubId' => $target->clubId, 'seasonId' => $target->seasonId, 'coachId' => $target->id];
    }
}
