<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\SocleGuard;
use App\Service\TeamImportGate;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\Unit\ParcImport\ParcPayloadValidatorTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chantier G PR1 — le DRY-RUN `POST /api/clubs/{id}/parc-import/analyze` : le
 * gestionnaire colle le texte parc-v1 rendu par son chatbot, le serveur lui répond,
 * PAR SECTION, le statut de chaque ligne (apparié / suggéré / décoché avec raison /
 * à créer / alerte) et un récap chiffré — et il N'ÉCRIT RIEN. C'est l'aperçu cochable
 * sur lequel il tranche avant l'import (PR2).
 *
 * Contrat de l'endpoint (défini ici, implémenté par NEO) :
 *  - Opération Post custom sur ClubResource (patron `import-teams/analyze`),
 *    `App\Controller\ParcImportAnalyzeController`, `read:false`, name
 *    `parc_import_analyze`. Le corps de la requête EST le texte parc-v1 BRUT
 *    (Content-Type application/json) — le contrôleur le lit via `getContent()` et le
 *    passe au validateur, pour qu'un JSON illisible produise un 422 nommé (et jamais
 *    un 400 de désérialisation API Platform avant nous).
 *  - Gate `App\Service\ParcImport\ParcImportGate` (patron {@see TeamImportGate})
 *    keyée sur le `{id}` du chemin : pas d'adhésion active → 404 byte-identique (jamais
 *    403, qui fuiterait l'existence du club) ; membre non gestionnaire → 403 ; saison
 *    archivée → 409 ; socle EN VIGUEUR → 409 ({@see SocleGuard::assertSeasonPlanNotChosen})
 *    car l'import de parc est un geste d'ONBOARDING, AVANT qu'un plan de saison soit pointé ;
 *    limiteur `parcImportLimiter` PAR UTILISATEUR → 429 ; bornes ≤256 Ko / ≤60 équipes /
 *    ≤20 gymnases / ≤500 (créneaux + réservations).
 *  - Réponse 200 : {format, recap:{<section>:{total,apparie,suggere,aCreer,decoche,alerte}},
 *    sections:{<section>:[{index,libelle,statut,coche,cibleId,raison, …}]}}.
 *    Réponse 422 (validateur) : {errors:[…]}.
 *
 * Appariement des noms : foyer {@see VenueLabelNormalizer}
 * / {@see VenueAliasResolver} (casse et accents repliés) pour
 * les gymnases, MÊME normalisation pour équipes et coachs. Les RÈGLES du format sont
 * éprouvées au plus bas par {@see ParcPayloadValidatorTest}
 * (jumeau unitaire) ; ici on prouve seulement le CÂBLAGE du validateur à l'endpoint
 * (un seul cas), les statuts d'appariement, l'étanchéité tenant, et le NON-ÉCRIT.
 *
 * ⚠ Limiteur Redis keyé PAR UTILISATEUR : chaque test crée sa propre identité — aucun
 * FLUSHALL, aucune fuite entre suites (mémoire backend-test-gotchas).
 */
#[Group('integration')]
final class ParcImportAnalyzeTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    // ───────────────────────────── Gate (promesse 3) ─────────────────────────────

    public function testNonMemberGets404WithTheClubNotFoundBody(): void
    {
        // Un uuid dont l'appelant n'est pas membre : 404 (jamais 403, qui fuiterait
        // l'existence). On assert le CORPS exact de la gate — un 404 de ROUTAGE (route
        // absente) a un autre corps, donc ce test rougit tant que l'endpoint n'existe pas.
        $stranger = '99999999-9999-4999-8999-999999999999';
        [$status, $body] = $this->analyze($this->validParc(), clubId: $stranger);

        self::assertSame(404, $status);
        self::assertSame(['error' => 'Club not found.'], $body);
    }

    public function testNonManagerIsRefused(): void
    {
        // Un membre ACTIF mais non-gestionnaire (viewer) : 403 — jamais l'analyse.
        [$status, $body] = $this->analyze($this->validParc(), token: $this->viewerToken());

        self::assertSame(403, $status);
        self::assertSame(['error' => 'Forbidden.'], $body);
    }

    public function testSocleEnVigueurIsRefusedWith409(): void
    {
        // Le plan de saison POINTE une version = socle en vigueur. Importer un parc est
        // un geste d'onboarding : il est refusé (409) tant que le socle n'est pas rouvert.
        $this->settleSeasonPlan($this->season);

        [$status] = $this->analyze($this->validParc());

        self::assertSame(409, $status);
    }

    public function testArchivedSeasonIsRefusedWith409(): void
    {
        // Une saison PASSÉE est en lecture seule : l'analyser (geste d'écriture) → 409.
        $this->scopeGucToClub($this->club->getId());
        $old = (new Season)->setClubId($this->club->getId())->setName('2000-2001')
            ->setStartDate(new DateTimeImmutable('2000-07-15'))->setEndDate(new DateTimeImmutable('2001-07-14'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($old);
        $this->em->flush();

        [$status] = $this->analyze($this->validParc(), server: ['HTTP_X_SEASON_ID' => $old->getId()]);

        self::assertSame(409, $status);
    }

    // ───────────────────────────── Abus (promesse 4) ─────────────────────────────

    public function testPayloadBeyondBoundsIsRefused(): void
    {
        // Octets : un corps au-delà de 256 Ko est refusé (413) AVANT tout parse.
        $huge = str_repeat('x', 256 * 1024 + 1);
        [$oversized] = $this->analyzeRaw('{"format":"parc-v1","pad":"' . $huge . '"}');
        self::assertSame(413, $oversized, 'un corps > 256 Ko doit être refusé (413)');

        // Dénombrement : 61 équipes dépasse la borne (≤ 60) → 422 nommé.
        $tooManyTeams = $this->validParc();
        $tooManyTeams['equipes'] = array_map(
            static fn (int $i): array => ['nom' => 'E' . $i, 'genre' => 'M'],
            range(1, 61),
        );
        [$teamsStatus] = $this->analyze($tooManyTeams);
        self::assertSame(422, $teamsStatus, '61 équipes doit être refusé (borne ≤ 60)');

        // Débit : le limiteur `parc_import` (override when@test, patron xlsxImport) borne
        // PAR UTILISATEUR — après la borne, 429. On consomme le budget puis on dépasse.
        $accepted = 0;
        for ($i = 1; $i <= 30; ++$i) {
            [$status] = $this->analyze($this->validParc());
            if (429 === $status) {
                self::assertGreaterThan(0, $accepted, 'le limiteur doit laisser passer au moins une analyse avant de borner');

                return;
            }
            ++$accepted;
        }
        self::fail('le limiteur parc_import n\'a jamais rendu 429 en 30 analyses');
    }

    public function testMatchingNeverReachesAnotherClub(): void
    {
        // Un AUTRE club possède un gymnase « Gymnase Mateo » et une équipe « SM1 ».
        // L'analyse de NOTRE club ne doit JAMAIS les apparier (filtre tenant) : la ligne
        // ressort « à créer », cibleId null — jamais l'id d'une entité hors tenant.
        $other = $this->seedForeignClubWithVenueAndTeam('Gymnase Mateo', 'SM1');

        $parc = $this->validParc();
        $parc['gymnases'] = [['nom' => 'Gymnase Mateo']];
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M']];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $gymLine = $body['sections']['gymnases'][0];
        self::assertSame('aCreer', $gymLine['statut'], 'un gymnase homonyme d\'un autre club ne s\'apparie pas');
        self::assertNotSame($other['venueId'], $gymLine['cibleId'] ?? null, 'jamais la cible d\'un autre club');

        $teamLine = $body['sections']['equipes'][0];
        self::assertSame('aCreer', $teamLine['statut'], 'une équipe homonyme d\'un autre club ne s\'apparie pas');
        self::assertNotSame($other['teamId'], $teamLine['cibleId'] ?? null, 'jamais la cible d\'un autre club');
    }

    // ───────────────────────── Appariement & statuts (promesse 2) ─────────────────

    public function testEachGymnaseLineGetsItsMatchingVerdictPlusARecap(): void
    {
        // Décor : « Gymnase Mateo » (match exact), plus « Gymnase Nord » et « Gymnase
        // Sud » (tous deux contiennent le mot « gymnase » → ambiguïté sur « Gymnase »).
        $mateo = $this->makeVenue('Gymnase Mateo');
        $this->makeVenue('Gymnase Nord');
        $this->makeVenue('Gymnase Sud');

        $parc = $this->validParc();
        $parc['gymnases'] = [
            ['nom' => 'GYMNASE MATÉO'],      // casse + accent repliés → apparié, cible = Mateo
            ['nom' => 'Mateo'],              // mot unique de « gymnase mateo » → suggéré
            ['nom' => 'Gymnase'],            // mot commun aux trois → ambigu → décoché
            ['nom' => 'Palais des Sports'],  // inconnu → à créer
        ];
        // Les créneaux/réservations pointent le 1er gymnase connu (hors sujet ici).
        $parc['creneaux'] = [];
        $parc['reservations'] = [];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $lines = $body['sections']['gymnases'];

        self::assertSame('apparie', $lines[0]['statut']);
        self::assertSame($mateo->getId(), $lines[0]['cibleId'], 'l\'exact normalisé pointe le bon gymnase');
        self::assertTrue($lines[0]['coche']);

        self::assertSame('suggere', $lines[1]['statut'], 'un match flou unique est suggéré');
        self::assertSame($mateo->getId(), $lines[1]['cibleId']);

        self::assertSame('decoche', $lines[2]['statut'], 'une ambiguïté est décochée, jamais devinée');
        self::assertFalse($lines[2]['coche']);
        self::assertStringContainsString('ambig', mb_strtolower((string) ($lines[2]['raison'] ?? '')));

        self::assertSame('aCreer', $lines[3]['statut']);
        self::assertTrue($lines[3]['coche']);
        self::assertNull($lines[3]['cibleId']);

        // Récap chiffré de la section.
        $recap = $body['recap']['gymnases'];
        self::assertSame(4, $recap['total']);
        self::assertSame(1, $recap['apparie']);
        self::assertSame(1, $recap['suggere']);
        self::assertSame(1, $recap['decoche']);
        self::assertSame(1, $recap['aCreer']);
    }

    public function testAlreadyPresentReservationIsDecochedForIdempotence(): void
    {
        // Le gymnase, l'équipe ET la réservation (même case) existent déjà : relancer le
        // même parc ne re-propose rien — la ligne ressort « déjà présent », décochée.
        $venue = $this->makeVenue('Gymnase Mateo');
        $team = $this->makeTeam('SM1', sessionsPerWeek: 3);
        $this->persistReservation($team->getId(), $venue->getId(), 2, '18:00');

        $parc = $this->validParc();
        $parc['gymnases'] = [['nom' => 'Gymnase Mateo']];
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M', 'seances' => 3]];
        $parc['reservations'] = [['equipe' => 'SM1', 'gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '18:00']];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $line = $body['sections']['reservations'][0];
        self::assertSame('decoche', $line['statut']);
        self::assertFalse($line['coche']);
        self::assertStringContainsString('déjà présent', mb_strtolower((string) ($line['raison'] ?? '')));
    }

    public function testMatchedTeamWithDifferentSessionsProposesAnUpdate(): void
    {
        // L'équipe existe à 2 séances/sem ; le parc en déclare 3. L'analyse propose une
        // MISE À JOUR 2→3 (cochée) — et rien d'autre de l'équipe n'est touché.
        $this->makeTeam('SM1', sessionsPerWeek: 2);

        $parc = $this->validParc();
        $parc['equipes'] = [['nom' => 'SM1', 'genre' => 'M', 'seances' => 3]];
        $parc['gymnases'] = [];
        $parc['creneaux'] = [];
        $parc['reservations'] = [];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status);

        $line = $body['sections']['equipes'][0];
        self::assertSame('apparie', $line['statut'], 'une équipe connue reste appariée');
        self::assertTrue($line['coche'], 'la mise à jour est proposée cochée');
        self::assertSame(['champ' => 'seances', 'de' => 2, 'a' => 3], $line['miseAJour'] ?? null);
    }

    public function testTwoTeamsOnTheSameCaseRaiseAnAlertNamingTheCauses(): void
    {
        // Deux équipes réservent la MÊME case (même gymnase/jour/heure) sans mutualisation
        // ni terrain divisé déclaré : on ne pose RIEN et on alerte en nommant les causes
        // possibles (mutualisation / terrain divisé / erreur de lecture). Le gestionnaire tranche.
        $this->makeVenue('Gymnase Mateo');
        $this->makeTeam('U11M1', sessionsPerWeek: 1);
        $this->makeTeam('U11M2', sessionsPerWeek: 1);

        $parc = $this->validParc();
        $parc['gymnases'] = [['nom' => 'Gymnase Mateo']];
        $parc['equipes'] = [['nom' => 'U11M1', 'genre' => 'M'], ['nom' => 'U11M2', 'genre' => 'M']];
        $parc['creneaux'] = [['gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '17:00', 'duree' => 90, 'equipes_en_parallele' => 1]];
        $parc['mutualisations'] = [];
        $parc['reservations'] = [
            ['equipe' => 'U11M1', 'gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '17:00'],
            ['equipe' => 'U11M2', 'gymnase' => 'Gymnase Mateo', 'jour' => 3, 'debut' => '17:00'],
        ];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status, 'une collision déclarée est une ALERTE, jamais un 500');

        foreach ($body['sections']['reservations'] as $line) {
            self::assertSame('alerte', $line['statut'], 'aucune réservation posée sur la case contestée');
            self::assertFalse($line['coche']);
            $raison = mb_strtolower((string) ($line['raison'] ?? ''));
            self::assertStringContainsString('mutualisation', $raison);
            self::assertStringContainsString('divis', $raison);
            self::assertStringContainsString('lecture', $raison);
        }
    }

    public function testAnExistingGuardReplayTurnsTheLineToAlertNeverA500(): void
    {
        // Deux créneaux du MÊME gymnase le MÊME jour se chevauchent (18:00-19:30 et
        // 19:00-20:30). La garde de chevauchement (VenueTrainingSlotStateProcessor) est
        // rejouée AU DRY-RUN : la ligne fautive passe en alerte/décoché, et surtout
        // l'analyse répond 200 — une violation ne devient jamais un 500.
        $this->makeVenue('Gymnase Mateo');

        $parc = $this->validParc();
        $parc['gymnases'] = [['nom' => 'Gymnase Mateo']];
        $parc['equipes'] = [];
        $parc['creneaux'] = [
            ['gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes_en_parallele' => 1],
            ['gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '19:00', 'duree' => 90, 'equipes_en_parallele' => 1],
        ];
        $parc['reservations'] = [];

        [$status, $body] = $this->analyze($parc);
        self::assertSame(200, $status, 'une garde rejouée ne doit jamais faire un 500');

        $statuts = array_column($body['sections']['creneaux'], 'statut');
        self::assertContains('alerte', $statuts, 'le chevauchement rejoué marque la ligne, sans bloquer le reste');
    }

    public function testDryRunWritesNothingToTheDatabase(): void
    {
        // Un parc entièrement « à créer » (gymnases, équipes, réservations inconnus) :
        // l'analyse décrit ce qui SERAIT créé mais ne persiste RIEN. On compte en base
        // avant/après — le dry-run est inerte (l'écriture est le métier de PR2).
        $this->scopeGucToClub($this->club->getId());
        $venuesBefore = $this->rowCount('venue');
        $teamsBefore = $this->rowCount('team');
        $reservationsBefore = $this->rowCount('reservation');

        $parc = $this->validParc();
        $parc['gymnases'] = [['nom' => 'Tout Neuf']];
        $parc['equipes'] = [['nom' => 'ToutNeuf', 'genre' => 'M', 'seances' => 2]];
        $parc['creneaux'] = [['gymnase' => 'Tout Neuf', 'jour' => 4, 'debut' => '18:00', 'duree' => 90, 'equipes_en_parallele' => 1]];
        $parc['reservations'] = [['equipe' => 'ToutNeuf', 'gymnase' => 'Tout Neuf', 'jour' => 4, 'debut' => '18:00']];

        [$status] = $this->analyze($parc);
        self::assertSame(200, $status);

        self::assertSame($venuesBefore, $this->rowCount('venue'), 'le dry-run ne crée aucun gymnase');
        self::assertSame($teamsBefore, $this->rowCount('team'), 'le dry-run ne crée aucune équipe');
        self::assertSame($reservationsBefore, $this->rowCount('reservation'), 'le dry-run ne crée aucune réservation');
    }

    public function testBadFormatIsRefusedWith422NamingParcV1(): void
    {
        // CÂBLAGE du validateur à l'endpoint (les règles elles-mêmes sont au niveau
        // unitaire — {@see \App\Tests\Unit\ParcImport\ParcPayloadValidatorTest}). Une
        // version inconnue → 422 avec des erreurs qui nomment parc-v1, jamais un 200.
        $parc = $this->validParc();
        $parc['format'] = 'parc-v2';

        [$status, $body] = $this->analyze($parc);

        self::assertSame(422, $status);
        self::assertNotEmpty($body['errors'] ?? [], 'le 422 doit porter des erreurs nommées');
        self::assertStringContainsString('parc-v1', implode(' ', $body['errors']));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Parc Club ' . $uid)->setSlug('parc-club-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('parc' . $uid . '@test.com');
        $this->user->setFirstName('Parc');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-07-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->provisionSeasonPlan($this->season);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /**
     * Un parc-v1 minimal recevable — chaque test l'adapte à la promesse qu'il prouve.
     *
     * @return array<string, mixed>
     */
    private function validParc(): array
    {
        return [
            'format' => 'parc-v1',
            'gymnases' => [['nom' => 'Gymnase Mateo']],
            'equipes' => [['nom' => 'SM1', 'genre' => 'M', 'seances' => 3]],
            'creneaux' => [['gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '18:00', 'duree' => 90, 'equipes_en_parallele' => 1]],
            'mutualisations' => [],
            'reservations' => [['equipe' => 'SM1', 'gymnase' => 'Gymnase Mateo', 'jour' => 2, 'debut' => '18:00']],
        ];
    }

    /**
     * POST le parc (encodé en JSON) sur l'endpoint d'analyse. Le corps EST le texte
     * parc-v1 brut (Content-Type application/json).
     *
     * @param array<string, mixed>  $parc
     * @param array<string, string> $server
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function analyze(array $parc, ?string $clubId = null, ?string $token = null, array $server = []): array
    {
        return $this->analyzeRaw((string) json_encode($parc, \JSON_THROW_ON_ERROR), $clubId, $token, $server);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function analyzeRaw(string $rawBody, ?string $clubId = null, ?string $token = null, array $server = []): array
    {
        $this->client->request(
            'POST',
            '/api/clubs/' . ($clubId ?? $this->club->getId()) . '/parc-import/analyze',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token), 'CONTENT_TYPE' => 'application/json'] + $server,
            $rawBody,
        );
        $response = $this->client->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    private function makeVenue(string $name): Venue
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = (new Venue)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($name)->setSource('manual')->setIsActive(true);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function makeTeam(string $name, int $sessionsPerWeek): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = (new Team)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($name)->setSessionsPerWeek($sessionsPerWeek)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function persistReservation(string $teamId, string $venueId, int $dayOfWeek, string $startTime): void
    {
        $this->scopeGucToClub($this->club->getId());
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($teamId)->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($startTime))
            ->setDurationMinutes(90)->setSchedulePlanId(null);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    /**
     * Un club TIERS (autre tenant) avec un gymnase et une équipe homonymes — pour
     * prouver que l'appariement ne franchit jamais la frontière de tenant.
     *
     * @return array{venueId: string, teamId: string}
     */
    private function seedForeignClubWithVenueAndTeam(string $venueName, string $teamName): array
    {
        $uid = uniqid('', true);
        $otherClub = (new Club)
            ->setName('Autre ' . $uid)->setSlug('autre-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($otherClub);
        $this->em->flush();

        // RLS WITH CHECK : scoper le GUC sur l'AUTRE club avant d'y insérer saison/gymnase/équipe.
        $this->scopeGucToClub($otherClub->getId());
        $otherSeason = (new Season)
            ->setClubId($otherClub->getId())->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-07-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($otherSeason);
        $this->em->flush();

        $venue = (new Venue)
            ->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setName($venueName)->setSource('manual')->setIsActive(true);
        $this->em->persist($venue);
        $team = (new Team)
            ->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName($teamName)->setSessionsPerWeek(3)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        // On rescope sur NOTRE club pour la suite du test (les requêtes HTTP rescopent seules).
        $this->scopeGucToClub($this->club->getId());

        return ['venueId' => $venue->getId(), 'teamId' => $team->getId()];
    }

    private function viewerToken(): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $viewer = new User;
        $viewer->setEmail('viewer' . $uid . '@test.com');
        $viewer->setFirstName('View');
        $viewer->setLastName('Only');
        $viewer->setPasswordHash($hasher->hashPassword($viewer, 'Password123!'));
        $this->em->persist($viewer);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $cu = new ClubUser;
        $cu->setClubId($this->club->getId())->setUserId($viewer->getId())->setRole('viewer')->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($viewer);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE club_id = :c',
            ['c' => $this->club->getId()],
        );
    }

    private function uuid(): string
    {
        return \sprintf(
            '%04x%04x-%04x-4%03x-8%03x-%04x%04x%04x',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
    }
}
