<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seed;

use App\Entity\OpponentVenueLink;
use App\Enum\OpponentVenueLinkSource;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use App\Service\Basketball\CategoryCatalog;
use App\Service\ClosureSegmentation;
use App\Service\HolidayWorkweekRule;
use App\Service\PeriodWindowUniquenessGuard;
use App\Service\SoloReservationBudget;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

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
     * Retours de tests — le seed pose le SIÈGE du club dev (adresse/CP/ville + coordonnées)
     * quand il est vide, pour que les trajets vers les adversaires s'estiment sur le club réel.
     * Only-fill-when-empty (même patron que schoolZone/league/accent) : un PATCH manuel survit
     * à un re-run. Falsifié dans les deux sens : un siège absent au premier run, ET une
     * coordonnée corrigée à la main écrasée au second.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedFillsTheClubSiegeAndNeverOverwritesAManualValue(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        self::assertSame('5 RUE EMILE DUNIERE', $club->getAddress(), 'le siège du club dev est posé');
        self::assertSame('69100', $club->getPostalCode());
        self::assertSame('VILLEURBANNE', $club->getCity());
        self::assertSame(45.78017, $club->getLatitude(), 'les coordonnées du siège sont posées');
        self::assertSame(4.88467, $club->getLongitude());

        // Un gestionnaire corrige le siège à la main.
        $club->setLatitude(45.5)->setLongitude(4.5)->setAddress('AUTRE ADRESSE');
        $this->em->flush();

        // Un second seed ne réverte JAMAIS la correction (only-fill-when-empty).
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertSame(45.5, $club->getLatitude(), 'un re-run n\'écrase pas une coordonnée corrigée à la main');
        self::assertSame(4.5, $club->getLongitude());
        self::assertSame('AUTRE ADRESSE', $club->getAddress());
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

        // P4-266 — une base FRAÎCHE naît « à régénérer » MUET : la péremption ne vit plus dans un
        // drapeau posé par un listener (supprimé), elle se dérive de l'empreinte de structure
        // (le `snapshotHash` de la version pointée ⇄ la structure courante, servie par
        // SchedulePlanStructureHashController). Le seed pose le snapshot de structure sur la
        // version pointée (`buildForClubSeason`), donc son `snapshot_hash` est renseigné — base
        // cohérente, « Régénérer » honnêtement grisé.
        $snapshotHash = $this->connection->fetchOne(
            'SELECT s.snapshot_hash FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
            . 'WHERE sp.season_id = (SELECT id FROM season WHERE club_id = ? AND name = \'2026-2027\') '
            . 'AND sp.type = \'SEASON\'',
            [$club->getId()],
        );
        self::assertIsString($snapshotHash, 'la version de saison pointée porte une empreinte de structure (base fraîche cohérente)');
        self::assertNotSame('', $snapshotHash, 'l\'empreinte de structure n\'est pas vide');
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
     * Le code FFBB de la démo est synthétique (ARA9999999) → le résolveur de zone n'y lit aucun
     * département : sans repli, `school_zone` resterait NULL et le radar afficherait « zone scolaire
     * à renseigner » au lieu du compte à rebours des vacances. Le seed démo pose la zone A (Rhône).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDemoSeedGivesTheClubTheRhoneSchoolZone(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::demo('demo-pass-zone'));

        self::assertSame('A', $club->getSchoolZone(), 'le club démo vit en zone A (Rhône)');
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
     * réel de la semaine contredit (U18M1 s'entraîne le jeudi de Coach PoivreSel, U15M1 le
     * vendredi de Coach Tho) — sans quoi la génération ne peut pas atteindre le planning
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
            . 'AND c.name IN (\'Coach PoivreSel · indispo jeudi\', \'Coach Tho · indispo vendredi\', \'SM2 · pas vendredi\') '
            . 'ORDER BY c.name',
            [$club->getId(), 'Reprise du 17 août'],
        );
        self::assertSame(
            ['Coach PoivreSel · indispo jeudi', 'Coach Tho · indispo vendredi', 'SM2 · pas vendredi'],
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
     * lun/ven de Coach PoivreSel) portent calendar_entry_id = l'entrée du 17, avec leurs configs
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
            [$clubId, 'Coach PoivreSel', ''],
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
                'name' => 'Coach PoivreSel · indispo lundi, vendredi',
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

        // --- La RACINE ne porte PLUS de plan (découpage début·milieu·fin, fondateur 2026-09-05) :
        // une fermeture à semaine entamée s'adapte par ses enfants, jamais d'un bloc sur la racine ---
        $rootPlan = $this->connection->fetchOne('SELECT 1 FROM schedule_plan WHERE calendar_entry_id = ?', [$incidentId]);
        self::assertFalse($rootPlan, 'la racine (fermeture à semaine entamée) ne porte AUCUN plan-bloc');

        // --- DEUX enfants-segments : milieu 31/08→11/10 (6 semaines pleines) et fin = semaine du 12/10 ---
        /** @var list<array{id: string, s: string, e: string, period_type: string}> $children */
        $children = $this->connection->fetchAllAssociative(
            'SELECT id, to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e, period_type '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id = ? ORDER BY start_date ASC',
            [$club->getId(), $incidentId],
        );
        self::assertCount(2, $children, 'l\'incident se découpe en DEUX enfants (milieu + fin)');
        $windows = array_map(static fn (array $c): string => $c['s'] . '→' . $c['e'], $children);
        self::assertSame(['2026-08-31→2026-10-11', '2026-10-12→2026-10-18'], $windows, 'milieu 31/08→11/10, fin = semaine calendaire du 12/10 (12→18/10)');
        foreach ($children as $child) {
            self::assertSame('closure', (string) $child['period_type'], 'chaque enfant hérite le type CLOSURE de sa mère');
        }

        // --- Chaque enfant porte SON plan CLOSURE, VALIDÉ (chosen), transcrivant le MÊME overlay :
        // version COMPLETED 90 créneaux, 50 TPO (49 actives Σ=90 ; Training Individuel décochée),
        // 1 CPO (« SM2 · au moins 1 à Matéo »), 0 VPO, 0 réservation. ---
        $assertPlanCarriesOverlay = function (string $planId): void {
            $plan = $this->connection->fetchAssociative(
                'SELECT type, chosen_schedule_id, team_selection_initialized FROM schedule_plan WHERE id = ?',
                [$planId],
            );
            self::assertNotFalse($plan, 'l\'enfant porte son plan');
            self::assertSame('CLOSURE', (string) $plan['type'], 'le plan est de type CLOSURE');
            self::assertNotNull($plan['chosen_schedule_id'], 'le plan EST validé (il pointe une version)');
            self::assertTrue((bool) $plan['team_selection_initialized'], 'la sélection d\'équipes est initialisée');

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

            $totalTpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE schedule_plan_id = ?', [$planId]);
            self::assertSame(50, $totalTpo, '50 lignes d\'équipe (49 actives + 1 décochée)');
            $activeTpo = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE schedule_plan_id = ? AND is_active = true', [$planId]);
            self::assertSame(49, $activeTpo, '49 équipes actives (celles qui figurent au planning)');
            $spwSum = (int) $this->connection->fetchOne('SELECT COALESCE(SUM(sessions_per_week), 0) FROM team_period_override WHERE schedule_plan_id = ? AND is_active = true', [$planId]);
            self::assertSame(90, $spwSum, 'la somme des séances/semaine actives vaut 90');

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
        };

        $childPlanIds = $this->mateoIncidentChildPlanIds($club->getId());
        self::assertCount(2, $childPlanIds, 'les deux enfants portent chacun leur plan');
        foreach ($childPlanIds as $planId) {
            $assertPlanCarriesOverlay($planId);
        }
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

        // Les DEUX plans-enfants (milieu, fin) portent la MÊME grille transcrite.
        $assertGrid = function (string $planId): void {
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
        };

        $childPlanIds = $this->mateoIncidentChildPlanIds($club->getId());
        self::assertCount(2, $childPlanIds, 'les deux enfants portent chacun leur plan');
        foreach ($childPlanIds as $planId) {
            $assertGrid($planId);
        }

        // Idempotence : un second run purge+réinsère, chaque grille reste à 76 et les 13 blocs
        // tiennent, sans doublon ni résurrection de l'ancien incident « (travaux) ».
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        $childPlanIds2 = $this->mateoIncidentChildPlanIds($club->getId());
        self::assertCount(2, $childPlanIds2, 'les deux plans-enfants tiennent au second run');
        foreach ($childPlanIds2 as $planId) {
            $assertGrid($planId);
        }
        self::assertSame([], $this->duplicateSlots(), 'aucun créneau en doublon après le second run');
        $staleEntries = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_entry WHERE club_id = ? AND title LIKE ?',
            [$club->getId(), 'Matéo indisponible (travaux)%'],
        );
        self::assertSame(0, $staleEntries, 'l\'ancien incident ne ressuscite pas au second run');
    }

    /**
     * NR (règle fondateur 2026-09-05) — TOUT enfant CLOSURE seedé est un SEGMENT VALIDE de la règle
     * (WeekSegmentationRule via ClosureSegmentation), et AUCUNE racine CLOSURE à plusieurs segments
     * ne porte de plan-bloc. Falsifiable : re-porter un plan sur la racine de l'incident, ou seeder
     * un enfant dont la fenêtre n'est pas un segment, rend ce test ROUGE.
     *
     * ⚠ HORLOGE FIGÉE. `ClosureSegmentation::segments` ne garde que les semaines dont il reste un jour
     * devant (`endDate >= today`) : joué à l'horloge réelle, ce test a rougi le lundi 2026-09-07 — la
     * semaine du 31/08 était révolue, le premier segment devenait 07/09→11/10 et l'enfant seedé
     * 31/08→11/10 (donnée fondateur, session du 2026-09-02) ne matchait plus rien. Les enfants seedés
     * sont une photo datée : on les juge à la date de cette photo, pas à celle du run.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMateoIncidentChildrenAreValidSegmentsAndRootCarriesNoBlockPlan(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        // Horloge figée à la session fondateur qui a posé l'incident (voir le docblock) : le service
        // du conteneur porte l'horloge réelle, on rebâtit la règle avec les mêmes dépendances.
        $segmentation = new ClosureSegmentation(
            $this->em,
            self::getContainer()->get(SchoolHolidayPeriodRepository::class),
            self::getContainer()->get(PeriodWindowUniquenessGuard::class),
            new MockClock('2026-09-02'),
        );

        /** @var array{id: string, season_id: string, s: string, e: string} $root */
        $root = $this->connection->fetchAssociative(
            'SELECT id, season_id, to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id IS NULL AND title LIKE ?',
            [$club->getId(), 'Matéo indisponible (incident)%'],
        ) ?: throw new RuntimeException('racine incident introuvable');

        // La racine (fermeture à semaine entamée) ne porte AUCUN plan.
        self::assertFalse(
            $this->connection->fetchOne('SELECT 1 FROM schedule_plan WHERE calendar_entry_id = ?', [(string) $root['id']]),
            'aucune racine CLOSURE à plusieurs segments ne porte de plan-bloc',
        );

        /** @var array{start_date: string, end_date: string} $season */
        $season = $this->connection->fetchAssociative(
            'SELECT to_char(start_date, \'YYYY-MM-DD\') AS start_date, to_char(end_date, \'YYYY-MM-DD\') AS end_date FROM season WHERE id = ?',
            [(string) $root['season_id']],
        ) ?: throw new RuntimeException('saison introuvable');

        $segments = $segmentation->segments(
            (string) $club->getId(),
            (string) $root['season_id'],
            (string) $root['id'],
            (string) $root['s'],
            (string) $root['e'],
            (string) $season['start_date'],
            (string) $season['end_date'],
        );
        self::assertGreaterThan(1, \count($segments), 'la fenêtre de l\'incident se décompose en plusieurs segments (semaine entamée)');
        $segmentWindows = array_map(static fn (array $seg): string => $seg['startDate'] . '→' . $seg['endDate'], $segments);

        /** @var list<array{s: string, e: string}> $children */
        $children = $this->connection->fetchAllAssociative(
            'SELECT to_char(start_date, \'YYYY-MM-DD\') AS s, to_char(end_date, \'YYYY-MM-DD\') AS e '
            . 'FROM calendar_entry WHERE club_id = ? AND parent_entry_id = ? AND period_type = \'closure\'',
            [$club->getId(), (string) $root['id']],
        );
        self::assertNotEmpty($children, 'l\'incident a bien des enfants CLOSURE');
        foreach ($children as $child) {
            self::assertContains(
                $child['s'] . '→' . $child['e'],
                $segmentWindows,
                \sprintf('l\'enfant %s→%s doit être un segment valide de la règle (segments : %s)', $child['s'], $child['e'], implode(', ', $segmentWindows)),
            );
        }
    }

    /**
     * P5-13 — les reprises et les gestionnaires additionnels ne visent QUE le profil dev. Le club
     * de DÉMONSTRATION ne porte aucun plan de période (HOLIDAY/CLOSURE), aucune entrée calendrier
     * (l'incident Matéo compris), et le gestionnaire du club dev n'existe pas chez la démo (identités
     * disjointes). La répartition WE des matchs est dev-only aussi : aucune fenêtre d'accès match ni
     * créneau idéal de match.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDemoSeedCarriesNoRepriseNorDevManagerAccount(): void
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

        // Identités disjointes : le gestionnaire fictif du club dev n'est pas créé par la démo.
        $devManager = $this->connection->fetchOne(
            'SELECT 1 FROM app_user WHERE email = ?',
            ['dev-bccl@amateo.local'],
        );
        self::assertFalse($devManager, 'la démo ne crée pas le compte gestionnaire du club dev');

        foreach (['team_match_habit', 'venue_match_window', 'opponent_venue_link'] as $table) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE club_id = ?',
                [$club->getId()],
            );
            self::assertSame(0, $count, \sprintf('la démo ne pose aucune ligne dans %s (répartition WE + adversaires dev/prod-only)', $table));
        }

        // Les tables d'adversaires GLOBALES (hors tenant) restent vides : la démo n'amorce rien.
        self::assertSame(0, $this->rowsIn('opponent_directory'), 'la démo n\'amorce aucune localisation d\'adversaire partagée');
        self::assertSame(0, $this->rowsIn('opponent_venue_suggestion'), 'la démo n\'amorce aucune suggestion de gymnase partagée');
    }

    /**
     * Répartition WE des matchs (données fondateur) — le seed dev pose l'état terrain du week-end
     * en trois entités du module matchs :
     *
     *  - 10 fenêtres d'accès match (relevées de la base réelle le 2026-09-29, co-construction
     *    fondateur : Annexe sam, Armand sam, Debarros jeu + sam, JDR ven + sam + dim, Matéo ven +
     *    sam + dim) ;
     *  - 32 créneaux idéaux de match (un par équipe qui reçoit le WE : jour + coup d'envoi + gymnase
     *    + semaine A/B), l'alternance A/B des 8 paires d'Armand/Debarros portée par le tag `week`
     *    (P4-271 : plus aucune entité de rotation).
     *
     * Le seed ne crée aucun match (0 ligne `fixture`). Falsifiable : changer une heure, un gymnase,
     * un tag de semaine, ou créer un match, rend ce test ROUGE.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedCarriesWeekendMatchLayout(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $clubId = $club->getId();

        // --- 10 fenêtres d'accès match (gymnase, jour, début, fin). ---
        $windowRows = $this->connection->fetchAllAssociative(
            'SELECT v.name AS venue, w.day_of_week AS day, to_char(w.start_time, \'HH24:MI\') AS s, '
            . 'to_char(w.end_time, \'HH24:MI\') AS e FROM venue_match_window w '
            . 'JOIN venue v ON v.id = w.venue_id WHERE w.club_id = ? ORDER BY v.name, w.day_of_week, w.start_time',
            [$clubId],
        );
        $windows = array_map(
            static fn (array $r): array => [(string) $r['venue'], (int) $r['day'], (string) $r['s'], (string) $r['e']],
            $windowRows,
        );
        self::assertSame(
            [
                ['Annexe', 6, '12:00', '22:00'],
                ['Armand', 6, '10:45', '21:00'],
                ['Debarros', 4, '20:30', '22:00'],
                ['Debarros', 6, '12:00', '22:00'],
                ['JDR', 5, '20:00', '22:00'],
                ['JDR', 6, '10:30', '22:00'],
                ['JDR', 7, '09:00', '20:00'],
                ['Matéo', 5, '20:30', '22:00'],
                ['Matéo', 6, '11:00', '22:00'],
                ['Matéo', 7, '09:00', '18:30'],
            ],
            $windows,
            'les 10 fenêtres d\'accès match sont exactement celles du terrain (relevé 2026-09-29)',
        );

        // --- 32 créneaux idéaux de match (équipe, jour, coup d'envoi, gymnase, semaine A/B). ---
        $habitRows = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, h.day_of_week AS day, to_char(h.kickoff_time, \'HH24:MI\') AS k, '
            . 'v.name AS venue, h.week AS week FROM team_match_habit h JOIN team t ON t.id = h.team_id '
            . 'JOIN venue v ON v.id = h.venue_id WHERE h.club_id = ? ORDER BY t.name',
            [$clubId],
        );
        $habits = array_map(
            static fn (array $r): array => [(string) $r['team'], (int) $r['day'], (string) $r['k'], (string) $r['venue'], (string) $r['week']],
            $habitRows,
        );
        // Tri PHP des deux côtés (par nom d'équipe, total sur 32 équipes distinctes) : la
        // comparaison ne dépend plus de la collation Postgres de l'ORDER BY.
        usort($habits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        // Le tag de semaine A/B porte l'alternance des 8 paires d'Armand/Debarros (P4-271).
        $expectedHabits = [
            ['SF1', 6, '18:30', 'Matéo', 'B'], ['SF2', 7, '11:00', 'Matéo', 'B'], ['SF3', 7, '16:30', 'Matéo', 'A'],
            ['SM1', 6, '20:45', 'Matéo', 'B'], ['SM2', 7, '15:30', 'Matéo', 'B'], ['SM3', 7, '10:00', 'Matéo', 'A'], ['SM4', 7, '09:00', 'Matéo', 'B'],
            ['U11F1', 6, '13:45', 'Armand', 'A'], ['U11F2', 6, '13:45', 'Armand', 'B'], ['U11M1', 6, '15:30', 'Armand', 'B'], ['U11M2', 6, '15:30', 'Armand', 'A'],
            ['U13F1', 6, '13:00', 'Matéo', 'A'], ['U13F2', 6, '15:00', 'Debarros', 'B'], ['U13F3', 6, '15:00', 'Debarros', 'A'],
            ['U13M1', 6, '17:00', 'Matéo', 'A'], ['U13M2', 6, '13:00', 'Debarros', 'A'],
            ['U15F1', 6, '13:45', 'Matéo', 'B'], ['U15F2', 6, '17:00', 'Debarros', 'B'], ['U15F3', 6, '17:00', 'Debarros', 'A'],
            ['U15M1', 6, '16:00', 'Matéo', 'B'], ['U15M2', 6, '13:00', 'Debarros', 'B'],
            ['U18F1', 7, '14:15', 'Matéo', 'A'], ['U18F2', 6, '15:00', 'Matéo', 'A'], ['U18F3', 6, '17:15', 'Armand', 'A'],
            ['U18M1', 7, '12:00', 'Matéo', 'A'], ['U18M2', 6, '19:00', 'Matéo', 'A'],
            ['U21M1', 7, '13:15', 'Matéo', 'B'], ['U21M2', 6, '17:15', 'Armand', 'B'],
            ['U9F1', 6, '12:15', 'Armand', 'A'], ['U9F2', 6, '10:45', 'Armand', 'A'], ['U9M1', 6, '10:45', 'Armand', 'B'], ['U9M2', 6, '12:15', 'Armand', 'B'],
        ];
        usort($expectedHabits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertCount(32, $habits, 'le seed pose exactement 32 créneaux idéaux de match');
        self::assertSame($expectedHabits, $habits, 'chaque créneau idéal porte son jour, son coup d\'envoi, son gymnase et sa semaine A/B exacts');

        // Le seed ne crée aucun match.
        $fixtures = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture WHERE club_id = ?', [$clubId]);
        self::assertSame(0, $fixtures, 'le seed ne crée aucun match (fixture)');
    }

    /**
     * Durées de match + échauffement par catégorie (relevé de la base réelle 2026-09-29) — le seed
     * pose Senior 120/45, U15 105/(défaut), U21 120/(défaut) ; les autres catégories suivent le
     * défaut de famille (NULL). Ces valeurs sont RÉAPPLIQUÉES à chaque run (pas « only-fill » comme
     * le siège) : une dérive manuelle est ramenée au réel au re-run. Falsifiable dans les deux sens.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedCarriesPerCategoryMatchDurations(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $clubId = $club->getId();

        $durations = fn (): array => $this->connection->fetchAllKeyValue(
            'SELECT name, match_minutes || \'/\' || COALESCE(warmup_minutes::text, \'-\') '
            . 'FROM sport_category WHERE club_id = ? AND name IN (\'Senior\', \'U15\', \'U21\') ORDER BY name',
            [$clubId],
        );
        self::assertSame(
            ['Senior' => '120/45', 'U15' => '105/-', 'U21' => '120/-'],
            $durations(),
            'le seed pose les durées de match + échauffement du terrain (Senior 120/45, U15 105/défaut, U21 120/défaut)',
        );

        // Réappliquées au re-run : une dérive manuelle est ramenée au réel (pas « only-fill »).
        $this->connection->executeStatement(
            'UPDATE sport_category SET match_minutes = 999, warmup_minutes = 999 WHERE club_id = ? AND name = \'U15\'',
            [$clubId],
        );
        $this->em->clear();
        $this->seeder->run($this->em, BcclSeedProfile::dev());
        self::assertSame(
            ['Senior' => '120/45', 'U15' => '105/-', 'U21' => '120/-'],
            $durations(),
            'un second seed réapplique les durées (la dérive 999/999 sur U15 est ramenée à 105/défaut)',
        );
    }

    /**
     * Amorçage des adversaires (relevé de la base réelle 2026-09-29) — le seed dev pose les trois
     * tables de référence du module « adversaires » à leurs volumes réels : 75 localisations
     * partagées (opponent_directory, GLOBALE), 101 appariements du club (opponent_venue_link : 32
     * MANUAL + 69 AUTO), 15 suggestions partagées (opponent_venue_suggestion, GLOBALE, toutes
     * MANUAL). Le club ne crée aucun match (le ré-import se fait en prod). Idempotent (upserts
     * natifs + find-or-create) : un second run ne double rien.
     *
     * Deux sens de la règle « source préservée, MANUAL jamais écrasé » : un lien MANUAL modifié à
     * la main SURVIT à un re-run (le seed ne le touche pas) ; un lien AUTO dérivé est RESTAURÉ au
     * réel (le seed réapplique ses champs fédéraux).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDevSeedCarriesOpponentReferenceData(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $clubId = $club->getId();

        // Volumes (baseline test = 0 sur les tables GLOBALES, cf. amateo_test vierge).
        self::assertSame(75, $this->rowsIn('opponent_directory'), 'le seed pose 75 localisations partagées');
        self::assertSame(15, $this->rowsIn('opponent_venue_suggestion'), 'le seed pose 15 suggestions partagées');
        $linkCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM opponent_venue_link WHERE club_id = ?', [$clubId]);
        self::assertSame(101, $linkCount, 'le seed pose 101 appariements pour le club');

        /** @var array<string, int> $bySource */
        $bySource = $this->connection->fetchAllKeyValue(
            'SELECT source, COUNT(*) FROM opponent_venue_link WHERE club_id = ? GROUP BY source ORDER BY source',
            [$clubId],
        );
        self::assertSame(['AUTO' => 69, 'MANUAL' => 32], array_map('intval', $bySource), 'les liens se répartissent 69 AUTO + 32 MANUAL');

        // Toutes les suggestions sont MANUAL, à compte 0 (l'upsert ne fabrique pas de choix).
        $suggestionSources = $this->connection->fetchFirstColumn('SELECT DISTINCT source FROM opponent_venue_suggestion');
        self::assertSame(['MANUAL'], array_map('strval', $suggestionSources), 'les suggestions semées sont MANUAL');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COALESCE(MAX(chosen_by_count), 0) FROM opponent_venue_suggestion'), 'le seed ne fabrique aucun compte de choix (« un COMPTE, jamais un QUI »)');

        // Aucun match : le ré-import se fait en prod, hors seed.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture WHERE club_id = ?', [$clubId]), 'le seed ne crée aucun match');

        // (1) Un lien MANUAL modifié à la main SURVIT au re-run.
        $manual = $this->em->getRepository(OpponentVenueLink::class)->findOneBy(['clubId' => $clubId, 'source' => OpponentVenueLinkSource::MANUAL]);
        self::assertInstanceOf(OpponentVenueLink::class, $manual, 'un lien MANUAL existe');
        $manual->setVenueLabel('SENTINELLE MANUAL');
        $this->em->flush();

        // (2) Un lien AUTO dérivé est RESTAURÉ au réel au re-run.
        $auto = $this->em->getRepository(OpponentVenueLink::class)->findOneBy(['clubId' => $clubId, 'source' => OpponentVenueLinkSource::AUTO]);
        self::assertInstanceOf(OpponentVenueLink::class, $auto, 'un lien AUTO existe');
        $seededAutoLabel = $auto->getVenueLabel();
        $auto->setVenueLabel('DERIVE AUTO');
        $this->em->flush();

        $this->seeder->run($this->em, BcclSeedProfile::dev());

        // Idempotent : mêmes volumes après le second run.
        self::assertSame(75, $this->rowsIn('opponent_directory'), 'un second run ne double pas les localisations');
        self::assertSame(15, $this->rowsIn('opponent_venue_suggestion'), 'un second run ne double pas les suggestions');
        self::assertSame(101, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM opponent_venue_link WHERE club_id = ?', [$clubId]), 'un second run ne double pas les appariements');

        $this->em->refresh($manual);
        self::assertSame('SENTINELLE MANUAL', $manual->getVenueLabel(), 'un lien MANUAL n\'est JAMAIS écrasé par un re-run');
        $this->em->refresh($auto);
        self::assertSame($seededAutoLabel, $auto->getVenueLabel(), 'un lien AUTO dérivé est restauré au réel par un re-run');
    }

    /**
     * P2-60 — L'INVARIANT « unité de placement = le bloc » TIENT SUR LA DONNÉE SEMÉE : sur le socle
     * ET sur CHAQUE plan de période, pour chaque équipe, ses réservations INDIVIDUELLES (hors cases
     * bloc-complètes) ≤ son résidu solo R(T) = S(T) − B(T) ({@see SoloReservationBudget}, maison
     * unique de R). Sinon une génération sur cette portée rendrait le solveur INFEASIBLE — le geste
     * même que P2-60 interdit désormais aux deux portes d'écriture.
     *
     * ⚠ Si ce test rougit, le seed BCCL (club RÉEL du fondateur) viole déjà l'invariant : le message
     * nomme la portée, l'équipe et son S/B/R/individualUsed — à RAPPORTER, pas à corriger d'office.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEverySeededScopeSatisfiesTheSoloBudgetInvariant(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $clubId = $club->getId();

        $seasonId = (string) $this->connection->fetchOne(
            'SELECT id FROM season WHERE club_id = ? AND name = \'2026-2027\'',
            [$clubId],
        );
        self::assertNotSame('', $seasonId, 'la saison 2026-2027 du club dev existe');

        /** @var array<string, string> $teamNames */
        $teamNames = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name FROM team WHERE club_id = ?', [$clubId]) as $row) {
            $teamNames[(string) $row['id']] = (string) $row['name'];
        }

        // Socle (planId NULL) + chaque plan de période (reprises, incident — tout sauf SEASON).
        $planIds = [null];
        foreach ($this->connection->fetchFirstColumn(
            'SELECT id FROM schedule_plan WHERE club_id = ? AND type <> \'SEASON\'',
            [$clubId],
        ) as $planId) {
            $planIds[] = (string) $planId;
        }

        $budgetService = self::getContainer()->get(SoloReservationBudget::class);
        self::assertInstanceOf(SoloReservationBudget::class, $budgetService);

        $violations = [];
        foreach ($planIds as $planId) {
            $scope = null === $planId ? 'socle' : ('plan ' . $planId);
            foreach ($budgetService->forScope($clubId, $seasonId, $planId) as $budget) {
                if ($budget->individualUsed > $budget->residual) {
                    $violations[] = \sprintf(
                        '%s — %s : S=%d B=%d R=%d individualUsed=%d',
                        $scope,
                        $teamNames[$budget->teamId] ?? $budget->teamId,
                        $budget->effective,
                        $budget->block,
                        $budget->residual,
                        $budget->individualUsed,
                    );
                }
            }
        }

        self::assertSame([], $violations, "le seed BCCL viole l'invariant solo (réservations individuelles > résidu) — RAPPORTER au fondateur, ne pas corriger le seed :\n  - " . implode("\n  - ", $violations));
    }

    /**
     * NR (décision fondateur 2026-09-04) — TOUTE SEMAINE-ENFANT DE VACANCES SEMÉE EST « DE
     * VACANCES » : son lundi→vendredi est entièrement couvert par sa mère (règle
     * {@see HolidayWorkweekRule::covers}, celle-là même que garde le POST). Sans quoi le seed
     * livrerait une semaine de reprise que l'app refuserait de recréer — une donnée qui « ne vient
     * pas de l'app ». Falsifiable : décaler une reprise d'un jour hors de sa mère rend ce test ROUGE.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEverySeededHolidayWeekChildCoversItsMotherWorkweek(): void
    {
        $club = $this->seeder->run($this->em, BcclSeedProfile::dev());

        /** @var list<array{title: string, cs: string, ce: string, ms: string, me: string, ss: string, se: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT child.title AS title, '
            . 'to_char(child.start_date, \'YYYY-MM-DD\') AS cs, to_char(child.end_date, \'YYYY-MM-DD\') AS ce, '
            . 'to_char(mother.start_date, \'YYYY-MM-DD\') AS ms, to_char(mother.end_date, \'YYYY-MM-DD\') AS me, '
            . 'to_char(s.start_date, \'YYYY-MM-DD\') AS ss, to_char(s.end_date, \'YYYY-MM-DD\') AS se '
            . 'FROM calendar_entry child '
            . 'JOIN calendar_entry mother ON mother.id = child.parent_entry_id '
            . 'JOIN season s ON s.id = child.season_id '
            . 'WHERE child.club_id = ? AND child.period_type = \'holiday\'',
            [$club->getId()],
        );
        self::assertNotSame([], $rows, 'le seed dev pose bien des semaines-enfants de vacances (reprises)');

        $violations = [];
        foreach ($rows as $row) {
            $weekMonday = new DateTimeImmutable($row['cs']);
            $weekMonday = $weekMonday->modify(\sprintf('-%d days', (int) $weekMonday->format('N') - 1));
            while ($weekMonday->format('Y-m-d') <= $row['ce']) {
                if (!HolidayWorkweekRule::covers($weekMonday->format('Y-m-d'), $row['ms'], $row['me'], $row['ss'], $row['se'])) {
                    $violations[] = \sprintf('« %s » : la semaine du %s n\'est pas entièrement en vacances', $row['title'], $weekMonday->format('Y-m-d'));
                }
                $weekMonday = $weekMonday->modify('+7 days');
            }
        }

        self::assertSame([], $violations, 'chaque semaine-enfant de vacances seedée est entièrement de vacances (lun→ven)');
    }

    /**
     * NR (tenant isolation, §7.1) — LE SEED SCOPE SES CATÉGORIES SPORTIVES AU CLUB.
     *
     * Le seed tourne sur la connexion ADMIN, qui TRAVERSE la RLS : rien n'arrête un
     * find-or-create de catégorie non scopé au club, et rien ne le signale. Si les deux
     * recherches de `SportCategory` du seeder (create-or-pass + le closure `$fetchCat`)
     * oublient le `clubId`, un club seedé sur une base où un AUTRE a déjà créé ses
     * catégories les RETROUVE par (sportId, name), n'en crée AUCUNE pour lui, et accroche
     * ses 50 équipes aux catégories d'un AUTRE club — fuite de tenant pure. En aval,
     * GET /api/sport_categories sous le jeton de ce club rend une liste VIDE (le
     * TenantFilter fait, lui, son travail) et une trentaine de scénarios Behat meurent
     * faute de catégorie pour bâtir une équipe.
     *
     * Ce test seede DEUX clubs dans la même base (dev PUIS démo) et exige : (a) chaque
     * club possède son propre jeu COMPLET de catégories ; (b) AUCUNE équipe de ces deux
     * clubs ne pointe une catégorie appartenant à un AUTRE club (jointure team →
     * sport_category, club de l'équipe == club de la catégorie).
     *
     * Falsifiable : retirer le `clubId` de l'UNE OU l'AUTRE recherche rend ce test ROUGE
     * — la seule seconde recherche non scopée suffit à accrocher les équipes ailleurs.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSeedScopesSportCategoriesToTheirOwnClub(): void
    {
        $bccl = $this->seeder->run($this->em, BcclSeedProfile::dev());
        $demo = $this->seeder->run($this->em, BcclSeedProfile::demo('demo-pass-tenant-scope'));

        self::assertNotSame($bccl->getId(), $demo->getId(), 'les deux clubs seedés sont bien distincts');

        // (a) Chaque club a son PROPRE jeu complet de catégories (le catalogue entier).
        $expectedCount = \count(CategoryCatalog::categories());
        foreach ([$bccl->getId(), $demo->getId()] as $clubId) {
            $ownCategories = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM sport_category WHERE club_id = ?',
                [$clubId],
            );
            self::assertSame($expectedCount, $ownCategories, \sprintf('le club %s possède son propre jeu complet de catégories', $clubId));

            $teamCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team WHERE club_id = ?', [$clubId]);
            self::assertGreaterThan(0, $teamCount, 'le club a bien des équipes — sinon la requête d\'isolation ci-dessous serait vide');
        }

        // (b) LA REQUÊTE QUI TRANCHE : une équipe de l'un des deux clubs dont la catégorie
        // appartient à un AUTRE club. Avec la recherche non scopée, les équipes du second
        // club seedé pointent les catégories du premier → ces lignes apparaissent → ROUGE.
        $leaks = $this->connection->fetchAllAssociative(
            'SELECT t.name AS team, t.club_id AS team_club, sc.club_id AS category_club '
            . 'FROM team t JOIN sport_category sc ON sc.id = t.sport_category_id '
            . 'WHERE t.club_id IN (?, ?) AND sc.club_id <> t.club_id',
            [$bccl->getId(), $demo->getId()],
        );
        self::assertSame([], $leaks, 'aucune équipe ne pointe une catégorie appartenant à un autre club');
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
     * @return array{clubs:int, teams:int, slots:int, reservations:int, schedules:int, slotTemplates:int, clubUsers:int, calendarEntries:int, schedulePlans:int, sharedBlocks:int, sharedBlockTeams:int, teamLinks:int, venuePeriodOverrides:int, teamPeriodOverrides:int, constraintPeriodOverrides:int, teamMatchHabits:int, venueMatchWindows:int, opponentDirectory:int, opponentVenueLinks:int, opponentVenueSuggestions:int}
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
            // P5-13 : les gestionnaires (le principal, + d'éventuels additionnels du fichier local),
            // les deux entrées-semaines + leur mère, les plans de reprise et TOUS leurs réglages ancrés au plan
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
            // Répartition WE des matchs (profil dev) : les 32 créneaux idéaux (find-or-create sur
            // (club, saison, équipe), tag de semaine réappliqué) et les 10 fenêtres d'accès
            // (purge+recréation) entrent dans la mesure — deux runs = mêmes comptes.
            'teamMatchHabits' => $this->rowsIn('team_match_habit'),
            'venueMatchWindows' => $this->rowsIn('venue_match_window'),
            // Amorçage des adversaires (profils dev/prod) : les localisations partagées et
            // suggestions (upserts natifs ON CONFLICT) et les 101 appariements du club
            // (find-or-create par clé unique, MANUAL préservé) entrent dans la mesure — deux
            // runs = mêmes comptes (baseline test = 0, cf. amateo_test vierge de ces tables).
            'opponentDirectory' => $this->rowsIn('opponent_directory'),
            'opponentVenueLinks' => $this->rowsIn('opponent_venue_link'),
            'opponentVenueSuggestions' => $this->rowsIn('opponent_venue_suggestion'),
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
    /**
     * Les plans des DEUX enfants-segments de l'incident Matéo (milieu, fin), indexés par le début
     * de leur fenêtre — le plan vit sur les enfants, jamais sur la racine (fondateur 2026-09-05).
     *
     * @return array<string, string> début (Y-m-d) => id du plan
     */
    private function mateoIncidentChildPlanIds(string $clubId): array
    {
        /** @var list<array{s: string, plan_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT to_char(child.start_date, \'YYYY-MM-DD\') AS s, sp.id AS plan_id FROM schedule_plan sp '
            . 'JOIN calendar_entry child ON child.id = sp.calendar_entry_id '
            . 'JOIN calendar_entry root ON root.id = child.parent_entry_id '
            . 'WHERE sp.club_id = ? AND root.parent_entry_id IS NULL AND root.title LIKE ? '
            . 'ORDER BY child.start_date ASC',
            [$clubId, 'Matéo indisponible (incident)%'],
        );
        $byStart = [];
        foreach ($rows as $row) {
            $byStart[(string) $row['s']] = (string) $row['plan_id'];
        }

        return $byStart;
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
