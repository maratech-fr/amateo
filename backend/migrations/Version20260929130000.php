<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\LeagueWindowCatalogFile;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-272 ② — CORRECTIF catalogue : là où `league_match_window` est resté VIDE
 * (base de test/CI, dev jamais seedé), `doctrine:migrations:migrate` seul charge
 * désormais le catalogue fédéral depuis `data/league-match-windows.aura.json`, PUIS
 * pose la copie de chaque club existant (comme `Version20260928140000` l'aurait fait
 * si le catalogue avait été plein à son passage). Sans cela, la suggestion de plages
 * (PR ②) n'aurait aucune matière et le placement perdrait toute règle fédérale.
 *
 * ⚠ ANTI-RÉSURRECTION — le discriminant est « catalogue VIDE AU DÉPART » :
 *   - un gestionnaire n'a PU vider sa copie (`club_league_window`) que dans un
 *     environnement où le catalogue était PLEIN (il avait des lignes à supprimer) ;
 *   - donc, catalogue vide au départ ⇒ personne n'a rien vidé ⇒ recopier est sûr ;
 *   - catalogue plein au départ ⇒ on ne touche À RIEN (ni au catalogue déjà là — le
 *     `NULL`-op —, ni aux copies : une copie volontairement vidée NE ressuscite pas).
 * Le `NOT EXISTS (club, saison)` de la recopie reste le filet d'idempotence.
 *
 * Lecture/validation du JSON déléguée à {@see LeagueWindowCatalogFile} (foyer unique,
 * partagé avec `app:league-windows:seed`). La commande de seed reste, pour rafraîchir
 * le catalogue à la main. Migration écrite à la main (dbal < 4.5). Tourne sous
 * `amateo_owner` (BYPASSRLS) : la recopie cross-club n'a pas besoin de GUC.
 */
final class Version20260929130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-272 ②: charge le catalogue ligue là où il est vide + backfill des copies (anti-résurrection : vide au départ seulement).';
    }

    public function up(Schema $schema): void
    {
        // Discriminant : le catalogue global était-il VIDE avant cette migration ?
        // Plein au départ ⇒ on ne touche À RIEN (ni catalogue déjà là, ni copies
        // éventuellement vidées à dessein) — aucun `addSql`, la migration est un
        // no-op propre (pas de `skipIf`, pour rester rejouable et testable en direct).
        $catalogWasEmpty = 0 === (int) $this->connection->fetchOne('SELECT count(*) FROM league_match_window');
        if (!$catalogWasEmpty) {
            return;
        }

        foreach ((new LeagueWindowCatalogFile)->read(LeagueWindowCatalogFile::DEFAULT_PATH) as $row) {
            $this->addSql(
                'INSERT INTO league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) '
                . 'VALUES (gen_random_uuid(), now(), ?, ?, ?, ?, ?, ?::time, ?::time)',
                [$row['league'], $row['category'], $row['level'], $row['gender'], $row['dayOfWeek'], $row['kickoffMin'], $row['kickoffMax']],
            );
        }

        // Backfill des copies — MÊME règle/SQL que Version20260928140000 : saisons en
        // cours (`active`) ou suivante déjà là (`draft`), copie de la ligue effective
        // (celle du club si cataloguée, sinon la défaut fédérale AURA), idempotent au
        // grain (club, saison). Sûr ici car le catalogue était vide au départ.
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
        // Migration de DONNÉES, one-way : une ligne de catalogue chargée est
        // indiscernable d'une ligne seedée à la main, et une copie recopiée d'une
        // fenêtre saisie/corrigée par le gestionnaire. No-op assumé (patron des
        // down() de migration de données du dépôt).
    }
}
