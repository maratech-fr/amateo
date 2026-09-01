<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\SeasonStatus;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
final class ConstraintApiTest extends WebTestCase
{
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    /** @return iterable<string, array{string, string}> */
    public static function invalidEnumValues(): iterable
    {
        yield 'family' => ['family', 'DAYS'];
        yield 'scope' => ['scope', 'CLUBS'];
        yield 'ruleType' => ['ruleType', 'HARDER'];
    }

    public function testCreateConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'No Saturday Practice',
            'description' => 'Teams cannot practice on Saturdays',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'isActive' => true,
            'sortOrder' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertSame('No Saturday Practice', $data['name']);
        self::assertSame('CLUB', $data['scope']);
        self::assertSame('DAY', $data['family']);
        self::assertSame('HARD', $data['ruleType']);
        self::assertSame(['forbiddenDays' => [6]], $data['config']);
        self::assertTrue($data['isActive']);
        self::assertSame(1, $data['sortOrder']);
    }

    /**
     * AUD-BCK-12 — axe *constraint semantics* : une valeur d'enum fautive est REFUSÉE,
     * jamais silencieusement corrigée.
     *
     * Le finding annonçait un repli silencieux (`family` fautif → TIME) atteignable par
     * l'API. Mesure faite, il ne l'est pas : `ConstraintInput` porte `Assert\Choice` sur
     * les trois champs et le validateur rend 422 avant le processeur. Ce test épingle la
     * garantie qui compte — celle du bord — plutôt que le détail d'implémentation qui la
     * rend vraie.
     *
     * ⚑ Ce qui serait perdu sans elle : « DAYS » deviendrait une contrainte TIME,
     * enregistrée et envoyée au solveur. Le gestionnaire poserait une règle de JOUR et
     * obtiendrait une règle d'HEURE — honorée, cohérente, et fausse. C'est le motif
     * « déclaré ≠ effectif » que SEC-13 a tué sur `config`.
     *
     * ⚠ Ce test tourne dans `unit-tests`, PAS dans le job `blocking-tests` (§4) : il
     * bloque le merge via le contexte requis « Unit Tests », mais ne gate pas
     * `build-docker` et son verdict tombe après celui du gate.
     */
    #[DataProvider('invalidEnumValues')]
    public function testAnInvalidEnumValueIsRefusedNeverSilentlyCoerced(string $field, string $value): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $payload = [
            'name' => 'Typo',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'isActive' => true,
            'sortOrder' => 1,
        ];
        $payload[$field] = $value;

        $client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode(), \sprintf(
            'Une valeur « %s » sur %s doit être REFUSÉE. Si elle passe, elle a été corrigée en silence : '
            . 'la contrainte enregistrée ne sera pas celle que le gestionnaire a demandée.',
            $value,
            $field,
        ));
        self::assertStringContainsString($field, (string) $client->getResponse()->getContent(), 'La réponse doit NOMMER le champ fautif — sans quoi la typo se corrige à l\'aveugle.');
    }

    /**
     * AUD-BCK-13 — un gymnase inconnu dans le `config` est REFUSÉ à l'écriture.
     *
     * ⚑ Mesuré côté moteur avant d'écrire le correctif : un `forcedVenueId` qui ne
     * correspond à aucun gymnase fait forcer à 0 TOUTES les affectations de l'équipe
     * (« quand un gymnase est imposé, tous les autres sont fixés à 0 » — et aucun n'est
     * celui-là). Le solve rend `completed` avec **zéro créneau**, l'équipe absente.
     *
     * ⚠ Et le diagnostic désigne les mauvaises causes : « déjà occupés par des équipes plus
     * prioritaires, ou en conflit avec ses contraintes (coach indisponible, gymnase fermé,
     * jour interdit) ». Quatre pistes, toutes fausses. Le gestionnaire chercherait une
     * indisponibilité qui n'existe pas.
     *
     * Le refus à l'écriture supprime l'état plutôt que d'espérer qu'un diagnostic le
     * rattrape — d'autant que celui-ci, ici, ment.
     */
    public function testAConstraintCannotImposeAVenueThatDoesNotExist(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Impose un gymnase fantôme',
            'scope' => 'TEAM',
            'scopeTargetId' => '11111111-1111-4111-8111-111111111111',
            'family' => 'FACILITY',
            'ruleType' => 'HARD',
            'config' => ['forcedVenueId' => '00000000-0000-4000-8000-000000000000'],
            'isActive' => true,
            'sortOrder' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Un gymnase inexistant doit être refusé : la contrainte rendrait l\'équipe impossible à placer, et le diagnostic du moteur accuserait autre chose.');
        self::assertStringContainsString('forcedVenueId', (string) $client->getResponse()->getContent(), 'La réponse doit NOMMER la clé fautive.');
    }

    /**
     * P2-29 — la NOUVELLE forme de ciblage (`targetTags` INTERSECTION, `excludeTags` UNION
     * soustraite) est acceptée à l'écriture quand elle désigne au moins une équipe.
     */
    public function testNewFormTargetTagsIsAcceptedWhenItResolves(): void
    {
        $this->createTeamWithGender('SM', Gender::M);

        $status = $this->postConstraint([
            'name' => 'Masculins · pas le dimanche',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'PREFERRED',
            'config' => ['targetTags' => ['MASCULINE'], 'forbiddenDays' => [7]],
        ]);

        self::assertSame(201, $status, 'un ciblage qui désigne une équipe passe');
    }

    /**
     * P2-29 D10 — un groupe INCONNU du club est refusé à l'écriture (nouvelle forme).
     */
    public function testUnknownTagInNewFormIsRefused(): void
    {
        $status = $this->postConstraint([
            'name' => 'Groupe fantôme',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'PREFERRED',
            'config' => ['targetTags' => ['CE_GROUPE_N_EXISTE_PAS'], 'forbiddenDays' => [7]],
        ]);

        self::assertSame(422, $status, 'un groupe inconnu doit être refusé');
        self::assertStringContainsString('CE_GROUPE_N_EXISTE_PAS', (string) $this->client->getResponse()->getContent());
    }

    /**
     * P2-29 D10 — une résolution VIDE contre la saison (ici une intersection de deux tags
     * qu'aucune équipe ne porte ensemble) est refusée, en nommant les groupes.
     */
    public function testEmptyIntersectionIsRefused(): void
    {
        $this->createTeamWithGender('SM', Gender::M);
        $this->createTeamWithGender('SF', Gender::F);

        $status = $this->postConstraint([
            'name' => 'À la fois masculin et féminin',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'PREFERRED',
            'config' => ['targetTags' => ['MASCULINE', 'FEMININE'], 'forbiddenDays' => [7]],
        ]);

        self::assertSame(422, $status, 'une intersection vide n\'a aucun effet → refus');
        self::assertStringContainsString('aucune équipe', (string) $this->client->getResponse()->getContent());
    }

    /**
     * P2-29 D7 — mélanger l'ancienne forme (`targetTag`) et la nouvelle (`targetTags`) est
     * refusé : jamais d'ambiguïté silencieuse sur la cible.
     */
    public function testMixingSingularAndPluralTagFormsIsRefused(): void
    {
        $status = $this->postConstraint([
            'name' => 'Mélange interdit',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'PREFERRED',
            'config' => ['targetTag' => 'MASCULINE', 'targetTags' => ['FEMININE'], 'forbiddenDays' => [7]],
        ]);

        self::assertSame(422, $status, 'targetTag + targetTags dans un même config → refus');
    }

    /**
     * P2-29 D10 — cibler ET exclure le même groupe se contredit : refus de forme.
     */
    public function testTargetingAndExcludingTheSameGroupIsRefused(): void
    {
        $status = $this->postConstraint([
            'name' => 'Ciblé et exclu',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'PREFERRED',
            'config' => ['targetTags' => ['MASCULINE', 'FEMININE'], 'excludeTags' => ['FEMININE'], 'forbiddenDays' => [7]],
        ]);

        self::assertSame(422, $status, 'un groupe à la fois ciblé et exclu → refus');
    }

    /**
     * P2-29 — RÉTRO-COMPAT stricte : le legacy `targetTag` (un seul tag) n'est PAS soumis à
     * la validation DB. Un tag inconnu y reste un NO-OP au build (jamais un 422 à l'écriture).
     */
    public function testLegacyTargetTagWithUnknownTagIsStillAccepted(): void
    {
        $status = $this->postConstraint([
            'name' => 'Ancienne forme, tag pas encore posé',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'PREFERRED',
            'config' => ['targetTag' => 'PAS_ENCORE_LA', 'forbiddenDays' => [7]],
        ]);

        self::assertSame(201, $status, 'le legacy targetTag garde son comportement — aucune validation DB');
    }

    public function testListConstraints(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $this->createConstraint('Constraint A', 'CLUB');
        $this->createConstraint('Constraint B', 'TEAM');

        $client->request('GET', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('member', $data);
        self::assertCount(2, $data['member']);
    }

    public function testGetConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('Test Constraint', 'CLUB');

        $client->request('GET', \sprintf('/api/constraints/%s', $constraint->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($constraint->getId(), $data['id']);
        self::assertSame('Test Constraint', $data['name']);
        self::assertSame('CLUB', $data['scope']);
    }

    public function testUpdateConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('Original Name', 'CLUB');

        $client->request('PUT', \sprintf('/api/constraints/%s', $constraint->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6, 7]],
            'isActive' => false,
            'sortOrder' => 5,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Updated Name', $data['name']);
        self::assertSame('Updated description', $data['description']);
        self::assertFalse($data['isActive']);
        self::assertSame(5, $data['sortOrder']);
        self::assertSame([6, 7], $data['config']['forbiddenDays']);
    }

    /**
     * P2-59 (revue sécurité #814) — garde SYMÉTRIQUE : l'interdiction de décocher une datée est
     * contournable par l'ORDRE (décocher une PERMANENTE puis la dater). On la ferme dans le
     * chemin UPDATE : dater une contrainte qui porte ≥1 décochage par plan est refusé (422),
     * sinon le décochage devient orphelin sur une datée — état que le modèle FAIT/GENÈSE proscrit.
     */
    public function testDatingAConstraintThatHasPeriodOverridesIsRefused(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('Permanente décochée', 'CLUB');
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $this->em->persist((new ConstraintPeriodOverride)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setSchedulePlanId($planId)
            ->setConstraintId($constraint->getId())
            ->setIsActive(false));
        $this->em->flush();

        $client->request('PUT', \sprintf('/api/constraints/%s', $constraint->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Permanente décochée',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'calendarEntryId' => '22222222-2222-4222-8222-222222222222',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $client->getResponse()->getStatusCode(), 'dater une contrainte qui porte un décochage par plan doit être refusé — sinon l\'override reste orpheline sur une datée.');
        self::assertStringContainsString('décochages', (string) $client->getResponse()->getContent(), 'le message doit dire au gestionnaire de retirer les décochages avant de dater.');
    }

    /**
     * P2-59 — cas NOMINAL : dater une contrainte SANS aucun décochage passe (200) et pose bien
     * le calendarEntryId. La garde ne freine que l'état contradictoire (datée + décochée).
     */
    public function testDatingAConstraintWithoutOverridesIsAccepted(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('Permanente à dater', 'CLUB');

        $client->request('PUT', \sprintf('/api/constraints/%s', $constraint->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Permanente à dater',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [6]],
            'calendarEntryId' => '22222222-2222-4222-8222-222222222222',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('22222222-2222-4222-8222-222222222222', $data['calendarEntryId']);
    }

    public function testDeleteConstraint(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $constraint = $this->createConstraint('To Delete', 'CLUB');
        $constraintId = $constraint->getId();

        $client->request('DELETE', \sprintf('/api/constraints/%s', $constraintId), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(204);

        $deleted = $this->em->getRepository(Constraint::class)->find($constraintId);
        self::assertNull($deleted);
    }

    public function testUnauthorized(): void
    {
        $this->client->request('GET', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $this->club = $this->createClub();
        $this->user = $this->createUser();
        $this->createClubUser($this->club, $this->user);
        $this->season = $this->createSeason($this->club);
    }

    private function createClub(): Club
    {
        $club = new Club;
        $club->setName('Test Club ' . uniqid());
        $club->setSlug('test-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function createUser(): User
    {
        $user = new User;
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'Password123!'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClubUser(Club $club, User $user): void
    {
        $clubUser = new ClubUser;
        $clubUser->setClubId($club->getId());
        $clubUser->setUserId($user->getId());
        $clubUser->setRole('admin');
        $clubUser->setIsActive(true);

        $this->em->persist($clubUser);
        $this->em->flush();
    }

    private function createSeason(Club $club): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    /**
     * POST /api/constraints avec un corps minimal ; renvoie le code HTTP.
     *
     * @param array<string, mixed> $body
     */
    private function postConstraint(array $body): int
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($body + ['isActive' => true, 'sortOrder' => 1], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Une équipe dont le genre fait naître son tag système (MASCULINE/FEMININE), via le
     * listener de dérivation — on ne pose jamais les tags à la main.
     */
    private function createTeamWithGender(string $name, Gender $gender): Team
    {
        $this->scopeGucToClub($this->club->getId());

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('cst-' . uniqid('', true));
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName('Cat ' . $name);
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
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name);
        $team->setGender($gender);
        $team->setSessionsPerWeek(1);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createConstraint(string $name, string $scope): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName($name);
        $constraint->setScope(ConstraintScope::from($scope));
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['forbiddenDays' => [6]]);

        if ('CLUB' !== $scope) {
            $constraint->setScopeTargetId('11111111-1111-1111-1111-111111111111');
        }

        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint;
    }
}
