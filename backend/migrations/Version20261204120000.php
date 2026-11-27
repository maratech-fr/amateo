<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Matches\CompetitionClassifier;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La nature écrite une fois, amical compris (lot 7, PR 4a) — conversion faite une fois, seule exception à « une
 * compétition existante ne change jamais de nature » :
 *  - une compétition dont le premier mot est « amical » devient amicale, quelle que soit sa nature stockée ;
 *  - une compétition stockée championnat prend la nature que son nom dit (« Coupe … », « CRML … », « CMRL … » →
 *    coupe ; « Brassage … » → brassage) ; une nature déjà posée n'est pas défaite. Même règle que
 *    {@see CompetitionClassifier}, en SQL ;
 *  - chaque saison reçoit UNE compétition « Amicaux » marquée d'office (`default_friendly`, unique partiel par club
 *    et saison), saison sans match comprise ; une compétition que le club a déjà nommée « Amicaux » reste ce qu'elle
 *    est ;
 *  - chaque match sans compétition est versé dans « Amicaux » de sa saison, son équipe y est engagée une fois ;
 *    `fixture.competition_id` devient obligatoire.
 *
 * Part dans la mise en production unique du lot conception, site fermé. Irréversible.
 */
final class Version20261204120000 extends AbstractMigration
{
    /** La normalisation du classifieur (casse, accents, ponctuation), en SQL. */
    private const string NORMALIZED_NAME = 'trim(regexp_replace(lower(translate(name, \'àáâãäåçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ\', \'aaaaaaceeeeiiiinooooouuuuyyAAAAAACEEEEIIIINOOOOOUUUUY\')), \'[^a-z0-9]+\', \' \', \'g\'))';

    public function getDescription(): string
    {
        return 'competition : nature amicale et coupes converties, une « Amicaux » d\'office par saison ; fixture.competition_id obligatoire.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition ADD default_friendly BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_competition_default_friendly ON competition (club_id, season_id) WHERE (default_friendly = true)');

        $this->addSql(\sprintf('UPDATE competition SET competition_type = \'FRIENDLY\' WHERE split_part(%s, \' \', 1) = \'amical\'', self::NORMALIZED_NAME));
        $this->addSql(\sprintf(
            'UPDATE competition SET competition_type = \'CUP\' WHERE competition_type = \'CHAMPIONSHIP\' AND (%1$s LIKE \'%%coupe%%\' OR %1$s LIKE \'crml%%\' OR %1$s LIKE \'cmrl%%\')',
            self::NORMALIZED_NAME,
        ));
        $this->addSql(\sprintf('UPDATE competition SET competition_type = \'BRASSAGE\' WHERE competition_type = \'CHAMPIONSHIP\' AND %s LIKE \'%%brassage%%\'', self::NORMALIZED_NAME));

        $this->addSql('INSERT INTO competition (id, version, created_at, updated_at, club_id, season_id, name, competition_type, default_friendly) SELECT gen_random_uuid(), 1, now(), now(), s.club_id, s.id, \'Amicaux\', \'FRIENDLY\', true FROM season s');
        $this->addSql(<<<'SQL'
            INSERT INTO engagement (id, version, created_at, updated_at, club_id, season_id, competition_id, team_id)
            SELECT gen_random_uuid(), 1, now(), now(), d.club_id, d.season_id, c.id, d.team_id
              FROM (SELECT DISTINCT club_id, season_id, team_id FROM fixture WHERE competition_id IS NULL) d
              JOIN competition c ON c.club_id = d.club_id AND c.season_id = d.season_id AND c.default_friendly
            SQL);
        $this->addSql('UPDATE fixture f SET competition_id = c.id FROM competition c WHERE f.competition_id IS NULL AND c.club_id = f.club_id AND c.season_id = f.season_id AND c.default_friendly');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (SELECT 1 FROM fixture WHERE competition_id IS NULL) THEN
                RAISE EXCEPTION 'Conversion de la nature : % match(s) restent sans compétition, la migration s''arrête.',
                  (SELECT COUNT(*) FROM fixture WHERE competition_id IS NULL);
              END IF;
            END
            $$
            SQL);
        $this->addSql('ALTER TABLE fixture ALTER competition_id SET NOT NULL');
    }

    public function postUp(Schema $schema): void
    {
        $this->write(\sprintf(
            'Nature : %d compétitions amicales (dont %d « Amicaux » d\'office), %d coupes, %d brassages ; %d matchs dans « Amicaux ».',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition WHERE competition_type = \'FRIENDLY\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition WHERE default_friendly'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition WHERE competition_type = \'CUP\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition WHERE competition_type = \'BRASSAGE\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.default_friendly'),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'La nature stockée avant la conversion et l\'absence de compétition des amicaux ne se retrouvent pas. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
