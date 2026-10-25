<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chantier D (CTC) PR1 — le champ « entente (CTC) » d'une équipe fait le tour de l'API.
 *
 * Promesse métier (retour Rillieux #6, décisions fondateur 2026-10-24) : une équipe peut être
 * déclarée « en entente (CTC) » avec un ou plusieurs partenaires saisis EN TEXTE LIBRE. Jamais une
 * référence vers un autre club/organisme (aucun lien inter-clubs en V1) : le texte est le texte, le
 * serveur ne le résout contre rien. Le club traite une CTC comme une équipe à part entière — ZÉRO
 * effet moteur (prouvé à part par ScheduleConstraintBuilderCtcNeutralityTest).
 *
 * Ce test garde le CONTRAT API des deux champs (`isCtc` bool, `ctcPartner` texte ≤ 120, nullable) au
 * plus bas coût qui traverse le serveur :
 *   - POST / GET / PUT les transportent et les ré-échoent (champ absent du code = rouge) ;
 *   - normalisation : isCtc=false ⇒ ctcPartner forcé null ; trim ; vide ⇒ null ; partenaire
 *     FACULTATIF même coché ;
 *   - une équipe ENGAGÉE garde sa CTC MODIFIABLE (comme son nom, contrairement à son `level` —
 *     D-h du plan) ;
 *   - abus A1 : un UUID d'un AUTRE club passé en `ctcPartner` est servi TEL QUEL en chaîne, aucune
 *     résolution, aucune fuite cross-tenant (c'est du texte libre, pas un lien) ;
 *   - abus A2 : un partenaire de 121 caractères → 422 (pas un 500, pas une troncature silencieuse).
 *
 * ⚠ Bearer à CHAQUE requête quand l'identité doit survivre d'un appel au suivant (firewall
 * stateless, gotcha maison). JAMAIS de FLUSHALL Redis ici (DEL ciblé), APP_ENV=test explicite.
 *
 * Signatures attendues de NEO (option A — ce test est ROUGE tant qu'elles n'existent pas) :
 *   App\Entity\Team::isIsCtc(): bool / getIsCtc(): bool · setIsCtc(bool): self
 *   App\Entity\Team::getCtcPartner(): ?string · setCtcPartner(?string): self
 *   App\Dto\TeamInput::$isCtc (?bool, write) · $ctcPartner (?string, write, Assert\Length max:120)
 *   App\ApiResource\TeamResource::$isCtc (bool, read) · $ctcPartner (?string, read)
 *   TeamStateProcessor : isCtc=false ⇒ ctcPartner null ; trim ; '' ⇒ null (create + update).
 */
#[Group('integration')]
final class TeamCtcFieldTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    private Sport $sport;

    private SportCategory $sportCategory;

    private PriorityTier $priorityTier;

    private KernelBrowser $client;

    public function testCreatingATeamWithCtcEchoesBothFields(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15F Entente',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
            'ctcPartner' => 'AS Voisine',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['isCtc'], 'une équipe créée en CTC se relit en CTC');
        self::assertSame('AS Voisine', $data['ctcPartner']);
    }

    public function testATeamWithoutCtcReadsFalseAndNullPartner(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U11 Maison',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['isCtc'], 'le défaut est « pas une CTC »');
        self::assertNull($data['ctcPartner']);
    }

    public function testCtcIsOptionalEvenWhenChecked(): void
    {
        // D-c : le partenaire est FACULTATIF même case cochée — un club peut dire « c'est une
        // entente » sans nommer tout de suite avec qui.
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U13 Entente sans nom',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['isCtc']);
        self::assertNull($data['ctcPartner'], 'coché sans partenaire reste valide (null)');
    }

    public function testClearingCtcForcesThePartnerToNull(): void
    {
        // isCtc=false ⇒ ctcPartner null, même si un partenaire est encore dans le payload : décocher
        // l'entente ne laisse pas traîner un nom d'ex-partenaire.
        $client = $this->client;
        $team = $this->createTeam('U18F', isCtc: true, ctcPartner: 'AS Voisine');
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U18F',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => false,
            'ctcPartner' => 'AS Voisine',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['isCtc']);
        self::assertNull($data['ctcPartner'], 'décocher la CTC efface le partenaire');
    }

    public function testThePartnerIsTrimmed(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U17M Entente',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
            'ctcPartner' => '   AS Voisine   ',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('AS Voisine', json_decode((string) $client->getResponse()->getContent(), true)['ctcPartner']);
    }

    public function testABlankPartnerBecomesNull(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U20M Entente',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
            'ctcPartner' => '   ',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['ctcPartner'], 'un partenaire blanc (que des espaces) devient null');
    }

    public function testAnEngagedTeamCanStillChangeItsCtc(): void
    {
        // D-h — contrairement à son `level`, la CTC d'une équipe ENGAGÉE reste MODIFIABLE (comme son
        // nom) : la fédération ne connaît pas l'entente, c'est une perception interne du club.
        $client = $this->client;
        $team = $this->createTeam('U15 en championnat');
        $this->fixture($team, FixtureStatus::PLACED);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 en championnat',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
            'ctcPartner' => 'Entente des 3 Rivières',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful('la CTC d\'une équipe engagée se modifie comme son nom');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $fresh = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertTrue($fresh?->getIsCtc());
        self::assertSame('Entente des 3 Rivières', $fresh?->getCtcPartner());
    }

    public function testACtcPartnerThatLooksLikeAnotherClubUuidIsServedAsAStringUnresolved(): void
    {
        // Abus A1 — le partenaire est du TEXTE LIBRE, jamais un lien. Un UUID d'un AUTRE club glissé
        // là ne déclenche AUCUNE résolution : il ressort EXACTEMENT tel quel, aucun nom d'autre club
        // n'est divulgué, aucune requête cross-tenant. La chaîne est opaque.
        $otherClub = $this->createClub('Club Secret Voisin');
        $uuid = $otherClub->getId();

        $client = $this->client;
        $client->loginUser($this->user);
        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 curieuse',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
            'ctcPartner' => $uuid,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($uuid, $data['ctcPartner'], 'l\'UUID reste une chaîne opaque : aucune résolution');
        self::assertStringNotContainsString('Club Secret Voisin', (string) $client->getResponse()->getContent(), 'jamais le nom de l\'autre club');
    }

    public function testAPartnerLongerThanTheLimitIsRejected(): void
    {
        // Abus A2 — borne à 120 caractères (colonne varchar(120)). 121 → 422 NOMMÉ, jamais un 500 ni
        // une troncature silencieuse.
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 bavarde',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'isCtc' => true,
            'ctcPartner' => str_repeat('a', 121),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422, 'un partenaire de 121 caractères est refusé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('CTC Test Club');
        $this->club->setSlug('ctc-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('CTC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->sport = new Sport;
        $this->sport->setName('Basketball');
        $this->sport->setSlug('bball-' . $uid);
        $this->sport->setIsActive(true);
        $this->em->persist($this->sport);

        $this->user = new User;
        $this->user->setEmail('ctc' . $uid . '@test.com');
        $this->user->setFirstName('CTC');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($this->passwordHasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->sportCategory = new SportCategory;
        $this->sportCategory->setClubId($this->club->getId());
        $this->sportCategory->setSportId($this->sport->getId());
        $this->sportCategory->setName('U15');
        $this->sportCategory->setIsCustom(false);
        $this->sportCategory->setSortOrder(0);
        $this->em->persist($this->sportCategory);

        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            $this->priorityTier = $existing;
        } else {
            $this->priorityTier = new PriorityTier;
            $this->priorityTier->setId(1);
            $this->priorityTier->setLabel('S');
            $this->priorityTier->setName('Senior');
            $this->priorityTier->setColor('#FF0000');
            $this->priorityTier->setOrToolsWeight(100);
            $this->priorityTier->setDefaultMinSessions(2);
            $this->em->persist($this->priorityTier);
        }

        $this->em->flush();
    }

    private function createClub(string $name): Club
    {
        $club = new Club;
        $club->setName($name . ' ' . uniqid());
        $club->setSlug('other-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function createTeam(string $name, bool $isCtc = false, ?string $ctcPartner = null): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->sportCategory->getId());
        $team->setPriorityTierId($this->priorityTier->getId());
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $team->setIsCtc($isCtc);
        $team->setCtcPartner($ctcPartner);

        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function fixture(Team $team, FixtureStatus $status, FixtureHomeAway $homeAway = FixtureHomeAway::HOME): Fixture
    {
        $this->scopeGucToClub($this->club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('AS Voisins');
        $fixture->setStatus($status, new DateTimeImmutable);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
