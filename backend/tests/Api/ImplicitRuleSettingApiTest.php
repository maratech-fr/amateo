<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\CrossStack\ImplicitRulePayloadParityTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La porte API des règles implicites « bien-être » (P2-28 PR 2) : collection RÉSOLUE (toujours
 * 4), upsert par `ruleKey`, bornes de seuil et couple règle↔seuil refusés en 422 français, DELETE
 * = réinitialiser.
 */
#[Group('integration')]
final class ImplicitRuleSettingApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCollectionAlwaysResolvesTheFiveRulesWithDefaults(): void
    {
        [$user] = $this->seed();

        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        $rules = $this->members();
        self::assertSame(
            ['ageAscending', 'coachRestDay', 'maxConsecutiveDays', 'maxConsecutiveSessions', 'salarieDistribution'],
            $this->sortedKeys($rules),
            'la collection résout TOUJOURS les 5 règles, défauts compris',
        );
        foreach ($rules as $rule) {
            // ⚑ P2-42 — la règle OPT-IN est SERVIE mais éteinte. Deux besoins opposés tenus
            // ensemble : le payload moteur l'omet (donc elle ne s'applique pas), l'API la
            // montre (donc le gestionnaire peut l'allumer). L'omettre ici la rendrait
            // inaccessible : proposée nulle part, activable jamais.
            $expected = 'maxConsecutiveDays' === $rule['ruleKey'] ? 'OFF' : 'HARD';
            self::assertSame($expected, $rule['intensity'], 'sans réglage : HARD, sauf une opt-in qui naît OFF');
            self::assertTrue($rule['isDefault']);
        }
    }

    public function testUpsertStoresAndResolvedGetReflectsIt(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth);
        $coachRest = $this->member('coachRestDay');
        self::assertSame('PREFERRED', $coachRest['intensity']);
        self::assertSame(3, $coachRest['minRestDays']);
        self::assertFalse($coachRest['isDefault']);
    }

    public function testDeleteResetsToDefault(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        $this->client->request('PUT', '/api/implicit_rule_settings/maxConsecutiveSessions', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'maxConsecutive' => 5], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->client->request('DELETE', '/api/implicit_rule_settings/maxConsecutiveSessions', [], [], $auth);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth);
        $rule = $this->member('maxConsecutiveSessions');
        self::assertSame('HARD', $rule['intensity'], 'après réinitialisation, retour au défaut');
        self::assertSame(3, $rule['maxConsecutive']);
        self::assertTrue($rule['isDefault']);
    }

    public function testAThresholdOutOfBoundsIs422(): void
    {
        [$user] = $this->seed();

        // minRestDays borné 1-4 : 9 est hors bornes.
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'HARD', 'minRestDays' => 9], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAThresholdOnTheWrongRuleIs422(): void
    {
        [$user] = $this->seed();

        // ageAscending n'a pas de seuil : lui envoyer minRestDays est un contresens refusé.
        $this->client->request('PUT', '/api/implicit_rule_settings/ageAscending', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 2], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnknownRuleKeyIs404(): void
    {
        [$user] = $this->seed();

        $this->client->request('PUT', '/api/implicit_rule_settings/notARule', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'HARD'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * ADR-0002 inv. 5 — un réglage de PLAN de période est cloisonné de la portée SAISON : chacun
     * se lit et s'écrit par SA portée, l'un ne voit jamais l'autre.
     */
    public function testPlanScopeIsIsolatedFromSeasonScope(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);
        $planId = $this->adaptHolidayPlan($user);

        // Écriture PAR PLAN (schedulePlanId dans le corps).
        $this->client->request('PUT', '/api/implicit_rule_settings/maxConsecutiveSessions', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['schedulePlanId' => $planId, 'intensity' => 'PREFERRED', 'maxConsecutive' => 5], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // Lecture PAR PLAN : le réglage est là.
        $this->client->request('GET', '/api/implicit_rule_settings?schedulePlanId=' . $planId, [], [], $auth);
        $planRule = $this->member('maxConsecutiveSessions');
        self::assertSame('PREFERRED', $planRule['intensity']);
        self::assertSame(5, $planRule['maxConsecutive']);

        // Lecture PAR SAISON (sans portée) : intacte, au défaut.
        $this->client->request('GET', '/api/implicit_rule_settings', [], [], $auth);
        $seasonRule = $this->member('maxConsecutiveSessions');
        self::assertSame('HARD', $seasonRule['intensity'], 'la portée saison ne voit pas le réglage du plan');
        self::assertSame(3, $seasonRule['maxConsecutive']);
        self::assertTrue($seasonRule['isDefault']);
    }

    /**
     * DELETE en portée PLAN = RE-COPIER la valeur SAISON courante dans la ligne du plan (invariant
     * 4 lignes conservé) — surtout PAS revenir au défaut du moteur ni supprimer la ligne.
     */
    public function testPlanScopeDeleteRecopiesTheSeasonValue(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);

        // La SAISON dévie du défaut : coachRestDay PREFERRED, minRestDays 2.
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 2], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $planId = $this->adaptHolidayPlan($user);

        // Le PLAN dévie à son tour : HARD, minRestDays 4.
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['schedulePlanId' => $planId, 'intensity' => 'HARD', 'minRestDays' => 4], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // DELETE en portée plan : re-copie la valeur SAISON (PREFERRED 2), pas le défaut moteur.
        $this->client->request('DELETE', '/api/implicit_rule_settings/coachRestDay?schedulePlanId=' . $planId, [], [], $auth);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/implicit_rule_settings?schedulePlanId=' . $planId, [], [], $auth);
        $rules = $this->members();
        self::assertCount(5, $rules, 'la portée plan garde TOUJOURS ses 4 règles (invariant 4 lignes)');
        $planRule = $this->member('coachRestDay');
        self::assertSame('PREFERRED', $planRule['intensity'], 'DELETE re-copie la valeur SAISON, pas le défaut moteur');
        self::assertSame(2, $planRule['minRestDays']);
    }

    public function testAnUnknownPlanScopeIs422(): void
    {
        [$user] = $this->seed();

        $unknownPlanId = '11111111-1111-4111-8111-111111111111';
        $this->client->request('PUT', '/api/implicit_rule_settings/ageAscending', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['schedulePlanId' => $unknownPlanId, 'intensity' => 'PREFERRED'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * P4-340 — CHEMIN D'ABUS : désactiver (OFF) une règle qui n'est PAS opt-in est refusé (422,
     * message français).
     *
     * Seule `maxConsecutiveDays` est optionnelle ({@see ImplicitRuleKey::isOptIn}). Accepter OFF
     * sur une règle historique (ici `coachRestDay`) serait un piège : le résolveur l'omettrait du
     * payload et le moteur appliquerait alors son DÉFAUT HARD — le gestionnaire croirait l'avoir
     * éteinte alors qu'elle s'imposerait quand même. Le refus garde cette porte fermée.
     */
    public function testPutOffOnANonOptInRuleIsRefused(): void
    {
        [$user] = $this->seed();

        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['intensity' => 'OFF'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'OFF sur une règle non opt-in doit être refusé');

        // Décoder le JSON (API Platform échappe l'unicode : lire le CORPS BRUT manquerait « é ») ;
        // le refus doit PARLER en français (le champ exact du message reste ajustable côté impl).
        $haystack = json_encode(json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR), \JSON_UNESCAPED_UNICODE);
        self::assertMatchesRegularExpression('/désactiv|optionnelle|ne peut pas/iu', (string) $haystack, 'le 422 doit expliquer EN FRANÇAIS qu\'on ne peut pas désactiver cette règle');
    }

    /**
     * P4-340 — l'opt-in `maxConsecutiveDays`, ELLE, s'éteint (OFF) : en portée PLAN le PUT répond
     * 200 et sert une entrée INACTIVE bien formée (intensité OFF + seuil au défaut).
     *
     * Garde du repli `inactiveEntry()` au retour du PUT : quand le résolveur SAUTE l'opt-in
     * résolue OFF (correctif P4-340), `$resolved[ruleKey]` disparaît — le processor doit retomber
     * sur l'entrée d'affichage inactive, sinon il sert du vide. Reste donc VERT avant ET après le
     * correctif (jumeau payload : {@see ImplicitRulePayloadParityTest::
     * testPeriodPlanOfAFreshClubOmitsTheOptInRuleMaterializedOff}, qui prouve l'OMISSION côté moteur).
     */
    public function testPutOffOnTheOptInRuleInPlanScopeServesAnInactiveEntry(): void
    {
        [$user] = $this->seed();
        $auth = $this->authHeaders($user);
        $planId = $this->adaptHolidayPlan($user);

        $this->client->request('PUT', '/api/implicit_rule_settings/maxConsecutiveDays', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['schedulePlanId' => $planId, 'intensity' => 'OFF'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $resource = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($resource);
        self::assertSame('OFF', $resource['intensity'] ?? null, 'l\'opt-in éteinte est servie INACTIVE (intensité OFF)');
        self::assertSame(3, $resource['maxConsecutiveDays'] ?? null, 'l\'entrée inactive porte le seuil au défaut, jamais un vide');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Club, 2: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Impl API Club');
        $club->setSlug('impl-api-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('IAC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('impl-api-' . $uid . '@test.com');
        $user->setFirstName('I');
        $user->setLastName('A');
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
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /** @return list<array{ruleKey: string, intensity: string, minRestDays: ?int, maxConsecutive: ?int, isDefault: bool}> */
    private function members(): array
    {
        /** @var array{member?: list<array{ruleKey: string, intensity: string, minRestDays: ?int, maxConsecutive: ?int, isDefault: bool}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data['member'] ?? [];
    }

    /** @return array{ruleKey: string, intensity: string, minRestDays: ?int, maxConsecutive: ?int, isDefault: bool} */
    private function member(string $ruleKey): array
    {
        foreach ($this->members() as $rule) {
            if ($rule['ruleKey'] === $ruleKey) {
                return $rule;
            }
        }

        self::fail(\sprintf('la règle %s doit figurer dans la collection', $ruleKey));
    }

    /**
     * @param list<array{ruleKey: string}> $rules
     *
     * @return list<string>
     */
    private function sortedKeys(array $rules): array
    {
        $keys = array_map(static fn (array $r): string => $r['ruleKey'], $rules);
        sort($keys);

        return $keys;
    }

    /**
     * Le geste réel : POST d'une période vacances puis « Adapter » (POST /api/schedule_plans) —
     * le plan naît AVEC sa copie des 4 règles. Rend l'id du plan.
     */
    private function adaptHolidayPlan(User $user): string
    {
        $auth = $this->authHeaders($user);
        $this->client->request('POST', '/api/calendar_entries', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'kind' => 'period',
            'title' => 'Vacances',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
            'periodType' => 'holiday',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $entry = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);

        $this->client->request('POST', '/api/schedule_plans', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['calendarEntryId' => $entry['id']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $plan = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertIsString($plan['id']);

        return $plan['id'];
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
