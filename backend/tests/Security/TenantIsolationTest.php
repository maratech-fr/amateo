<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\ImplicitRuleSetting;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class TenantIsolationTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * NR AUD-SEC-25 (axe tenant isolation). Le client ne peut plus CHOISIR son
     * club : l'en-tête `X-Club-Id` n'est plus lu côté serveur. Un membre du
     * club A qui porte l'UUID du club B dans l'en-tête ne bascule pas vers B —
     * il ne voit QUE les données de SON club A (hier un 403, aujourd'hui une
     * assertion sur les DONNÉES : la frontière tient par le tenant dérivé du
     * JWT, pas par un refus de l'en-tête).
     *
     * Falsifiable : rendre à nouveau l'en-tête prioritaire dans
     * `TenantFilterListener::resolveClubId` ferait voir à A les données de B
     * (ou un 403 d'adhésion) — ce test rougirait.
     */
    public function testForeignClubHeaderIsIgnoredAndOnlyOwnDataIsReturned(): void
    {
        [$clubA, $clubB, $userA] = $this->createTwoClubs();
        $teamA = $this->createTeam($clubA, 'Team A');
        $this->createTeam($clubB, 'Team B');

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $clubB->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        /** @var array{member?: list<array{id: string}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $ids = array_column($data['member'] ?? [], 'id');
        self::assertSame([$teamA->getId()], $ids, 'l’en-tête d’un club étranger est ignoré : seules les données du club A du JWT remontent');
    }

    public function testUserCanAccessOwnClubData(): void
    {
        [, , $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams');
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Adhésion inactive = aucun club résolu (fail-closed AUD-BCK-10) : la requête
     * passe mais ne voit AUCUNE donnée d'un club — le filtre tenant reste éteint,
     * la collection est vide. Pas de fuite. Un écran dédié et la suppression du
     * compte à J+30 viendront ailleurs, hors de ce périmètre.
     */
    public function testInactiveMembershipSeesNoClubData(): void
    {
        [$clubA, , $userA] = $this->createTwoClubs();
        $this->createTeam($clubA, 'Team A');

        $membership = $this->em->getRepository(ClubUser::class)->findOneBy([
            'userId' => $userA->getId(),
            'clubId' => $clubA->getId(),
        ]);
        $membership->setIsActive(false);
        $this->em->flush();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams');

        self::assertResponseStatusCodeSame(200);
        /** @var array{member?: list<mixed>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $data['member'] ?? [], 'sans adhésion active, aucun club n’est résolu : zéro donnée');
    }

    public function testNoClubHeaderReturnsData(): void
    {
        [, , $userA] = $this->createTwoClubs();

        $this->client->loginUser($userA);
        $this->client->request('GET', '/api/teams');
        self::assertResponseIsSuccessful();
    }

    /**
     * NR AUD-SEC-25 (axes tenant isolation + auth & memberships). L'exploit
     * fermé : une requête ANONYME portant l'UUID d'un club (une démo) se plaçait
     * dans son contexte, car l'anti-spoof ne s'armait que `if ($user instanceof
     * User)` et l'en-tête était lu avant. Conséquence : horloge simulée d'une
     * démo → échéance publique d'un vrai club contournée. Désormais le club ne
     * vient QUE de l'utilisateur authentifié : une requête anonyme ne pose JAMAIS
     * le GUC `app.club_id`, quel que soit l'en-tête.
     *
     * On tire sur une route PUBLIC_ACCESS (`/api/health`) pour que le firewall
     * laisse passer et que le listener tourne, puis on lit le GUC posé sur la
     * connexion runtime : il doit être VIDE.
     *
     * Falsifiable : rendre à `resolveClubId` la lecture de l'en-tête → l'anonyme
     * re-poserait le GUC sur le club porté → ce test rougirait.
     */
    public function testAnonymousRequestCarryingAClubHeaderNeverSetsTheTenantContext(): void
    {
        [$clubA] = $this->createTwoClubs();

        // Aucun loginUser : requête anonyme, en-tête du club A.
        $this->client->request('GET', '/api/health', [], [], [
            'HTTP_X-Club-Id' => $clubA->getId(),
        ]);
        self::assertResponseIsSuccessful();

        $guc = (string) self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
        self::assertSame('', $guc, 'une requête anonyme ne doit jamais poser le GUC tenant, même avec un en-tête de club');
    }

    /**
     * Un réglage de règle implicite (bien-être) posé par le club A est INVISIBLE au club B : la
     * collection RÉSOLUE de B reste au défaut HARD. RLS + filtre tenant bornent la ligne au club.
     */
    public function testImplicitRuleSettingOfClubAIsInvisibleToClubB(): void
    {
        [$clubA, $clubB, $userA] = $this->createTwoClubs();

        // Un second gestionnaire, membre du club B seulement.
        $this->scopeGucToClub($clubB->getId());
        $userB = new User;
        $userB->setEmail('b' . uniqid('', true) . '@test.com');
        $userB->setFirstName('B');
        $userB->setLastName('User');
        $userB->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($userB, 'pass'));
        $this->em->persist($userB);
        $this->em->flush();
        $cuB = new ClubUser;
        $cuB->setClubId($clubB->getId());
        $cuB->setUserId($userB->getId());
        $cuB->setRole('admin');
        $cuB->setIsActive(true);
        $this->em->persist($cuB);
        // Le club B a sa propre saison courante (sinon sa collection résolue serait vide,
        // faute de saison à scoper).
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $seasonB = new Season;
        $seasonB->setClubId($clubB->getId());
        $seasonB->setName($year . '-' . ($year + 1));
        $seasonB->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $seasonB->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $seasonB->setStatus(SeasonStatus::ACTIVE);
        $seasonB->setTransitionData([]);
        $this->em->persist($seasonB);
        $this->em->flush();

        // Club A assouplit coachRestDay (écrit directement sous le contexte tenant du club A).
        $this->scopeGucToClub($clubA->getId());
        $seasonA = new Season;
        $seasonA->setClubId($clubA->getId());
        $seasonA->setName('2025-2026');
        $seasonA->setStartDate(new DateTimeImmutable('2025-08-01'));
        $seasonA->setEndDate(new DateTimeImmutable('2026-07-15'));
        $seasonA->setStatus(SeasonStatus::ACTIVE);
        $seasonA->setTransitionData([]);
        $this->em->persist($seasonA);
        $this->em->flush();
        $setting = new ImplicitRuleSetting;
        $setting->setClubId($clubA->getId());
        $setting->setSeasonId($seasonA->getId());
        $setting->setRuleKey(ImplicitRuleKey::COACH_REST_DAY);
        $setting->setIntensity(ImplicitRuleIntensity::PREFERRED);
        $this->em->persist($setting);
        $this->em->flush();

        // Le club B ne voit QUE le défaut — la ligne du club A lui est invisible.
        $this->client->loginUser($userB);
        $this->client->request('GET', '/api/implicit_rule_settings');
        self::assertResponseStatusCodeSame(200);
        /** @var array{member?: list<array{ruleKey: string, intensity: string}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $coachRest = array_values(array_filter($data['member'] ?? [], static fn (array $r): bool => 'coachRestDay' === $r['ruleKey']));
        self::assertNotEmpty($coachRest, 'la collection résolue du club B porte coachRestDay');
        self::assertSame('HARD', $coachRest[0]['intensity'], 'le club B reste au défaut — le réglage du club A ne fuit pas');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Club, 2: User} */
    private function createTwoClubs(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $clubA = new Club;
        $clubA->setName('Club A');
        $clubA->setSlug('club-a-' . $uid);
        $clubA->setTimezone('Europe/Paris');
        $clubA->setLocale('fr');
        $clubA->setOnboardingCompleted(true);
        $clubA->setFfbbClubCode('AAA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($clubA);

        $clubB = new Club;
        $clubB->setName('Club B');
        $clubB->setSlug('club-b-' . $uid);
        $clubB->setTimezone('Europe/Paris');
        $clubB->setLocale('fr');
        $clubB->setOnboardingCompleted(true);
        $clubB->setFfbbClubCode('BBB' . strtoupper(substr(md5($uid . 'b'), 0, 10)));
        $this->em->persist($clubB);

        $userA = new User;
        $userA->setEmail('a' . $uid . '@test.com');
        $userA->setFirstName('A');
        $userA->setLastName('User');
        $userA->setPasswordHash($hasher->hashPassword($userA, 'pass'));
        $this->em->persist($userA);

        $this->em->flush();

        $this->scopeGucToClub($clubA->getId());

        $cu = new ClubUser;
        $cu->setClubId($clubA->getId());
        $cu->setUserId($userA->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();

        return [$clubA, $clubB, $userA];
    }

    /**
     * Seeds a team for $club in its current season (created on the fly).
     * Writes go through the amateo_app connection → scope the GUC to $club first.
     */
    private function createTeam(Club $club, string $name): Team
    {
        $this->scopeGucToClub($club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('bball-' . uniqid('', true));
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId($tier->getId());
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
