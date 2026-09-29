<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubTravelCache;
use App\Entity\ClubUser;
use App\Entity\OpponentVenueLink;
use App\Entity\User;
use App\Enum\OpponentVenueLinkSource;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PATCH /api/club/siege : le serveur RE-géocode l'adresse via la BAN et écrit
 * adresse/CP/ville + coordonnées depuis SON hit — une latitude forgée dans le corps est
 * IGNORÉE (patron SEC-15). Adresse introuvable → 422. Le stub BAN déterministe
 * (`BanGeocodingHttpClientStub`) rend deux candidats dont le libellé écho la requête.
 */
#[Group('integration')]
final class ClubSiegeTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testSiegeIsGeocodedServerSideAndAForgedLatitudeIsIgnored(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne', 'latitude' => 99.0, 'longitude' => 99.0], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['geolocated']);
        // L'adresse rendue est le LIBELLÉ du hit BAN (le stub y ajoute « — Gymnase A »), pas le texte brut.
        self::assertStringContainsString('Gymnase A', (string) $data['address']);

        // La lat/long vient du hit BAN (45.75 / 4.85), JAMAIS de la latitude forgée du corps.
        $this->em->clear();
        $club = $this->em->getRepository(Club::class)->find($this->club->getId());
        self::assertNotNull($club);
        self::assertSame(45.75, $club->getLatitude(), 'la latitude vient de la BAN, jamais du corps (99 forgé ignoré)');
        self::assertSame(4.85, $club->getLongitude());
    }

    public function testChangingTheSiegeKeepsTheOpponentVenueLinks(): void
    {
        // Le club a déjà un siège AILLEURS (40/3) et un gymnase adverse ÉPINGLÉ (MANUAL).
        $this->scopeGucToClub($this->club->getId());
        $this->club->setLatitude(40.0)->setLongitude(3.0);
        $link = (new OpponentVenueLink)
            ->setClubId($this->club->getId())
            ->setOpponentOrganismeCode('ARA0069STALE')
            ->setFbiLabel('SALLE X')->setFbiLabelNorm('salle x')
            ->setVenueExternalRef('166900999')->setVenueLabel('Gymnase X')
            ->setLatitude(45.5)->setLongitude(4.5)
            ->setSource(OpponentVenueLinkSource::MANUAL);
        $this->em->persist($link);
        $this->em->flush();
        $this->em->clear();

        // Le siège DÉMÉNAGE (le stub BAN rend 45.75/4.85, ≠ 40/3).
        $this->client->loginUser($this->user);
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // Amendement 2026-09-20 : un déménagement de siège ne DÉTRUIT jamais les appariements
        // de gymnase (le trajet est une constante du cache, directionnelle — la nouvelle origine
        // est une nouvelle clé, rien à invalider). Le worker recalcule les paires manquantes.
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $survivor = $this->em->getRepository(OpponentVenueLink::class)->findOneBy(['opponentOrganismeCode' => 'ARA0069STALE']);
        self::assertInstanceOf(OpponentVenueLink::class, $survivor, 'l\'appariement de gymnase survit au déménagement');
        self::assertSame('166900999', $survivor->getVenueExternalRef(), 'le gymnase épinglé est intact');
    }

    /**
     * P4-249 — quand le siège DÉMÉNAGE, l'ancienne origine devient une clé morte du cache
     * directionnel `club_travel_cache` (plus jamais lue) : elle est PURGÉE (tous profils),
     * bornée au club. Une autre origine du même club, et la même origine chez un AUTRE club,
     * restent intactes.
     */
    public function testMovingTheSiegePurgesTheOldOriginCacheButSparesOtherOriginsAndClubs(): void
    {
        // Le club a un siège AILLEURS (40/3) et un cache DEPUIS ce siège (deux profils), plus une
        // ligne depuis une AUTRE origine (41/3).
        $this->scopeGucToClub($this->club->getId());
        $this->club->setLatitude(40.0)->setLongitude(3.0);
        $this->em->flush();
        $this->seedCacheRow($this->club->getId(), 'car', '40.00000', '3.00000', '45.50000', '4.50000', 12);
        $this->seedCacheRow($this->club->getId(), 'pedestrian', '40.00000', '3.00000', '45.50000', '4.50000', 30);
        $this->seedCacheRow($this->club->getId(), 'car', '41.00000', '3.00000', '45.50000', '4.50000', 99);

        // Un AUTRE club a une ligne depuis la MÊME vieille origine (40/3) — jamais touchée.
        $otherClub = $this->makeOtherClub();
        $this->scopeGucToClub($otherClub->getId());
        $this->seedCacheRow($otherClub->getId(), 'car', '40.00000', '3.00000', '45.50000', '4.50000', 77);

        // Le siège DÉMÉNAGE (le stub BAN rend 45.75/4.85, ≠ 40/3).
        $this->client->loginUser($this->user);
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->countRows($this->club->getId(), '40.00000', '3.00000'), 'l\'ancienne origine du club est purgée, tous profils');
        self::assertSame(1, $this->countRows($this->club->getId(), '41.00000', '3.00000'), 'une autre origine du même club est intacte');
        self::assertSame(1, $this->countRows($otherClub->getId(), '40.00000', '3.00000'), 'un autre club n\'est jamais purgé (borne clubId)');
    }

    /**
     * P4-249 — re-géocoder la MÊME adresse ne fait pas bouger le siège (coordonnées inchangées à
     * la granularité du cache) : aucune purge, le cache existant survit intégralement.
     */
    public function testReGeocodingTheSameAddressPurgesNothing(): void
    {
        // Le siège est DÉJÀ aux coordonnées que la BAN rendra (45.75 / 4.85).
        $this->scopeGucToClub($this->club->getId());
        $this->club->setLatitude(45.75)->setLongitude(4.85);
        $this->em->flush();
        $this->seedCacheRow($this->club->getId(), 'car', '45.75000', '4.85000', '46.00000', '5.00000', 21);

        $this->client->loginUser($this->user);
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->countRows($this->club->getId(), '45.75000', '4.85000'), 're-géocoder la même adresse ne purge aucun trajet');
    }

    public function testAddressNotFoundIs422(): void
    {
        $this->client->loginUser($this->user);

        // Requête trop courte (< 3 caractères) → aucun hit → 422 parlant, aucune écriture.
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => 'ab'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * P4-245 — le contrôleur ne lit plus l'en-tête `X-Club-Id` : une requête AUTHENTIFIÉE
     * SANS cet en-tête résout quand même le club, car le listener tenant pose `_club_id`
     * depuis l'adhésion active du porteur du JWT (repli d'appartenance). Le front n'envoie
     * jamais `X-Club-Id` (`CLAUDE.md` §10.3) : c'est bien `_club_id` du listener qui suffit.
     */
    public function testSiegeResolvesTheClubFromMembershipWithoutTheClubIdHeader(): void
    {
        $this->client->loginUser($this->user);

        // Aucun en-tête X-Club-Id : le club vient de l'unique adhésion active du gestionnaire.
        $this->client->request('PATCH', '/api/club/siege', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['address' => '5 rue Emile Duniere Villeurbanne'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['geolocated']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Siège Test Club');
        $this->club->setSlug('siege-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('SIE' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('siege' . $uid . '@test.com');
        $this->user->setFirstName('Siege');
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
        $this->em->flush();
    }

    private function makeOtherClub(): Club
    {
        $uid = uniqid('other', true);
        $this->clearGuc(); // insertion d'un club : hors scope tenant (comme le club principal en setUp)
        $other = new Club;
        $other->setName('Autre Club');
        $other->setSlug('other-' . $uid);
        $other->setTimezone('Europe/Paris');
        $other->setLocale('fr');
        $other->setOnboardingCompleted(true);
        $other->setFfbbClubCode('OTH' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($other);
        $this->em->flush();

        return $other;
    }

    /** Une ligne de cache de trajet, coordonnées déjà sous forme canonique `%.5f`. GUC déjà scopé au club. */
    private function seedCacheRow(string $clubId, string $profile, string $originLat, string $originLon, string $destLat, string $destLon, int $minutes): void
    {
        $row = (new ClubTravelCache)
            ->setClubId($clubId)
            ->setProfile($profile)
            ->setOriginLat($originLat)
            ->setOriginLon($originLon)
            ->setDestLat($destLat)
            ->setDestLon($destLon)
            ->setMinutes($minutes);
        $this->em->persist($row);
        $this->em->flush();
    }

    private function countRows(string $clubId, string $originLat, string $originLon): int
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM club_travel_cache WHERE club_id = :c AND origin_lat = :lat AND origin_lon = :lon',
            ['c' => $clubId, 'lat' => $originLat, 'lon' => $originLon],
        );
    }
}
