<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\HolidayCloseReason;
use App\Service\HolidayReminderStateResolver;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR axe *tenant isolation* (rappel des vacances scolaires) : ce qui FERME le rappel d'une
 * vacance pour le club A ne se lit JAMAIS dans les données du club B — chaque requête de
 * `HolidayReminderStateResolver::isClosed` porte sa ceinture `club_id = :club`, et chaque
 * ceinture tient SEULE.
 *
 * Les trois requêtes et la table qui porte leur UNIQUE ceinture :
 *  - (a) vacance ignorée — ceinture sur `calendar_entry` (entrée HOLIDAY `ignored` liée à la vacance) ;
 *  - (b) vacance planifiée — ceinture sur `calendar_entry` (racine HOLIDAY chevauchante et ses
 *    semaines-enfants), jointe à `schedule_plan` puis `schedule` SANS ceinture sur ces deux-là ;
 *  - (c) vacance couverte — ceinture sur `calendar_entry` (fenêtres CUTOFF/CLOSURE racines actives).
 *
 * Pourquoi la ceinture tient seule ici : tout se joue sur la connexion ADMIN, sous un rôle
 * propriétaire NON-superuser (`rls_admin_probe`, membre de `amateo_owner`, patron
 * `RlsIsolationTest::testAdminDoorLetsOwnerCrossClubsWhileAppUserStaysScoped`) que la policy
 * `admin_all` laisse voir tous les clubs : la RLS n'y filtre RIEN (témoin positif ci-dessous). La
 * RLS de `calendar_entry` sous le rôle runtime est prouvée ailleurs (`RlsIsolationTest`).
 *
 * B porte les TROIS fermeurs à la fois, A aucun : `isClosed(A)` doit rester `null`. Retirer
 * N'IMPORTE LAQUELLE des trois ceintures fait remonter un fermeur de B (IGNORED, COVERED ou
 * PLANNED) → ROUGE. Les témoins sur B prouvent que chacun des trois fermeurs semés est bien
 * reconnu (sinon une ceinture retirée resterait verte faute de fermeur) : IGNORED d'abord (priorité),
 * puis COVERED une fois l'ignorée réactivée, puis PLANNED une fois la coupure retirée, puis null
 * une fois la version retirée.
 */
#[Group('phase1')]
#[Group('integration')]
final class HolidayReminderStateIsolationTest extends KernelTestCase
{
    private const string CLUB_A = '7a1d0c11-0000-4000-8000-00000000000a';

    private const string CLUB_B = '7a1d0c11-0000-4000-8000-00000000000b';

    private const string SEASON_A = '7a1d0c11-0000-4000-8000-0000000005aa';

    private const string SEASON_B = '7a1d0c11-0000-4000-8000-0000000005bb';

    private const string HOLIDAY = '7a1d0c11-0000-4000-8000-0000000000ff';

    private const string IGNORED_ENTRY = '7a1d0c11-0000-4000-8000-0000000000e1';

    private const string CUTOFF_ENTRY = '7a1d0c11-0000-4000-8000-0000000000e2';

    private const string PLANNED_ROOT = '7a1d0c11-0000-4000-8000-0000000000e3';

    private const string PLANNED_PLAN = '7a1d0c11-0000-4000-8000-0000000000a3';

    private const string PLANNED_VERSION = '7a1d0c11-0000-4000-8000-0000000000b3';

    /** Noël 2026 : samedi 19/12/2026 → dimanche 03/01/2027 ; saison 15/07/2026 → 14/07/2027. */
    private const array NOEL = ['2026-12-19', '2027-01-03', '2026-07-15', '2027-07-14'];

    public function testEveryClubFilterAloneHidesAnotherClubsClosers(): void
    {
        $admin = $this->adminConnection();
        $admin->executeStatement('DROP ROLE IF EXISTS rls_admin_probe');
        $admin->executeStatement('CREATE ROLE rls_admin_probe NOSUPERUSER NOLOGIN IN ROLE amateo_owner');

        $admin->beginTransaction();
        try {
            $this->seed($admin);

            $admin->executeStatement('SET LOCAL ROLE rls_admin_probe');
            self::assertFalse(
                (bool) $admin->fetchOne('SELECT usesuper FROM pg_user WHERE usename = current_user'),
                'le probe doit être NON-superuser — sinon il bypasse RLS par un autre chemin',
            );
            // Témoin : sous ce rôle la RLS ne filtre rien — les fermeurs de B sont lisibles sans
            // ceinture. Seule la ceinture club_id de chaque requête peut donc les cacher à A.
            self::assertSame(3, (int) $admin->fetchOne(
                'SELECT count(*) FROM calendar_entry WHERE club_id = ? AND season_id = ?',
                [self::CLUB_B, self::SEASON_B],
            ), 'témoin : les trois entrées de B sont visibles sous le probe (admin_all), la RLS n\'aide pas ici');

            $resolver = new HolidayReminderStateResolver($admin);

            self::assertNull(
                $resolver->isClosed(self::CLUB_A, self::HOLIDAY, ...self::NOEL),
                'A n\'a ni vacance ignorée, ni coupure, ni version : un fermeur de B a traversé une ceinture club_id',
            );

            // Témoins sur B : chacun des trois fermeurs semés est reconnu, dans l'ordre de priorité.
            self::assertSame(HolidayCloseReason::IGNORED, $resolver->isClosed(self::CLUB_B, self::HOLIDAY, ...self::NOEL), 'témoin (a) : l\'entrée ignorée de B ferme la vacance de B');

            $admin->executeStatement('UPDATE calendar_entry SET status = \'active\' WHERE id = ?', [self::IGNORED_ENTRY]);
            self::assertSame(HolidayCloseReason::COVERED, $resolver->isClosed(self::CLUB_B, self::HOLIDAY, ...self::NOEL), 'témoin (c) : la coupure couvrante de B ferme la vacance de B (prime sur la version)');

            $admin->executeStatement('DELETE FROM calendar_entry WHERE id = ?', [self::CUTOFF_ENTRY]);
            self::assertSame(HolidayCloseReason::PLANNED, $resolver->isClosed(self::CLUB_B, self::HOLIDAY, ...self::NOEL), 'témoin (b) : la racine HOLIDAY versionnée de B ferme la vacance de B');

            $admin->executeStatement('DELETE FROM schedule WHERE id = ?', [self::PLANNED_VERSION]);
            self::assertNull($resolver->isClosed(self::CLUB_B, self::HOLIDAY, ...self::NOEL), 'témoin : sans version, la racine HOLIDAY ne ferme plus rien');
        } finally {
            $admin->executeStatement('RESET ROLE');
            $admin->rollBack();
            $admin->executeStatement('DROP ROLE IF EXISTS rls_admin_probe');
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * Semis SQL brut dans la transaction de la connexion admin (les lignes de la connexion
     * default vivent dans une autre transaction DAMA, invisibles ici). La base de test ne porte
     * aucune vacance scolaire : la vacance est semée aussi, sur une zone propre au test.
     */
    private function seed(Connection $admin): void
    {
        foreach ([self::CLUB_A => self::SEASON_A, self::CLUB_B => self::SEASON_B] as $clubId => $seasonId) {
            $admin->executeStatement(
                'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, school_zone) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, \'ZISO\')',
                [$clubId, 'Iso vacances ' . substr($clubId, -1), 'iso-vacances-' . substr($clubId, -1) . '-' . substr(md5($clubId), 0, 6)],
            );
            $admin->executeStatement(
                'INSERT INTO season (id, version, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data) VALUES (?, 1, now(), now(), ?, \'2026-2027\', \'2026-07-15\', \'2027-07-14\', \'active\', \'[]\')',
                [$seasonId, $clubId],
            );
        }

        $admin->executeStatement(
            'INSERT INTO school_holiday_period (id, created_at, zone, label, holiday_type, start_date, end_date, school_year) VALUES (?, now(), \'ZISO\', \'Vacances de Noël\', \'noel\', \'2026-12-19\', \'2027-01-03\', \'2026-2027\')',
            [self::HOLIDAY],
        );

        $entry = 'INSERT INTO calendar_entry (id, version, created_at, updated_at, club_id, season_id, kind, title, start_date, end_date, is_disruptive, status, period_type, school_holiday_id) VALUES (?, 1, now(), now(), ?, ?, \'period\', ?, ?, ?, false, ?, ?, ?)';
        // (a) la vacance ignorée par B (liée au référentiel).
        $admin->executeStatement($entry, [self::IGNORED_ENTRY, self::CLUB_B, self::SEASON_B, 'Vacances de Noël', '2026-12-19', '2027-01-03', 'ignored', 'holiday', self::HOLIDAY]);
        // (c) une coupure de B qui couvre chaque lundi→vendredi de la vacance.
        $admin->executeStatement($entry, [self::CUTOFF_ENTRY, self::CLUB_B, self::SEASON_B, 'Coupure de Noël', '2026-12-21', '2027-01-03', 'active', 'cutoff', null]);
        // (b) une racine HOLIDAY de B, chevauchante, qui porte une version.
        $admin->executeStatement($entry, [self::PLANNED_ROOT, self::CLUB_B, self::SEASON_B, 'Stage de Noël', '2026-12-21', '2026-12-27', 'active', 'holiday', null]);
        $admin->executeStatement(
            'INSERT INTO schedule_plan (id, version, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, last_version_number, team_selection_initialized, calendar_entry_id) VALUES (?, 1, now(), now(), ?, ?, \'HOLIDAY\', \'Stage de Noël\', \'2026-12-21\', \'2026-12-27\', 1, false, ?)',
            [self::PLANNED_PLAN, self::CLUB_B, self::SEASON_B, self::PLANNED_ROOT],
        );
        $admin->executeStatement(
            'INSERT INTO schedule (id, version, created_at, updated_at, club_id, season_id, name, status, solver_seed, snapshot_data, schedule_plan_id, version_number, manually_edited_since_generation) VALUES (?, 1, now(), now(), ?, ?, \'Stage\', \'COMPLETED\', 0, \'{}\', ?, 1, false)',
            [self::PLANNED_VERSION, self::CLUB_B, self::SEASON_B, self::PLANNED_PLAN],
        );
    }

    private function adminConnection(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
