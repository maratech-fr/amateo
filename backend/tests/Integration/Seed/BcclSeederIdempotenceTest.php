<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seed;

use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P4-84 — le seed BCCL est IDEMPOTENT : le relancer ne crée pas de doublon.
 *
 * Le « bug doublons » qui a ouvert le lot était une méprise (copies légitimes par
 * plan ADR-0002 + gymnases homonymes entre clubs — 0 doublon avec `schedule_plan_id`
 * dans la clé). Un doublon EXACT de créneau reste par ailleurs un état TOLÉRÉ que le
 * moteur déduplique (le récap de capacité en dépend — `RecapCapacityWarningTest`) :
 * rien ne l'interdit en base. Ce que le seeder garantit tient donc à sa seule PURGE
 * (`BcclSeeder`, section « VENUE TRAINING SLOTS ») : elle vide les créneaux du
 * club/saison AVANT de réinsérer, la boucle d'insertion ne contrôlant aucune
 * existence. Commenter cette purge fait DOUBLER les créneaux au second passage —
 * comptes divergents et clés de dédup vues deux fois, que ce test attrape.
 *
 * Le seeder exige la connexion SUPERUSER (il purge/insère à travers la RLS, comme
 * `make fixtures`). En test la connexion par défaut est `app_user` : on bascule
 * `DATABASE_URL` sur l'URL admin AVANT de booter, exactement comme la commande
 * `app:demo:seed-bccl` tourne sous `DATABASE_URL=$DATABASE_ADMIN_URL`.
 *
 * ⚠ PROCESSUS ISOLÉ obligatoire : DAMA épingle sa connexion statique au PREMIER
 * usager de `default` pour toute la durée du process (c'est ce qui tient sa
 * transaction ouverte d'un test à l'autre). Un autre test l'ayant ouverte en
 * `app_user`, notre bascule d'URL n'aurait plus prise. Un process neuf établit
 * la connexion superuser d'entrée.
 *
 * ⚠ ROLLBACK EXPLICITE : sur cette connexion superuser reconstruite, la
 * transaction statique de DAMA ne couvre pas nos écritures (constaté : un BCCL
 * fuyait dans la base de test partagée et cassait les tests Ffbb en aval). On
 * ouvre donc NOTRE transaction et on la rollback en `tearDown` — le seed, massif,
 * ne laisse aucune trace, et les deux passages tiennent dans la même transaction
 * (savepoints), ce qui n'ôte rien à la mesure d'idempotence.
 */
#[Group('integration')]
final class BcclSeederIdempotenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private Connection $connection;

    private BcclSeeder $seeder;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunningTheDevSeedTwiceIsStable(): void
    {
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertTrue($this->em->isOpen(), 'le premier passage garde l\'EntityManager ouvert');
        $first = $this->counts();

        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertTrue($this->em->isOpen(), 'le second passage garde l\'EntityManager ouvert');
        $second = $this->counts();

        self::assertSame($first, $second, 'un second seed dev ne change aucun compte (clubs, équipes, créneaux, réservations)');
        self::assertSame([], $this->duplicateSlots(), 'aucun créneau en doublon pour la clé (gymnase, jour, heure, saison, plan)');
    }

    /**
     * NR — les noms des contraintes semées SONT ceux que le wizard produirait (décision fondateur
     * 2026-08-15 : « on doit croire que la donnée vient de l'app »). Test de FORME, pas de contenu :
     * chaque nom suit « <cible> · <prédicat> », et une contrainte ciblant un TAG commence par
     * « Groupe » (le sélecteur de cible du wizard préfixe ainsi les groupes). La convention ne se
     * perd donc pas au fil des éditions du seed.
     *
     * EXCEPTION (P5-13 « incident Matéo ») : une fermeture datée `venue_closed` est app-générée
     * aussi, mais par une AUTRE règle — `useCreateVenueClosure` nomme la contrainte comme le TITRE
     * de son entrée de fermeture, pas « <cible> · <prédicat> ». On l'exclut donc du motif « · » et
     * on vérifie à la place sa convention propre : son nom == le titre de l'entrée qu'elle porte.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSeededConstraintNamesLookAppGenerated(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{name: string, config: string, entry_title: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.name, c.config, e.title AS entry_title FROM "constraint" c '
            . 'LEFT JOIN calendar_entry e ON e.id = c.calendar_entry_id WHERE c.club_id = ?',
            [$club->getId()],
        );
        self::assertNotEmpty($rows, 'le seed pose bien des contraintes');

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $config = json_decode((string) $row['config'], true);

            // Fermeture datée : nom == titre de son entrée (convention `useCreateVenueClosure`).
            if (\is_array($config) && 'venue_closed' === ($config['type'] ?? null)) {
                self::assertSame((string) $row['entry_title'], $name, \sprintf('« %s » est une fermeture datée : son nom doit être le titre de son entrée', $name));
                continue;
            }

            self::assertMatchesRegularExpression('/^.+ · .+$/u', $name, \sprintf('« %s » ne suit pas « <cible> · <prédicat> »', $name));

            if (\is_array($config) && isset($config['targetTag'])) {
                self::assertStringStartsWith('Groupe ', $name, \sprintf('« %s » cible un tag : le nom doit commencer par « Groupe »', $name));
            }
        }
    }

    /**
     * P5-17 — après le seed dev, le plan SEASON du club POINTE (chosen) une version
     * COMPLETED : la transcription littérale du planning réel (90 créneaux), marquée
     * `seed-transcription`. Le club dev n'est donc plus « avant première génération » —
     * il ouvre sur le planning réel, sans jamais appeler le solveur.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedPointsSeasonPlanAtCompletedTranscription(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        $row = $this->connection->fetchAssociative(
            'SELECT s.status, s.solver_version, '
            . '(SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
            . 'FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
            . 'WHERE sp.season_id = (SELECT id FROM season WHERE club_id = ? AND name = \'2026-2027\') '
            . 'AND sp.type = \'SEASON\'',
            [$club->getId()],
        );

        self::assertNotFalse($row, 'le plan SEASON du club dev pointe une version choisie');
        self::assertSame('COMPLETED', (string) $row['status'], 'la version pointée est COMPLETED');
        self::assertSame('seed-transcription', (string) $row['solver_version'], 'la provenance est la transcription du seed');
        self::assertSame(90, (int) $row['slot_count'], 'la transcription pose exactement 90 créneaux (lundi→samedi)');
    }

    /**
     * P5-17 — chaque RÉSERVATION DE BASE seedée (pin durable HARD, plan NULL) retrouve son
     * créneau dans la transcription de SAISON pointée, verrouillé HARD (exactement ce qu'un
     * import de résultat solveur produirait). Une réservation ORPHELINE — sans créneau transcrit
     * apparié — trahit une transcription incomplète, et ce test la nomme.
     *
     * P5-13 : la clause `schedule_plan_id IS NULL` cible les seules réservations de BASE ; celles
     * des reprises sont portées par leur plan de période et vérifiées par leur propre test.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEverySeededReservationHasMatchingTranscribedSlot(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        $orphans = $this->connection->fetchAllAssociative(
            'SELECT r.team_id, r.venue_id, r.day_of_week, r.start_time FROM reservation r '
            . 'WHERE r.club_id = ? AND r.schedule_plan_id IS NULL AND NOT EXISTS ( '
            . 'SELECT 1 FROM schedule_slot_template t '
            . 'JOIN schedule_plan sp ON sp.chosen_schedule_id = t.schedule_id '
            . 'WHERE sp.type = \'SEASON\' AND sp.club_id = r.club_id '
            . 'AND t.team_id = r.team_id AND t.venue_id = r.venue_id '
            . 'AND t.day_of_week = r.day_of_week AND t.start_time = r.start_time '
            . 'AND t.lock_level = \'HARD\' )',
            [$club->getId()],
        );

        self::assertSame([], $orphans, 'chaque réservation seedée est appariée à un créneau transcrit verrouillé HARD');
    }

    /**
     * P5-17 — la transcription ne vise QUE le profil dev : le club de DÉMONSTRATION reste
     * « avant première génération » (plan SEASON sans pointeur), l'écran de démo part sur le
     * wizard/Récap comme avant.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDemoSeedLeavesSeasonPlanUnpointed(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::demo('demo-pass-transcription'));

        $chosen = $this->connection->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE club_id = ? AND type = \'SEASON\'',
            [$club->getId()],
        );

        self::assertNull(false === $chosen ? null : $chosen, 'le club de démonstration ne pointe aucun planning');
    }

    /**
     * NR — LE PLANNING RÉEL TRANSCRIT RESPECTE LES RÈGLES DURES QUE LE SEED DÉCLARE.
     *
     * Le seed fait deux choses qui peuvent se contredire : il transcrit le planning RÉEL du club
     * (P5-17, 90 créneaux relevés sur le terrain) et il déclare les contraintes du club. Ajouter
     * une règle DURE qui interdit ce que le planning réel fait rendrait la génération infaisable
     * — et on ne s'en apercevrait qu'au premier `make fixtures` suivi d'une génération, donc
     * potentiellement des jours plus tard, sur une base neuve.
     *
     * Ce test le dit tout de suite, sans moteur ni solve : pour chaque contrainte HARD de portée
     * ÉQUIPE (jour ou horaire), chaque séance transcrite de cette équipe doit la satisfaire.
     *
     * PORTÉE ASSUMÉE : les contraintes de portée CLUB (par tag) ne sont pas couvertes ici — leur
     * résolution en équipes vit dans le builder, pas dans le seed, et la garder ici dupliquerait
     * cette algèbre. Le risque que ce test cible est celui qui s'est présenté : une règle TEAM
     * ajoutée au seed d'après ce que le gestionnaire a saisi dans l'app.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTranscribedRealScheduleSatisfiesEveryHardTeamRule(): void
    {
        $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{team: string, day: int, start: string, name: string, family: string, config: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, s.day_of_week AS day, to_char(s.start_time, \'HH24:MI\') AS start, '
            . 'c.name AS name, c.family AS family, c.config::text AS config '
            . 'FROM "constraint" c '
            . 'JOIN team t ON t.id = c.scope_target_id '
            . 'JOIN schedule_slot_template s ON s.team_id = t.id '
            . 'JOIN schedule sc ON sc.id = s.schedule_id '
            . 'JOIN schedule_plan p ON p.id = sc.schedule_plan_id AND p.type = \'SEASON\' '
            . 'WHERE c.calendar_entry_id IS NULL AND c.scope = \'TEAM\' AND c.rule_type = \'HARD\' '
            . 'AND c.family IN (\'DAY\', \'TIME\')',
        );
        self::assertNotSame([], $rows, 'le seed doit produire des règles dures d\'équipe ET un planning transcrit — sinon ce test ne garde rien');

        $violations = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $config */
            $config = json_decode($row['config'], true, 512, \JSON_THROW_ON_ERROR);
            $day = (int) $row['day'];
            $start = (string) $row['start'];

            $allowed = $config['allowedDays'] ?? null;
            if (\is_array($allowed) && !\in_array($day, array_map('intval', $allowed), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $forbidden = $config['forbiddenDays'] ?? null;
            if (\is_array($forbidden) && \in_array($day, array_map('intval', $forbidden), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $min = $config['minStartTime'] ?? null;
            if (\is_string($min) && $start < $min) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
            $max = $config['maxStartTime'] ?? null;
            if (\is_string($max) && $start > $max) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
        }

        self::assertSame([], array_values(array_unique($violations)), 'une règle dure du seed contredit le planning réel qu\'il transcrit');
    }

    /**
     * P5-13 — chaque plan de REPRISE pointe (chosen) une version COMPLETED transcrivant sa
     * semaine au bon nombre de créneaux (25 pour le 17 août, 38 pour le 24 août), et porte ses
     * groupes de mutualisation ANCRÉS au plan : {SM1,SM2}+{SF1,SF2} le 17 (SF séparées ⇒ absentes
     * le 24), {SM1,SM2} seul le 24.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachRepriseWeekPlanPointsCompletedTranscriptionWithItsGroups(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        foreach ([['Reprise du 17 août', 25, 2], ['Reprise du 24 août', 38, 1]] as [$planName, $expectedSlots, $expectedGroups]) {
            $row = $this->connection->fetchAssociative(
                'SELECT s.status, (SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
                . 'FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
                . 'WHERE sp.club_id = ? AND sp.name = ? AND sp.type = \'HOLIDAY\'',
                [$club->getId(), $planName],
            );
            self::assertNotFalse($row, \sprintf('le plan « %s » pointe une version choisie', $planName));
            self::assertSame('COMPLETED', (string) $row['status'], \sprintf('« %s » pointe une version COMPLETED', $planName));
            self::assertSame($expectedSlots, (int) $row['slot_count'], \sprintf('« %s » transcrit %d séances', $planName, $expectedSlots));

            $groupCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM shared_training_group g JOIN schedule_plan sp ON sp.id = g.schedule_plan_id '
                . 'WHERE sp.club_id = ? AND sp.name = ?',
                [$club->getId(), $planName],
            );
            self::assertSame($expectedGroups, $groupCount, \sprintf('« %s » porte %d groupe(s) de mutualisation', $planName, $expectedGroups));
        }

        // {SF1,SF2} n'est mutualisé QUE la semaine du 17 : aucun groupe de la semaine du 24 ne
        // contient SF1 ni SF2 (elles s'y entraînent séparément).
        $sfGroupsWeek24 = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT gt.group_id) FROM shared_training_group_team gt '
            . 'JOIN schedule_plan sp ON sp.id = gt.schedule_plan_id '
            . 'JOIN team t ON t.id = gt.team_id '
            . 'WHERE sp.club_id = ? AND sp.name = ? AND t.name IN (\'SF1\', \'SF2\')',
            [$club->getId(), 'Reprise du 24 août'],
        );
        self::assertSame(0, $sfGroupsWeek24, 'SF1/SF2 ne sont pas mutualisées la semaine du 24 août');

        // {SM1,SM2} l'est LES DEUX semaines : chaque plan a un groupe couvrant exactement SM1 et SM2.
        foreach (['Reprise du 17 août', 'Reprise du 24 août'] as $planName) {
            $smMembers = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM shared_training_group_team gt '
                . 'JOIN schedule_plan sp ON sp.id = gt.schedule_plan_id '
                . 'JOIN team t ON t.id = gt.team_id '
                . 'WHERE sp.club_id = ? AND sp.name = ? AND t.name IN (\'SM1\', \'SM2\')',
                [$club->getId(), $planName],
            );
            self::assertSame(2, $smMembers, \sprintf('« %s » mutualise SM1 et SM2', $planName));
        }
    }

    /**
     * Défaut 4 (doublon « Vacances d'été ») — la mère « Vacances d'été » est un ANCRAGE de
     * vacances scolaires : elle porte `school_holiday_id` pointant la vacance « été » de la ZONE
     * du club (dépt 69 → A), et sa fenêtre est celle de la vacance CLAMPÉE à la saison. Sans ce
     * lien, le cockpit affichait DEUX « Vacances d'été » (feed scolaire + cette entrée).
     *
     * Les migrations créent la table de référence mais ne la peuplent pas en test : on garantit
     * la ligne « été » que le seed doit rattacher. Idempotence : un second run garde le lien.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSummerRepriseMotherCarriesSchoolHolidayLink(): void
    {
        // Donnée de référence globale (sans RLS) : la vacance d'été de la zone A recouvrant
        // l'été 2026 (04/07 → 31/08), telle que le feed officiel la porte (année 2025-2026).
        $this->connection->executeStatement(
            'INSERT INTO school_holiday_period (id, created_at, zone, label, holiday_type, start_date, end_date, school_year) '
            . 'VALUES (:id, now(), \'A\', \'Vacances d\'\'Été\', \'ete\', \'2026-07-04\', \'2026-08-31\', \'2025-2026\') '
            . 'ON CONFLICT (zone, holiday_type, school_year) DO UPDATE SET start_date = EXCLUDED.start_date, end_date = EXCLUDED.end_date',
            ['id' => '11111111-1111-4111-8111-111111111111'],
        );
        $holidayId = (string) $this->connection->fetchOne(
            'SELECT id FROM school_holiday_period WHERE zone = \'A\' AND holiday_type = \'ete\' AND school_year = \'2025-2026\'',
        );

        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        $mother = $this->connection->fetchAssociative(
            'SELECT school_holiday_id, to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id IS NULL AND title = ?',
            [$club->getId(), 'Vacances d\'été'],
        );
        self::assertNotFalse($mother, 'la mère « Vacances d\'été » existe');
        self::assertSame($holidayId, (string) $mother['school_holiday_id'], 'la mère porte le lien vers la vacance scolaire été de sa zone');
        // Fenêtre = celle de la vacance (04/07→31/08) CLAMPÉE à la saison (début 15/07).
        self::assertSame('2026-07-15', (string) $mother['s'], 'début clampé au début de saison');
        self::assertSame('2026-08-31', (string) $mother['e'], 'fin = fin des vacances (dans la saison)');

        // Idempotence : un second run garde le lien (find-or-create, aucun doublon).
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        $secondLink = (string) $this->connection->fetchOne(
            'SELECT school_holiday_id FROM calendar_entry WHERE club_id = ? AND parent_entry_id IS NULL AND title = ?',
            [$club->getId(), 'Vacances d\'été'],
        );
        self::assertSame($holidayId, $secondLink, 'un second seed garde le lien');
    }

    /**
     * NR (P5-13) — LES SEMAINES DE REPRISE TRANSCRITES RESPECTENT LES RÈGLES DURES D'ÉQUIPE
     * QU'ELLES N'ONT PAS DÉCOCHÉES.
     *
     * Miroir période de {@see testTheTranscribedRealScheduleSatisfiesEveryHardTeamRule}, avec un
     * amendement essentiel : une contrainte DÉCOCHÉE pour le plan (ConstraintPeriodOverride
     * isActive=false) n'est PAS exigée — c'est précisément le mécanisme qui rend une reprise
     * cohérente avec les règles de saison qu'elle contredit (SM1 hors mardi/jeudi, etc.). Toute
     * règle dure d'équipe (jour/horaire) NON décochée doit, elle, être satisfaite par chaque
     * séance transcrite de la semaine.
     *
     * PORTÉE ASSUMÉE (identique au test de saison) : seules les contraintes de portée ÉQUIPE
     * sont couvertes ; la résolution des règles CLUB par tag vit dans le builder, pas dans le seed.
     *
     * Falsifiable : retirer un décochage du seed (p. ex. « SM1 · uniquement mardi, jeudi » de la
     * semaine du 17) rend ce test ROUGE en nommant la séance du lundi de SM1.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTranscribedReprisePlansSatisfyEveryHardTeamRuleHonouringOverrides(): void
    {
        $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{team: string, day: int, start: string, name: string, family: string, config: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, s.day_of_week AS day, to_char(s.start_time, \'HH24:MI\') AS start, '
            . 'c.name AS name, c.family AS family, c.config::text AS config '
            . 'FROM "constraint" c '
            . 'JOIN team t ON t.id = c.scope_target_id '
            . 'JOIN schedule_slot_template s ON s.team_id = t.id '
            . 'JOIN schedule sc ON sc.id = s.schedule_id '
            . 'JOIN schedule_plan p ON p.id = sc.schedule_plan_id AND p.type = \'HOLIDAY\' AND sc.id = p.chosen_schedule_id '
            . 'WHERE c.calendar_entry_id IS NULL AND c.scope = \'TEAM\' AND c.rule_type = \'HARD\' '
            . 'AND c.family IN (\'DAY\', \'TIME\') '
            // Une règle DÉCOCHÉE pour ce plan (isActive=false) n'est pas exigée.
            . 'AND NOT EXISTS (SELECT 1 FROM constraint_period_override o '
            . 'WHERE o.schedule_plan_id = p.id AND o.constraint_id = c.id AND o.is_active = false)',
        );
        self::assertNotSame([], $rows, 'le seed doit produire des règles dures d\'équipe ET des semaines de reprise transcrites — sinon ce test ne garde rien');

        $violations = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $config */
            $config = json_decode($row['config'], true, 512, \JSON_THROW_ON_ERROR);
            $day = (int) $row['day'];
            $start = (string) $row['start'];

            $allowed = $config['allowedDays'] ?? null;
            if (\is_array($allowed) && !\in_array($day, array_map('intval', $allowed), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $forbidden = $config['forbiddenDays'] ?? null;
            if (\is_array($forbidden) && \in_array($day, array_map('intval', $forbidden), true)) {
                $violations[] = \sprintf('%s le jour %d — « %s »', $row['team'], $day, $row['name']);
            }
            $min = $config['minStartTime'] ?? null;
            if (\is_string($min) && $start < $min) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
            $max = $config['maxStartTime'] ?? null;
            if (\is_string($max) && $start > $max) {
                $violations[] = \sprintf('%s à %s — « %s »', $row['team'], $start, $row['name']);
            }
        }

        self::assertSame([], array_values(array_unique($violations)), 'une règle dure NON décochée du seed contredit une semaine de reprise transcrite');
    }

    /**
     * P5-13 « incident Matéo » — le seed dev fige l'état d'ADAPTATION EN COURS du gestionnaire :
     *
     *  - le FAIT : une entrée RACINE `closure` « Matéo indisponible (travaux) » (18/08→30/09) SANS
     *    plan, portant sa datée `venue_closed` (FACILITY/HARD sur Matéo, config datée) ;
     *  - la RÉPONSE ENTAMÉE : un segment-enfant (parent = l'incident, 07→27/09) né AVEC son plan
     *    CLOSURE, plan NON validé (chosen NULL) et SANS version (0 schedule) — un travail en cours ;
     *  - ses réglages : 12 TeamPeriodOverride (8 équipes actives à 2 séances/sem, « Training
     *    Individuel » + les 3 équipes « Academie » décochées — BYE pendant l'incident),
     *    1 ConstraintPeriodOverride (« SM2 · au moins 1 à Matéo » décochée), 0 VenuePeriodOverride
     *    (la fermeture agit par l'état effectif, pas par un override).
     *
     * Falsifiable : retirer le décochage de « SM2 · au moins 1 à Matéo » du seed, ou une des 12
     * lignes d'équipe (les 3 « Academie » comprises), ou la datée `venue_closed`, rend ce test
     * ROUGE en nommant l'invariant.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedCarriesMateoIncidentAdaptationInProgress(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        // --- L'incident (entrée racine closure, sans plan) + sa datée venue_closed ---
        $incident = $this->connection->fetchAssociative(
            'SELECT id, period_type, to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id IS NULL AND title = ?',
            [$club->getId(), 'Matéo indisponible (travaux)'],
        );
        self::assertNotFalse($incident, 'l\'incident racine « Matéo indisponible (travaux) » existe');
        self::assertSame('closure', (string) $incident['period_type'], 'l\'incident est une fermeture');
        self::assertSame('2026-08-18', (string) $incident['s'], 'la fermeture débute le 18 août');
        self::assertSame('2026-09-30', (string) $incident['e'], 'la fermeture court jusqu\'au 30 septembre');
        $incidentId = (string) $incident['id'];

        $rootPlans = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM schedule_plan WHERE calendar_entry_id = ?',
            [$incidentId],
        );
        self::assertSame(0, $rootPlans, 'une entrée racine ne provisionne aucun plan');

        $closure = $this->connection->fetchAssociative(
            'SELECT c.scope, c.rule_type, c.family, v.name AS venue, c.config::text AS config '
            . 'FROM "constraint" c JOIN venue v ON v.id = c.scope_target_id '
            . 'WHERE c.club_id = ? AND c.calendar_entry_id = ?',
            [$club->getId(), $incidentId],
        );
        self::assertNotFalse($closure, 'l\'incident porte sa contrainte datée');
        self::assertSame('FACILITY', (string) $closure['scope'], 'la datée est de portée FACILITY');
        self::assertSame('HARD', (string) $closure['rule_type'], 'la datée est HARD');
        self::assertSame('Matéo', (string) $closure['venue'], 'la datée vise le gymnase Matéo');
        /** @var array<string, mixed> $closureConfig */
        $closureConfig = json_decode((string) $closure['config'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('venue_closed', $closureConfig['type'] ?? null, 'la datée est une fermeture de gymnase');
        self::assertSame('2026-08-18', $closureConfig['startDate'] ?? null, 'la fermeture datée débute le 18 août');
        self::assertSame('2026-09-30', $closureConfig['endDate'] ?? null, 'la fermeture datée court jusqu\'au 30 septembre');

        // --- Le segment-enfant né avec son plan CLOSURE, NON validé, SANS version ---
        $segment = $this->connection->fetchAssociative(
            'SELECT id, period_type, to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id = ?',
            [$club->getId(), $incidentId],
        );
        self::assertNotFalse($segment, 'le segment-enfant d\'adaptation existe');
        self::assertSame('closure', (string) $segment['period_type'], 'le segment est une fermeture');
        self::assertSame('2026-09-07', (string) $segment['s'], 'le segment couvre du 7 septembre');
        self::assertSame('2026-09-27', (string) $segment['e'], 'le segment couvre jusqu\'au 27 septembre');
        $segmentId = (string) $segment['id'];

        $plan = $this->connection->fetchAssociative(
            'SELECT id, name, type, chosen_schedule_id, team_selection_initialized '
            . 'FROM schedule_plan WHERE calendar_entry_id = ?',
            [$segmentId],
        );
        self::assertNotFalse($plan, 'le segment est né AVEC son plan');
        self::assertSame('CLOSURE', (string) $plan['type'], 'le plan est de type CLOSURE');
        self::assertSame('Matéo indisponible (travaux) — semaines du 7 sept. 2026 au 27 sept. 2026', (string) $plan['name'], 'le plan de période naît nommé du TITRE de son segment-enfant (décision fondateur 2026-08-23)');
        self::assertNull($plan['chosen_schedule_id'], 'le plan n\'est PAS validé (aucune version pointée)');
        self::assertTrue((bool) $plan['team_selection_initialized'], 'la sélection d\'équipes est initialisée (le wizard ne re-seede pas)');
        $planId = (string) $plan['id'];

        $versions = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = ?',
            [$planId],
        );
        self::assertSame(0, $versions, 'le plan d\'adaptation ne porte AUCUNE version (travail en cours)');

        // --- Réglages : 9 TPO (8 actives spw=2, 1 décochée), 1 CPO, 0 VPO ---
        $activeTpo = $this->connection->fetchFirstColumn(
            'SELECT t.name FROM team_period_override o JOIN team t ON t.id = o.team_id '
            . 'WHERE o.schedule_plan_id = ? AND o.is_active = true AND o.sessions_per_week = 2 ORDER BY t.name',
            [$planId],
        );
        self::assertSame(
            ['U13F1', 'U13F2', 'U13M1', 'U13M2', 'U15F1', 'U15M1', 'U18F1', 'U18M1'],
            array_map('strval', $activeTpo),
            'exactement les 8 équipes actives à 2 séances/semaine',
        );
        $inactiveTpo = array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT t.name FROM team_period_override o JOIN team t ON t.id = o.team_id '
            . 'WHERE o.schedule_plan_id = ? AND o.is_active = false',
            [$planId],
        ));
        sort($inactiveTpo, \SORT_STRING);
        self::assertSame(
            ['Academie U13-U15', 'Academie U18', 'Academie U9-U11', 'Training Individuel'],
            $inactiveTpo,
            '« Training Individuel » et les 3 équipes « Academie » sont décochées (BYE pendant l\'incident)',
        );
        $totalTpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE schedule_plan_id = ?', [$planId]);
        self::assertSame(12, $totalTpo, 'aucune autre ligne d\'équipe que ces 12 (8 actives + 4 décochées)');

        $deactivated = $this->connection->fetchFirstColumn(
            'SELECT c.name FROM constraint_period_override o JOIN "constraint" c ON c.id = o.constraint_id '
            . 'WHERE o.schedule_plan_id = ? AND o.is_active = false',
            [$planId],
        );
        self::assertSame(['SM2 · au moins 1 à Matéo'], array_map('strval', $deactivated), '« SM2 · au moins 1 à Matéo » est la seule contrainte décochée');
        $totalCpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM constraint_period_override WHERE schedule_plan_id = ?', [$planId]);
        self::assertSame(1, $totalCpo, 'aucun autre override de contrainte');

        $vpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_period_override WHERE schedule_plan_id = ?', [$planId]);
        self::assertSame(0, $vpo, 'aucun VenuePeriodOverride : la fermeture agit par l\'état effectif');
    }

    /**
     * « Incident Matéo » — la grille du plan d'adaptation reste la COPIE de saison pour tous les
     * gymnases SAUF JDR, dont la grille de plan est EXPLICITE (retouches réelles du gestionnaire,
     * re-snapshot du 2026-08-19) : exactement 19 créneaux JDR portés PAR CE PLAN, dont les caps 2
     * sur lun→ven à 17:30 et à 19:00, ET le nouveau créneau MERCREDI 16:00→17:30 (90 min, cap 1).
     * Idempotence : deux runs laissent 19 créneaux (purge+réinsertion).
     *
     * Falsifiable : remettre la capacity de JDR lun→ven 19:00 à 1, ou retirer le créneau mercredi
     * 16:00, rend ce test ROUGE en nommant l'invariant manquant.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMateoIncidentPlanCarriesExplicitJdrGrid(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $planId = $this->mateoIncidentPlanId($club->getId());

        self::assertSame(19, $this->jdrPlanSlotCount($planId), 'la grille de plan JDR compte exactement 19 créneaux explicites');

        $capTwoAt = fn (string $start): int => (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM venue_training_slot s JOIN venue v ON v.id = s.venue_id '
            . 'WHERE s.schedule_plan_id = ? AND v.name = \'JDR\' AND s.day_of_week BETWEEN 1 AND 5 '
            . 'AND to_char(s.start_time, \'HH24:MI\') = ? AND s.capacity = 2',
            [$planId, $start],
        );
        self::assertSame(5, $capTwoAt('17:30'), 'lun→ven à 17:30 : un créneau JDR à cap 2 par jour ouvré');
        self::assertSame(5, $capTwoAt('19:00'), 'lun→ven à 19:00 : un créneau JDR à cap 2 par jour ouvré');

        // Le nouveau créneau du gestionnaire : mercredi (jour ISO 3) 16:00→17:30, 90 min, cap 1.
        $wed1600 = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM venue_training_slot s JOIN venue v ON v.id = s.venue_id '
            . 'WHERE s.schedule_plan_id = ? AND v.name = \'JDR\' AND s.day_of_week = 3 '
            . 'AND to_char(s.start_time, \'HH24:MI\') = \'16:00\' AND s.duration_minutes = 90 AND s.capacity = 1',
            [$planId],
        );
        self::assertSame(1, $wed1600, 'la grille JDR porte le créneau mercredi 16:00→17:30 (90 min, cap 1)');

        // Idempotence : un second run purge+réinsère, la grille JDR reste à 19 (pas de doublon).
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertSame(19, $this->jdrPlanSlotCount($this->mateoIncidentPlanId($club->getId())), 'un second seed laisse 19 créneaux JDR de plan');
    }

    /**
     * P5-13 — les reprises et le compte Nicolas ne visent QUE le profil dev. Le club de
     * DÉMONSTRATION ne porte aucun plan de période (HOLIDAY/CLOSURE), aucune entrée calendrier
     * (l'incident Matéo compris), et le compte gestionnaire Nicolas n'existe pas.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDemoSeedCarriesNoRepriseNorNicolasAccount(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::demo('demo-pass-reprise'));

        $periodPlans = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM schedule_plan WHERE club_id = ? AND type IN (\'HOLIDAY\', \'CLOSURE\')',
            [$club->getId()],
        );
        self::assertSame(0, $periodPlans, 'la démo ne porte aucun plan de période');

        $entries = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_entry WHERE club_id = ?',
            [$club->getId()],
        );
        self::assertSame(0, $entries, 'la démo ne pose aucune entrée calendrier');

        $nicolas = $this->connection->fetchOne(
            'SELECT 1 FROM app_user WHERE email = ?',
            ['nicolas.barilleau@bccl.fr'],
        );
        self::assertFalse($nicolas, 'la démo ne crée pas le compte gestionnaire Nicolas');
    }

    protected function setUp(): void
    {
        $adminUrl = $_SERVER['DATABASE_ADMIN_URL'] ?? getenv('DATABASE_ADMIN_URL');
        self::assertNotFalse($adminUrl, 'DATABASE_ADMIN_URL doit être défini pour seeder en superuser');
        $_SERVER['DATABASE_URL'] = $adminUrl;
        $_ENV['DATABASE_URL'] = $adminUrl;
        putenv('DATABASE_URL=' . $adminUrl);

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->seeder = self::getContainer()->get(BcclSeeder::class);

        // Garde-fou : sans la connexion superuser, le seeder échouerait sur son
        // propre garde RLS — mais silencieusement tard. On l'affirme tôt.
        self::assertSame('clubscheduler', $this->connection->fetchOne('SELECT current_user'));

        // Notre filet de rollback (voir docblock) : tout le seed vit ici.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /**
     * @return array{clubs:int, teams:int, slots:int, reservations:int, schedules:int, slotTemplates:int, clubUsers:int, calendarEntries:int, schedulePlans:int, sharedGroups:int, sharedGroupTeams:int, venuePeriodOverrides:int, teamPeriodOverrides:int, constraintPeriodOverrides:int}
     */
    private function counts(): array
    {
        return [
            'clubs' => $this->rowsIn('club'),
            'teams' => $this->rowsIn('team'),
            'slots' => $this->rowsIn('venue_training_slot'),
            'reservations' => $this->rowsIn('reservation'),
            // P5-17 : la version transcrite et ses créneaux entrent dans la mesure
            // d'idempotence — un second seed ne doit ni dupliquer la version (find-or-create
            // par plan+provenance) ni doubler ses 90 créneaux (purge l.853 + réinsertion).
            'schedules' => $this->rowsIn('schedule'),
            'slotTemplates' => $this->rowsIn('schedule_slot_template'),
            // P5-13 : le compte gestionnaire additionnel (Nicolas), les deux entrées-semaines
            // + leur mère, les plans de reprise et TOUS leurs réglages ancrés au plan
            // (mutualisation, overrides gymnase/équipe/contrainte) entrent dans la mesure —
            // find-or-create / purge-recréation partout, deux runs = mêmes comptes.
            'clubUsers' => $this->rowsIn('club_user'),
            'calendarEntries' => $this->rowsIn('calendar_entry'),
            'schedulePlans' => $this->rowsIn('schedule_plan'),
            'sharedGroups' => $this->rowsIn('shared_training_group'),
            'sharedGroupTeams' => $this->rowsIn('shared_training_group_team'),
            'venuePeriodOverrides' => $this->rowsIn('venue_period_override'),
            'teamPeriodOverrides' => $this->rowsIn('team_period_override'),
            'constraintPeriodOverrides' => $this->rowsIn('constraint_period_override'),
        ];
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * Le plan d'ajustement de l'incident Matéo : porté par le segment-enfant (parent = l'entrée
     * racine « Matéo indisponible (travaux) »).
     */
    private function mateoIncidentPlanId(string $clubId): string
    {
        $planId = $this->connection->fetchOne(
            'SELECT sp.id FROM schedule_plan sp '
            . 'JOIN calendar_entry seg ON seg.id = sp.calendar_entry_id '
            . 'JOIN calendar_entry root ON root.id = seg.parent_entry_id '
            . 'WHERE sp.club_id = ? AND root.title = ? AND root.parent_entry_id IS NULL',
            [$clubId, 'Matéo indisponible (travaux)'],
        );
        self::assertNotFalse($planId, 'le plan d\'ajustement de l\'incident Matéo existe');

        return (string) $planId;
    }

    private function jdrPlanSlotCount(string $planId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM venue_training_slot s JOIN venue v ON v.id = s.venue_id '
            . 'WHERE s.schedule_plan_id = ? AND v.name = \'JDR\'',
            [$planId],
        );
    }

    /**
     * Les clés de dédup vues plus d'une fois — `schedule_plan_id` DANS la clé,
     * sinon les copies légitimes par plan (ADR-0002) passeraient pour des doublons.
     * `GROUP BY` regroupe les NULL ensemble : deux créneaux de BASE identiques
     * (plan NULL) seraient bien comptés comme un doublon.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateSlots(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT venue_id, day_of_week, start_time, season_id, schedule_plan_id, COUNT(*) AS n
             FROM venue_training_slot
             GROUP BY venue_id, day_of_week, start_time, season_id, schedule_plan_id
             HAVING COUNT(*) > 1',
        );
    }
}
