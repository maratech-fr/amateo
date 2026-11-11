<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\CalendarEntryPeriodType;
use App\Service\PeriodPlanAutoValidator;
use App\Tests\PeriodPlanAutoValidationDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D8/D9 — l'API sert, par planning de période, `autoValidated`, `alert` et `radarDismissed`
 * (calculés en LOT : une collection de N plans coûte le même nombre de requêtes qu'une de 2), et
 * « J'ai vu » (`POST /api/schedule_plans/{id}/alert-seen`) éteint l'alerte pour TOUT le club —
 * réservé au gestionnaire de CE club, sur un type d'alerte connu.
 *
 * Le jour du club est piloté par l'horloge d'un club de DÉMO (le serveur lit `ClubDay`), jamais
 * par le jour réel du run. Jumeau de fond : `Security/PeriodPlanAutoValidationTest` (faits,
 * seuil, fuseaux) ; jumeau pur : `Unit/Service/PeriodPlanAlertResolverTest`.
 */
#[Group('integration')]
final class SchedulePlanAlertSeenTest extends WebTestCase
{
    use PeriodPlanAutoValidationDecorTrait;
    use StartsFreshBrowserSession;

    /** Jour simulé du club : 28/12/2026, 7ᵉ jour des vacances de Noël (21/12 → 03/01). */
    private const string TODAY = '2026-12-28';

    private KernelBrowser $client;

    /**
     * C7 — la collection sert les champs d'alerte pour chaque plan, et les FAITS d'alerte se lisent
     * en un nombre de requêtes constant (zéro N+1) : quatre plans de plus, pas une requête de faits
     * de plus. Le compteur ne vise que les requêtes qui lisent `alert_seen_kind` — colonne que seuls
     * les faits (et le chargement des plans) lisent — parce que le provider porte déjà, hors de ce
     * chantier, quatre requêtes PAR plan (`parent_entry_id`, `title`, existence de versions, saison :
     * mesuré 9 requêtes pour 2 plans, 27 pour 6, avant P4-503) qu'un compte global attribuerait à tort.
     */
    public function testTheCollectionServesAlertsWithoutOneFactsQueryPerPlan(): void
    {
        ['club' => $club, 'season' => $season, 'token' => $token] = $this->demoClub('LOT');
        $started = $this->seedDecorPeriod($season, '2026-12-21', '2027-01-03')['planId'];
        $auto = $this->autoValidatedPlanWithASibling($club, $season, '2026-12-14', '2026-12-31');

        [$small, $smallCount] = $this->collection($token);
        self::assertSame(['kind' => 'started_without_plan'], $small[$started]['alert'] ?? 'absent');
        self::assertSame('auto_validated', $small[$auto]['alert']['kind'] ?? 'absent');
        self::assertTrue($small[$auto]['autoValidated'] ?? null);

        foreach (['2026-12-01', '2026-12-08', '2026-12-15', '2026-12-22'] as $i => $start) {
            $this->seedDecorPeriod($season, $start, new DateTimeImmutable($start)->modify('+6 days')->format('Y-m-d'), CalendarEntryPeriodType::CLOSURE, title: 'Fermeture ' . $i);
        }
        [$large, $largeCount] = $this->collection($token);

        self::assertCount(\count($small) + 4, $large, 'décor : quatre plans de plus servis');
        self::assertGreaterThanOrEqual(1, $smallCount, 'les faits d\'alerte sont lus en base (témoin du compteur)');
        self::assertSame($smallCount, $largeCount, 'quatre plans de plus ne coûtent aucune requête de faits de plus (lot)');
    }

    /** C7 — l'item sert exactement les mêmes champs que la collection pour ce plan. */
    public function testTheItemServesTheSameAlertFieldsAsTheCollection(): void
    {
        ['club' => $club, 'season' => $season, 'token' => $token] = $this->demoClub('ITEM');
        $auto = $this->autoValidatedPlanWithASibling($club, $season, '2026-12-14', '2026-12-31');

        [$collection] = $this->collection($token);
        $item = $this->getPlan($token, $auto);

        foreach (['autoValidated', 'alert', 'radarDismissed'] as $field) {
            self::assertArrayHasKey($field, $item);
            self::assertSame($collection[$auto][$field], $item[$field], $field . ' : item et collection disent la même chose');
        }
    }

    /** D9 — « J'ai vu » sur « commencée sans planning » : 200 daté du jour du club, plus d'alerte, période hors radar, pour TOUT le club. */
    public function testManagerMarksStartedWithoutPlanSeenForTheWholeClub(): void
    {
        ['club' => $club, 'season' => $season, 'token' => $token] = $this->demoClub('SEEN');
        $planId = $this->seedDecorPeriod($season, '2026-12-21', '2027-01-03')['planId'];
        $otherManagerToken = $this->decorToken($this->seedDecorMember($club, 'admin'));

        $body = $this->postSeen($token, $planId, 'started_without_plan');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => $planId, 'alertSeenDate' => self::TODAY], $body, 'réponse : le plan et le jour du club, en Y-m-d');
        $seenByOther = $this->getPlan($otherManagerToken, $planId);
        self::assertNull($seenByOther['alert'], 'un autre gestionnaire du club ne voit plus l\'alerte');
        self::assertTrue($seenByOther['radarDismissed'], 'la période sort du radar pour tout le club');
    }

    /** D9 — « J'ai vu » sur « validée automatiquement » éteint la carte ; le plan reste validé, il n'est pas « hors radar ». */
    public function testManagerMarksAutoValidatedSeen(): void
    {
        ['club' => $club, 'season' => $season, 'token' => $token] = $this->demoClub('SEENAUTO');
        $planId = $this->autoValidatedPlanWithASibling($club, $season, '2026-12-14', '2026-12-31');

        $this->postSeen($token, $planId, 'auto_validated');

        self::assertResponseStatusCodeSame(200);
        $plan = $this->getPlan($token, $planId);
        self::assertNull($plan['alert']);
        self::assertTrue($plan['autoValidated'], 'la marque reste');
        self::assertFalse($plan['radarDismissed']);
    }

    /** Abus 3 — un membre (lecture seule) est refusé 403 et rien n'est écrit. */
    public function testMemberIsRefusedAndNothingIsWritten(): void
    {
        ['club' => $club, 'season' => $season, 'token' => $token] = $this->demoClub('MBR');
        $planId = $this->seedDecorPeriod($season, '2026-12-21', '2027-01-03')['planId'];
        $memberToken = $this->decorToken($this->seedDecorMember($club, 'member'));

        $this->postSeen($memberToken, $planId, 'started_without_plan');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('started_without_plan', $this->getPlan($token, $planId)['alert']['kind'] ?? null, 'rien écrit : l\'alerte reste');
    }

    /**
     * Tenant — le gestionnaire d'un AUTRE club ne marque jamais le plan d'un club (403/404) et rien
     * n'est écrit. « Rien écrit » se lit dans la BASE (les deux colonnes de la vue restent nulles),
     * pas par un GET du propriétaire après la requête de l'intrus : sous `disableReboot`, le filtre
     * Doctrine `tenant_filter` posé pour l'intrus survit jusqu'à la requête suivante et y filtre
     * l'adhésion du propriétaire (404 de harnais, reproduit avec un simple GET de l'intrus à la
     * place du POST). Le GET du propriétaire AVANT l'intrusion reste le témoin que l'alerte existe.
     */
    public function testAnotherClubManagerCannotMarkThePlan(): void
    {
        ['club' => $owner, 'season' => $season, 'token' => $token] = $this->demoClub('OWNER');
        $planId = $this->seedDecorPeriod($season, '2026-12-21', '2027-01-03')['planId'];
        self::assertSame('started_without_plan', $this->getPlan($token, $planId)['alert']['kind'] ?? null, 'témoin : l\'alerte existe avant l\'intrusion');
        ['token' => $intruderToken] = $this->demoClub('INTRUDER');

        $this->postSeen($intruderToken, $planId, 'started_without_plan');

        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'un autre club est refusé');
        $this->scopeGucToClub($owner->getId());
        $seen = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT alert_seen_kind, alert_seen_date FROM schedule_plan WHERE id = :id',
            ['id' => $planId],
        );
        self::assertSame(['alert_seen_kind' => null, 'alert_seen_date' => null], $seen, 'rien écrit : aucune vue posée sur le plan du propriétaire');
    }

    /** Un type d'alerte inconnu est refusé 422, message en clair, rien écrit. */
    public function testUnknownKindIsRefused(): void
    {
        ['season' => $season, 'token' => $token] = $this->demoClub('KIND');
        $planId = $this->seedDecorPeriod($season, '2026-12-21', '2027-01-03')['planId'];

        $body = $this->postSeen($token, $planId, 'whatever');

        self::assertResponseStatusCodeSame(422);
        self::assertContains('Type d\'alerte inconnu.', $this->strings($body));
        self::assertSame('started_without_plan', $this->getPlan($token, $planId)['alert']['kind'] ?? null);
    }

    /** Un plan inconnu : 404 « Planning introuvable. ». */
    public function testUnknownPlanIs404(): void
    {
        ['token' => $token] = $this->demoClub('NOPLAN');

        $body = $this->postSeen($token, '00000000-0000-4000-8000-0000000000ff', 'started_without_plan');

        self::assertResponseStatusCodeSame(404);
        self::assertContains('Planning introuvable.', $this->strings($body));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    /** @return array{club: Club, season: Season, token: string} */
    private function demoClub(string $tag): array
    {
        ['club' => $club, 'seasons' => [$season], 'manager' => $manager] = $this->seedDecorClub($tag, simulatedToday: self::TODAY);

        return ['club' => $club, 'season' => $season, 'token' => $this->decorToken($manager)];
    }

    /** Un plan auto-validé au jour du club, sa version chargée pointée, une sœur gardée → carte « validée automatiquement ». */
    private function autoValidatedPlanWithASibling(Club $club, Season $season, string $start, string $end): string
    {
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, $start, $end, CalendarEntryPeriodType::CLOSURE, title: 'Fermeture auto-validée');
        $this->seedDecorVersion($season, $entryId);
        $this->seedDecorVersion($season, $entryId);

        $this->scopeGucToClub($club->getId());
        $validator = self::getContainer()->get(PeriodPlanAutoValidator::class);
        \assert($validator instanceof PeriodPlanAutoValidator);
        $validator->sweepClub($club, new DateTimeImmutable(self::TODAY));
        self::assertNotNull($this->decorChosenOf($club->getId(), $planId), 'décor : le plan est auto-validé');

        return $planId;
    }

    /**
     * Les plans servis par la collection, indexés par id, et le nombre de requêtes SQL de l'appel
     * qui lisent les faits d'alerte (`alert_seen_kind`).
     *
     * @return array{0: array<string, array<string, mixed>>, 1: int}
     */
    private function collection(string $token): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        \assert($holder instanceof DebugDataHolder);
        $holder->reset();

        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/schedule_plans', [], [], $this->headers($token));
        self::assertResponseIsSuccessful();
        $queries = \count(array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'alert_seen_kind'),
        ));

        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        $byId = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $byId[(string) $row['id']] = $row;
        }

        return [$byId, $queries];
    }

    /** @return array<string, mixed> */
    private function getPlan(string $token, string $planId): array
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/schedule_plans/' . $planId, [], [], $this->headers($token));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** @return array<mixed> */
    private function postSeen(string $token, string $planId, string $kind): array
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', \sprintf('/api/schedule_plans/%s/alert-seen', $planId), [], [], $this->headers($token) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['kind' => $kind], \JSON_THROW_ON_ERROR));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'];
    }

    /**
     * Toutes les chaînes d'un corps JSON décodé (le message d'erreur peut vivre sous `error`,
     * `detail` ou une violation — on vérifie le TEXTE servi, pas la forme du conteneur).
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private function strings(array $body): array
    {
        $out = [];
        array_walk_recursive($body, static function (mixed $value) use (&$out): void {
            if (\is_string($value)) {
                $out[] = $value;
            }
        });

        return $out;
    }
}
