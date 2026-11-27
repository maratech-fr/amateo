<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Coach;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\Gender;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLevel;
use App\Enum\VenueTravelRuleIntensity;
use App\Matches\CompetitionRegistry;
use App\Plans\PlanComposition;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonTransitionService;
use App\Tests\Support\SubscribesClub;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Le filet du lot 5 (le socle froid), posé sur le décor des demandes au moteur (références 20 et 21) :
 *
 *  20. le socle de la saison suivante, née par le VRAI geste « Préparer la saison suivante »
 *      (`SeasonTransitionService`) depuis un socle complet en vigueur : grille, occupation, réservations
 *      d'équipe et libre, groupe mutualisé, règle de bien-être, règle de trajet obligatoire, temps de
 *      trajet, passerelle, contraintes permanentes, coach-joueur, séances et plafond propres, gymnase
 *      imposé, niveau et sexe d'une équipe, entente d'une autre, jours maximum d'un coach. La PR 7 du
 *      lot fait reprendre la saison suivante sur le socle en vigueur : cette référence ne doit alors
 *      changer que de ses réservations ;
 *  21. le placement des matchs quand une équipe (U17 F), créée APRÈS la validation du socle avec son
 *      coach principal, porte un domicile à placer. La PR 5 fait lire l'en vigueur dans sa copie : une
 *      équipe née depuis reste lue en direct, et cette référence ne change pas.
 *
 * Saison suivante : le geste tire ses identifiants au hasard (`random_bytes`, aucune prise). Après lui,
 * `fixTransitionIds` remplace chaque identifiant né de la bascule par un identifiant fixe, partout où il
 * paraît (colonnes `uuid` et `json` des tables du club) : les lignes d'une table reçoivent leurs
 * identifiants dans l'ordre de leur contenu, identifiants aléatoires masqués, une table n'étant traitée
 * qu'une fois ses références nommées. Deux lignes copiées indiscernables font échouer le décor. Les plans
 * gardent leur identifiant aléatoire, comme dans le reste du décor (aucun ne paraît dans une demande).
 * Ce remplacement suppose qu'aucune clé étrangère ne vise une équipe, un gymnase, un coach ou une
 * contrainte : le lot 6 les pose ; une clé ajoutée fera échouer le remplacement, bruyamment.
 *
 * Chaque cas lit ses témoins en base AVANT la composition : un témoin absent fait échouer le test.
 *
 * S'emploie AVEC `EngineRequestDecorTrait`, `EngineRequestPlanSettingsDecorTrait` et
 * `EngineRequestTravelDecorTrait`. Consommé par `Integration/EngineRequestReferenceTest`.
 */
trait EngineRequestSocleNetDecorTrait
{
    use SubscribesClub;

    /** La saison suivante née de la bascule, et la version brouillon de son socle. */
    private const string ER_TRANSITION_SEASON = '5ea50000-0000-4000-8000-000000000020';
    private const string ER_TRANSITION_SOCLE_V1 = '5c4e0000-0000-4000-8000-000000000020';
    /** Préfixe des identifiants fixés après la bascule. */
    private const string ER_TRANSITION_PREFIX = 'b020';
    /** Le jour du geste : dans la fenêtre de préparation (à partir du 1er mai), saison 2025-2026 en cours. */
    private const string ER_TRANSITION_DAY = '2026-05-04T12:00:00+00:00';

    private const string ER_TEAM_U17_AFTER_VALIDATION = '7ea00000-0000-4000-8000-000000000210';

    /**
     * Référence 20 : le décor complet de la saison en cours, son socle en vigueur, puis la bascule et un
     * brouillon du socle de la saison suivante. Rend la version à générer.
     */
    private function seedEngineRequestTransitionedSocle(): Schedule
    {
        $em = $this->erEm();
        $this->seedEngineRequestCompleteSocle();
        $this->seedEngineRequestTravelOfCurrentSeason();
        $em->persist((new VenueTravelRuleSetting)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)->setIntensity(VenueTravelRuleIntensity::MANDATORY));
        $u13 = $em->find(Team::class, self::ER_TEAM_U13);
        $u15 = $em->find(Team::class, self::ER_TEAM_U15);
        $sm1 = $em->find(Team::class, self::ER_TEAM_SM1);
        $coachB = $em->find(Coach::class, self::ER_COACH_B);
        Assert::assertInstanceOf(Team::class, $u13);
        Assert::assertInstanceOf(Team::class, $u15);
        Assert::assertInstanceOf(Team::class, $sm1);
        Assert::assertInstanceOf(Coach::class, $coachB);
        $u13->setLevel(TeamLevel::DEPARTEMENTAL)->setGender(Gender::F);
        $u15->setForcedVenueId(self::ER_VENUE_SUD);
        $sm1->setCtcPartner('Club voisin');
        $coachB->setMaxDaysOverride(2);
        $em->flush();

        // Le socle en vigueur : V1 terminée, pointée et chargée, un verrou.
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
            [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
            [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
        $this->assertTransitionedSocleWitnesses();

        // La saison que la bascule crée est réglée : le décor porte la copie, pas le contrôle du paiement.
        $this->subscribeClub(self::ER_CLUB, 2026, 'beta');
        $this->scopeGucToClub(self::ER_CLUB);
        $source = $em->find(Season::class, self::ER_SEASON);
        Assert::assertInstanceOf(Season::class, $source);
        $next = static::getContainer()->get(SeasonTransitionService::class)->transition($source, new DateTimeImmutable(self::ER_TRANSITION_DAY));
        $this->scopeGucToClub(self::ER_CLUB);
        $this->fixTransitionIds($next->getId());
        $em->clear();

        $provisioner = static::getContainer()->get(SchedulePlanProvisioner::class);
        $planId = $provisioner->ensureSeasonPlanId(self::ER_TRANSITION_SEASON);
        Assert::assertIsString($planId, 'la saison suivante a son plan de saison');
        $plan = $em->getRepository(SchedulePlan::class)->find($planId);
        Assert::assertInstanceOf(SchedulePlan::class, $plan);
        $schedule = (new Schedule)->setId(self::ER_TRANSITION_SOCLE_V1)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_TRANSITION_SEASON)
            ->setName('Version 20')->setStatus(ScheduleStatus::DRAFT)->setSchedulePlanId($planId);
        $em->persist($schedule);
        $provisioner->linkSchedule($schedule);
        $em->flush();

        return $schedule;
    }

    /**
     * Référence 21 : après la validation du socle (`seedSocleInForce`) et les matchs du décor, l'équipe
     * U17 F est créée avec son coach principal, et porte un domicile à placer et un extérieur.
     */
    private function seedEngineRequestTeamCreatedAfterValidation(): void
    {
        $em = $this->erEm();
        $em->persist((new Team)->setId(self::ER_TEAM_U17_AFTER_VALIDATION)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSportCategoryId(self::ER_CATEGORY)->setPriorityTierId(2)->setName('U17 F')->setIsActive(true));
        $em->flush();
        // Comme une saisie : la porte pose sa ligne dans le plan de saison.
        static::getContainer()->get(PlanComposition::class)->ensureLines(self::ER_SEASON);
        $em->persist((new TeamCoach)->setId(self::erId('7c0a', 210))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setTeamId(self::ER_TEAM_U17_AFTER_VALIDATION)->setCoachId(self::ER_COACH_B)->setRole(TeamCoachRole::MAIN));
        $competition = (new Competition)->setId(self::erId('c0e9', 210))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setName('Championnat U17')->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $em->persist($competition);
        static::getContainer()->get(CompetitionRegistry::class)->engage($competition, self::ER_TEAM_U17_AFTER_VALIDATION);
        $em->flush();
        foreach ([[211, '2026-01-24', FixtureHomeAway::HOME, null], [212, '2026-01-31', FixtureHomeAway::AWAY, '15:00']] as [$n, $date, $homeAway, $kickoff]) {
            $fixture = (new Fixture)->setId(self::erId('f1c7', $n));
            $fixture->setClubId(self::ER_CLUB);
            $fixture->setSeasonId(self::ER_SEASON);
            $fixture->setTeamId(self::ER_TEAM_U17_AFTER_VALIDATION);
            $fixture->setCompetitionId(self::erId('c0e9', 210));
            $fixture->setMatchDate(new DateTimeImmutable($date));
            $fixture->setHomeAway($homeAway);
            $fixture->setOpponentLabel('Adversaire ' . $n);
            $fixture->setKickoffTime(null === $kickoff ? null : new DateTimeImmutable($kickoff));
            $em->persist($fixture);
        }
        $em->flush();
    }

    /** Témoins de la référence 21 : l'équipe n'a aucune séance dans le socle en vigueur, elle a son coach et un domicile à placer. */
    private function assertTeamCreatedAfterValidationWitnesses(): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $connection = $this->erEm()->getConnection();
        Assert::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM schedule_slot_template s JOIN schedule_plan p ON p.chosen_schedule_id = s.schedule_id WHERE p.type = \'SEASON\' AND s.team_id = :t',
            ['t' => self::ER_TEAM_U17_AFTER_VALIDATION],
        ), 'témoin 21 : U17 F n\'a aucune séance dans le socle en vigueur');
        Assert::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM schedule_plan WHERE type = \'SEASON\' AND season_id = :s AND chosen_schedule_id IS NOT NULL',
            ['s' => self::ER_SEASON],
        ), 'témoin 21 : le socle est en vigueur');
        Assert::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM team_coach WHERE team_id = :t AND role = \'MAIN\'',
            ['t' => self::ER_TEAM_U17_AFTER_VALIDATION],
        ), 'témoin 21 : U17 F a son coach principal');
        Assert::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM fixture WHERE team_id = :t AND home_away = \'HOME\' AND status = \'UNPLACED\'',
            ['t' => self::ER_TEAM_U17_AFTER_VALIDATION],
        ), 'témoin 21 : U17 F a un domicile à placer');
    }

    /** Témoins de la référence 20, lus sur la saison en cours avant la bascule : le socle complet est en vigueur. */
    private function assertTransitionedSocleWitnesses(): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $connection = $this->erEm()->getConnection();
        $plan = $this->erSeasonPlanId();
        foreach ([
            'reservation' => 2,
            'shared_training_block' => 1,
            'venue_occupancy' => 1,
            'implicit_rule_setting' => 1,
        ] as $table => $expected) {
            Assert::assertSame($expected, (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE schedule_plan_id = :p', $table), ['p' => $plan]), \sprintf('témoin 20 : %s du socle', $table));
        }
        foreach ([
            'SELECT COUNT(*) FROM venue_travel_rule_setting WHERE season_id = :s' => 1,
            'SELECT COUNT(*) FROM team_link WHERE season_id = :s' => 1,
            'SELECT COUNT(*) FROM coach_player_membership WHERE season_id = :s' => 1,
            'SELECT COUNT(*) FROM team WHERE season_id = :s AND forced_venue_id IS NOT NULL' => 1,
            'SELECT COUNT(*) FROM team WHERE season_id = :s AND ctc_partner IS NOT NULL' => 1,
            'SELECT COUNT(*) FROM "constraint" WHERE season_id = :s AND calendar_entry_id IS NULL' => 4,
            'SELECT COUNT(*) FROM schedule_plan WHERE season_id = :s AND type = \'SEASON\' AND chosen_schedule_id IS NOT NULL' => 1,
        ] as $sql => $expected) {
            Assert::assertSame($expected, (int) $connection->fetchOne($sql, ['s' => self::ER_SEASON]), 'témoin 20 : ' . $sql);
        }
    }

    /**
     * Remplace chaque identifiant né de la bascule (la saison suivante et les lignes qui la portent, sauf
     * ses plans) par un identifiant fixe, dans toutes les colonnes `uuid` et `json` des tables du club.
     */
    private function fixTransitionIds(string $nextSeasonId): void
    {
        $connection = $this->erEm()->getConnection();
        $tables = $connection->fetchFirstColumn(
            'SELECT c.table_name FROM information_schema.columns c'
            . ' JOIN information_schema.columns i ON i.table_schema = c.table_schema AND i.table_name = c.table_name AND i.column_name = \'id\' AND i.data_type = \'uuid\''
            . ' WHERE c.table_schema = \'public\' AND c.column_name = \'season_id\' AND c.table_name <> \'schedule_plan\' ORDER BY c.table_name',
        );
        /** @var array<string, array<string, string>> $pending table → [id → contenu sans identifiant ni horodatage] */
        $pending = [];
        foreach ($tables as $table) {
            $rows = $connection->fetchAllKeyValue(
                \sprintf('SELECT id::text, (to_jsonb(t) - \'id\' - \'created_at\' - \'updated_at\' - \'version\')::text FROM public.%s t WHERE season_id = :s', $connection->quoteSingleIdentifier((string) $table)),
                ['s' => $nextSeasonId],
            );
            if ([] !== $rows) {
                $pending[(string) $table] = array_map(strval(...), $rows);
            }
        }
        $born = [$nextSeasonId => true];
        foreach ($pending as $rows) {
            foreach (array_keys($rows) as $id) {
                $born[(string) $id] = true;
            }
        }
        $map = [$nextSeasonId => self::ER_TRANSITION_SEASON];
        $n = 0;
        while ([] !== $pending) {
            $progress = false;
            foreach ($pending as $table => $rows) {
                $keys = [];
                foreach ($rows as $id => $content) {
                    preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $content, $found);
                    foreach ($found[0] as $uuid) {
                        if (isset($born[$uuid]) && !isset($map[$uuid])) {
                            continue 3; // une référence pas encore fixée : la table attend son tour
                        }
                    }
                    $keys[(string) $id] = (string) preg_replace_callback(
                        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
                        static fn (array $m): string => $map[$m[0]] ?? (1 === preg_match('/^[0-9a-f]{4}0000-0000-4000-8000-\d{12}$/', $m[0]) ? $m[0] : '~'),
                        $content,
                    );
                }
                Assert::assertSame(\count($keys), \count(array_unique($keys)), \sprintf('%s : deux lignes copiées par la bascule sont indiscernables, le décor doit les distinguer', $table));
                asort($keys, \SORT_STRING);
                foreach (array_keys($keys) as $id) {
                    $map[$id] = self::erId(self::ER_TRANSITION_PREFIX, ++$n);
                }
                unset($pending[$table]);
                $progress = true;
            }
            Assert::assertTrue($progress, 'les lignes nées de la bascule se référencent en cycle : aucun ordre fixe possible');
        }

        $columns = $connection->fetchAllAssociative(
            'SELECT c.table_name, c.column_name, c.data_type FROM information_schema.columns c'
            . ' WHERE c.table_schema = \'public\' AND c.data_type IN (\'uuid\', \'json\', \'jsonb\')'
            . ' AND EXISTS (SELECT 1 FROM information_schema.columns k WHERE k.table_schema = \'public\' AND k.table_name = c.table_name AND k.column_name = \'club_id\')'
            . ' AND has_table_privilege(current_user, quote_ident(c.table_schema) || \'.\' || quote_ident(c.table_name), \'UPDATE\')'
            . ' ORDER BY c.table_name, c.column_name',
        );
        $values = implode(', ', array_fill(0, \count($map), '(?, ?)'));
        $params = [];
        foreach ($map as $old => $new) {
            $params[] = $old;
            $params[] = $new;
        }
        $alternation = implode('|', array_keys($map));
        foreach ($columns as $column) {
            $table = $connection->quoteSingleIdentifier((string) $column['table_name']);
            $name = $connection->quoteSingleIdentifier((string) $column['column_name']);
            if ('uuid' === $column['data_type']) {
                $connection->executeStatement(
                    \sprintf('UPDATE public.%1$s t SET %2$s = m.n::uuid FROM (VALUES %3$s) AS m(o, n) WHERE t.%2$s = m.o::uuid', $table, $name, $values),
                    $params,
                );

                continue;
            }
            $rows = $connection->fetchAllKeyValue(
                \sprintf('SELECT ctid::text, %1$s::text FROM public.%2$s WHERE %1$s::text ~ :alt', $name, $table),
                ['alt' => $alternation],
            );
            foreach ($rows as $ctid => $json) {
                $connection->executeStatement(
                    \sprintf('UPDATE public.%1$s SET %2$s = ?::%3$s WHERE ctid = ?::tid', $table, $name, (string) $column['data_type']),
                    [strtr((string) $json, $map), $ctid],
                );
            }
        }
        // L'invariant que la bascule pose sur une passerelle (équipe A < équipe B) suit les identifiants
        // fixés : rangé sur les identifiants tirés au hasard, il ne tiendrait plus qu'une fois sur deux.
        $connection->executeStatement(
            'UPDATE team_link SET team_a_id = team_b_id, team_b_id = team_a_id WHERE season_id = :s AND team_a_id > team_b_id',
            ['s' => self::ER_TRANSITION_SEASON],
        );
        Assert::assertSame(self::ER_TRANSITION_SEASON, $connection->fetchOne('SELECT id::text FROM season WHERE id = :s', ['s' => self::ER_TRANSITION_SEASON]), 'la saison suivante porte son identifiant fixe');
    }
}
