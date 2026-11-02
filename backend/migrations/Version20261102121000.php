<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Doléances : un seul destinataire par équipe. `team_coach.wish_recipient` retient le choix du
 * gestionnaire ; `coach_wish_token.team_ids` devient la vérité de « qui répond pour quoi ».
 *
 * Reprise : `team_ids` = les équipes de la campagne où le coach a un lien, tout rôle, l'ANCIENNE
 * sémantique, pour que les campagnes passées gardent exactement leur affichage.
 *
 * Écrite à la main (`make migration-diff` inopérant, .claude/rules/backend.md). RLS sans objet : les
 * tables l'ont déjà, les migrations tournent sous `amateo_owner` (BYPASSRLS).
 */
final class Version20261102121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_coach.wish_recipient and coach_wish_token.team_ids, backfilled with the former all-roles perimeter.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_coach ADD wish_recipient BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE coach_wish_token ADD team_ids JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE coach_wish_token token
            SET team_ids = COALESCE((
                SELECT json_agg(DISTINCT link.team_id::text)
                FROM coach_wish_campaign campaign
                JOIN team_coach link ON link.coach_id = token.coach_id
                WHERE campaign.id = token.campaign_id
                  AND link.team_id::text IN (SELECT json_array_elements_text(campaign.team_ids))
            ), '[]'::json)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish_token DROP team_ids');
        $this->addSql('ALTER TABLE team_coach DROP wish_recipient');
    }
}
