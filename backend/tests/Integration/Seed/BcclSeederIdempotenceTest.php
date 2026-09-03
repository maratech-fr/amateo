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
 * `make seed-bccl`). En test la connexion par défaut est `amateo_app` : on bascule
 * `DATABASE_URL` sur l'URL admin AVANT de booter, exactement comme la commande
 * `app:demo:seed` tourne sous `DATABASE_URL=$DATABASE_ADMIN_URL`.
 *
 * ⚠ PROCESSUS ISOLÉ obligatoire : DAMA épingle sa connexion statique au PREMIER
 * usager de `default` pour toute la durée du process (c'est ce qui tient sa
 * transaction ouverte d'un test à l'autre). Un autre test l'ayant ouverte en
 * `amateo_app`, notre bascule d'URL n'aurait plus prise. Un process neuf établit
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

        // Section 14 — une base FRAÎCHE naît sans bandeau « périmé » : le seed continue
        // d'insérer APRÈS la transcription (liens, blocs, incident) et les écouteurs de
        // péremption estampillaient les versions transcrites ; le dernier geste du run les
        // remet à zéro. Le défaut (programme plannings-bccl §5) rougirait ici.
        $stale = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM schedule s JOIN schedule_plan sp ON sp.id = s.schedule_plan_id '
            . 'WHERE sp.club_id = ? AND (s.constraints_changed_since_generation = true OR s.resources_changed_since_generation = true)',
            [$club->getId()],
        );
        self::assertSame(0, (int) $stale, 'aucune version seedée ne naît « périmée »');
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
     * — et on ne s'en apercevrait qu'au premier seed suivi d'une génération, donc
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
     * NR — LES 8 MUTUALISATIONS SOCLE ADOSSENT LES CAPACITÉS REDESCENDUES (P2-51, recalage
     * 2026-09-01).
     *
     * Les 8 partages réels du club (paires jeunes + 3 CEC du mercredi) portaient des capacités
     * 2/3 posées comme PALLIATIF à la mutualisation. Elles sont redescendues à 1 : le partage se
     * dit désormais par un bloc de mutualisation SOCLE (schedulePlanId NULL), un groupe complet
     * comptant pour UN occupant. L'invariant qui rend cette descente sûre : sur CHAQUE case
     * partagée, (a) le créneau socle est en capacité 1, (b) l'ensemble des équipes RÉSERVÉES y est
     * EXACTEMENT les membres d'un bloc socle (`reservedSetMatchesABlock`) — sans quoi une
     * génération verrait N pins HARD sur une case cap 1 SANS exemption de bloc, donc INFEASIBLE.
     *
     * Falsifiable dans les deux sens : remonter une capacité à 2 (palliatif ressuscité) OU retirer
     * un bloc / en changer un membre rend ce test ROUGE en nommant la case fautive.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSocleSharedBlocksBackTheDescendedCapacities(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        // [gymnase, jour ISO, HH:MM, membres attendus] — les 8 cases partagées.
        /** @var list<array{string, int, string, list<string>}> $cases */
        $cases = [
            ['Matéo', 1, '17:30', ['U9F1', 'U9F2']],
            ['Matéo', 3, '16:00', ['U11F2', 'U9M1']],
            ['Matéo', 3, '17:30', ['U11F1', 'U11M2']],
            ['JDR', 2, '17:30', ['U13F2', 'U13F3']],
            ['JDR', 4, '17:30', ['U9M1', 'U9M2']],
            ['Armand', 1, '17:30', ['U13M1', 'U13M2']],
            ['Armand', 3, '14:00', ['U13F1', 'U13F2']],
            ['ADN', 3, '17:30', ['U9F1', 'U9F2', 'U9M2']],
        ];

        // Les ensembles d'équipes des blocs SOCLE (schedulePlanId NULL), par nom d'équipe.
        /** @var list<array{block_id: string, name: string, common_sessions: int}> $blockRows */
        $blockRows = $this->connection->fetchAllAssociative(
            'SELECT b.id AS block_id, b.common_sessions, t.name '
            . 'FROM shared_training_block b '
            . 'JOIN shared_training_block_team bt ON bt.block_id = b.id '
            . 'JOIN team t ON t.id = bt.team_id '
            . 'WHERE b.club_id = ? AND b.schedule_plan_id IS NULL',
            [$club->getId()],
        );
        $blockSets = [];
        foreach ($blockRows as $row) {
            self::assertSame(1, (int) $row['common_sessions'], 'un bloc socle porte 1 séance commune');
            $blockSets[(string) $row['block_id']][] = (string) $row['name'];
        }
        $normalizedBlockSets = [];
        foreach ($blockSets as $names) {
            sort($names);
            $normalizedBlockSets[] = $names;
        }
        self::assertCount(8, $normalizedBlockSets, 'le socle porte exactement 8 blocs de mutualisation');

        foreach ($cases as [$venueName, $day, $start, $members]) {
            // (a) la case socle est en capacité 1 (plus de palliatif).
            $capacity = $this->connection->fetchOne(
                'SELECT s.capacity FROM venue_training_slot s JOIN venue v ON v.id = s.venue_id '
                . 'WHERE s.club_id = ? AND s.schedule_plan_id IS NULL AND v.name = ? '
                . 'AND s.day_of_week = ? AND to_char(s.start_time, \'HH24:MI\') = ?',
                [$club->getId(), $venueName, $day, $start],
            );
            self::assertSame(1, false === $capacity ? -1 : (int) $capacity, \sprintf('%s %d %s : le créneau socle est en capacité 1', $venueName, $day, $start));

            // (b) l'ensemble RÉSERVÉ sur la case (plan NULL) est exactement les membres attendus.
            $reserved = $this->connection->fetchFirstColumn(
                'SELECT t.name FROM reservation r JOIN team t ON t.id = r.team_id JOIN venue v ON v.id = r.venue_id '
                . 'WHERE r.club_id = ? AND r.schedule_plan_id IS NULL AND v.name = ? '
                . 'AND r.day_of_week = ? AND to_char(r.start_time, \'HH24:MI\') = ?',
                [$club->getId(), $venueName, $day, $start],
            );
            $reserved = array_map('strval', $reserved);
            sort($reserved);
            $expected = $members;
            sort($expected);
            self::assertSame($expected, $reserved, \sprintf('%s %d %s : l\'ensemble réservé est exactement les membres du bloc', $venueName, $day, $start));

            // (c) un bloc socle porte EXACTEMENT cet ensemble (reservedSetMatchesABlock).
            self::assertContains($expected, $normalizedBlockSets, \sprintf('%s %d %s : un bloc socle couvre exactement %s', $venueName, $day, $start, implode('/', $expected)));
        }
    }

    /**
     * NR — LES 10 PASSERELLES RÉELLES SONT SEMÉES (P5-23). « partagent des joueurs » ⇒
     * NOT_SIMULTANEOUS, intensité côté entraînement au défaut PREFERRED (fondateur non précisé).
     * Idempotent : le find-or-create sur le couple normalisé ne double pas au second passage.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSeedDeclaresTheTenRealTeamLinks(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{a: string, b: string, link_type: string, training_intensity: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ta.name AS a, tb.name AS b, l.link_type, l.training_intensity '
            . 'FROM team_link l JOIN team ta ON ta.id = l.team_a_id JOIN team tb ON tb.id = l.team_b_id '
            . 'WHERE l.club_id = ?',
            [$club->getId()],
        );
        self::assertCount(10, $rows, 'le socle déclare exactement 10 passerelles');

        $couples = [];
        foreach ($rows as $row) {
            self::assertSame('NOT_SIMULTANEOUS', (string) $row['link_type'], 'une passerelle « partage de joueurs » est NOT_SIMULTANEOUS');
            self::assertSame('PREFERRED', (string) $row['training_intensity'], 'l\'intensité entraînement reste au défaut PREFERRED');
            $pair = [(string) $row['a'], (string) $row['b']];
            sort($pair);
            $couples[] = implode('–', $pair);
        }
        sort($couples);

        $expected = [];
        foreach ([['SM1', 'SM2'], ['SM1', 'U21M1'], ['U18M1', 'U18M2'], ['U15M1', 'U15M2'], ['U13M1', 'U13M2'], ['SF1', 'SF2'], ['SF1', 'U18F1'], ['U18F2', 'U18F1'], ['U15F1', 'U15F2'], ['U13F1', 'U13F2']] as $pair) {
            sort($pair);
            $expected[] = implode('–', $pair);
        }
        sort($expected);

        self::assertSame($expected, $couples, 'les 10 couples réels sont présents, aucun de plus');
    }

    /**
     * P5-13 — chaque plan de REPRISE pointe (chosen) une version COMPLETED transcrivant sa
     * semaine au bon nombre de créneaux (25 pour le 17 août, 40 pour le 24 août), et porte ses
     * groupes de mutualisation ANCRÉS au plan : {SM1,SM2}+{SF1,SF2} le 17 ; {SM1,SM2}+{U18F1,U18F2}
     * le 24 (SF1/SF2 s'y entraînent séparément).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachRepriseWeekPlanPointsCompletedTranscriptionWithItsGroups(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        foreach ([['Reprise du 17 août', 25, 2], ['Reprise du 24 août', 40, 2]] as [$planName, $expectedSlots, $expectedGroups]) {
            $row = $this->connection->fetchAssociative(
                'SELECT s.status, (SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
                . 'FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
                . 'WHERE sp.club_id = ? AND sp.name = ? AND sp.type = \'HOLIDAY\'',
                [$club->getId(), $planName],
            );
            self::assertNotFalse($row, \sprintf('le plan « %s » pointe une version choisie', $planName));
            self::assertSame('COMPLETED', (string) $row['status'], \sprintf('« %s » pointe une version COMPLETED', $planName));
            self::assertSame($expectedSlots, (int) $row['slot_count'], \sprintf('« %s » transcrit %d séances', $planName, $expectedSlots));

            $blockCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM shared_training_block b JOIN schedule_plan sp ON sp.id = b.schedule_plan_id '
                . 'WHERE sp.club_id = ? AND sp.name = ?',
                [$club->getId(), $planName],
            );
            self::assertSame($expectedGroups, $blockCount, \sprintf('« %s » porte %d bloc(s) de mutualisation', $planName, $expectedGroups));
        }

        // {SF1,SF2} n'est mutualisé QUE la semaine du 17 : aucun bloc de la semaine du 24 ne
        // contient SF1 ni SF2 (elles s'y entraînent séparément).
        $sfGroupsWeek24 = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT bt.block_id) FROM shared_training_block_team bt '
            . 'JOIN schedule_plan sp ON sp.id = bt.schedule_plan_id '
            . 'JOIN team t ON t.id = bt.team_id '
            . 'WHERE sp.club_id = ? AND sp.name = ? AND t.name IN (\'SF1\', \'SF2\')',
            [$club->getId(), 'Reprise du 24 août'],
        );
        self::assertSame(0, $sfGroupsWeek24, 'SF1/SF2 ne sont pas mutualisées la semaine du 24 août');

        // {SM1,SM2} l'est LES DEUX semaines : chaque plan a un bloc couvrant exactement SM1 et SM2.
        foreach (['Reprise du 17 août', 'Reprise du 24 août'] as $planName) {
            $smMembers = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM shared_training_block_team bt '
                . 'JOIN schedule_plan sp ON sp.id = bt.schedule_plan_id '
                . 'JOIN team t ON t.id = bt.team_id '
                . 'WHERE sp.club_id = ? AND sp.name = ? AND t.name IN (\'SM1\', \'SM2\')',
                [$club->getId(), $planName],
            );
            self::assertSame(2, $smMembers, \sprintf('« %s » mutualise SM1 et SM2', $planName));
        }

        // {U18F1,U18F2} est mutualisé la semaine du 24 (JDR lun/jeu 19:30) : un bloc de k=2 séances
        // communes couvrant exactement les deux équipes.
        $u18fBlock = $this->connection->fetchAssociative(
            'SELECT b.common_sessions, COUNT(bt.id) AS members '
            . 'FROM shared_training_block b '
            . 'JOIN schedule_plan sp ON sp.id = b.schedule_plan_id '
            . 'JOIN shared_training_block_team bt ON bt.block_id = b.id '
            . 'JOIN team t ON t.id = bt.team_id AND t.name IN (\'U18F1\', \'U18F2\') '
            . 'WHERE sp.club_id = ? AND sp.name = ? GROUP BY b.id, b.common_sessions',
            [$club->getId(), 'Reprise du 24 août'],
        );
        self::assertNotFalse($u18fBlock, 'la semaine du 24 mutualise U18F1 et U18F2');
        self::assertSame(2, (int) $u18fBlock['members'], 'le bloc U18F du 24 couvre exactement U18F1 et U18F2');
        self::assertSame(2, (int) $u18fBlock['common_sessions'], 'le bloc U18F du 24 porte k=2 séances communes');
    }

    /**
     * Post-modèle bloc (arbitrage fondateur 2026-09-01) — les grilles des semaines de REPRISE
     * comptent un groupe mutualisé pour UN occupant : plus aucune case en capacité 2 (le
     * palliatif d'avant les blocs laissait le solveur y glisser une équipe de plus). Et la
     * semaine du 17 décoche AUSSI les deux indisponibilités coach héritées de la saison que le
     * réel de la semaine contredit (U18M1 s'entraîne le jeudi de Nicolas Barilleau, U15M1 le
     * vendredi de Thomas Francon) — sans quoi la génération ne peut pas atteindre le planning
     * transcrit. Falsifiable : remettre `++capacity` par séance, ou retirer un des deux noms de
     * `deactivatedConstraints`, rend ce test ROUGE en nommant l'invariant.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRepriseGridsCountBlockAsOneOccupantAndDeactivateInheritedIndispos(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        // 25 séances − 5 cases mutualisées = 20 créneaux le 17 ; 40 − 5 = 35 le 24.
        foreach ([['Reprise du 17 août', 20], ['Reprise du 24 août', 35]] as [$planName, $expectedSlots]) {
            $grid = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS slots, MAX(s.capacity) AS max_capacity '
                . 'FROM venue_training_slot s JOIN schedule_plan sp ON sp.id = s.schedule_plan_id '
                . 'WHERE sp.club_id = ? AND sp.name = ?',
                [$club->getId(), $planName],
            );
            self::assertNotFalse($grid);
            self::assertSame($expectedSlots, (int) $grid['slots'], \sprintf('« %s » : la grille du plan compte %d créneaux (une case mutualisée = un créneau)', $planName, $expectedSlots));
            self::assertSame(1, (int) $grid['max_capacity'], \sprintf('« %s » : aucune case au-dessus de la capacité 1 — un bloc mutualisé est UN occupant', $planName));
        }

        $deactivated = $this->connection->fetchFirstColumn(
            'SELECT c.name FROM constraint_period_override o '
            . 'JOIN "constraint" c ON c.id = o.constraint_id '
            . 'JOIN schedule_plan sp ON sp.id = o.schedule_plan_id '
            . 'WHERE sp.club_id = ? AND sp.name = ? AND o.is_active = false '
            . 'AND c.name IN (\'Nicolas Barilleau · indispo jeudi\', \'Thomas Francon · indispo vendredi\', \'SM2 · pas vendredi\') '
            . 'ORDER BY c.name',
            [$club->getId(), 'Reprise du 17 août'],
        );
        self::assertSame(
            ['Nicolas Barilleau · indispo jeudi', 'SM2 · pas vendredi', 'Thomas Francon · indispo vendredi'],
            array_map('strval', $deactivated),
            'la semaine du 17 décoche les deux indisponibilités coach héritées ET « SM2 · pas vendredi » (le bloc est figé lun/mar/jeu par réservation)',
        );
    }

    /**
     * Arbitrage fondateur 2026-09-01 (exercice solveur reprise-17) — les réservations du plan du
     * 17 ne transcrivent PLUS tout le planning : seuls les créneaux CHOISIS des équipes fanion
     * sont figés (bloc SM1+SM2 lun/mar/jeu 20:45, bloc SF1+SF2 mer/ven 19:30 — 10 lignes), le
     * reste appartient au solveur + contraintes (« je ne veux pas tout mettre en réservation »).
     * La semaine du 24 (exercice solveur CLOS le 2026-09-01) ne fige elle aussi que ses créneaux
     * fanion : le bloc SM (lun/mar Armand 20:30 + jeu JDR 20:45), la séance SOLO de SM2 (jeu Armand
     * 20:30) et les deux SF2 (lun/mar JDR 20:45) — 9 lignes exactement, plus les 38 d'antan.
     * Idempotence stricte : purge-puis-réinsertion — une ligne retirée de la liste ne survit pas
     * à un re-run sur base déjà seedée. Falsifiable : re-brancher `$week['sessions']` comme
     * source des réservations du 17 rend ce test ROUGE (25 ≠ 10).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReprise17ReservationsPinOnlyChosenFanionBlocks(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, r.day_of_week, to_char(r.start_time, \'HH24:MI\') AS start '
            . 'FROM reservation r JOIN team t ON t.id = r.team_id '
            . 'JOIN schedule_plan sp ON sp.id = r.schedule_plan_id '
            . 'WHERE sp.club_id = ? AND sp.name = ? ORDER BY r.day_of_week, start, t.name',
            [$club->getId(), 'Reprise du 17 août'],
        );
        $expected = [
            ['SM1', 1, '20:45'], ['SM2', 1, '20:45'],
            ['SM1', 2, '20:45'], ['SM2', 2, '20:45'],
            ['SF1', 3, '19:30'], ['SF2', 3, '19:30'],
            ['SM1', 4, '20:45'], ['SM2', 4, '20:45'],
            ['SF1', 5, '19:30'], ['SF2', 5, '19:30'],
        ];
        self::assertSame(
            $expected,
            array_map(static fn (array $row): array => [(string) $row['team'], (int) $row['day_of_week'], (string) $row['start']], $rows),
            'les réservations du 17 sont exactement les 10 créneaux fanion (blocs SM et SF) — rien d\'autre',
        );

        $rows24 = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, r.day_of_week, to_char(r.start_time, \'HH24:MI\') AS start '
            . 'FROM reservation r JOIN team t ON t.id = r.team_id '
            . 'JOIN schedule_plan sp ON sp.id = r.schedule_plan_id '
            . 'WHERE sp.club_id = ? AND sp.name = ? ORDER BY r.day_of_week, start, t.name',
            [$club->getId(), 'Reprise du 24 août'],
        );
        $expected24 = [
            ['SM1', 1, '20:30'], ['SM2', 1, '20:30'], ['SF2', 1, '20:45'],
            ['SM1', 2, '20:30'], ['SM2', 2, '20:30'], ['SF2', 2, '20:45'],
            ['SM2', 4, '20:30'], ['SM1', 4, '20:45'], ['SM2', 4, '20:45'],
        ];
        self::assertSame(
            $expected24,
            array_map(static fn (array $row): array => [(string) $row['team'], (int) $row['day_of_week'], (string) $row['start']], $rows24),
            'les réservations du 24 sont exactement les 9 créneaux fanion (bloc SM lun/mar/jeu, SM2 solo jeudi, SF2 lun/mar) — rien d\'autre',
        );

        // Idempotence : un second run purge-réinsère, mêmes comptes, pas de résurrection.
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        foreach ([['Reprise du 17 août', 10], ['Reprise du 24 août', 9]] as [$planName, $expectedCount]) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM reservation r JOIN schedule_plan sp ON sp.id = r.schedule_plan_id '
                . 'WHERE sp.club_id = ? AND sp.name = ?',
                [$club->getId(), $planName],
            );
            self::assertSame($expectedCount, $count, \sprintf('un second seed laisse exactement %d réservations sur « %s »', $expectedCount, $planName));
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
     * P2-59 — le moissonneur lit l'UNION du modèle FAIT/GENÈSE, pas les seules permanentes de
     * saison : une règle dure d'équipe PENDUE au plan lui-même (genèse, calendar_entry_id = l'entrée
     * du plan) ou aux FAITS de sa mère (calendar_entry_id = le parent de l'entrée du plan) doit
     * elle aussi être satisfaite par la transcription. C'est ce qui garde la genèse « Séniors
     * masculins mutualisés · pas avant 20:30 » du 17 (transcrite à 20:45 ≥ 20:30).
     *
     * Falsifiable : retirer un décochage du seed (p. ex. « SM1 · uniquement mardi, jeudi » de la
     * semaine du 17) rend ce test ROUGE en nommant la séance du lundi de SM1 ; poser la genèse SM
     * à 21:00 (> 20:45) le rendrait ROUGE aussi.
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
            . 'JOIN calendar_entry pe ON pe.id = p.calendar_entry_id '
            // Union FAIT/GENÈSE (P2-59) : permanentes de saison (calendar_entry_id NULL) ∪ genèses
            // pendues au plan (= l'entrée du plan) ∪ faits de sa mère (= le parent de l'entrée).
            . 'WHERE (c.calendar_entry_id IS NULL OR c.calendar_entry_id = p.calendar_entry_id OR c.calendar_entry_id = pe.parent_entry_id) '
            . 'AND c.scope = \'TEAM\' AND c.rule_type = \'HARD\' '
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
     * NR (P2-59) — LES CONTRAINTES DE GENÈSE DES SEMAINES DE REPRISE PENDENT À LEUR ENTRÉE-ENFANT.
     *
     * Le modèle FAIT/GENÈSE : une contrainte de genèse vit sur l'entrée-ENFANT (la semaine), pas
     * sur la mère. Les 3 genèses du 17 (le bloc SM mutualisé ≥ 20:30 sur SM1 ET SM2, l'indispo
     * lun/ven de Nicolas Barilleau) portent calendar_entry_id = l'entrée du 17, avec leurs configs
     * exactes. La semaine du 24 (exercice solveur CLOS le 2026-09-01) porte SES 16 genèses sur SON
     * entrée-enfant — « Mineurs · pas après 19:50 » (10 équipes), « U15M · pas après 18:15 » (2),
     * « U18F{1,2} · préfère JDR » (FACILITY PREFERRED), « SF1 · pas vendredi », « SM3 · préfère
     * Armand » — chacune invisible de l'autre semaine, et un second run est stable (find-or-create,
     * zéro doublon).
     *
     * Falsifiable : attacher une genèse à la mère (au lieu de l'enfant), altérer une config, ou
     * croiser les genèses des deux semaines rend ce test ROUGE.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRepriseGenesisConstraintsHangOnTheChildWeekOfTheSeventeenth(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $clubId = $club->getId();

        $motherId = (string) $this->connection->fetchOne(
            'SELECT id FROM calendar_entry WHERE club_id = ? AND parent_entry_id IS NULL AND title = ?',
            [$clubId, 'Vacances d\'été'],
        );
        $child17 = (string) $this->connection->fetchOne(
            'SELECT id FROM calendar_entry WHERE club_id = ? AND parent_entry_id = ? AND start_date = ?',
            [$clubId, $motherId, '2026-08-17'],
        );
        $child24 = (string) $this->connection->fetchOne(
            'SELECT id FROM calendar_entry WHERE club_id = ? AND parent_entry_id = ? AND start_date = ?',
            [$clubId, $motherId, '2026-08-24'],
        );
        self::assertNotSame('', $child17, 'l\'entrée-enfant du 17 existe');
        self::assertNotSame('', $child24, 'l\'entrée-enfant du 24 existe');

        $sm1 = (string) $this->connection->fetchOne('SELECT id FROM team WHERE club_id = ? AND name = ?', [$clubId, 'SM1']);
        $sm2 = (string) $this->connection->fetchOne('SELECT id FROM team WHERE club_id = ? AND name = ?', [$clubId, 'SM2']);
        $nico = (string) $this->connection->fetchOne(
            'SELECT id FROM coach WHERE club_id = ? AND first_name = ? AND last_name = ?',
            [$clubId, 'Nicolas', 'Barilleau'],
        );
        $jdrId = (string) $this->connection->fetchOne('SELECT id FROM venue WHERE club_id = ? AND name = ?', [$clubId, 'JDR']);
        $armandId = (string) $this->connection->fetchOne('SELECT id FROM venue WHERE club_id = ? AND name = ?', [$clubId, 'Armand']);

        $genesisRows = fn (string $entryId): array => $this->connection->fetchAllAssociative(
            'SELECT name, scope, family, rule_type, scope_target_id, config::text AS config '
            . 'FROM "constraint" WHERE club_id = ? AND calendar_entry_id = ? ORDER BY name, scope_target_id',
            [$clubId, $entryId],
        );

        $expected17 = [
            [
                'name' => 'Nicolas Barilleau · indispo lundi, vendredi',
                'scope' => 'COACH', 'family' => 'COACH_AVAILABILITY', 'rule_type' => 'HARD',
                'scope_target_id' => $nico, 'config' => ['unavailableDays' => [1, 5]],
            ],
            [
                'name' => 'Séniors masculins mutualisés · pas avant 20:30',
                'scope' => 'TEAM', 'family' => 'TIME', 'rule_type' => 'HARD',
                'scope_target_id' => $sm1, 'config' => ['minStartTime' => '20:30'],
            ],
            [
                'name' => 'Séniors masculins mutualisés · pas avant 20:30',
                'scope' => 'TEAM', 'family' => 'TIME', 'rule_type' => 'HARD',
                'scope_target_id' => $sm2, 'config' => ['minStartTime' => '20:30'],
            ],
        ];

        // Même tri que la requête (name, scope_target_id) : les deux lignes SM partagent le NOM,
        // leur ordre relatif dépend des UUID d'équipes — aléatoires à chaque seed. Sans ce tri,
        // l'assertion est une pièce de monnaie (constaté : vert puis rouge sur deux runs).
        usort($expected17, static fn (array $a, array $b): int => [$a['name'], $a['scope_target_id']] <=> [$b['name'], $b['scope_target_id']]);

        $assertMatches = function (array $rows) use ($expected17): void {
            self::assertCount(3, $rows, 'la semaine du 17 porte exactement 3 genèses');
            foreach ($rows as $i => $row) {
                $exp = $expected17[$i];
                self::assertSame($exp['name'], (string) $row['name']);
                self::assertSame($exp['scope'], (string) $row['scope']);
                self::assertSame($exp['family'], (string) $row['family']);
                self::assertSame($exp['rule_type'], (string) $row['rule_type']);
                self::assertSame($exp['scope_target_id'], (string) $row['scope_target_id'], \sprintf('« %s » vise la bonne cible', $exp['name']));
                self::assertSame($exp['config'], json_decode((string) $row['config'], true, 512, \JSON_THROW_ON_ERROR), \sprintf('« %s » porte sa config exacte', $exp['name']));
            }
        };

        // La semaine du 24 porte SES 16 genèses (comptes par nom + configs sondées), sans jamais
        // recouper celles du 17.
        $probe = static fn (array $row): array => [
            (string) $row['scope'], (string) $row['family'], (string) $row['rule_type'],
            json_decode((string) $row['config'], true, 512, \JSON_THROW_ON_ERROR),
        ];
        $assertWeek24 = function (array $rows) use ($probe, $jdrId, $armandId): void {
            self::assertCount(16, $rows, 'la semaine du 24 porte exactement 16 genèses');
            $byName = [];
            foreach ($rows as $row) {
                $byName[(string) $row['name']][] = $row;
            }
            // Multiplicités exactes : 10 + 2 + 1 + 1 + 1 + 1 = 16.
            self::assertCount(10, $byName['Mineurs · pas après 19:50'] ?? [], '10 genèses « Mineurs · pas après 19:50 »');
            self::assertCount(2, $byName['U15M · pas après 18:15'] ?? [], '2 genèses « U15M · pas après 18:15 »');
            self::assertCount(1, $byName['U18F1 · préfère JDR'] ?? [], '1 genèse « U18F1 · préfère JDR »');
            self::assertCount(1, $byName['U18F2 · préfère JDR'] ?? [], '1 genèse « U18F2 · préfère JDR »');
            self::assertCount(1, $byName['SF1 · pas vendredi'] ?? [], '1 genèse « SF1 · pas vendredi »');
            self::assertCount(1, $byName['SM3 · préfère Armand'] ?? [], '1 genèse « SM3 · préfère Armand »');
            // Configs sondées (scope, famille, type de règle, config).
            self::assertSame(['TEAM', 'TIME', 'HARD', ['maxStartTime' => '19:50']], $probe($byName['Mineurs · pas après 19:50'][0]), 'une genèse « Mineurs » est TIME/HARD à 19:50');
            self::assertSame(['TEAM', 'TIME', 'HARD', ['maxStartTime' => '18:15']], $probe($byName['U15M · pas après 18:15'][0]), '« U15M » est TIME/HARD à 18:15');
            self::assertSame(['TEAM', 'DAY', 'HARD', ['forbiddenDays' => [5]]], $probe($byName['SF1 · pas vendredi'][0]), '« SF1 · pas vendredi » est DAY/HARD forbiddenDays [5]');
            self::assertSame(['TEAM', 'FACILITY', 'PREFERRED', ['preferredVenueId' => $jdrId]], $probe($byName['U18F1 · préfère JDR'][0]), '« U18F1 · préfère JDR » vise JDR (id résolu depuis $venues)');
            self::assertSame(['TEAM', 'FACILITY', 'PREFERRED', ['preferredVenueId' => $armandId]], $probe($byName['SM3 · préfère Armand'][0]), '« SM3 · préfère Armand » vise Armand');
        };

        $assertMatches($genesisRows($child17));
        $assertWeek24($genesisRows($child24));

        // Idempotence : un second run ne duplique rien et garde les mêmes cibles.
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        $assertMatches($genesisRows($child17));
        $assertWeek24($genesisRows($child24));
    }

    /**
     * P5-13 « incident Matéo » (arbitrage fondateur 2026-09-02) — le seed dev fige l'overlay RÉEL
     * que le gestionnaire a construit face au nouvel incident :
     *
     *  - le FAIT : une entrée RACINE `closure` « Matéo indisponible (incident) — du 31 août… »
     *    (31/08→16/10), portant sa datée `venue_closed` (FACILITY/HARD sur Matéo, config datée) ;
     *  - la RÉPONSE : le plan naît DIRECTEMENT SUR LA RACINE (plus de segment), VALIDÉ — il POINTE
     *    une version COMPLETED (`seed-transcription`) transcrivant le planning d'overlay, 90 créneaux ;
     *  - ses réglages : 50 TeamPeriodOverride (49 équipes actives à leur nombre de séances DÉRIVÉ,
     *    Σ = 90 ; « Training Individuel » seule décochée), 1 ConstraintPeriodOverride (« SM2 · au
     *    moins 1 à Matéo » décochée), 0 VenuePeriodOverride (la fermeture agit par l'état effectif),
     *    0 réservation ;
     *  - l'ANCIEN incident « (travaux) » a DISPARU (pas de coexistence sur base vivante).
     *
     * Falsifiable : retirer le décochage de « SM2 · au moins 1 à Matéo », changer le nombre de
     * séances d'une équipe (Σ ≠ 90), ou laisser survivre l'ancien incident, rend ce test ROUGE.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedCarriesMateoIncidentValidatedOverlay(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $incidentTitle = 'Matéo indisponible (incident) — du 31 août 2026 au 16 oct. 2026';

        // --- L'ANCIEN incident « (travaux) » a été purgé : ni racine ni segment ne subsistent ---
        $staleEntries = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_entry WHERE club_id = ? AND title LIKE ?',
            [$club->getId(), 'Matéo indisponible (travaux)%'],
        );
        self::assertSame(0, $staleEntries, 'l\'ancien incident « (travaux) » (racine + segment 07→27/09) a disparu — pas de coexistence');

        // --- La NOUVELLE racine closure + sa datée venue_closed ---
        $incident = $this->connection->fetchAssociative(
            'SELECT id, period_type, to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id IS NULL AND title = ?',
            [$club->getId(), $incidentTitle],
        );
        self::assertNotFalse($incident, 'la racine « Matéo indisponible (incident) — … » existe');
        self::assertSame('closure', (string) $incident['period_type'], 'l\'incident est une fermeture');
        self::assertSame('2026-08-31', (string) $incident['s'], 'la fermeture débute le 31 août');
        self::assertSame('2026-10-16', (string) $incident['e'], 'la fermeture court jusqu\'au 16 octobre');
        $incidentId = (string) $incident['id'];

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
        self::assertSame('2026-08-31', $closureConfig['startDate'] ?? null, 'la fermeture datée débute le 31 août');
        self::assertSame('2026-10-16', $closureConfig['endDate'] ?? null, 'la fermeture datée court jusqu\'au 16 octobre');

        // --- Le plan naît SUR LA RACINE, VALIDÉ (chosen), pointant une version COMPLETED transcrite ---
        $plan = $this->connection->fetchAssociative(
            'SELECT id, name, type, chosen_schedule_id, team_selection_initialized '
            . 'FROM schedule_plan WHERE calendar_entry_id = ?',
            [$incidentId],
        );
        self::assertNotFalse($plan, 'la RACINE porte son plan (le plan naît sur la racine, plus de segment)');
        self::assertSame('CLOSURE', (string) $plan['type'], 'le plan est de type CLOSURE');
        self::assertSame($incidentTitle, (string) $plan['name'], 'le plan naît nommé du TITRE de son entrée-racine');
        self::assertNotNull($plan['chosen_schedule_id'], 'le plan EST validé (il pointe une version)');
        self::assertTrue((bool) $plan['team_selection_initialized'], 'la sélection d\'équipes est initialisée (le wizard ne re-seede pas)');
        $planId = (string) $plan['id'];

        $version = $this->connection->fetchAssociative(
            'SELECT s.status, s.solver_version, '
            . '(SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
            . 'FROM schedule s WHERE s.id = ?',
            [(string) $plan['chosen_schedule_id']],
        );
        self::assertNotFalse($version, 'la version pointée existe');
        self::assertSame('COMPLETED', (string) $version['status'], 'la version pointée est COMPLETED');
        self::assertSame('seed-transcription', (string) $version['solver_version'], 'la provenance est la transcription du seed');
        self::assertSame(90, (int) $version['slot_count'], 'la transcription pose exactement 90 créneaux');

        // --- Réglages : 50 TPO (49 actives, Σ séances = 90 ; Training Individuel seule décochée) ---
        $totalTpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE schedule_plan_id = ?', [$planId]);
        self::assertSame(50, $totalTpo, '50 lignes d\'équipe (49 actives + 1 décochée)');
        $activeTpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE schedule_plan_id = ? AND is_active = true', [$planId]);
        self::assertSame(49, $activeTpo, '49 équipes actives (celles qui figurent au planning)');
        $spwSum = (int) $this->connection->fetchOne('SELECT COALESCE(SUM(sessions_per_week), 0) FROM team_period_override WHERE schedule_plan_id = ? AND is_active = true', [$planId]);
        self::assertSame(90, $spwSum, 'la somme des séances/semaine actives vaut 90 (le total des séances-équipe)');

        $inactiveTpo = array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT t.name FROM team_period_override o JOIN team t ON t.id = o.team_id '
            . 'WHERE o.schedule_plan_id = ? AND o.is_active = false',
            [$planId],
        ));
        self::assertSame(['Training Individuel'], $inactiveTpo, '« Training Individuel » est la SEULE équipe décochée');

        $spwOf = fn (string $name): int => (int) $this->connection->fetchOne(
            'SELECT o.sessions_per_week FROM team_period_override o JOIN team t ON t.id = o.team_id '
            . 'WHERE o.schedule_plan_id = ? AND t.name = ?',
            [$planId, $name],
        );
        self::assertSame(3, $spwOf('Section J.Macé'), 'Section J.Macé s\'entraîne 3 fois (lun, jeu, ven)');
        self::assertSame(2, $spwOf('Basket Santé'), 'Basket Santé s\'entraîne 2 fois (mer annexe, sam JDR)');

        // --- 1 CPO (« SM2 · au moins 1 à Matéo »), 0 VPO, 0 réservation ---
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

        $reservations = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE schedule_plan_id = ?', [$planId]);
        self::assertSame(0, $reservations, 'ZÉRO réservation : les fanions viendront d\'un exercice ultérieur');
    }

    /**
     * « Incident Matéo » — la grille du plan est RECONSTRUITE depuis le planning d'overlay : 76
     * cases (venue+jour+heure), TOUTES à capacité 1 (occupant-unique — une case multi-équipes est
     * EXACTEMENT les membres d'un bloc déclaré, donc un occupant unique), ZÉRO créneau Matéo (le
     * gymnase est fermé). La mutualisation compte 13 blocs (les 8 socle hérités + 5 ajoutés),
     * chacun à commonSessions=1, aux ensembles réels. Idempotence : deux runs laissent 76 cases et
     * 13 blocs, sans doublon ni résurrection de l'ancien incident.
     *
     * Falsifiable : remonter une case à capacité 2, réintroduire un créneau Matéo, ou changer un
     * bloc (membre ou compte), rend ce test ROUGE.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMateoIncidentPlanCarriesReconstructedSingleOccupancyGrid(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $planId = $this->mateoIncidentPlanId($club->getId());

        self::assertSame(76, $this->incidentPlanSlotCount($planId), 'la grille du plan compte exactement 76 cases (venue+jour+heure)');
        $notOne = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM venue_training_slot WHERE schedule_plan_id = ? AND capacity <> 1',
            [$planId],
        );
        self::assertSame(0, $notOne, 'les 76 cases sont TOUTES à capacité 1 (occupant-unique par ensemble exact)');
        $mateoSlots = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM venue_training_slot s JOIN venue v ON v.id = s.venue_id '
            . 'WHERE s.schedule_plan_id = ? AND v.name = \'Matéo\'',
            [$planId],
        );
        self::assertSame(0, $mateoSlots, 'aucun créneau Matéo dans la grille du plan (le gymnase est fermé)');

        self::assertSame($this->expectedIncidentBlocs(), $this->incidentBlocSets($planId), 'les 13 blocs de mutualisation portent les ensembles réels');
        $notCommonOne = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM shared_training_block WHERE schedule_plan_id = ? AND common_sessions <> 1',
            [$planId],
        );
        self::assertSame(0, $notCommonOne, 'chaque bloc d\'incident est à commonSessions = 1');

        // Idempotence : un second run purge+réinsère, la grille reste à 76 et les 13 blocs tiennent,
        // sans doublon ni résurrection de l'ancien incident « (travaux) ».
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        $planId2 = $this->mateoIncidentPlanId($club->getId());
        self::assertSame(76, $this->incidentPlanSlotCount($planId2), 'un second seed laisse 76 cases de plan');
        self::assertSame($this->expectedIncidentBlocs(), $this->incidentBlocSets($planId2), 'un second seed laisse les 13 mêmes blocs');
        self::assertSame([], $this->duplicateSlots(), 'aucun créneau en doublon après le second run');
        $staleEntries = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_entry WHERE club_id = ? AND title LIKE ?',
            [$club->getId(), 'Matéo indisponible (travaux)%'],
        );
        self::assertSame(0, $staleEntries, 'l\'ancien incident ne ressuscite pas au second run');
    }

    /**
     * P5-13 — les reprises et le compte Nicolas ne visent QUE le profil dev. Le club de
     * DÉMONSTRATION ne porte aucun plan de période (HOLIDAY/CLOSURE), aucune entrée calendrier
     * (l'incident Matéo compris), et le compte gestionnaire Nicolas n'existe pas. La répartition WE
     * des matchs est dev-only aussi : aucune fenêtre d'accès match, habitude de match ou rotation.
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

        foreach (['team_match_habit', 'match_slot_rotation', 'match_slot_rotation_team', 'venue_match_window'] as $table) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE club_id = ?',
                [$club->getId()],
            );
            self::assertSame(0, $count, \sprintf('la démo ne pose aucune ligne dans %s (répartition WE dev-only)', $table));
        }
    }

    /**
     * Répartition WE des matchs (données fondateur, xlsx du 2026-09-02) — le seed dev pose l'état
     * terrain du week-end en trois entités du module matchs :
     *
     *  - 4 fenêtres d'accès match (Matéo sam 13:00→22:30 + dim 09:00→18:30, Armand sam 10:45→21:00,
     *    Debarros sam 13:00→18:30) ;
     *  - 32 habitudes de match (une par équipe qui reçoit le WE : jour + coup d'envoi + gymnase) ;
     *  - 8 créneaux partagés A/B (Armand ×5, Debarros ×3), chacun avec sa paire ORDONNÉE (position
     *    0 = équipe semaine A, 1 = semaine B) ; Matéo n'en porte AUCUN (heures A ≠ B).
     *
     * Le seed ne crée aucun match (0 ligne `fixture`). Falsifiable : changer une heure, un gymnase,
     * l'ordre d'une paire, ajouter une rotation à Matéo, ou créer un match, rend ce test ROUGE.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedCarriesWeekendMatchLayout(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $clubId = $club->getId();

        // --- 4 fenêtres d'accès match (gymnase, jour, début, fin). ---
        $windowRows = $this->connection->fetchAllAssociative(
            'SELECT v.name AS venue, w.day_of_week AS day, to_char(w.start_time, \'HH24:MI\') AS s, '
            . 'to_char(w.end_time, \'HH24:MI\') AS e FROM venue_match_window w '
            . 'JOIN venue v ON v.id = w.venue_id WHERE w.club_id = ? ORDER BY v.name, w.day_of_week',
            [$clubId],
        );
        $windows = array_map(
            static fn (array $r): array => [(string) $r['venue'], (int) $r['day'], (string) $r['s'], (string) $r['e']],
            $windowRows,
        );
        self::assertSame(
            [
                ['Armand', 6, '10:45', '21:00'],
                ['Debarros', 6, '13:00', '18:30'],
                ['Matéo', 6, '13:00', '22:30'],
                ['Matéo', 7, '09:00', '18:30'],
            ],
            $windows,
            'les 4 fenêtres d\'accès match sont exactement celles du terrain',
        );

        // --- 32 habitudes de match (équipe, jour, coup d'envoi, gymnase). ---
        $habitRows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, h.day_of_week AS day, to_char(h.kickoff_time, \'HH24:MI\') AS k, '
            . 'v.name AS venue FROM team_match_habit h JOIN team t ON t.id = h.team_id '
            . 'JOIN venue v ON v.id = h.venue_id WHERE h.club_id = ? ORDER BY t.name',
            [$clubId],
        );
        $habits = array_map(
            static fn (array $r): array => [(string) $r['team'], (int) $r['day'], (string) $r['k'], (string) $r['venue']],
            $habitRows,
        );
        // Tri PHP des deux côtés (par nom d'équipe, total sur 32 équipes distinctes) : la
        // comparaison ne dépend plus de la collation Postgres de l'ORDER BY.
        usort($habits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $expectedHabits = [
            ['SF1', 6, '18:30', 'Matéo'], ['SF2', 7, '11:00', 'Matéo'], ['SF3', 7, '16:30', 'Matéo'],
            ['SM1', 6, '20:45', 'Matéo'], ['SM2', 7, '15:30', 'Matéo'], ['SM3', 7, '10:00', 'Matéo'], ['SM4', 7, '09:00', 'Matéo'],
            ['U11F1', 6, '13:45', 'Armand'], ['U11F2', 6, '13:45', 'Armand'], ['U11M1', 6, '15:30', 'Armand'], ['U11M2', 6, '15:30', 'Armand'],
            ['U13F1', 6, '13:00', 'Matéo'], ['U13F2', 6, '15:00', 'Debarros'], ['U13F3', 6, '15:00', 'Debarros'],
            ['U13M1', 6, '17:00', 'Matéo'], ['U13M2', 6, '13:00', 'Debarros'],
            ['U15F1', 6, '13:45', 'Matéo'], ['U15F2', 6, '17:00', 'Debarros'], ['U15F3', 6, '17:00', 'Debarros'],
            ['U15M1', 6, '16:00', 'Matéo'], ['U15M2', 6, '13:00', 'Debarros'],
            ['U18F1', 7, '14:15', 'Matéo'], ['U18F2', 6, '15:00', 'Matéo'], ['U18F3', 6, '17:15', 'Armand'],
            ['U18M1', 7, '12:00', 'Matéo'], ['U18M2', 6, '19:00', 'Matéo'],
            ['U21M1', 7, '13:15', 'Matéo'], ['U21M2', 6, '17:15', 'Armand'],
            ['U9F1', 6, '12:15', 'Armand'], ['U9F2', 6, '10:45', 'Armand'], ['U9M1', 6, '10:45', 'Armand'], ['U9M2', 6, '12:15', 'Armand'],
        ];
        usort($expectedHabits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertCount(32, $habits, 'le seed pose exactement 32 habitudes de match');
        self::assertSame($expectedHabits, $habits, 'chaque habitude porte son jour, son coup d\'envoi et son gymnase exacts');

        // --- 8 créneaux partagés A/B, chacun avec sa paire ORDONNÉE (position 0 = A, 1 = B). ---
        $rotationRows = $this->connection->fetchAllAssociative(
            'SELECT v.name AS venue, r.day_of_week AS day, to_char(r.kickoff_time, \'HH24:MI\') AS k, '
            . 'rt.position AS pos, t.name AS team FROM match_slot_rotation r '
            . 'JOIN venue v ON v.id = r.venue_id '
            . 'JOIN match_slot_rotation_team rt ON rt.rotation_id = r.id '
            . 'JOIN team t ON t.id = rt.team_id WHERE r.club_id = ? '
            . 'ORDER BY v.name, r.kickoff_time, rt.position',
            [$clubId],
        );
        $membersBySlot = [];
        foreach ($rotationRows as $r) {
            $key = \sprintf('%s|%d|%s', (string) $r['venue'], (int) $r['day'], (string) $r['k']);
            $membersBySlot[$key][(int) $r['pos']] = (string) $r['team'];
        }
        $rotations = [];
        foreach ($membersBySlot as $key => $members) {
            ksort($members);
            [$venue, $day, $kickoff] = explode('|', $key);
            $rotations[] = [$venue, (int) $day, $kickoff, array_values($members)];
        }
        self::assertSame(
            [
                ['Armand', 6, '10:45', ['U9F2', 'U9M1']],
                ['Armand', 6, '12:15', ['U9F1', 'U9M2']],
                ['Armand', 6, '13:45', ['U11F1', 'U11F2']],
                ['Armand', 6, '15:30', ['U11M2', 'U11M1']],
                ['Armand', 6, '17:15', ['U18F3', 'U21M2']],
                ['Debarros', 6, '13:00', ['U13M2', 'U15M2']],
                ['Debarros', 6, '15:00', ['U13F3', 'U13F2']],
                ['Debarros', 6, '17:00', ['U15F3', 'U15F2']],
            ],
            $rotations,
            'les 8 créneaux partagés portent leur paire ordonnée (A puis B), Armand ×5 et Debarros ×3',
        );

        // Matéo ne porte AUCUNE rotation (ses heures diffèrent d'une semaine à l'autre).
        $mateoRotations = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM match_slot_rotation r JOIN venue v ON v.id = r.venue_id '
            . 'WHERE r.club_id = ? AND v.name = ?',
            [$clubId, 'Matéo'],
        );
        self::assertSame(0, $mateoRotations, 'Matéo ne porte aucune rotation (heures semaine A ≠ semaine B)');

        // Le seed ne crée aucun match.
        $fixtures = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture WHERE club_id = ?', [$clubId]);
        self::assertSame(0, $fixtures, 'le seed ne crée aucun match (fixture)');
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
        self::assertSame('amateo_owner', $this->connection->fetchOne('SELECT current_user'));

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
     * @return array{clubs:int, teams:int, slots:int, reservations:int, schedules:int, slotTemplates:int, clubUsers:int, calendarEntries:int, schedulePlans:int, sharedBlocks:int, sharedBlockTeams:int, teamLinks:int, venuePeriodOverrides:int, teamPeriodOverrides:int, constraintPeriodOverrides:int, teamMatchHabits:int, matchSlotRotations:int, matchSlotRotationTeams:int, venueMatchWindows:int}
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
            'sharedBlocks' => $this->rowsIn('shared_training_block'),
            'sharedBlockTeams' => $this->rowsIn('shared_training_block_team'),
            // P5-23/P2-51 (recalage 2026-09-01) : les 10 passerelles (find-or-create couple
            // normalisé) et les 8 blocs SOCLE (purge+recréation) entrent dans la mesure.
            'teamLinks' => $this->rowsIn('team_link'),
            'venuePeriodOverrides' => $this->rowsIn('venue_period_override'),
            'teamPeriodOverrides' => $this->rowsIn('team_period_override'),
            'constraintPeriodOverrides' => $this->rowsIn('constraint_period_override'),
            // Répartition WE des matchs (profil dev) : les 32 habitudes (find-or-create sur
            // (club, saison, équipe, jour)), les 8 rotations + 16 membres et les 4 fenêtres d'accès
            // (purge+recréation) entrent dans la mesure — deux runs = mêmes comptes.
            'teamMatchHabits' => $this->rowsIn('team_match_habit'),
            'matchSlotRotations' => $this->rowsIn('match_slot_rotation'),
            'matchSlotRotationTeams' => $this->rowsIn('match_slot_rotation_team'),
            'venueMatchWindows' => $this->rowsIn('venue_match_window'),
        ];
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * Le plan de fermeture de l'incident Matéo : porté DIRECTEMENT par l'entrée-racine « Matéo
     * indisponible (incident) — … » (plus de segment intermédiaire depuis l'arbitrage 2026-09-02).
     */
    private function mateoIncidentPlanId(string $clubId): string
    {
        $planId = $this->connection->fetchOne(
            'SELECT sp.id FROM schedule_plan sp '
            . 'JOIN calendar_entry root ON root.id = sp.calendar_entry_id '
            . 'WHERE sp.club_id = ? AND root.parent_entry_id IS NULL AND root.title LIKE ?',
            [$clubId, 'Matéo indisponible (incident)%'],
        );
        self::assertNotFalse($planId, 'le plan de fermeture de l\'incident Matéo existe (porté par sa racine)');

        return (string) $planId;
    }

    private function incidentPlanSlotCount(string $planId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM venue_training_slot WHERE schedule_plan_id = ?',
            [$planId],
        );
    }

    /**
     * Les ensembles d'équipes des blocs du plan d'incident, chaque bloc trié, la liste triée — pour
     * comparer indépendamment de l'ordre.
     *
     * @return list<list<string>>
     */
    private function incidentBlocSets(string $planId): array
    {
        /** @var list<array{block_id: string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id AS block_id, t.name FROM shared_training_block b '
            . 'JOIN shared_training_block_team bt ON bt.block_id = b.id '
            . 'JOIN team t ON t.id = bt.team_id '
            . 'WHERE b.schedule_plan_id = ?',
            [$planId],
        );
        $byBlock = [];
        foreach ($rows as $row) {
            $byBlock[(string) $row['block_id']][] = (string) $row['name'];
        }
        $sets = [];
        foreach ($byBlock as $members) {
            sort($members, \SORT_STRING);
            $sets[] = $members;
        }
        sort($sets);

        return $sets;
    }

    /**
     * Les 13 ensembles mutualisés attendus (8 socle hérités + 5 ajoutés), normalisés comme
     * {@see incidentBlocSets()}.
     *
     * @return list<list<string>>
     */
    private function expectedIncidentBlocs(): array
    {
        $sets = [
            ['U13M1', 'U13M2'],
            ['U18M1', 'U18F1'],
            ['U9F1', 'U9F2'],
            ['U15M1', 'U15F1'],
            ['U13F2', 'U13F3'],
            ['U11M2', 'U11F2'],
            ['U15F2', 'U15F3'],
            ['U13F1', 'U13F2'],
            ['U9M1', 'U11F2'],
            ['U11F1', 'U11M2'],
            ['U9M2', 'U9F1', 'U9F2'],
            ['U9M1', 'U9M2'],
            ['Loisir Feminine', 'Veterans'],
        ];
        foreach ($sets as &$members) {
            sort($members, \SORT_STRING);
        }
        unset($members);
        sort($sets);

        return $sets;
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
