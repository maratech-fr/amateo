<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — RMM-3 « le gardien à l'ouverture » (axe §7.1 : contrainte sémantique — le radar
 * de conflits ; sa persistance légère par visite).
 *
 * POURQUOI ce test existe : le radar est stateless — recalculé à chaque appel, il ne peut pas dire
 * ce qui a CHANGÉ « depuis ta dernière visite ». Le gardien (`POST /api/matches/module-visit`)
 * ajoute la persistance manquante : une référence figée PAR UTILISATEUR, contre laquelle chaque
 * passage se compare. Le cœur du geste, c'est l'EMPREINTE d'un conflit — son identité DURABLE : elle
 * doit rester constante tant que « c'est le même litige » et ne changer que si sa NATURE change.
 * Sans ce test, la moindre dérive du fingerprint ou de la rotation ferait clignoter des badges pour
 * rien (chaque import re-badgerait tout), ou en cacherait de vrais.
 *
 * Falsifié dans les DEUX sens : une nature changée (le match A passe d'un conflit avec B à un conflit
 * avec C) est SIGNALÉE ; une sévérité / un segment de recouvrement qui bougent SEULS, ou un
 * COMPETITION_INCOMPLETE dont le compte grossit (9/22 → 15/22), ne le sont PAS. Plus : première
 * visite muette, F5 dans la grâce (référence intacte, badges re-servis), rotation hors grâce, nouveaux
 * matchs comptés, planningChanged dans les deux sens, isolation USER + club + saison, Membre autorisé,
 * saison archivée qui stampe quand même.
 *
 * La rotation est prouvée par les EMPREINTES (aucune dépendance à l'horloge — deux stamps rapprochés
 * tombent dans la même seconde) : le vieillissement de la visite se fait en SQL brut (les requêtes
 * suivantes relisent la base, jamais un identity-map figé).
 */
#[Group('phase1')]
#[Group('security')]
final class MatchVisitDeltaParityTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Première visite : la référence est figée en SILENCE, aucun badge, même si un conflit existe déjà. */
    public function testFirstVisitIsSilent(): void
    {
        [, $season, $admin] = $this->createClub('fv');
        $this->coachOnTeams('fv', $season, [$t1 = $this->teamId('fv', 1), $t2 = $this->teamId('fv', 2)]);
        $this->homeFixture($season, $t1, '16:00');
        $this->homeFixture($season, $t2, '16:30'); // recouvre → un MATCH_MATCH existe déjà

        $out = $this->stamp($admin);
        self::assertTrue($out['firstVisit'], 'la première visite est muette');
        self::assertSame(0, $out['newFixturesCount']);
        self::assertSame([], $out['newConflictFingerprints']);
        self::assertFalse($out['planningChanged']);
    }

    /** État inchangé re-visité APRÈS la grâce → zéro « nouveau » (le conflit existant est dans la référence). */
    public function testUnchangedRevisitAfterGraceHasNothingNew(): void
    {
        [, $season, $admin] = $this->createClub('un');
        $this->coachOnTeams('un', $season, [$t1 = $this->teamId('un', 1), $t2 = $this->teamId('un', 2)]);
        $this->homeFixture($season, $t1, '16:00');
        $this->homeFixture($season, $t2, '16:30');

        $this->stamp($admin); // première visite → fige la référence (conflit compris)
        $this->expireGrace($admin, $season->getId());
        $out = $this->stamp($admin);

        self::assertFalse($out['firstVisit']);
        self::assertSame(0, $out['newFixturesCount']);
        self::assertSame([], $out['newConflictFingerprints'], 'un conflit déjà connu n\'est pas « nouveau »');
        self::assertFalse($out['planningChanged']);
    }

    /**
     * Nature changée → SIGNALÉE, et le disparu ne l'est jamais. A jouait contre B (conflit {A,B}) ;
     * après réagencement il joue contre C (conflit {A,C}) — sans qu'AUCUN match ne soit créé (on ne
     * fait que déplacer des heures). Le delta porte l'empreinte {A,C}, jamais {A,B}.
     */
    public function testChangedConflictNatureIsSignaledAndTheVanishedOneIsNot(): void
    {
        [$club, $season, $admin] = $this->createClub('nat');
        $coachId = $this->coachOnTeams('nat', $season, [
            $t1 = $this->teamId('nat', 1),
            $t2 = $this->teamId('nat', 2),
            $t3 = $this->teamId('nat', 3),
        ]);
        $fa = $this->homeFixture($season, $t1, '16:00');
        $fb = $this->homeFixture($season, $t2, '16:30'); // recouvre A → {A,B}
        $fc = $this->homeFixture($season, $t3, '20:00'); // disjoint

        $this->stamp($admin); // référence : {A,B}

        // Réagencement SANS création : B s'éloigne, C vient recouvrir A → {A,C}.
        $this->setKickoff($club->getId(), $fb, '20:00');
        $this->setKickoff($club->getId(), $fc, '16:15');

        $this->expireGrace($admin, $season->getId());
        $out = $this->stamp($admin);

        $appeared = $this->matchMatchFingerprint($coachId, $fa->getId(), $fc->getId());
        $vanished = $this->matchMatchFingerprint($coachId, $fa->getId(), $fb->getId());
        self::assertSame(0, $out['newFixturesCount'], 'aucun match créé — on n\'a fait que déplacer des heures');
        self::assertContains($appeared, $out['newConflictFingerprints'], 'le conflit de nature nouvelle est signalé');
        self::assertNotContains($vanished, $out['newConflictFingerprints'], 'le conflit disparu ne produit rien');
    }

    /** Le segment de recouvrement bouge SEUL (même paire, même coach) → l'empreinte tient → PAS signalé. */
    public function testSegmentShiftAloneIsNotSignaled(): void
    {
        [$club, $season, $admin] = $this->createClub('seg');
        $this->coachOnTeams('seg', $season, [$t1 = $this->teamId('seg', 1), $t2 = $this->teamId('seg', 2)]);
        $this->homeFixture($season, $t1, '16:00');
        $fb = $this->homeFixture($season, $t2, '16:30');

        $this->stamp($admin); // référence : {A,B}, segment ~16:30–17:45

        // On décale B : le recouvrement change, la PAIRE ne change pas → même empreinte.
        $this->setKickoff($club->getId(), $fb, '16:05');

        $this->expireGrace($admin, $season->getId());
        $out = $this->stamp($admin);

        self::assertSame([], $out['newConflictFingerprints'], 'un segment/sévérité qui bouge seul n\'est pas un conflit neuf');
    }

    /** COMPETITION_INCOMPLETE 9/22 → 15/22 : le compte grossit, l'empreinte (competitionId seul) tient → PAS signalé. */
    public function testCompetitionIncompleteCountGrowthIsNotSignaled(): void
    {
        [$club, $season, $admin] = $this->createClub('comp');
        $team = $this->teamId('comp', 1);
        $competitionId = $this->competition($club, $season, $team, expected: 22);
        for ($i = 0; $i < 9; ++$i) {
            $this->competitionFixture($season, $team, $competitionId); // HOME sans heure → seul COMPETITION_INCOMPLETE
        }

        $this->stamp($admin); // référence : COMPETITION_INCOMPLETE présent (9/22)

        for ($i = 0; $i < 6; ++$i) {
            $this->competitionFixture($season, $team, $competitionId); // 15/22 — toujours incomplet, MÊME empreinte
        }

        // On recule la référence dans le temps pour que les matchs neufs comptent sans course d'horloge.
        $this->ageReference($club->getId(), $season->getId());
        $this->expireGrace($admin, $season->getId());
        $out = $this->stamp($admin);

        self::assertGreaterThanOrEqual(6, $out['newFixturesCount'], 'les matchs neufs sont bien comptés');
        self::assertNotContains('COMPETITION_INCOMPLETE:' . $competitionId, $out['newConflictFingerprints'], 'un compte qui grossit ne re-badge pas la compétition');
    }

    /** Un match créé après la référence est COMPTÉ. */
    public function testNewFixturesAreCounted(): void
    {
        [$club, $season, $admin] = $this->createClub('cnt');
        $this->stamp($admin); // référence prise maintenant

        $this->homeFixture($season, $this->teamId('cnt', 1), '16:00');

        $this->ageReference($club->getId(), $season->getId());
        $this->expireGrace($admin, $season->getId());
        $out = $this->stamp($admin);
        self::assertSame(1, $out['newFixturesCount']);
    }

    /**
     * F5 dans la grâce → la référence NE tourne PAS : deux passages rapprochés servent les MÊMES
     * badges (idempotent). Hors grâce → la référence tourne : le badge s'éteint au passage suivant.
     * Prouvé par les EMPREINTES (le conflit apparaît après la première visite).
     */
    public function testF5WithinGraceKeepsBadgesAndOnlyGraceExpiryRotates(): void
    {
        [$season, $admin, $coachId, $t1, $t2] = $this->clubWithLatentConflict('f5');

        $first = $this->stamp($admin); // première visite → référence R0 (aucun conflit encore)
        self::assertTrue($first['firstVisit']);
        $reference = $first['referenceTakenAt'];

        // Le conflit NAÎT après la référence.
        $fa = $this->homeFixture($season, $t1, '16:00');
        $fb = $this->homeFixture($season, $t2, '16:30');
        $fingerprint = $this->matchMatchFingerprint($coachId, $fa->getId(), $fb->getId());

        // Deux F5 dans la grâce : même référence R0, mêmes badges.
        $a = $this->stamp($admin);
        $b = $this->stamp($admin);
        self::assertSame([$fingerprint], $a['newConflictFingerprints']);
        self::assertSame([$fingerprint], $b['newConflictFingerprints'], 'F5 dans la grâce re-sert le même badge');
        self::assertSame($reference, $a['referenceTakenAt'], 'la référence n\'a pas tourné');
        self::assertSame($reference, $b['referenceTakenAt']);

        // Hors grâce : NOUVELLE visite → la référence tourne sur l'état courant.
        $this->expireGrace($admin, $season->getId());
        $rotated = $this->stamp($admin);
        self::assertSame([$fingerprint], $rotated['newConflictFingerprints'], 'la visite neuve voit encore le badge, puis rotationne');

        // Le passage suivant (dans la grâce de la visite neuve) : le badge est éteint.
        $after = $this->stamp($admin);
        self::assertSame([], $after['newConflictFingerprints'], 'après rotation, le badge ne revient pas');
    }

    /** Isolation USER : le stamp d'Anna n'éteint pas le delta de Mateo (chacun sa référence). */
    public function testUserIsolation(): void
    {
        [$season, $anna, $coachId, $t1, $t2] = $this->clubWithLatentConflict('iso');
        $club = $this->em->getRepository(Club::class)->find($this->clubIdOf($anna));
        self::assertInstanceOf(Club::class, $club);
        $mateo = $this->addMember($club, 'admin');

        $this->stamp($anna);  // référence d'Anna
        $this->stamp($mateo); // référence de Mateo

        $fa = $this->homeFixture($season, $t1, '16:00');
        $fb = $this->homeFixture($season, $t2, '16:30');
        $fingerprint = $this->matchMatchFingerprint($coachId, $fa->getId(), $fb->getId());

        // Anna passe (hors grâce) et rotationne SA référence.
        $this->expireGrace($anna, $season->getId());
        $annaOut = $this->stamp($anna);
        self::assertSame([$fingerprint], $annaOut['newConflictFingerprints']);

        // Mateo, lui, voit toujours le conflit neuf : le stamp d'Anna n'a pas touché sa référence.
        $this->expireGrace($mateo, $season->getId());
        $mateoOut = $this->stamp($mateo);
        self::assertSame([$fingerprint], $mateoOut['newConflictFingerprints'], 'la visite d\'Anna n\'éteint pas le delta de Mateo');
    }

    /** Isolation CLUB : un conflit d'un autre club ne fuit pas dans le delta. */
    public function testClubIsolation(): void
    {
        [, $seasonA, $adminA] = $this->createClub('ca');
        [$seasonB, , $coachB, $tb1, $tb2] = $this->clubWithLatentConflict('cb');

        $this->stamp($adminA); // référence du club A (aucun conflit)

        // Un conflit naît dans le club B — invisible au club A.
        $fb1 = $this->homeFixture($seasonB, $tb1, '16:00');
        $fb2 = $this->homeFixture($seasonB, $tb2, '16:30');
        $foreign = $this->matchMatchFingerprint($coachB, $fb1->getId(), $fb2->getId());

        $this->expireGrace($adminA, $seasonA->getId());
        $out = $this->stamp($adminA);
        self::assertNotContains($foreign, $out['newConflictFingerprints'], 'le conflit d\'un autre club ne fuit pas');
        self::assertSame([], $out['newConflictFingerprints']);
    }

    /** Isolation SAISON (X-Season-Id) : la visite est PAR saison — stamper une saison ne stampe pas l'autre. */
    public function testSeasonIsolationViaHeader(): void
    {
        [$club, $current, $admin] = $this->createClub('sea');
        $older = $this->addOlderSeason($club);

        $this->stamp($admin); // première visite de la saison COURANTE

        // Stamper l'AUTRE saison : c'est une (club, saison, user) différente → première visite à nouveau.
        $other = $this->stamp($admin, $older->getId());
        self::assertTrue($other['firstVisit'], 'la référence est par saison : l\'autre saison est vierge');

        // Deux lignes distinctes existent bien.
        self::assertTrue($this->visitExists($club->getId(), $admin, $current->getId()));
        self::assertTrue($this->visitExists($club->getId(), $admin, $older->getId()));
    }

    /** Aucune garde management : un Membre (rôle non-management) stampe sa visite. */
    public function testMemberIsAllowed(): void
    {
        [$club] = $this->createClub('mem');
        $member = $this->addMember($club, 'coach'); // rôle non-management

        $out = $this->stamp($member);
        self::assertTrue($out['firstVisit'], 'le Membre stampe comme un gestionnaire');
    }

    /** Saison archivée : le stamp est ÉCRIT quand même (bookkeeping utilisateur, pas une mutation de planning). */
    public function testArchivedSeasonStampsAnyway(): void
    {
        [$club, , $admin] = $this->createClub('arc');
        $older = $this->addOlderSeason($club); // année antérieure → archivée / lecture seule

        $out = $this->stamp($admin, $older->getId());
        self::assertTrue($out['firstVisit']);
        self::assertTrue($this->visitExists($club->getId(), $admin, $older->getId()), 'la visite est écrite même sur une saison archivée');
    }

    /**
     * planningChanged dans les DEUX sens : une nouvelle COMPLETED sans repointage l'allume ; un
     * pointeur qui bouge l'allume ; sans changement (ni nouvelle version ni repointage) il est éteint.
     */
    public function testPlanningChangedBothWays(): void
    {
        [$club, $season, $admin] = $this->createClub('plan');
        $this->stamp($admin); // référence : pas de plan (chosen=null, latestCompleted=null)

        // (a) Une nouvelle version COMPLETED, SANS repointer → planningChanged.
        $plan = $this->seasonPlan($club, $season);
        $scheduleId = $this->completedSchedule($club, $season, $plan->getId());
        $this->expireGrace($admin, $season->getId());
        $afterCompleted = $this->stamp($admin);
        self::assertTrue($afterCompleted['planningChanged'], 'une nouvelle COMPLETED bouge le planning');

        // (c) Rien ne bouge → false (la référence a tourné sur l'état d'après (a)).
        $this->expireGrace($admin, $season->getId());
        $unchanged = $this->stamp($admin);
        self::assertFalse($unchanged['planningChanged'], 'sans changement, planningChanged est faux');

        // (b) Le pointeur bouge → planningChanged.
        $this->pointSeasonPlan($club->getId(), $season->getId(), $scheduleId);
        $this->expireGrace($admin, $season->getId());
        $afterPointer = $this->stamp($admin);
        self::assertTrue($afterPointer['planningChanged'], 'un repointage bouge le planning');

        // Et un état identique (aucun mouvement) → false.
        $this->expireGrace($admin, $season->getId());
        $afterNoop = $this->stamp($admin);
        self::assertFalse($afterNoop['planningChanged'], 'un état identique ne re-signale pas');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    // --- gestes HTTP ---------------------------------------------------------

    /**
     * @return array{firstVisit: bool, newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool, referenceTakenAt: string}
     */
    private function stamp(User $user, ?string $seasonId = null): array
    {
        $server = $this->authHeaders($user);
        if (null !== $seasonId) {
            $server['HTTP_X_SEASON_ID'] = $seasonId;
        }
        $this->client->request('POST', '/api/matches/module-visit', [], [], $server);
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array{firstVisit: bool, newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool, referenceTakenAt: string} */
        return $payload;
    }

    // --- vieillissement de la visite (SQL brut → la requête suivante relit la base) -------------

    /** Recule last_opened_at de 40 min : le prochain stamp est une NOUVELLE visite (hors grâce de 30 min). */
    private function expireGrace(User $user, string $seasonId): void
    {
        $this->scopeGucToClub($this->clubIdOf($user));
        $this->conn()->executeStatement(
            'UPDATE match_module_visit SET last_opened_at = NOW() - INTERVAL \'40 minutes\' WHERE user_id = :uid AND season_id = :sid',
            ['uid' => $user->getId(), 'sid' => $seasonId],
        );
    }

    /** Recule reference_taken_at d'une heure : les matchs créés « maintenant » comptent, sans course d'horloge. */
    private function ageReference(string $clubId, string $seasonId): void
    {
        $this->scopeGucToClub($clubId);
        $this->conn()->executeStatement(
            'UPDATE match_module_visit SET reference_taken_at = NOW() - INTERVAL \'1 hour\' WHERE season_id = :sid',
            ['sid' => $seasonId],
        );
    }

    private function visitExists(string $clubId, User $user, string $seasonId): bool
    {
        $this->scopeGucToClub($clubId);

        return false !== $this->conn()->fetchOne(
            'SELECT id FROM match_module_visit WHERE user_id = :uid AND season_id = :sid',
            ['uid' => $user->getId(), 'sid' => $seasonId],
        );
    }

    private function setKickoff(string $clubId, Fixture $fixture, string $kickoff): void
    {
        $this->scopeGucToClub($clubId);
        $this->conn()->executeStatement(
            'UPDATE fixture SET kickoff_time = :k, updated_at = NOW() WHERE id = :id',
            ['k' => $kickoff . ':00', 'id' => $fixture->getId()],
        );
    }

    private function pointSeasonPlan(string $clubId, string $seasonId, string $scheduleId): void
    {
        $this->scopeGucToClub($clubId);
        $this->conn()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = :sched, updated_at = NOW() WHERE season_id = :sid AND type = \'SEASON\'',
            ['sched' => $scheduleId, 'sid' => $seasonId],
        );
    }

    private function matchMatchFingerprint(string $coachId, string $fixtureA, string $fixtureB): string
    {
        $ids = [$fixtureA, $fixtureB];
        sort($ids);

        return 'MATCH_MATCH:' . $coachId . ':' . implode(',', $ids);
    }

    private function conn(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    // --- semis ---------------------------------------------------------------

    /** @return array{0: Club, 1: Season, 2: User} */
    private function createClub(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club ' . $suffix);
        $club->setSlug('club-visit-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('visit' . $uid . '@test.com');
        $user->setFirstName('Vi');
        $user->setLastName('Sit');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season, $user];
    }

    /**
     * Un club prêt pour un MATCH_MATCH LATENT : coach MAIN sur deux équipes, SANS match encore (le
     * conflit naît quand l'appelant pose deux fixtures qui se recouvrent).
     *
     * @return array{0: Season, 1: User, 2: string, 3: string, 4: string} season, admin, coachId, teamId1, teamId2
     */
    private function clubWithLatentConflict(string $suffix): array
    {
        [, $season, $admin] = $this->createClub($suffix);
        $t1 = $this->teamId($suffix, 1);
        $t2 = $this->teamId($suffix, 2);
        $coachId = $this->coachOnTeams($suffix, $season, [$t1, $t2]);

        return [$season, $admin, $coachId, $t1, $t2];
    }

    /** Une saison d'année ANTÉRIEURE (archivée / lecture seule) du même club. */
    private function addOlderSeason(Club $club): Season
    {
        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 2;
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ARCHIVED);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('m', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail('member' . $uid . '@test.com');
        $member->setFirstName('Me');
        $member->setLastName('Mber');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
    }

    /**
     * Un coach MAIN sur plusieurs équipes du même club/saison — la source d'un MATCH_MATCH quand
     * deux de ses matchs se recouvrent.
     *
     * @param list<string> $teamIds
     *
     * @return string coachId
     */
    private function coachOnTeams(string $suffix, Season $season, array $teamIds): string
    {
        $this->scopeGucToClub($season->getClubId());
        $coach = new Coach;
        $coach->setClubId($season->getClubId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName('Coach');
        $coach->setLastName($suffix);
        $this->em->persist($coach);
        $this->em->flush();

        foreach ($teamIds as $teamId) {
            $link = new TeamCoach;
            $link->setClubId($season->getClubId());
            $link->setSeasonId($season->getId());
            $link->setTeamId($teamId);
            $link->setCoachId($coach->getId());
            $link->setRole(TeamCoachRole::MAIN);
            $this->em->persist($link);
        }
        $this->em->flush();

        return $coach->getId();
    }

    private function homeFixture(Season $season, string $teamId, string $kickoff): Fixture
    {
        $this->scopeGucToClub($season->getClubId());
        $fixture = new Fixture;
        $fixture->setClubId($season->getClubId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    /** Un match HOME SANS heure et SANS lieu, rattaché à une compétition : il ne pèse QUE sur COMPETITION_INCOMPLETE. */
    private function competitionFixture(Season $season, string $teamId, string $competitionId): void
    {
        $this->scopeGucToClub($season->getClubId());
        $fixture = new Fixture;
        $fixture->setClubId($season->getClubId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setStatus(FixtureStatus::UNPLACED);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function competition(Club $club, Season $season, string $teamId, int $expected): string
    {
        $this->scopeGucToClub($club->getId());
        $competition = new Competition;
        $competition->setClubId($club->getId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId($teamId);
        $competition->setName('Championnat');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setExpectedMatchdays($expected);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition->getId();
    }

    private function seasonPlan(Club $club, Season $season): SchedulePlan
    {
        $this->scopeGucToClub($club->getId());
        $plan = new SchedulePlan;
        $plan->setClubId($club->getId());
        $plan->setSeasonId($season->getId());
        $plan->setType(SchedulePlanType::SEASON);
        $plan->setName('Saison');
        $plan->setStartDate($season->getStartDate());
        $plan->setEndDate($season->getEndDate());
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    private function completedSchedule(Club $club, Season $season, string $planId): string
    {
        $this->scopeGucToClub($club->getId());
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setSchedulePlanId($planId);
        $schedule->setName('v1');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule->getId();
    }

    private function teamId(string $suffix, int $n): string
    {
        $hex = substr(md5($suffix . $n), 0, 12);

        return \sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), '111', '111', '111111111111');
    }

    private function clubIdOf(User $user): string
    {
        // Le user n'est membre que d'un club dans ces scénarios — sa première adhésion suffit.
        // club_user reste lisible sans GUC (policy hybride) — pas de scope nécessaire ici.
        return (string) $this->conn()->fetchOne(
            'SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1',
            ['uid' => $user->getId()],
        );
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
