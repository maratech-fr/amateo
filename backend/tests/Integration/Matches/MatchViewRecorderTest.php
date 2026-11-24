<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matches;

use App\Entity\Fixture;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\ScheduleStatus;
use DateTimeImmutable;
use App\Matches\MatchViewRecorder;
use App\Tests\EngineRequestDecorTrait;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse : les vues des matchs enregistrées par l'outil de preuve (`app:engine-requests:record`,
 * `views/club-<id>/`) sont exactement ce que le gestionnaire reçoit de `GET /api/fixtures` et de
 * `GET /api/fixtures/conflicts` — triées (matchs par identifiant, conflits par empreinte) pour qu'une
 * différence entre deux enregistrements soit une différence de données, jamais d'ordre.
 *
 * C'est ce qui fait des vues le filet d'une migration du lot 7 là où la demande au moteur ne voit rien
 * (l'import et le radar) : une vue qui s'écarterait de l'API laisserait passer une différence servie.
 *
 * Décor : `EngineRequestDecorTrait` (socle en vigueur, matchs du décor), plus un domicile U15 posé au
 * gymnase Sud qui lui est interdit, pour qu'au moins deux conflits soient servis, l'un portant un statut
 * de traitement posé par l'API (témoins vérifiés) : l'égalité garde aussi la forme de `resolution`, que
 * la vue recopie du contrôleur (`FixtureConflictsController::resolutionView`, privée).
 * Jumeau, cité : `Integration/Command/EngineRequestsRecordCommandTest` (les vues sur disque : identiques
 * d'un passage à l'autre, sans écriture ni balayage, sans nom en clair, sans mêler deux clubs).
 */
#[Group('integration')]
final class MatchViewRecorderTest extends WebTestCase
{
    use EngineRequestDecorTrait;

    private KernelBrowser $client;

    public function testTheRecordedViewsAreWhatTheApiServes(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
        $this->seedEngineRequestMatches();
        $this->scopeGucToClub(self::ER_CLUB);
        $this->erEm()->getConnection()->executeStatement(
            'UPDATE fixture SET venue_id = ?, kickoff_time = ?, status = ?, placement_source = ? WHERE id = ?',
            [self::ER_VENUE_SUD, '14:00', 'PLACED', 'MANUAL', self::erId('f1c7', 2)],
        );
        $this->erEm()->clear();

        // Un statut de traitement posé par le gestionnaire sur le premier conflit : la vue recopie la
        // forme du champ `resolution` du contrôleur, cette égalité est sa parité.
        $first = $this->getJson('/api/fixtures/conflicts')['conflicts'][0]['fingerprint'] ?? null;
        self::assertIsString($first, 'témoin : le décor sert au moins un conflit');
        $this->request('PUT', '/api/fixtures/conflicts/' . $first . '/resolution', '{"status":"RESOLVED_INTERNALLY","note":"Vu avec le gymnase"}');

        $servedFixtures = $this->getJson('/api/fixtures');
        $servedConflicts = $this->getJson('/api/fixtures/conflicts')['conflicts'] ?? null;
        self::assertIsArray($servedConflicts);
        self::assertGreaterThanOrEqual(2, \count($servedConflicts), 'témoin : le décor sert au moins deux conflits (l\'ordre se prouve)');
        self::assertNotSame([], array_filter($servedConflicts, static fn (mixed $c): bool => \is_array($c) && \is_array($c['resolution'] ?? null)), 'témoin : un conflit servi porte son statut de traitement');
        self::assertCount(4, $servedFixtures, 'témoin : les quatre matchs du décor sont servis');

        $this->clearGuc();
        $recorded = self::getContainer()->get(MatchViewRecorder::class)->record(self::ER_CLUB, self::ER_SEASON);

        self::assertSame($this->sortedBy($servedFixtures, 'id'), $recorded['fixtures'], 'la vue des matchs = GET /api/fixtures, triée par identifiant');
        self::assertSame($this->sortedBy($servedConflicts, 'fingerprint'), $recorded['conflicts'], 'la vue des conflits = GET /api/fixtures/conflicts, triée par empreinte');
    }

    /**
     * La vue des matchs est TOUT le contenu servi, pas sa première page : au-delà de la taille de page
     * de `GET /api/fixtures` (100), elle vaut les pages servies mises bout à bout.
     */
    public function testTheFixturesViewCarriesEveryPageNotTheFirstOne(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
        $this->seedEngineRequestMatches();
        $em = $this->erEm();
        for ($n = 101; $n <= 201; ++$n) {
            $fixture = (new Fixture)->setId(self::erId('f1c7', $n));
            $fixture->setClubId(self::ER_CLUB);
            $fixture->setSeasonId(self::ER_SEASON);
            $fixture->setTeamId(self::ER_TEAM_SM1);
            $fixture->setCompetitionId(self::erId('c0e9', 3));
            $fixture->setMatchDate(new DateTimeImmutable('2026-02-01')->modify('+' . ($n - 101) . ' days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adversaire ' . $n);
            $em->persist($fixture);
        }
        $em->flush();
        $em->clear();

        $firstPage = $this->getJson('/api/fixtures?page=1');
        $secondPage = $this->getJson('/api/fixtures?page=2');
        self::assertCount(100, $firstPage, 'témoin : la première page servie est pleine');
        self::assertCount(5, $secondPage, 'témoin : 105 matchs servis sur deux pages');

        $this->clearGuc();
        $recorded = self::getContainer()->get(MatchViewRecorder::class)->record(self::ER_CLUB, self::ER_SEASON);

        self::assertSame($this->sortedBy([...$firstPage, ...$secondPage], 'id'), $recorded['fixtures'], 'la vue des matchs = toutes les pages servies, triées par identifiant');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }

    /** @return array<mixed> */
    private function getJson(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /** @return array<mixed> */
    private function request(string $method, string $path, ?string $body): array
    {
        $user = $this->erEm()->find(User::class, self::ER_USER);
        self::assertInstanceOf(User::class, $user);
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $body);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $method . ' ' . $path . ' : ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<mixed>
     */
    private function sortedBy(array $rows, string $key): array
    {
        $rows = array_values($rows);
        usort($rows, static fn (mixed $a, mixed $b): int => strcmp(
            \is_array($a) && \is_string($a[$key] ?? null) ? $a[$key] : '',
            \is_array($b) && \is_string($b[$key] ?? null) ? $b[$key] : '',
        ));

        return $rows;
    }
}
