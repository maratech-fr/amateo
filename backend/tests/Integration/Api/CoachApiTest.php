<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BW2 — Coach.isEmployee must be writable via the API (the recap counts salaried
 * coaches). It used to exist on the entity but be dropped by the input DTO.
 */
#[Group('integration')]
final class CoachApiTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testIsEmployeeIsWritable(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/api/coaches', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['firstName' => 'Jean', 'lastName' => 'Dupont', 'isEmployee' => true], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['isEmployee']);
    }

    /**
     * P4-51 — le cycle complet du plafond : poser, puis RETIRER.
     *
     * ⚠ Le retrait est le cas piégeux : le PUT est partiel (null = « inchangé »), donc null
     * ne peut pas le porter — un champ vidé à l'écran laisserait le plafond en base pour
     * toujours. `0` est la sentinelle « retirer », traduite en null par le processor.
     * Sans ce test, le geste « enlever le plafond » pourrait mourir sans qu'aucune suite
     * ne le voie : poser et lire resteraient verts.
     */
    public function testTheCapCanBeSetAndRemoved(): void
    {
        // ⚠ Bearer à CHAQUE requête : le firewall est stateless, loginUser ne survit
        // qu'au premier appel (mesuré : 401 « JWT Token not found » dès le PUT).
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $manager->create($this->user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];

        $this->client->request('POST', '/api/coaches', [], [], $headers, json_encode(['firstName' => 'Matthieu', 'maxDaysOverride' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $coach = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(3, $coach['maxDaysOverride'], 'le plafond posé à la création doit être lu');

        // Un PUT qui ne parle PAS du plafond ne doit pas le toucher (partial PUT).
        $this->client->request('PUT', '/api/coaches/' . $coach['id'], [], [], $headers, json_encode(['firstName' => 'Matthieu', 'isEmployee' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame(3, json_decode((string) $this->client->getResponse()->getContent(), true)['maxDaysOverride'], 'un PUT muet sur le plafond ne doit pas l\'effacer');

        // Le retrait : 0 → null.
        $this->client->request('PUT', '/api/coaches/' . $coach['id'], [], [], $headers, json_encode(['firstName' => 'Matthieu', 'maxDaysOverride' => 0], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['maxDaysOverride'], '0 doit RETIRER le plafond — null signifiant « inchangé », c\'est le seul chemin de retrait');

        // Hors bornes : refusé, jamais silencieusement corrigé.
        $this->client->request('PUT', '/api/coaches/' . $coach['id'], [], [], $headers, json_encode(['firstName' => 'Matthieu', 'maxDaysOverride' => 9], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un plafond hors bornes (1..6) doit être refusé');
    }

    /**
     * P4-311 — le genre : défaut UNSPECIFIED à la création, puis le cycle PUT (poser,
     * garder sur un PUT muet, refuser une valeur hors enum). Le genre accorde les
     * libellés côté front ; sans ce test, un défaut raté ou un PUT partiel qui écrase
     * le genre passerait inaperçu.
     */
    public function testGenderDefaultsToUnspecifiedAndIsWritable(): void
    {
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $manager->create($this->user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];

        // Création sans genre → UNSPECIFIED (double forme à l'écran).
        $this->client->request('POST', '/api/coaches', [], [], $headers, json_encode(['firstName' => 'Anna', 'lastName' => 'Durand'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $coach = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('UNSPECIFIED', $coach['gender'], 'un coach créé sans genre doit être « non précisé »');

        // PUT qui pose le genre.
        $this->client->request('PUT', '/api/coaches/' . $coach['id'], [], [], $headers, json_encode(['firstName' => 'Anna', 'gender' => 'FEMALE'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('FEMALE', json_decode((string) $this->client->getResponse()->getContent(), true)['gender']);

        // PUT muet sur le genre : ne doit pas le toucher (PUT partiel, null = inchangé).
        $this->client->request('PUT', '/api/coaches/' . $coach['id'], [], [], $headers, json_encode(['firstName' => 'Anna', 'isEmployee' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('FEMALE', json_decode((string) $this->client->getResponse()->getContent(), true)['gender'], 'un PUT muet sur le genre ne doit pas le réinitialiser');

        // Valeur hors enum : refusée, jamais corrigée en silence.
        $this->client->request('PUT', '/api/coaches/' . $coach['id'], [], [], $headers, json_encode(['firstName' => 'Anna', 'gender' => 'OTHER'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un genre hors enum doit être refusé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Coach Test Club');
        $this->club->setSlug('coach-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('CCH' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('coach' . $uid . '@test.com');
        $this->user->setFirstName('Coach');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($this->club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->em->flush();
    }
}
