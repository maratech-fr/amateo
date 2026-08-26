<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
use App\Enum\SeasonStatus;
use App\Enum\VenueTravelTimeSource;
use App\Service\SeasonResolver;
use App\Tests\Double\IgnRoutingHttpClientStub;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-53 RMM-8 (PR-1) — l'autofill de la matrice de trajet, falsifié dans les deux
 * sens (IGN stubbé, JAMAIS le live) :
 *   - LE test : une colonne MANUAL est INTACTE après l'autofill, quand ses voisines
 *     AUTO/nulles se remplissent ;
 *   - un gymnase sans géo → paire `unresolved` (missing_geo), jamais un échec global ;
 *   - un échec de transport sur UNE paire → celle-là seule `unresolved`
 *     (routing_failed), les autres remplies ;
 *   - au-delà du cap → 422 explicite.
 */
#[Group('integration')]
final class VenueTravelTimeAutofillTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAutofillFillsAutoAndNullColumnsAndNeverTouchesManual(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->geoVenue($club, $season, 'A', '45.750000', '4.850000');
        $b = $this->geoVenue($club, $season, 'B', '45.760000', '4.860000');
        $c = $this->geoVenue($club, $season, 'C', '45.770000', '4.870000');

        // Une valeur MANUAL POSÉE d'avance sur la voiture du couple A–B, walking laissé nul.
        [$lo, $hi] = $this->normalize($a->getId(), $b->getId());
        $manual = (new VenueTravelTime)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueAId($lo)->setVenueBId($hi)
            ->setDrivingMinutes(15)->setDrivingSource(VenueTravelTimeSource::MANUAL);
        $this->em->persist($manual);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('POST', '/api/venue-travel-times/autofill', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $result = $this->responseData();

        self::assertSame([], $result['unresolved'], 'trois gymnases géolocalisés : aucune paire irrésolue');
        self::assertSame(3, $result['filled'], 'A–B (walking), A–C, B–C reçoivent au moins une valeur AUTO');
        self::assertGreaterThanOrEqual(1, $result['skippedManual'], 'le couple A–B avait une colonne MANUAL préservée');

        // LE test : la voiture MANUAL de A–B n'a PAS bougé ; son walking s'est rempli AUTO.
        $ab = $this->travelRow($club, $lo, $hi);
        self::assertSame(15, $ab->getDrivingMinutes(), 'le 15 min MANUAL survit au recalcul');
        self::assertSame(VenueTravelTimeSource::MANUAL, $ab->getDrivingSource());
        self::assertSame(IgnRoutingHttpClientStub::WALKING_MINUTES, $ab->getWalkingMinutes());
        self::assertSame(VenueTravelTimeSource::AUTO, $ab->getWalkingSource());

        // Un couple neuf (A–C) est intégralement rempli AUTO.
        [$loAc, $hiAc] = $this->normalize($a->getId(), $c->getId());
        $ac = $this->travelRow($club, $loAc, $hiAc);
        self::assertSame(IgnRoutingHttpClientStub::DRIVING_MINUTES, $ac->getDrivingMinutes());
        self::assertSame(VenueTravelTimeSource::AUTO, $ac->getDrivingSource());
        self::assertSame(IgnRoutingHttpClientStub::WALKING_MINUTES, $ac->getWalkingMinutes());
        self::assertSame(VenueTravelTimeSource::AUTO, $ac->getWalkingSource());
    }

    public function testMissingGeoAndRoutingFailureAreUnresolvedOthersFilled(): void
    {
        [$club, $user, $season] = $this->createClubUser('b');
        $a = $this->geoVenue($club, $season, 'A', '45.750000', '4.850000');
        $b = $this->geoVenue($club, $season, 'B', '45.760000', '4.860000');
        $noGeo = $this->geoVenue($club, $season, 'NoGeo', null, null);
        // Un gymnase dont les coordonnées font échouer l'itinéraire dans le stub.
        $poison = $this->geoVenue($club, $season, 'Poison', IgnRoutingHttpClientStub::POISON_COORD, IgnRoutingHttpClientStub::POISON_COORD);

        $this->client->request('POST', '/api/venue-travel-times/autofill', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $result = $this->responseData();

        self::assertSame(1, $result['filled'], 'seule la paire A–B (les deux géolocalisés, routables) est remplie');

        $reasons = array_column($result['unresolved'], 'reason');
        self::assertContains('missing_geo', $reasons, 'toute paire avec le gymnase sans géo est irrésolue');
        self::assertContains('routing_failed', $reasons, 'toute paire avec le gymnase « poison » est irrésolue');

        // A–B a bien été écrite malgré les échecs voisins (best-effort par paire).
        [$lo, $hi] = $this->normalize($a->getId(), $b->getId());
        $ab = $this->travelRow($club, $lo, $hi);
        self::assertSame(IgnRoutingHttpClientStub::DRIVING_MINUTES, $ab->getDrivingMinutes());

        // Aucune ligne créée pour une paire irrésolue.
        [$loNo, $hiNo] = $this->normalize($a->getId(), $noGeo->getId());
        self::assertNull($this->travelRowOrNull($club, $loNo, $hiNo), 'un gymnase sans géo ne crée pas de ligne');
        [$loP, $hiP] = $this->normalize($a->getId(), $poison->getId());
        self::assertNull($this->travelRowOrNull($club, $loP, $hiP), 'une paire en échec de routage ne crée pas de ligne');
    }

    public function testCapExceededReturns422(): void
    {
        [$club, $user, $season] = $this->createClubUser('c');
        // 17 gymnases géolocalisés = 136 paires > 120 → refus explicite.
        for ($i = 0; $i < 17; ++$i) {
            $this->geoVenue($club, $season, 'G' . $i, \sprintf('45.%06d', 700000 + $i), \sprintf('4.%06d', 800000 + $i));
        }

        $this->client->request('POST', '/api/venue-travel-times/autofill', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(422, 'au-delà du cap de paires, l\'autofill refuse explicitement');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: string, 1: string} [lo, hi]
     */
    private function normalize(string $x, string $y): array
    {
        return strcasecmp($x, $y) > 0 ? [$y, $x] : [$x, $y];
    }

    private function travelRow(Club $club, string $lo, string $hi): VenueTravelTime
    {
        $row = $this->travelRowOrNull($club, $lo, $hi);
        self::assertInstanceOf(VenueTravelTime::class, $row);

        return $row;
    }

    private function travelRowOrNull(Club $club, string $lo, string $hi): ?VenueTravelTime
    {
        $this->scopeGucToClub($club->getId());
        $this->em->clear();

        return $this->em->getRepository(VenueTravelTime::class)->findOneBy(['venueAId' => $lo, 'venueBId' => $hi]);
    }

    private function geoVenue(Club $club, Season $season, string $name, ?string $latitude, ?string $longitude): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setLatitude($latitude);
        $venue->setLongitude($longitude);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club autofill ' . $suffix);
        $club->setSlug('club-autofill-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('autofill' . $uid . '@test.com');
        $user->setFirstName('Autofill');
        $user->setLastName('User');
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

        return [$club, $user, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
