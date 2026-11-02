<?php

declare(strict_types=1);

namespace App\Deletion;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Équipe supprimée : elle sort du périmètre de toutes les collectes de la saison et de tous leurs
 * jetons, closes comprises. C'est la seule modification permise sur une collecte close : la règle
 * du destinataire n'y rejoue plus (`CoachWishCampaignTokenSync::syncSeason` la saute), l'équipe
 * morte y resterait sinon pour toujours.
 *
 * Le jeton garde `sentAt` et `respondedAt` : perdre une équipe ne remet pas le coach « à envoyer »
 * et n'efface pas sa réponse.
 *
 * Muette, et déclarée telle dans le test de parité : la collecte reste, seule cette équipe en sort ;
 * les demandes de coach qu'emporte la suppression sont déjà annoncées par leur propre ligne.
 */
final readonly class CoachWishCampaignTeamStep implements CascadeStep
{
    private const string CONTAINS_TEAM = 'team_ids::jsonb @> jsonb_build_array(CAST(:teamId AS text))';

    private const string CAMPAIGN_SCOPE = 'club_id = :clubId AND season_id = :seasonId';

    private const string TOKEN_SCOPE = 'club_id = :clubId AND campaign_id IN (SELECT id FROM coach_wish_campaign WHERE ' . self::CAMPAIGN_SCOPE . ')';

    private const string WITHOUT_TEAM = 'team_ids = COALESCE((SELECT json_agg(kept.id ORDER BY kept.position) FROM json_array_elements_text(team_ids) WITH ORDINALITY AS kept(id, position) WHERE kept.id <> CAST(:teamId AS text)), \'[]\'::json)';

    public function label(): ?ImpactLabel
    {
        return null;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return $this->countIn($entityManager->getConnection(), 'coach_wish_token', self::TOKEN_SCOPE, $target)
            + $this->countIn($entityManager->getConnection(), 'coach_wish_campaign', self::CAMPAIGN_SCOPE, $target);
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('UPDATE coach_wish_token SET ' . self::WITHOUT_TEAM . ' WHERE ' . self::TOKEN_SCOPE . ' AND ' . self::CONTAINS_TEAM, $this->parameters($target));
        $connection->executeStatement('UPDATE coach_wish_campaign SET ' . self::WITHOUT_TEAM . ' WHERE ' . self::CAMPAIGN_SCOPE . ' AND ' . self::CONTAINS_TEAM, $this->parameters($target));
    }

    private function countIn(Connection $connection, string $table, string $scope, DeletionTarget $target): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $scope . ' AND ' . self::CONTAINS_TEAM, $this->parameters($target));
    }

    /** @return array<string, string> */
    private function parameters(DeletionTarget $target): array
    {
        return ['clubId' => $target->clubId, 'seasonId' => $target->seasonId, 'teamId' => $target->id];
    }
}
