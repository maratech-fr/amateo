<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Repository\ClubUserRepository;
use App\Service\TenantConnectionContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SEC-03 non-regression: the DATABASE itself enforces tenant isolation.
 * Raw SQL on the runtime (amateo_app) connection — no ORM, no Doctrine filter:
 * these tests prove the RLS policies work even if every application layer is
 * bypassed. dama wraps each test in a transaction (rollback cleans the rows
 * AND the session GUC, set_config(..., false) being transactional).
 */
#[Group('phase1')]
#[Group('integration')]
final class RlsIsolationTest extends KernelTestCase
{
    private const CLUB_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const CLUB_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /**
     * SQL prédicat identifiant les tables portant une colonne club_id, PARTAGÉ par
     * les deux tests de portée RLS (SEC-12 #4 : une seule source d'énumération,
     * sinon un correctif appliqué d'un côté laisse l'autre aveugle). Suppose les
     * alias `c` (pg_class) et `n` (pg_namespace) en portée.
     *
     * relkind IN ('r', 'p') : table ORDINAIRE + table PARTITIONNÉE parente — cette
     * dernière porte les policies héritées par ses enfants. Les VUES ('v'/'m')
     * n'ont PAS de RLS propre (la RLS s'applique aux tables sous-jacentes) : ne pas
     * les inclure ici, ce n'est pas un oubli.
     */
    private const CLUB_ID_TABLE_PREDICATE = <<<'SQL'
        n.nspname = 'public'
        AND c.relkind IN ('r', 'p')
        AND EXISTS (
            SELECT 1 FROM pg_attribute a
            WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped
        )
        SQL;

    private Connection $connection;

    private TenantConnectionContext $guc;

    public function testConnectionUserIsNotSuperuser(): void
    {
        // Guard against a DATABASE_URL regression back to the superuser: with a
        // superuser (or table owner without FORCE), every policy is bypassed and
        // all the assertions below would silently test nothing.
        $superuser = $this->connection->fetchOne(
            'SELECT usesuper FROM pg_user WHERE usename = current_user',
        );
        self::assertFalse((bool) $superuser, 'runtime connection must NOT be a superuser');
    }

    public function testEveryClubIdTableIsUnderForcedRls(): void
    {
        // Coverage guard: the migration hardcodes the table list — a future
        // migration adding a club_id table without RLS would silently open a
        // tenant hole. Enumerate club_id tables dynamically (shared predicate,
        // SEC-12 #4) and require ENABLE + FORCE + at least one policy on each.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.relname AS table_name, '
            . 'c.relrowsecurity AS rls_enabled, '
            . 'c.relforcerowsecurity AS rls_forced, '
            . '(SELECT count(*) FROM pg_policies p WHERE p.schemaname = \'public\' AND p.tablename = c.relname) AS policies '
            . 'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE ' . self::CLUB_ID_TABLE_PREDICATE,
        );

        self::assertNotEmpty($rows, 'expected club_id tables to exist');
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            self::assertTrue((bool) $row['rls_enabled'], \sprintf('table %s owns club_id but RLS is not ENABLED — add it to the RLS migration', $table));
            self::assertTrue((bool) $row['rls_forced'], \sprintf('table %s owns club_id but RLS is not FORCED', $table));
            self::assertGreaterThan(0, (int) $row['policies'], \sprintf('table %s owns club_id but has no policy', $table));
        }
    }

    public function testEveryPolicyOnClubIdTablesIsTenantScoped(): void
    {
        // SEC-12 — PORTÉE des policies. testEveryClubIdTableIsUnderForcedRls ne
        // vérifie que ENABLE+FORCE+count>0 : une policy USING (true) copiée-collée
        // sur une future table tenant passerait ce gate sans rougir. Ici on juge
        // CHAQUE policy permissive contre le prédicat canonique.
        //
        // Ce que ce test NE couvre PAS (volontaire, fail-noisy) :
        //  - un prédicat sémantiquement équivalent mais écrit autrement échouera :
        //    l'égalité est STRICTE contre le canon runtime, pas une preuve sémantique ;
        //  - la justesse sémantique du canon lui-même est portée par les tests
        //    comportementaux voisins (testGucScoped* / testCrossTenant* / testInsert*),
        //    jamais par celui-ci.

        // D1 : le canon est LU À L'EXÉCUTION depuis team_tag.tenant_isolation, jamais
        // une chaîne en dur — PostgreSQL reformate les prédicats (casts ::text,
        // parenthèses ajoutées), donc pg_policies.qual ne ressemble pas à ce
        // qu'écrivent les migrations. team_tag est l'étalon : son isolation est déjà
        // prouvée par les tests comportementaux de ce fichier.
        $canon = $this->connection->fetchOne(
            'SELECT qual FROM pg_policies WHERE schemaname = \'public\' AND tablename = \'team_tag\' AND policyname = \'tenant_isolation\'',
        );
        self::assertIsString($canon, 'canon policy team_tag.tenant_isolation must exist');
        self::assertNotSame('', $canon, 'canon predicate must not be empty — sinon le test se compare à du néant');
        self::assertNotSame('true', $canon, 'canon predicate must not be USING (true) — sinon le test ne prouve plus rien');
        // Le canon doit RÉELLEMENT scoper sur le GUC. Sans ça il pourrait dégénérer
        // en un prédicat permissif autre que le littéral 'true' (1=1,
        // club_id IS NOT NULL, …) et tout deviendrait « conforme » à du vide.
        self::assertStringContainsString('current_setting', $canon, 'canon must reference the GUC via current_setting — sinon il a dégénéré');
        self::assertStringContainsString('app.club_id', $canon, 'canon must key on the app.club_id GUC');

        // Dérogations indexées sur table.policyname.cmd (SEC-12 : la clé DOIT porter
        // le nom de la policy — sinon une policy pirate USING (true) partageant la
        // paire table.cmd d'une entrée légitime emprunterait sa branche et
        // consommerait son entrée, neutralisant à la fois la garde ET la
        // bidirectionnalité). Une policy au nom inattendu tombe donc dans le fail.

        // D2 / SEC-12 (résiduel, soldé 2026-08-04) : SELECT HYBRIDE — ouvert quand AUCUN
        // contexte tenant n'est posé (GUC absent ou vidé par clear()), scopé au canon dès
        // qu'il l'est. Le besoin d'ouverture (bootstrap du tenant depuis les memberships,
        // lookup du token public qui PORTE le club) ne vaut qu'AVANT la pose du GUC — le
        // `USING (true)` historique ouvrait aussi tout le reste de la requête. Composé À
        // PARTIR du canon runtime (patron D4) : le sous-prédicat NULLIF en est extrait,
        // pas réécrit — robuste au déparseur.
        self::assertSame(1, preg_match('/^\(club_id = \((?<expr>.+)\)::uuid\)$/', $canon, $canonParts), 'canon must have the form (club_id = (<NULLIF expr>)::uuid) — extraction du sous-prédicat impossible, adapter la regex');
        $hybridSelectPredicate = \sprintf('((%s IS NULL) OR %s)', $canonParts['expr'], $canon);
        $selectPredicateOverrides = [
            'club_user.club_user_read.SELECT' => $hybridSelectPredicate,
            'coach_wish_token.coach_wish_token_read.SELECT' => $hybridSelectPredicate,
        ];

        // D4 : audit_log INSERT n'est PAS ouvert mais s'écarte du canon par FORME —
        // les actions hors club (club_id NULL) sont journalisables. Composé À PARTIR
        // du canon runtime → robuste au déparseur au même titre que D1. L'absence de
        // policy UPDATE/DELETE sur audit_log ne demande rien : sous FORCE, pas de
        // policy = deny (append-only tenu par la base).
        $insertPredicateOverrides = [
            'audit_log.audit_log_insert.INSERT' => \sprintf('((club_id IS NULL) OR %s)', $canon),
        ];

        // D6 / SEC-12 #4 : même énumération des tables club_id que le test FORCE
        // (constante partagée), jointe à pg_policies.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.relname AS table_name, p.policyname, p.cmd, p.qual, p.with_check, p.permissive, p.roles '
            . 'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'JOIN pg_policies p ON p.schemaname = \'public\' AND p.tablename = c.relname '
            . 'WHERE ' . self::CLUB_ID_TABLE_PREDICATE
            . ' ORDER BY c.relname, p.policyname',
        );
        self::assertNotEmpty($rows, 'expected policies on club_id tables');

        $consumedAllowlist = [];
        $consumedOverrides = [];
        // Portes admin comptabilisées PAR TABLE (une seule attendue par table
        // FORCE — vérifié après la boucle contre l'énumération des tables FORCE).
        $adminDoorCounts = [];

        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $policy = (string) $row['policyname'];
            $cmd = (string) $row['cmd'];
            $qual = null === $row['qual'] ? '' : (string) $row['qual'];
            $withCheck = null === $row['with_check'] ? '' : (string) $row['with_check'];

            // Une policy RESTRICTIVE ne fait que RESTREINDRE (sémantique ET) : elle ne
            // peut pas ouvrir une table. Seules les policies PERMISSIVE participent au
            // OU qui peut accorder l'accès — ce sont elles qu'on juge. (Aucune
            // restrictive aujourd'hui ; garde défensive.)
            if ('PERMISSIVE' !== (string) $row['permissive']) {
                continue;
            }

            $key = $table . '.' . $policy . '.' . $cmd;
            $where = \sprintf('%s.%s (%s)', $table, $policy, $cmd);

            // Porte admin sous FORCE RLS. Sur un provider managé le rôle
            // propriétaire (amateo_owner) n'est PAS superuser → FORCE lui
            // s'applique, et sans policy chaque table FORCE le DENY par défaut :
            // la porte de supervision se referme. La policy admin_all (FOR ALL,
            // USING/WITH CHECK true) TO amateo_owner la rouvre. Jugée sur SA clé
            // — le rôle — AVANT le canon tenant : une policy admin_all destinée à
            // TOUT AUTRE rôle (amateo_app, PUBLIC) ne prend PAS cette branche et
            // tombe dans le fail hors-canon plus bas (elle ouvrirait la table au
            // runtime). Le rôle DOIT être exactement {amateo_owner}.
            if ('{amateo_owner}' === (string) $row['roles']) {
                self::assertSame('admin_all', $policy, \sprintf('%s : policy TO amateo_owner mais nommée %s — la porte admin doit s\'appeler admin_all.', $where, $policy));
                self::assertSame('ALL', $cmd, \sprintf('%s : porte admin admin_all mais cmd=%s — attendu FOR ALL.', $where, $cmd));
                self::assertSame('true', $qual, \sprintf('%s : porte admin admin_all mais USING=%s — attendu true (accès total du superviseur).', $where, '' === $qual ? '<none>' : $qual));
                self::assertSame('true', $withCheck, \sprintf('%s : porte admin admin_all mais WITH CHECK=%s — attendu true.', $where, '' === $withCheck ? '<none>' : $withCheck));
                $adminDoorCounts[$table] = ($adminDoorCounts[$table] ?? 0) + 1;

                continue;
            }

            // Tenant-scopée contre le canon → correct, rien de plus à vérifier. On
            // JUGE CHAQUE policy séparément : PostgreSQL fait un OU entre policies
            // permissives, donc une tenant_isolation saine à côté d'un USING (true)
            // laisse la table ouverte — d'où le fail plus bas, sans « il en existe
            // une bonne ».
            if ($this->policyMatchesCanon($cmd, $qual, $withCheck, $canon)) {
                continue;
            }

            // À partir d'ici la policy S'ÉCARTE du canon → acceptable UNIQUEMENT si
            // explicitement justifiée. Chaque branche vérifie les DEUX moitiés du
            // prédicat (USING et WITH CHECK) : la moitié non concernée doit être
            // explicitement vide, sinon une future dérogation ALL/UPDATE verrait sa
            // seconde moitié ignorée en silence (SEC-12 #3).

            // D4 : override de FORME sur l'INSERT (with_check porté, qual NULL).
            if (isset($insertPredicateOverrides[$key])) {
                self::assertSame(
                    $insertPredicateOverrides[$key],
                    $withCheck,
                    \sprintf(
                        '%s : WITH CHECK diverge de sa dérogation justifiée — attendu %s, trouvé %s. Vérifier la forme exacte en base.',
                        $where,
                        $insertPredicateOverrides[$key],
                        '' === $withCheck ? '<none>' : $withCheck,
                    ),
                );
                self::assertSame(
                    '',
                    $qual,
                    \sprintf('%s : dérogation INSERT mais USING non vide (%s) — la seconde moitié du prédicat échapperait au contrôle.', $where, $qual),
                );
                $consumedOverrides[$key] = true;

                continue;
            }

            // D2 : SELECT hybride dérogé (bootstrap club_user / coach_wish_token) — la
            // forme EXACTE est exigée : un retour au USING (true) d'avant SEC-12 tombe
            // dans ce même assert et rougit en nommant l'attendu.
            if (isset($selectPredicateOverrides[$key])) {
                self::assertSame(
                    $selectPredicateOverrides[$key],
                    $qual,
                    \sprintf(
                        '%s : dérogée comme SELECT hybride mais le prédicat est %s, pas le %s attendu. Scoper au GUC, ou corriger la dérogation.',
                        $where,
                        '' === $qual ? '<none>' : $qual,
                        $selectPredicateOverrides[$key],
                    ),
                );
                self::assertSame(
                    '',
                    $withCheck,
                    \sprintf('%s : dérogation SELECT hybride mais WITH CHECK non vide (%s) — la seconde moitié du prédicat échapperait au contrôle.', $where, $withCheck),
                );
                $consumedAllowlist[$key] = true;

                continue;
            }

            // Écart non justifié → la fuite que ce test garde.
            self::fail(\sprintf(
                '%s N\'EST PAS tenant-scopée : USING=%s WITH CHECK=%s, canon attendu %s. Une policy permissive hors-canon OUVRE la table (PostgreSQL fait un OU entre policies permissives). Correctif : scoper au GUC app.club_id, ou ajouter une dérogation JUSTIFIÉE dans ce test.',
                $where,
                '' === $qual ? '<none>' : $qual,
                '' === $withCheck ? '<none>' : $withCheck,
                $canon,
            ));
        }

        // D3 : dérogations BIDIRECTIONNELLES — une entrée qui n'a été consommée par
        // aucune policy réellement hors-canon est périmée (le durcissement a été fait,
        // ou la table/policy a disparu ou été renommée). Une liste qui ne peut pas se
        // périmer ment.
        foreach (array_keys($selectPredicateOverrides) as $key) {
            self::assertArrayHasKey(
                $key,
                $consumedAllowlist,
                \sprintf('dérogation périmée : aucune policy SELECT hors-canon pour \'%s\' — retirer l\'entrée de $selectPredicateOverrides.', $key),
            );
        }
        foreach (array_keys($insertPredicateOverrides) as $key) {
            self::assertArrayHasKey(
                $key,
                $consumedOverrides,
                \sprintf('dérogation périmée : aucune policy INSERT hors-canon pour \'%s\' — retirer l\'entrée de $insertPredicateOverrides.', $key),
            );
        }

        // PRÉSENCE de la porte admin : énumération dynamique des tables FORCE
        // (pas seulement les tables club_id — la porte admin doit exister sur
        // TOUTE table FORCE), chacune doit avoir consommé EXACTEMENT une policy
        // admin_all dans la boucle. Une migration future qui ajoute une table
        // FORCE sans sa porte admin (ou en pose deux) rougit ici en la nommant :
        // sur un provider managé cette table refermerait la porte de supervision.
        /** @var list<string> $forceTables */
        $forceTables = $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = \'public\' AND c.relforcerowsecurity AND c.relkind IN (\'r\', \'p\') ORDER BY c.relname',
        );
        self::assertNotEmpty($forceTables, 'expected FORCE-RLS tables to exist');
        foreach ($forceTables as $table) {
            self::assertSame(
                1,
                $adminDoorCounts[$table] ?? 0,
                \sprintf('table %s est sous FORCE RLS mais porte %d policy admin_all (attendu exactement 1) — une migration a ajouté une table FORCE sans sa porte admin_all, ou l\'a dupliquée. Sous un provider managé, le rôle propriétaire non-superuser y serait DENY.', $table, $adminDoorCounts[$table] ?? 0),
            );
        }
    }

    public function testGucScopedSelectCannotSeeOtherClub(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        $this->guc->setClubId(self::CLUB_A);
        /** @var list<string> $clubIds */
        $clubIds = $this->connection->fetchFirstColumn('SELECT DISTINCT club_id FROM team_tag');

        self::assertSame([self::CLUB_A], $clubIds, 'club A must only ever see its own rows');
    }

    public function testNoGucSeesNoRows(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        $this->guc->clear();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM team_tag'), 'no GUC → fail-closed, zero rows, no error');
    }

    public function testCrossTenantUpdateAndDeleteAffectZeroRows(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        $this->guc->setClubId(self::CLUB_A);
        $updated = $this->connection->executeStatement(
            'UPDATE team_tag SET name = ? WHERE club_id = ?',
            ['pwned', self::CLUB_B],
        );
        $deleted = $this->connection->executeStatement(
            'DELETE FROM team_tag WHERE club_id = ?',
            [self::CLUB_B],
        );

        self::assertSame(0, $updated, 'cross-tenant UPDATE must touch zero rows');
        self::assertSame(0, $deleted, 'cross-tenant DELETE must touch zero rows');
    }

    public function testInsertWithMismatchedClubIdIsRejected(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        // GUC = A, row claims club B → WITH CHECK must reject.
        $this->guc->setClubId(self::CLUB_A);
        $this->expectException(DriverException::class);
        $this->insertTeam(self::CLUB_B, 'smuggled');
    }

    public function testClubUserRemainsReadableWithoutGuc(): void
    {
        // Membership bootstrap: the tenant listener / register / /api/me read
        // club_user BEFORE any club is known. SELECT must work without a GUC.
        $this->seedTwoClubsWithOneTeamEach();
        $userId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->connection->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (?, 1, now(), now(), ?, ?, ?, ?)',
            [$userId, 'rls-bootstrap@test.fr', 'x', 'R', 'B'],
        );
        $this->guc->setClubId(self::CLUB_A);
        $this->connection->executeStatement(
            'INSERT INTO club_user (id, version, created_at, updated_at, club_id, user_id, role, joined_at, is_active) VALUES (?, 1, now(), now(), ?, ?, ?, now(), true)',
            ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', self::CLUB_A, $userId, 'admin'],
        );

        $this->guc->clear();
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM club_user WHERE user_id = ?',
            [$userId],
        );
        self::assertSame(1, $count, 'club_user must stay readable without a GUC (tenant bootstrap)');
    }

    public function testClubUserSelectIsTenantScopedOnceGucIsSet(): void
    {
        // SEC-12 (résiduel) — l'autre moitié du contrat hybride : dès qu'un contexte
        // tenant existe, club_user redevient étanche. Avant ce durcissement le SELECT
        // était ouvert en permanence et l'isolation de cette table ne tenait qu'au
        // filtre Doctrine (une couche au lieu de deux). coach_wish_token porte le MÊME
        // prédicat, exigé à l'identique par testEveryPolicyOnClubIdTablesIsTenantScoped
        // (dérogation partagée) : le comportement est prouvé ici une fois, l'égalité de
        // texte fait le reste — semer un token exige la chaîne campagne/coach/saison,
        // coût sans gain de preuve.
        $userId = $this->seedUserWithMembershipInBothClubs();

        $this->guc->setClubId(self::CLUB_A);
        /** @var list<string> $clubIds */
        $clubIds = $this->connection->fetchFirstColumn(
            'SELECT club_id FROM club_user WHERE user_id = ? ORDER BY club_id',
            [$userId],
        );
        self::assertSame([self::CLUB_A], $clubIds, 'GUC posé → club_user ne montre que les memberships du tenant courant');
    }

    public function testRunWithoutTenantSeesAcrossClubsThenRestoresGuc(): void
    {
        // Le geste EXPLICITE qui remplace l'ouverture permanente : la lecture
        // cross-tenant légitime (export RGPD, effacement de compte, liste des clubs
        // d'un user) sort du contexte le temps d'une requête — et le REND, sinon la
        // suite de la requête HTTP tournerait sans tenant, fail-closed partout.
        $userId = $this->seedUserWithMembershipInBothClubs();

        $this->guc->setClubId(self::CLUB_A);
        /** @var list<string> $clubIds */
        $clubIds = $this->guc->runWithoutTenant(
            fn (): array => $this->connection->fetchFirstColumn(
                'SELECT club_id FROM club_user WHERE user_id = ? ORDER BY club_id',
                [$userId],
            ),
        );
        self::assertSame([self::CLUB_A, self::CLUB_B], $clubIds, 'runWithoutTenant doit voir les memberships de TOUS les clubs');

        self::assertSame(
            self::CLUB_A,
            $this->connection->fetchOne('SELECT current_setting(\'app.club_id\', true)'),
            'le GUC doit être RESTAURÉ après runWithoutTenant',
        );
    }

    public function testFindActiveClubIdsStaysCrossClubWithGucSet(): void
    {
        // LE consommateur qui aurait cassé en silence : ClubStateProvider liste les
        // clubs d'un user multi-club alors que le GUC pointe UN club. Le repository
        // doit sortir du contexte lui-même (SEC-12) — sans ça, l'écran « mes clubs »
        // d'un user multi-club se réduirait au club actif, sans erreur nulle part.
        $userId = $this->seedUserWithMembershipInBothClubs();
        $repository = self::getContainer()->get(ClubUserRepository::class);
        self::assertInstanceOf(ClubUserRepository::class, $repository);

        $this->guc->setClubId(self::CLUB_A);
        $clubIds = $repository->findActiveClubIds($userId);
        sort($clubIds);

        self::assertSame([self::CLUB_A, self::CLUB_B], $clubIds, 'findActiveClubIds doit rester cross-club même GUC posé');
    }

    public function testAdminDoorLetsOwnerCrossClubsWhileAppUserStaysScoped(): void
    {
        // P5-7 — comportement des DEUX rôles sous FORCE RLS, sur la CONNEXION ADMIN.
        // En dev/test amateo_owner est superuser (bypass total) : on ne peut PAS
        // prouver la porte admin depuis lui. On crée donc un rôle propriétaire
        // NON-superuser (membre de amateo_owner, DML hérité, attributs SUPERUSER/
        // BYPASSRLS non hérités) — la situation exacte d'un provider managé — et on
        // bascule dessus par SET LOCAL ROLE (LOCAL obligatoire : un SET ROLE nu
        // survivrait à la connexion statique et empoisonnerait la suite).
        // Tout le semis vit sur la connexion admin, dans SA transaction : les lignes
        // semées sur la connexion default (autre transaction DAMA) y sont invisibles.
        $clubA = '11111111-1111-4111-8111-111111111111';
        $clubB = '22222222-2222-4222-8222-222222222222';

        $admin = $this->adminConnection();

        // Idempotence : nom fixe, DROP IF EXISTS en tête (pas de paratest ici).
        $admin->executeStatement('DROP ROLE IF EXISTS rls_admin_probe');
        $admin->executeStatement('CREATE ROLE rls_admin_probe NOSUPERUSER NOLOGIN IN ROLE amateo_owner');

        $admin->beginTransaction();
        try {
            // Semis (comme amateo_owner : superuser bypass en dev/test, porte
            // admin_all sur un managé). club n'a pas de club_id → pas de RLS.
            foreach ([$clubA => 'PA', $clubB => 'PB'] as $clubId => $tag) {
                $admin->executeStatement(
                    'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, 1, now(), now(), ?, ?, ?, ?, true, 0)',
                    [$clubId, 'Admin Probe ' . $tag, 'admin-probe-' . strtolower($tag) . '-' . substr(md5($clubId), 0, 6), 'Europe/Paris', 'fr'],
                );
                $admin->executeStatement(
                    'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)',
                    [$clubId, 'Team ' . $tag],
                );
            }

            // SENS 1 : le propriétaire non-superuser voit et écrit CROSS-CLUB via admin_all.
            $admin->executeStatement('SET LOCAL ROLE rls_admin_probe');
            self::assertFalse(
                (bool) $admin->fetchOne('SELECT usesuper FROM pg_user WHERE usename = current_user'),
                'le probe doit être NON-superuser — sinon il bypasse RLS et la porte admin ne prouve rien',
            );
            self::assertSame(
                2,
                (int) $admin->fetchOne('SELECT count(*) FROM team_tag WHERE club_id IN (?, ?)', [$clubA, $clubB]),
                'porte admin : le rôle propriétaire non-superuser voit les DEUX clubs sous FORCE RLS',
            );
            $admin->executeStatement(
                'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)',
                [$clubB, 'admin-cross-insert'],
            );
            self::assertSame(
                1,
                $admin->executeStatement('UPDATE team_tag SET name = ? WHERE club_id = ?', ['admin-cross-update', $clubA]),
                'porte admin : UPDATE cross-club passe',
            );
            self::assertSame(
                1,
                $admin->executeStatement('DELETE FROM team_tag WHERE club_id = ? AND name = ?', [$clubB, 'admin-cross-insert']),
                'porte admin : DELETE cross-club passe',
            );

            // SENS 2 : amateo_app reste scopé au GUC — le durcissement admin ne l'ouvre pas.
            $admin->executeStatement('SET LOCAL ROLE amateo_app');
            $admin->executeStatement('SELECT set_config(?, ?, true)', ['app.club_id', $clubA]);
            /** @var list<string> $visible */
            $visible = $admin->fetchFirstColumn('SELECT DISTINCT club_id FROM team_tag WHERE club_id IN (?, ?)', [$clubA, $clubB]);
            self::assertSame([$clubA], $visible, 'amateo_app posé sur A ne voit que A — la porte admin n\'a pas ouvert amateo_app');

            // INSERT cross-club rejeté par WITH CHECK — contenu dans un savepoint pour
            // que l'abort PostgreSQL ne poison pas la transaction externe (nettoyage).
            $rejected = false;
            $admin->beginTransaction();
            try {
                $admin->executeStatement(
                    'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)',
                    [$clubB, 'app-user-smuggle'],
                );
                $admin->commit();
            } catch (DriverException) {
                $rejected = true;
                $admin->rollBack();
            }
            self::assertTrue($rejected, 'amateo_app sous GUC=A doit se voir REFUSER un INSERT club B (WITH CHECK tenant_isolation)');
        } finally {
            $admin->executeStatement('RESET ROLE');
            $admin->rollBack();
            $admin->executeStatement('DROP ROLE IF EXISTS rls_admin_probe');
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->guc = self::getContainer()->get(TenantConnectionContext::class);
    }

    private function adminConnection(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Une policy est tenant-scopée ssi son/ses prédicat(s) égalent STRICTEMENT le
     * canon, selon la commande :
     *  - ALL/SELECT/UPDATE : qual === canon ET (with_check vide OU === canon)
     *  - DELETE : qual === canon
     *  - INSERT : with_check === canon (qual est NULL pour INSERT).
     */
    private function policyMatchesCanon(string $cmd, string $qual, string $withCheck, string $canon): bool
    {
        return match ($cmd) {
            'ALL', 'SELECT', 'UPDATE' => $qual === $canon && ('' === $withCheck || $withCheck === $canon),
            'DELETE' => $qual === $canon,
            'INSERT' => $withCheck === $canon,
            default => false,
        };
    }

    /** Un user actif dans les DEUX clubs — le cas multi-club que SEC-12 ne doit pas casser. */
    private function seedUserWithMembershipInBothClubs(): string
    {
        $this->seedTwoClubsWithOneTeamEach();
        $userId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $this->connection->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (?, 1, now(), now(), ?, ?, ?, ?)',
            [$userId, 'rls-multiclub@test.fr', 'x', 'R', 'M'],
        );
        foreach ([self::CLUB_A => 'f1f1f1f1-f1f1-4f1f-8f1f-f1f1f1f1f1f1', self::CLUB_B => 'f2f2f2f2-f2f2-4f2f-8f2f-f2f2f2f2f2f2'] as $clubId => $membershipId) {
            // L'INSERT de club_user est tenant-gardé (WITH CHECK) : on pose le GUC
            // club par club, comme AccountErasureService le fait pour ses UPDATE.
            $this->guc->setClubId($clubId);
            $this->connection->executeStatement(
                'INSERT INTO club_user (id, version, created_at, updated_at, club_id, user_id, role, joined_at, is_active) VALUES (?, 1, now(), now(), ?, ?, ?, now(), true)',
                [$membershipId, $clubId, $userId, 'admin'],
            );
        }
        $this->guc->clear();

        return $userId;
    }

    private function seedTwoClubsWithOneTeamEach(): void
    {
        foreach ([self::CLUB_A => 'RLSA', self::CLUB_B => 'RLSB'] as $clubId => $slug) {
            // club has no club_id column → no RLS, insert freely.
            $this->connection->executeStatement(
                'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, 1, now(), now(), ?, ?, ?, ?, true, 0)',
                [$clubId, 'Club ' . $slug, strtolower($slug) . '-' . substr(md5($clubId), 0, 6), 'Europe/Paris', 'fr'],
            );
            $this->guc->setClubId($clubId);
            $this->insertTeam($clubId, 'Team ' . $slug);
        }
        $this->guc->clear();
    }

    private function insertTeam(string $clubId, string $name): void
    {
        // team_tag is the leanest club-scoped table (no FK chain) — the policy
        // template is identical on every tenant table.
        $this->connection->executeStatement(
            'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)',
            [$clubId, $name],
        );
    }
}
