<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-272 ① — BACKFILL de `club_league_window` pour les clubs EXISTANTS, porté par
 * la migration (et non une commande manuelle) : `doctrine:migrations:migrate` seul
 * — chez le fondateur, en CI, au déploiement prod — pose la copie de chaque club,
 * sinon la table naîtrait VIDE partout et le placement perdrait toute règle de
 * ligue le « jour 1 » (comportement que la décision fondateur interdit).
 *
 * Séparée de la migration de schéma (Version20260928130000) À DESSEIN : celle-ci a
 * déjà pu tourner (bases de dev/test/sandbox) et une migration exécutée ne se
 * rejoue pas — une migration NEUVE, elle, s'applique partout.
 *
 * Règle de recopie = `LeagueMatchWindowRepository::effectiveLeague` en SQL : pour
 * chaque saison EN COURS (`active`) ou SUIVANTE déjà là (`draft`) — le passé
 * (`archived`/`closed`) reste consultatif —, on recopie les fenêtres de
 * `league_match_window` de la ligue du club si elle est cataloguée, sinon de la
 * défaut fédérale AURA. Idempotent au grain (club, saison) : `NOT EXISTS` saute une
 * saison qui porte déjà une copie (club créé après la migration de schéma via
 * ClubProvisioner, ou re-passage), et l'unicité (club, saison, catégorie, niveau,
 * genre, jour, début) est le filet.
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que
 * doctrine/dbal < 4.5). Les migrations tournent sous `amateo_owner` (BYPASSRLS) :
 * le backfill cross-club n'a pas besoin de GUC (patron Version20260831130000).
 * Catalogue global vide dans un environnement → 0 ligne recopiée, cohérent (copie
 * vide = aucune règle fédérale).
 */
final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-272 ①: backfill club_league_window des clubs existants (copie de la ligue effective, idempotent).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO club_league_window '
            . '(id, version, created_at, updated_at, club_id, season_id, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) '
            . 'SELECT gen_random_uuid(), 1, now(), now(), s.club_id, s.id, eff.league, '
            . 'w.category, w.level, w.gender, w.day_of_week, w.kickoff_min, w.kickoff_max '
            . 'FROM season s '
            . 'JOIN club c ON c.id = s.club_id '
            . 'JOIN LATERAL (SELECT CASE WHEN c.league IS NOT NULL '
            . 'AND EXISTS (SELECT 1 FROM league_match_window l WHERE l.league = c.league) '
            . 'THEN c.league ELSE \'AURA\' END AS league) eff ON true '
            . 'JOIN league_match_window w ON w.league = eff.league '
            . 'WHERE s.status IN (\'active\', \'draft\') '
            . 'AND NOT EXISTS (SELECT 1 FROM club_league_window x WHERE x.club_id = s.club_id AND x.season_id = s.id)',
        );
    }

    public function down(Schema $schema): void
    {
        // Backfill de DONNÉES, one-way par nature : une ligne recopiée est
        // indiscernable d'une fenêtre saisie/corrigée par le gestionnaire — les
        // effacer toutes détruirait ses éditions. La table entière part, elle, par
        // le down() de la migration de schéma (Version20260928130000). No-op assumé
        // (patron des down() de migration de données du dépôt).
    }
}
