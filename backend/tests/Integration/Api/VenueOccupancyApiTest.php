<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Une occupation de gymnase et un créneau d'entraînement ne se superposent jamais à la saisie,
 * avec les règles de chevauchement de deux créneaux, et chaque refus nomme ce qu'il heurte.
 *
 * Logique à nombreux cas nommée par le plan (N3) : la promesse de bout en bout (je pose, un
 * créneau sur l'occupation est refusé, je la déplace, je la supprime) est le scénario Behat
 * `une-occupation-nomme-un-moment-ou-le-gymnase-n-est-pas-au-club.feature` ; ce test en prouve
 * les cas : chaque croisement de la matrice avec son message, les bornes jointives, les couches,
 * la modification de soi, minuit, l'état déjà superposé (fabriqué en base : il naît d'une copie,
 * jamais de la saisie), le motif, le plan disparu, le gymnase d'un autre club, l'import du parc
 * et le motif en balisage.
 *
 * Les messages sont lus dans le JSON DÉCODÉ : API Platform sérialise l'apostrophe en `'`
 * (`backend/docs/error-copy.md`).
 */
#[Group('phase1')]
final class VenueOccupancyApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private const string OCC_ON_OCC = 'Cette occupation chevauche l\'occupation « École » (mardi 14:00–16:15) du même gymnase.';

    private const string OCC_ON_SLOT = 'Cette occupation chevauche un créneau du même gymnase (mardi 18:00–19:30).';

    private const string SLOT_ON_OCC = 'Ce créneau chevauche l\'occupation « École » (mardi 14:00–16:15) du même gymnase.';

    private const string MIDNIGHT = 'Une occupation doit se terminer dans sa journée : celle-ci déborderait après minuit.';

    private const string EMPTY_LABEL = 'Donnez un motif à l\'occupation.';

    private const string LONG_LABEL = 'Le motif d\'une occupation tient en 40 caractères.';

    private const string UNKNOWN_VENUE = 'Gymnase inconnu pour ce club.';

    private const string PLAN_GONE = 'Ce planning n\'existe plus — rechargez la page.';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    /** Une occupation posée sur une autre occupation du même gymnase et de la même couche est refusée, en nommant la première. */
    public function testAnOccupancyOnAnotherOccupancyIsRefusedNamingIt(): void
    {
        $venue = $this->createVenue();
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);

        $this->postOccupancy($venue, 2, '15:00', 60, 'Mairie');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::OCC_ON_OCC, $this->responseText());
    }

    /** Une occupation posée sur un créneau est refusée, en nommant le créneau par son jour et ses heures. */
    public function testAnOccupancyOnASlotIsRefusedNamingTheSlot(): void
    {
        $venue = $this->createVenue();
        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201);

        $this->postOccupancy($venue, 2, '19:00', 60, 'École');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::OCC_ON_SLOT, $this->responseText());
    }

    /** Un créneau posé sur une occupation est refusé avec le message de l'occupation ; un créneau sur un créneau garde son message d'aujourd'hui. */
    public function testASlotOnAnOccupancyIsRefusedNamingTheOccupancy(): void
    {
        $venue = $this->createVenue();
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);

        $this->postSlot($venue, 2, '15:00', 90);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::SLOT_ON_OCC, $this->responseText());
        self::assertStringNotContainsString('en chevauche un autre', $this->responseText(), 'le message « créneau sur créneau » ne sert pas pour une occupation');
    }

    /** Bornes jointives : un créneau qui commence à la fin d'une occupation, ou une occupation qui finit au début d'un créneau, se posent. */
    public function testTouchingBoundsAreAccepted(): void
    {
        $venue = $this->createVenue();
        $this->postOccupancy($venue, 2, '14:00', 135, 'École'); // 14:00–16:15
        self::assertResponseStatusCodeSame(201);

        $this->postSlot($venue, 2, '16:15', 90); // 16:15–17:45
        self::assertResponseStatusCodeSame(201, 'un créneau à 16:15 se pose : les bornes se touchent');

        $this->postOccupancy($venue, 2, '13:00', 60, 'Cantine'); // 13:00–14:00
        self::assertResponseStatusCodeSame(201, 'une occupation qui finit à 14:00 touche « École » sans la chevaucher');

        $this->postOccupancy($venue, 2, '17:45', 30, 'Ménage'); // 17:45–18:15
        self::assertResponseStatusCodeSame(201, 'une occupation qui commence à la fin du créneau se pose');

        $this->postOccupancy($venue, 3, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201, 'un autre jour ne heurte jamais');
    }

    /** La saison et une période sont deux couches : elles ne se gênent pas ; à l'intérieur d'une période, la règle vaut comme en saison. */
    public function testOverlapIsCheckedWithinALayerNotAcrossLayers(): void
    {
        $venue = $this->createVenue();
        $plan = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);

        $this->postOccupancy($venue, 2, '14:00', 135, 'École', $plan);
        self::assertResponseStatusCodeSame(201, 'une occupation de période au même horaire qu\'une occupation de saison se pose');
        $this->postSlot($venue, 2, '18:00', 90, 1, $plan);
        self::assertResponseStatusCodeSame(201);

        $this->postSlot($venue, 2, '15:00', 90, 1, $plan);
        self::assertResponseStatusCodeSame(422, 'dans la période, un créneau sur l\'occupation de la période est refusé');
        self::assertStringContainsString(self::SLOT_ON_OCC, $this->responseText());

        $this->postOccupancy($venue, 2, '19:00', 60, 'Mairie', $plan);
        self::assertResponseStatusCodeSame(422, 'dans la période, une occupation sur le créneau de la période est refusée');
        self::assertStringContainsString(self::OCC_ON_SLOT, $this->responseText());

        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201, 'le créneau de période n\'empêche pas le même créneau en saison');
    }

    /** Une occupation qu'on modifie ne se heurte pas à elle-même ; la déplacer sur une autre occupation ou sur un créneau est refusé. */
    public function testUpdatingAnOccupancyIgnoresItselfButNotTheOthers(): void
    {
        $venue = $this->createVenue();
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);
        $schoolId = $this->createdId();
        $this->postOccupancy($venue, 4, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);
        $otherId = $this->createdId();
        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201);

        $this->putOccupancy($schoolId, $venue, 2, '14:30', 105, 'École');
        self::assertResponseStatusCodeSame(200, 'se déplacer sur sa propre ancienne plage n\'est pas un chevauchement');

        $this->putOccupancy($otherId, $venue, 2, '15:00', 60, 'École');
        self::assertResponseStatusCodeSame(422, 'déplacer une occupation sur une autre est refusé');
        self::assertStringContainsString('Cette occupation chevauche l\'occupation « École » (mardi 14:30–16:15) du même gymnase.', $this->responseText());

        $this->putOccupancy($otherId, $venue, 2, '19:00', 60, 'École');
        self::assertResponseStatusCodeSame(422, 'déplacer une occupation sur un créneau est refusé');
        self::assertStringContainsString(self::OCC_ON_SLOT, $this->responseText());
    }

    /** Déplacer un créneau sur une occupation est refusé avec le message de l'occupation. */
    public function testMovingASlotOntoAnOccupancyIsRefused(): void
    {
        $venue = $this->createVenue();
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);
        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $slotId = $this->createdId();

        $this->putSlot($slotId, $venue, 2, '15:00', 90, 1);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::SLOT_ON_OCC, $this->responseText());
    }

    /** Une occupation qui finirait après minuit est refusée, à la création comme à la modification ; finir pile à minuit passe. */
    public function testAnOccupancyCrossingMidnightIsRefused(): void
    {
        $venue = $this->createVenue();
        $this->postOccupancy($venue, 2, '22:45', 150, 'Ménage');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::MIDNIGHT, $this->responseText());

        $this->postOccupancy($venue, 2, '23:00', 60, 'Ménage');
        self::assertResponseStatusCodeSame(201, 'finir pile à minuit reste dans la journée');
        $id = $this->createdId();

        $this->putOccupancy($id, $venue, 2, '23:30', 90, 'Ménage');
        self::assertResponseStatusCodeSame(422, 'la garde de minuit vaut aussi pour une modification');
        self::assertStringContainsString(self::MIDNIGHT, $this->responseText());
    }

    /**
     * Un créneau et une occupation déjà superposés (état né d'une copie ou d'une version chargée,
     * fabriqué ici en base) : modifier la seule capacité du créneau est refusé avec le message de
     * l'occupation ; supprimer le créneau passe.
     */
    public function testOnAlreadySuperposedStateTheSlotCannotBeEditedButCanBeDeleted(): void
    {
        $venue = $this->createVenue(canSplit: true);
        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $slotId = $this->createdId();
        $this->insertOccupancyRow($venue, 2, '18:00', 60, 'École');

        $this->putSlot($slotId, $venue, 2, '18:00', 90, 2);
        self::assertResponseStatusCodeSame(422, 'modifier la seule capacité du créneau recouvert est refusé');
        self::assertStringContainsString('Ce créneau chevauche l\'occupation « École » (mardi 18:00–19:00) du même gymnase.', $this->responseText());

        $this->request('DELETE', '/api/venue_training_slots/' . $slotId);
        self::assertResponseStatusCodeSame(204, 'supprimer le créneau recouvert passe toujours');
    }

    /** Sur un état déjà superposé, l'occupation se supprime toujours. */
    public function testOnAlreadySuperposedStateTheOccupancyCanBeDeleted(): void
    {
        $venue = $this->createVenue();
        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $occupancyId = $this->insertOccupancyRow($venue, 2, '18:00', 60, 'École');

        $this->request('DELETE', '/api/venue_occupancies/' . $occupancyId);
        self::assertResponseStatusCodeSame(204, 'supprimer l\'occupation passe toujours : la suppression ne contrôle aucun chevauchement');
    }

    /** Sur un état déjà superposé, l'occupation se déplace vers une plage libre. */
    public function testOnAlreadySuperposedStateTheOccupancyMovesToAFreeRange(): void
    {
        $venue = $this->createVenue();
        $this->postSlot($venue, 2, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $occupancyId = $this->insertOccupancyRow($venue, 2, '18:00', 60, 'École');

        $this->putOccupancy($occupancyId, $venue, 5, '13:45', 120, 'École');
        self::assertResponseStatusCodeSame(200, 'déplacer l\'occupation vers une plage libre passe');
        self::assertSame(5, $this->body()['dayOfWeek'] ?? null);
    }

    /** Le motif est rogné, obligatoire et borné à 40 caractères. */
    public function testTheLabelIsTrimmedRequiredAndBounded(): void
    {
        $venue = $this->createVenue();

        $this->postOccupancy($venue, 1, '08:00', 60, '  École  ');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('École', $this->body()['label'] ?? null, 'le motif est rogné');

        foreach (['', '   '] as $blank) {
            $this->postOccupancy($venue, 2, '08:00', 60, $blank);
            self::assertResponseStatusCodeSame(422, 'un motif vide est refusé');
            self::assertStringContainsString(self::EMPTY_LABEL, $this->responseText());
        }

        $this->postOccupancy($venue, 3, '08:00', 60, str_repeat('a', 41));
        self::assertResponseStatusCodeSame(422, 'un motif de 41 caractères est refusé');
        self::assertStringContainsString(self::LONG_LABEL, $this->responseText());

        $this->postOccupancy($venue, 4, '08:00', 60, str_repeat('é', 40));
        self::assertResponseStatusCodeSame(201, '40 caractères (multi-octets compris) tiennent');
    }

    /** Une occupation ancrée sur un plan qui n'existe pas est refusée avec le message du plan disparu. */
    public function testAnOccupancyOnAMissingPlanIsRefused(): void
    {
        $venue = $this->createVenue();

        $this->postOccupancy($venue, 2, '14:00', 135, 'École', '00000000-0000-4000-8000-00000000dead');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::PLAN_GONE, $this->responseText());
    }

    /** Le gymnase d'un autre club est inconnu pour ce club : ni poser une occupation dedans, ni y déplacer la sienne. */
    public function testAVenueOfAnotherClubIsUnknownOnPostAndPut(): void
    {
        $venue = $this->createVenue();
        $foreignVenueId = $this->createForeignVenue();
        $this->scopeGucToClub($this->club->getId());

        $this->postOccupancy($foreignVenueId, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(422, 'poser une occupation dans le gymnase d\'un autre club est refusé');
        self::assertStringContainsString(self::UNKNOWN_VENUE, $this->responseText());

        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);
        $id = $this->createdId();

        $this->putOccupancy($id, $foreignVenueId, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(422, 'déplacer son occupation dans le gymnase d\'un autre club est refusé');
        self::assertStringContainsString(self::UNKNOWN_VENUE, $this->responseText());
    }

    /** L'import du parc refuse un créneau du fichier posé sur une occupation, avec le message préfixé de sa section. */
    public function testParcImportRefusesASlotOnAnOccupancy(): void
    {
        $venue = $this->createVenue('Palais Neuf');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        self::assertResponseStatusCodeSame(201);

        $parc = [
            'format' => 'parc-v2',
            'gymnases' => [['nom' => 'Palais Neuf']],
            'cases' => [['gymnase' => 'Palais Neuf', 'jour' => 2, 'debut' => '15:00', 'duree' => 90, 'equipes' => ['SM1']]],
        ];
        $decisions = [
            ['section' => 'equipes', 'index' => 0, 'coche' => true, 'corrections' => ['genre' => 'M']],
            ['section' => 'creneaux', 'index' => 0, 'coche' => true, 'cibleId' => $venue],
        ];
        $this->client->request(
            'POST',
            '/api/clubs/' . $this->club->getId() . '/parc-import',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_SEASON_ID' => $this->season->getId()],
            (string) json_encode(['payload' => $parc, 'decisions' => $decisions], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('créneau : ' . self::SLOT_ON_OCC, $this->responseText());
    }

    /** Un motif en balisage est rendu tel quel dans le JSON : la donnée n'est ni interprétée ni réécrite. */
    public function testAMarkupLabelIsReturnedVerbatim(): void
    {
        $venue = $this->createVenue();

        $this->postOccupancy($venue, 2, '14:00', 60, '<b>École</b>');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('<b>École</b>', $this->body()['label'] ?? null);

        $this->request('GET', '/api/venue_occupancies/' . $this->createdId());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('<b>École</b>', $this->body()['label'] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Occupation Club ' . $uid)->setSlug('occupation-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $user = new User;
        $user->setEmail('occupation-' . $uid . '@test.com');
        $user->setFirstName('Occ');
        $user->setLastName('Upation');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $membership = new ClubUser;
        $membership->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($membership);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-07-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        // Le plan SEASON que toute vraie saison possède (l'import du parc l'exige).
        $this->provisionSeasonPlan($this->season);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function postOccupancy(string $venueId, int $day, string $start, int $duration, string $label, ?string $planId = null): void
    {
        $body = ['venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'label' => $label];
        if (null !== $planId) {
            $body['schedulePlanId'] = $planId;
        }
        $this->request('POST', '/api/venue_occupancies', $body);
    }

    private function putOccupancy(string $id, string $venueId, int $day, string $start, int $duration, string $label): void
    {
        $this->request('PUT', '/api/venue_occupancies/' . $id, ['venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'label' => $label]);
    }

    private function postSlot(string $venueId, int $day, string $start, int $duration, int $capacity = 1, ?string $planId = null): void
    {
        $body = ['venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'capacity' => $capacity];
        if (null !== $planId) {
            $body['schedulePlanId'] = $planId;
        }
        $this->request('POST', '/api/venue_training_slots', $body);
    }

    private function putSlot(string $id, string $venueId, int $day, string $start, int $duration, int $capacity): void
    {
        $this->request('PUT', '/api/venue_training_slots/' . $id, ['venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'capacity' => $capacity]);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function createdId(): string
    {
        $id = $this->body()['id'] ?? null;
        self::assertIsString($id, 'la réponse de création porte un identifiant');

        return $id;
    }

    /** Toutes les chaînes du corps décodé, mises bout à bout : le message s'y lit quelle que soit sa clé (`detail`, `violations[].message`, `errors[]`). */
    private function responseText(): string
    {
        $strings = [];
        $decoded = $this->body();
        array_walk_recursive($decoded, static function (mixed $value) use (&$strings): void {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        });

        return implode("\n", $strings) . "\n" . (string) $this->client->getResponse()->getContent();
    }

    private function createVenue(?string $name = null, bool $canSplit = false): string
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = $this->newVenue($this->club->getId(), $this->season->getId(), $name ?? 'Gymnase ' . uniqid())->setIsActive(true)->setCanSplit($canSplit);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /** Un gymnase d'un AUTRE club, inséré sous le GUC de cet autre club (RLS WITH CHECK). */
    private function createForeignVenue(): string
    {
        $uid = uniqid('', true);
        $club = (new Club)
            ->setName('Autre club ' . $uid)->setSlug('autre-club-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-07-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase étranger')->setIsActive(true)->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /**
     * Une occupation écrite EN BASE, sans passer par la garde : l'état superposé ne naît jamais de
     * la saisie (copie à la naissance d'un plan, « Reprendre », version chargée, deux gestionnaires).
     */
    private function insertOccupancyRow(string $venueId, int $day, string $start, int $duration, string $label): string
    {
        $this->scopeGucToClub($this->club->getId());
        $id = \sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF));
        $this->em->getConnection()->executeStatement(
            'INSERT INTO venue_occupancy (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, start_time, duration_minutes, label, schedule_plan_id)'
            . ' VALUES (:id, 1, now(), now(), :club, :season, :venue, :day, :start, :duration, :label, NULL)',
            ['id' => $id, 'club' => $this->club->getId(), 'season' => $this->season->getId(), 'venue' => $venueId, 'day' => $day, 'start' => $start . ':00', 'duration' => $duration, 'label' => $label],
        );

        return $id;
    }
}
