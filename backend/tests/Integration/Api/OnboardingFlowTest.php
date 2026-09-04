<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Service\SeasonResolver;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Onboarding happy path (minimal), driven through the real API with a real JWT
 * (not loginUser). A brand-new club is empty (isolation), the manager enters the
 * minimum (team + gym slot + coach), creates a schedule and launches generation:
 * the club becomes onboarded and the schedule is queued. The COMPLETED plan
 * itself (engine solve) is covered by the Behat feature
 * inscription-et-premier-planning.feature.
 */
#[Group('phase1')]
#[Group('integration')]
final class OnboardingFlowTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    use VerifiesRegistration;

    private static int $ip = 0;

    private KernelBrowser $client;

    private string $token = '';

    public function testMinimalOnboardingCompletesAndQueuesGeneration(): void
    {
        // 1. Create the account (new club → active admin, real JWT).
        $this->token = $this->register('ONB' . uniqid());
        self::assertNotSame('', $this->token, 'register returns a JWT');

        // 2. A fresh club is EMPTY (tenant isolation — no other club's data leaks).
        self::assertCount(0, $this->get('/api/teams')['member']);
        self::assertCount(0, $this->get('/api/venues')['member']);
        $me = $this->get('/api/me');
        self::assertFalse($me['club']['onboardingCompleted']);

        // NR (retour fondateur 2026-07-18) : un club neuf connaît son sport de
        // première main — basket, le sport de ses catégories. Lu via la connexion
        // admin (club porte du RLS ; l'admin bypasse). Axe auth & memberships.
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($admin instanceof Connection);
        $clubSportId = $admin->fetchOne('SELECT sport_id FROM club WHERE id = ?', [$me['club']['id']]);
        self::assertNotNull($clubSportId, 'une inscription neuve pose le sport du club');
        self::assertSame($admin->fetchOne('SELECT id FROM sport WHERE slug = \'basketball\''), $clubSportId, 'le sport du club neuf est basketball');

        // Just-subscribed state: exactly ONE season, current, writable (not
        // read-only), and no validated socle yet (the cockpit gate sends the
        // fresh club to the work-loop). Guards the historical endDate <
        // startDate seed bug too (window must stay coherent).
        self::assertCount(1, $me['seasons']);
        self::assertSame($me['seasons'][0]['id'], $me['currentSeasonId']);
        self::assertTrue($me['seasons'][0]['isCurrent']);
        self::assertFalse($me['seasons'][0]['isReadonly']);
        // Un club neuf : le plan de la saison existe dès sa naissance, vide — aucune
        // version, donc rien de pointé et rien de terminé (le cockpit reste verrouillé,
        // le wizard est la maison). Assertion sur `seasonPlan` : `socleValidatedAt`
        // n'existe plus, et l'assertion qui le lisait passait sur une clé absente.
        self::assertNotNull($me['seasonPlan'], 'une saison possède son plan SEASON dès sa création');
        self::assertNull($me['seasonPlan']['chosenScheduleId']);
        self::assertFalse($me['seasonPlan']['hasFinishedVersion']);
        self::assertGreaterThan($me['seasons'][0]['startDate'], $me['seasons'][0]['endDate']);
        // NR (fondateur 2026-07-24) : nom « YYYY-YYYY+1 » (jamais mono-année, ambigu)
        // et fenêtre alignée sur le pivot système du 15 juillet — un club inscrit en
        // janvier rejoint la saison EN COURS, pas une saison future.
        $seasonYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        self::assertSame($seasonYear . '-' . ($seasonYear + 1), $me['seasons'][0]['name'], 'le nom par défaut couvre les deux années civiles');
        self::assertSame($seasonYear . '-07-15', $me['seasons'][0]['startDate'], 'la fenêtre démarre au pivot système (15 juillet)');
        self::assertSame(($seasonYear + 1) . '-07-14', $me['seasons'][0]['endDate'], 'la fenêtre finit la veille du pivot suivant');

        // P2-16 (décision fondateur 2026-08-04) — « la page pas vierge » : un club
        // neuf naît avec ses contraintes de base, TOUTES en PREFERRED (du HARD semé
        // peut rendre INFEASIBLE un club atypique dès son premier planning), visibles
        // et supprimables comme n'importe quelle contrainte. La liste exacte est un
        // contrat : jeunes ≤ 19h30 · baby ≤ 18h30 · EMB ≤ 19h · adultes ≥ 19h ·
        // pas le dimanche. « Pas après » = maxStartTime (maxEndTime n'existe qu'en HARD).
        $seeded = $this->get('/api/constraints')['member'];
        self::assertCount(5, $seeded, 'un club neuf naît avec ses 5 contraintes de base');
        $byTag = [];
        foreach ($seeded as $row) {
            self::assertSame('PREFERRED', $row['ruleType'], 'jamais de HARD semé : ' . $row['name']);
            self::assertSame('onboarding_seed', $row['source']);
            $byTag[$row['config']['targetTag'] ?? 'CLUB'] = $row;
        }
        self::assertSame('19:30', $byTag['JEUNE']['config']['maxStartTime']);
        self::assertSame('18:30', $byTag['BABY']['config']['maxStartTime']);
        self::assertSame('19:00', $byTag['EMB']['config']['maxStartTime']);
        self::assertSame('19:00', $byTag['ADULTE']['config']['minStartTime']);
        self::assertSame([7], $byTag['CLUB']['config']['forbiddenDays'], 'le dimanche (7) est exclu pour tout le club');

        // 3. Minimal data: one team, one gym with a slot, one coach.
        $categoryId = $this->get('/api/sport_categories')['member'][0]['id'];
        $this->post('/api/teams', ['name' => 'SM1', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        self::assertResponseStatusCodeSame(201);

        $venue = $this->post('/api/venues', ['name' => 'Gym A', 'source' => 'manual']);
        self::assertResponseStatusCodeSame(201);
        $this->post('/api/venue_training_slots', ['venueId' => $venue['id'], 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]);
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/coaches', ['firstName' => 'Jean', 'isEmployee' => true]);
        self::assertResponseStatusCodeSame(201);

        // 4. Create a schedule and launch generation.
        $schedule = $this->post('/api/schedules', ['name' => 'Mon planning', 'status' => 'DRAFT']);
        self::assertResponseStatusCodeSame(201);
        $this->post('/api/schedules/' . $schedule['id'] . '/generate', null);
        self::assertResponseStatusCodeSame(202);

        // 5. Onboarding is completed on launch; the schedule is queued.
        self::assertTrue($this->get('/api/me')['club']['onboardingCompleted'], 'launching generation completes onboarding');
        self::assertContains($this->get('/api/schedules/' . $schedule['id'])['status'], ['PENDING', 'GENERATING', 'COMPLETED']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep the same kernel across requests so the JWT auth ordering is exercised.
        self::getContainer()->get(EntityManagerInterface::class);
    }

    private function register(string $ara): string
    {
        // SEC-16 : le cookie d’auth de l’identité précédente ne doit pas partir
        // avec cette inscription (sinon 429 du quota par utilisateur).
        $this->startFreshBrowserSession($this->client);
        $ip = '10.7.' . intdiv(self::$ip, 254) . '.' . (self::$ip % 254 + 1);
        ++self::$ip;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => strtolower($ara) . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'On', 'lastName' => 'Board', 'ara' => strtoupper($ara), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        return $this->verifyRegistration($this->client, strtolower($ara) . '@test.fr');
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, ?array $body): array
    {
        $this->client->request('POST', $path, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/ld+json',
        ], null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
