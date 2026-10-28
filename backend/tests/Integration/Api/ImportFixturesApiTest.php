<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\FixtureStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * End-to-end FBI one-pass import over HTTP (cadrage P1-4): analyze (dry-run
 * mapping table) → import with mappings → diff/update on re-import. Also the
 * NR of the « périmètre engagé » axis: the import IS the engagement.
 */
#[Group('integration')]
final class ImportFixturesApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testAnalyzeThenOnePassImportThenDiffUpdate(): void
    {
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        // 1. ANALYZE — dry-run: the division shows up unmapped, nothing written.
        $this->upload('/api/fixtures/import/analyze', $token, $this->xlsx([
            ['D2 Poule A', 'A9001', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', $needle . ' - 1', '10/10/2026', '00:00', 'Salle Y'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $analysis = $this->responseData();
        self::assertSame(2, $analysis['totalRows']);
        self::assertSame([[
            'name' => 'D2 Poule A',
            'fbiTeamLabel' => null,
            'rowCount' => 2,
            'teamId' => null,
            'competitionId' => null,
            // P1-4 PR F2 — suggestion + poule-guard verdicts of the dry-run.
            'suggestedTeamId' => null,
            'suggestedCompetitionId' => null,
            'pouleError' => null,
            'pouleUnknownOpponents' => [],
        ]], $analysis['divisions']);

        $this->client->request('GET', '/api/fixtures', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertCount(0, $this->responseData()['member'] ?? [], 'analyze must write nothing');

        // 2. IMPORT — same file + the manager's mapping: ONE pass creates all. Chantier A PR 2 :
        // le domicile « Gymnase X » est apparié à un gymnase du club (sinon l'import est refusé).
        $venueX = $this->createClubVenue($token, 'Gymnase X');
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2 Poule A', 'A9001', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', $needle . ' - 1', '10/10/2026', '00:00', 'Salle Y'],
        ]), ['mappings' => json_encode([['division' => 'D2 Poule A', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR), 'venueMappings' => json_encode([['label' => 'Gymnase X', 'venueId' => $venueX]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        $report = $this->responseData();
        self::assertSame(2, $report['created']);
        self::assertSame(0, $report['updated']);
        self::assertSame([], $report['errors']);
        self::assertSame([], $report['unmappedDivisions']);

        // The fixtures surface on the collection; the FBI « Salle » label is
        // exposed (fact F3) and the 00:00 sentinel produced a null kickoff.
        $this->client->request('GET', '/api/fixtures', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $members = $this->responseData()['member'] ?? [];
        self::assertCount(2, $members);
        $byRef = array_column($members, null, 'externalRef');
        self::assertSame('Gymnase X', $byRef['A9001']['fbiVenueLabel'] ?? null);
        self::assertTrue(\array_key_exists('kickoffTime', $byRef['A9002']), 'kickoffTime must be exposed');
        self::assertNull($byRef['A9002']['kickoffTime']);
        // PR-3a — the review workflow fields are exposed; an imported rencontre is
        // « à traiter » (NEW), with no open deviation. reviewedAt/ffbbRencontreId
        // are null here, so omitted by skip_null_values (see FixtureApiTest for
        // the non-null cases).
        self::assertSame('NEW', $byRef['A9001']['reviewState'] ?? null);
        self::assertSame([], $byRef['A9001']['pendingDeviations'] ?? null);

        // 3. RE-IMPORT with a rescheduled date — diff/update, not skip: the
        // mapping persisted, no « mappings » field needed anymore.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2 Poule A', 'A9001', $needle . ' - 1', 'AS Voisins', '17/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', $needle . ' - 1', '10/10/2026', '00:00', 'Salle Y'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $second = $this->responseData();
        self::assertSame(0, $second['created']);
        self::assertSame(1, $second['updated']);
        self::assertSame(1, $second['unchanged']);
        self::assertSame('RESCHEDULED', $second['warnings'][0]['type'] ?? null);
    }

    public function testReconciliationDeviationsOverHttp(): void
    {
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        // Import a home match, then place it (PLACED — inside the perimeter). Chantier A PR 2 :
        // « Gymnase X » apparié à un gymnase du club (sinon l'import est refusé).
        $venueX = $this->createClubVenue($token, 'Gymnase X');
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
        ]), ['mappings' => json_encode([['division' => 'D2', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR), 'venueMappings' => json_encode([['label' => 'Gymnase X', 'venueId' => $venueX]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);

        $fixtureId = $this->placeFixture('A9300');

        // ANALYZE the same file with a rescheduled date → the deviation surfaces.
        $this->upload('/api/fixtures/import/analyze', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $analysis = $this->responseData();
        self::assertCount(1, $analysis['deviations']);
        self::assertSame($fixtureId, $analysis['deviations'][0]['fixtureId']);
        self::assertSame(['app' => '2026-10-03', 'file' => '2026-10-10'], $analysis['deviations'][0]['fields']['date']);

        // IMPORT WITHOUT a decision → intact + reported in unresolvedDeviations.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $noDecision = $this->responseData();
        self::assertCount(1, $noDecision['unresolvedDeviations']);
        self::assertArrayHasKey('depositedAt', $noDecision);
        $this->assertFixtureDate($fixtureId, '2026-10-03');

        // IMPORT WITH a take_file decision → applied, nothing left unresolved.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X'],
        ]), ['decisions' => json_encode([['fixtureId' => $fixtureId, 'field' => 'date', 'choice' => 'take_file']], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseData()['unresolvedDeviations']);
        $this->assertFixtureDate($fixtureId, '2026-10-10');

        // Freshness read (open to member, tenant+season auto): the last deposit.
        $this->client->request('GET', '/api/fbi-ingestions/latest', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $freshness = $this->responseData()['latest'] ?? null;
        self::assertIsArray($freshness);
        self::assertSame('FBI_XLSX', $freshness['source']);
    }

    public function testMappingASecondPhaseOntoTheFirstPhaseChampionshipIsRefusedOverHttp(): void
    {
        // D4 (M15 / U13F3) — l'écho HTTP du refus : rattacher la 2ᵉ phase « DFU13-3-P2 » au
        // championnat « DFU13-3 » déjà peuplé de rencontres FBI est refusé, le message remonte
        // dans le rapport d'import, et le championnat de la 1ʳᵉ phase n'est pas renommé. Twin
        // HTTP du service {@see App\Tests\Integration\Service\FbiFixtureImporterTest::testMappingADivisionOntoACompetitionAlreadyFilledWithFbiRencontresUnderAnotherLabelIsRefused}.
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        $venueX = $this->createClubVenue($token, 'Gymnase X');
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['DFU13-3', 'P1-1', $needle . ' - 1', 'AS Un', '03/10/2026', '15:30', 'Gymnase X'],
        ]), ['mappings' => json_encode([['division' => 'DFU13-3', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR), 'venueMappings' => json_encode([['label' => 'Gymnase X', 'venueId' => $venueX]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->scopeGucToClub($this->responseData()['club']['id']);
        $c1 = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $teamId, 'name' => 'DFU13-3']);
        self::assertNotNull($c1);
        $c1Id = $c1->getId();

        // La 2ᵉ phase (extérieur, aucune salle à couvrir) forcée sur le championnat de la 1ʳᵉ.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['DFU13-3-P2', 'P2-1', 'AS Deux', $needle . ' - 1', '14/11/2026', '15:30', ''],
        ]), ['mappings' => json_encode([['division' => 'DFU13-3-P2', 'teamId' => $teamId, 'competitionId' => $c1Id]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);

        // Le message du refus remonte dans le rapport (décodé en JSON — l'API sérialise les
        // apostrophes en ', cf. backend/docs/error-copy.md §rail 422).
        $report = $this->responseData();
        $refusals = array_filter($report['errors'], static fn (string $e): bool => str_contains($e, 'DFU13-3') && str_contains($e, 'championnat'));
        self::assertNotSame([], $refusals, 'le refus D4 remonte dans le rapport d\'import');

        $this->em->clear();
        self::assertSame('DFU13-3', $this->em->getRepository(Competition::class)->find($c1Id)?->getName(), 'le championnat de la 1ʳᵉ phase n\'est pas renommé');
        self::assertNull($this->em->getRepository(Fixture::class)->findOneBy(['teamId' => $teamId, 'externalRef' => 'P2-1']), 'la rencontre de la 2ᵉ phase n\'est pas greffée');
    }

    public function testVenueDeviationOnAnUnplacedHomeIsSurfacedOverHttp(): void
    {
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        // Import a home match (UNPLACED, no salle) then attach it to a club gym
        // WITHOUT placing it — the « 20 »-case state. Chantier A PR 2 : un domicile sans salle
        // dans le fichier prend un gymnase via venueChoices (sinon l'import est refusé) ; on le
        // re-pointe ensuite à la main sur GYMNASE JDR pour le scénario.
        $choiceVenue = $this->createClubVenue($token, 'Gymnase Choix');
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9600', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', ''],
        ]), ['mappings' => json_encode([['division' => 'D2', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR), 'venueChoices' => json_encode([['division' => 'D2', 'venueId' => $choiceVenue]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        $venueId = $this->attachUnplacedGym('A9600', 'GYMNASE JDR');

        // Re-import: the league names a divergent salle → the venue écart surfaces,
        // the gym stays intact and the label is NOT silently rewritten.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9600', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $report = $this->responseData();
        self::assertCount(1, $report['unresolvedDeviations']);
        self::assertSame(
            ['app' => 'GYMNASE JDR', 'file' => 'SALLE RAPHAEL DE BARROS'],
            $report['unresolvedDeviations'][0]['fields']['venue'],
        );

        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'A9600']);
        self::assertSame($venueId, $fixture?->getVenueId(), 'no decision never moves the gym');
        self::assertNull($fixture?->getFbiVenueLabel(), 'the salle label is not silently rewritten');
    }

    public function testMalformedDecisionsFieldIsRejected(): void
    {
        [$token] = $this->registerWithTeam();

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9400', 'X - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]), ['decisions' => '{ not a list }']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testImportEngagesTheTeam(): void
    {
        // NR « périmètre engagé » (§7.1) : the import IS the engagement — even
        // all-UNPLACED, the team can no longer be deleted (its matches exist
        // at the federation).
        [$token, $clubName, $teamId] = $this->registerWithTeam();

        // Chantier A PR 2 : le domicile sans salle prend un gymnase via venueChoices (sinon refus).
        $choiceVenue = $this->createClubVenue($token, 'Gymnase Engagement');
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9200', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]), ['mappings' => json_encode([['division' => 'D2', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR), 'venueChoices' => json_encode([['division' => 'D2', 'venueId' => $choiceVenue]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['created']);

        $this->client->request('DELETE', '/api/teams/' . $teamId, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409, 'an engaged team must refuse deletion');
    }

    public function testImportResponseCarriesTheLeagueValidatedCount(): void
    {
        // Chantier A — la réponse de POST /api/fixtures/import porte le compteur
        // `leagueValidated` : au dépôt, un domicile de championnat COMMENCÉ (premier match
        // joué, date passée) avec heure + gymnase (alias confirmé) bascule tout seul
        // « validé ligue ». Le détail de la MÉCANIQUE (byte-identité avec le POST manuel, les
        // cas non validables) vit dans Integration/Service/ImportLeagueAutoValidationTest ; ICI
        // seule la SURFACE exposée par l'API est en jeu.
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        // Un gymnase du club portant l'alias FBI confirmé — le domicile importé naît avec lui.
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $clubId = $this->responseData()['club']['id'];
        self::assertIsString($clubId);
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase Matéo');
        $venue->setSource('manual');
        $venue->setExternalLabels(['gymnase mateo']);
        $this->em->persist($venue);
        $this->em->flush();

        // Un domicile daté DANS LE PASSÉ (premier match joué) → championnat commencé.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D CHAMP', 'LV1', $needle . ' - 1', 'AS Voisins', '19/09/2020', '15:30', 'GYMNASE MATEO'],
        ]), ['mappings' => json_encode([['division' => 'D CHAMP', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['leagueValidated'] ?? null, 'la réponse d\'import porte le compte des domiciles auto-validés');
    }

    public function testImportRefusedWhileSocleNotValidated(): void
    {
        // SocleGuard path on BOTH import endpoints: without a validated main
        // plan the write must 409 — the other tests pre-stamp the socle.
        [$token, $clubName] = $this->registerWithTeam(validateSocle: false);

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9100', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '15:30', ''],
        ]));
        self::assertResponseStatusCodeSame(409);
    }

    public function testNonXlsxUploadIsRejected(): void
    {
        [$token] = $this->registerWithTeam();

        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.csv';
        file_put_contents($path, 'not;an;xlsx');
        $this->tempFiles[] = $path;

        $this->client->request('POST', '/api/fixtures/import', [], [
            'file' => new UploadedFile($path, 'fbi.csv', 'text/csv', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testMalformedMappingsFieldIsRejected(): void
    {
        [$token, $clubName] = $this->registerWithTeam();

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]), ['mappings' => '{"not":"a list"}']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testUploadIniSizeErrorReturns413(): void
    {
        // SEC-22 — falsification native : un upload marqué UPLOAD_ERR_INI_SIZE (le
        // fichier a dépassé la limite PHP) doit répondre 413 nommant la borne. Sur
        // le code d'AVANT le garde, ce chemin tombait dans le 422 générique.
        [$token] = $this->registerWithTeam();

        $path = tempnam(sys_get_temp_dir(), 'ini') . '.xlsx';
        file_put_contents($path, '');
        $this->tempFiles[] = $path;

        $this->client->request('POST', '/api/fixtures/import', [], [
            'file' => new UploadedFile($path, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', \UPLOAD_ERR_INI_SIZE, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('2 Mo', $this->errorMessage());
    }

    public function testUploadOverTwoMegabytesIsRefusedOnImport(): void
    {
        [$token] = $this->registerWithTeam();

        $this->client->request('POST', '/api/fixtures/import', [], [
            'file' => new UploadedFile($this->oversizedFile(), 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('2 Mo', $this->errorMessage());
    }

    public function testUploadOverTwoMegabytesIsRefusedOnAnalyze(): void
    {
        // Le MÊME refus sur le dry-run : les deux endpoints partagent le garde.
        [$token] = $this->registerWithTeam();

        $this->client->request('POST', '/api/fixtures/import/analyze', [], [
            'file' => new UploadedFile($this->oversizedFile(), 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('2 Mo', $this->errorMessage());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Horloge figée : les rencontres d'octobre de ces scénarios doivent rester des
        // matchs FUTURS hors de la semaine ISO en cours, sinon treatOnArrival les naît
        // « traitées » (REVIEWED) et « FBI fait foi » applique d'office les écarts
        // imminents (P4-199) — sinon ces tests, verts un dimanche, rougissent le lundi.
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-16 10:00:00'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        // Relâche l'horloge épinglée (Redis partagé, non rollbacké entre tests).
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /**
     * Crée un gymnase du club (saison courante) et rend son id — chantier A PR 2 : un domicile
     * importable ne naît jamais sans gymnase, l'appariement de salles (venueMappings/venueChoices)
     * pointe un gymnase EXISTANT. Scope GUC rétabli au club pour l'écriture.
     */
    private function createClubVenue(string $token, string $name): string
    {
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $clubId = $this->responseData()['club']['id'];
        self::assertIsString($clubId);
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /** Places the imported fixture (PLACED + a venue id) and returns its id. */
    private function placeFixture(string $externalRef): string
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture, 'imported fixture must exist');
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setVenueId('11111111-1111-4111-8111-111111111111');
        $this->em->flush();

        return $fixture->getId();
    }

    /** Attaches an imported fixture to a real club gym WITHOUT placing it (stays UNPLACED). */
    private function attachUnplacedGym(string $externalRef, string $venueName): string
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture, 'imported fixture must exist');
        $this->scopeGucToClub($fixture->getClubId());
        $venue = new Venue;
        $venue->setClubId($fixture->getClubId());
        $venue->setSeasonId($fixture->getSeasonId());
        $venue->setName($venueName);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $fixture->setVenueId($venue->getId());
        $this->em->flush();

        return $venue->getId();
    }

    private function assertFixtureDate(string $id, string $expected): void
    {
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->find($id);
        self::assertNotNull($fixture);
        self::assertSame($expected, $fixture->getMatchDate()->format('Y-m-d'));
    }

    private function upload(string $uri, string $token, string $filePath, array $parameters = []): void
    {
        $this->client->request('POST', $uri, $parameters, [
            'file' => new UploadedFile($filePath, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /**
     * Register a club (its name is the HOME/AWAY needle) + create a team in the
     * register-seeded season.
     *
     * @return array{0: string, 1: string, 2: string} [token, clubName, teamId]
     */
    private function registerWithTeam(bool $validateSocle = true): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'fbi' . substr(md5(uniqid('', true)), 0, 6);
        $clubName = 'BC ' . ucfirst($suffix);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Bi', 'ara' => strtoupper($suffix), 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        $clubId = $me['club']['id'];

        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        // SocleGuard: fixture import is a match-module write, refused (409) until
        // the season's plan points at a version — settle it like the real flow
        // would (opt-out for the test covering the 409 branch itself).
        if ($validateSocle) {
            $this->settleSeasonPlan($season);
        }

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        self::assertNotNull($sport, 'register seeds the basketball sport');
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return [$token, $clubName, $team->getId()];
    }

    /** @param list<list<string>> $rows real-format header (fact F1/F8) */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** 2,1 Mo d'octets quelconques nommés .xlsx : la borne octets tombe avant tout parsing. */
    private function oversizedFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'big') . '.xlsx';
        file_put_contents($path, str_repeat('x', (int) (2.1 * 1024 * 1024)));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function errorMessage(): string
    {
        return (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '');
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
