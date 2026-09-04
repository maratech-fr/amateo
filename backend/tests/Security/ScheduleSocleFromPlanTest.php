<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — ADR-0002 lot C4, axe *planning lifecycle* : LE SOCLE SE LIT DU PLAN.
 *
 * « Est-ce le planning principal (le socle) ? » se dérive de `plan.type === SEASON`,
 * jamais de l'absence de `Schedule.calendarEntryId` (doublon d'ancre nullable
 * supprimé en C4). Il n'y a qu'UN plan SEASON par club × saison (inv. 3), donc cette
 * lecture désigne un socle unique et non ambigu.
 *
 * Ruling fondateur (2026-07-17) : une VERSION SANS PLAN n'existe pas — le rattachement
 * est obligatoire. Un schedule non lié (`schedulePlanId` null) est une ANOMALIE : il ne
 * se fait JAMAIS passer pour le socle. Sur les chemins de DÉCISION on LÈVE (fail-loud) —
 * l'alternative silencieuse (le traiter en saison) générerait la saison avec les
 * contraintes d'une période, sans erreur ni signal : c'est le piège d'ancre nullable
 * de C2/C3, pour la troisième fois.
 *
 * Vu P4-21, ces tests ont été vérifiés en CASSANT le code d'abord (fallback socle sur
 * l'absence de plan) : ils rougissent, puis repassent au vert une fois la garde en place.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleSocleFromPlanTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Le point de passage que TOUT site de décision consomme (génération, validation,
     * régénération) : la vérité « socle ? » vient du TYPE du plan (SEASON), pas d'un doublon.
     * Une version a TOUJOURS un plan (lot D) ; si sa LIGNE de plan a disparu sous les pieds
     * (reset concurrent), la lecture LÈVE plutôt que de deviner un socle.
     */
    public function testTheSocleTruthComesFromThePlanType(): void
    {
        [$user, $club, $season] = $this->seed();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);

        // Une version de saison LIÉE : c'est le socle, et elle ne pointe aucune période.
        $seasonVersion = $this->linkedSeasonVersion($season);
        self::assertTrue($provisioner->isSeasonSchedule($seasonVersion), 'une version du plan SEASON EST le socle');
        self::assertNull($provisioner->periodEntryIdOf($seasonVersion), 'le socle ne pointe aucune période');

        // Un overlay LIÉ (plan CLOSURE/HOLIDAY) : jamais le socle, et il pointe sa période.
        [$overlay, $entryId] = $this->linkedOverlay($user, $club, $season);
        self::assertFalse($provisioner->isSeasonSchedule($overlay), 'un overlay de période n’est jamais le socle');
        self::assertSame($entryId, $provisioner->periodEntryIdOf($overlay), 'l’overlay pointe sa période via son plan');

        // Plan disparu (reset concurrent) : la ligne du plan n'existe plus → LÈVE.
        $this->scopeGucToClub($club->getId());
        $this->em->getConnection()->executeStatement('DELETE FROM schedule_plan WHERE id = :pid', ['pid' => $seasonVersion->getSchedulePlanId()]);
        $this->expectException(LogicException::class);
        $provisioner->isSeasonSchedule($seasonVersion);
    }

    /**
     * NR lot D — L'INVARIANT EST SCELLÉ EN BASE : « une version sans plan n'existe pas » n'est
     * plus une garde applicative contournable, c'est une impossibilité STRUCTURELLE. Le type PHP
     * est non-nullable (on ne peut pas construire une version orpheline) ET les colonnes
     * `schedule_plan_id` / `version_number` sont NOT NULL (la DB refuse tout INSERT sans plan).
     */
    public function testTheNoVersionWithoutPlanInvariantIsSealedInTheSchema(): void
    {
        [, $club] = $this->seed();
        $this->scopeGucToClub($club->getId());

        foreach (['schedule_plan_id', 'version_number'] as $column) {
            $nullable = $this->em->getConnection()->fetchOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_name = \'schedule\' AND column_name = :col',
                ['col' => $column],
            );
            self::assertSame('NO', $nullable, \sprintf('schedule.%s est NOT NULL — une version sans plan est impossible en base (lot D)', $column));
        }
    }

    /**
     * NR PR2 — LE CONTRAT DE CRÉATION : une version se crée SOUS un plan nommé
     * (`schedulePlanId`), plus « pour une période ». Le plan la LIE et la TYPE (`planType`) ;
     * un plan étranger/inconnu est refusé (422, validation tenant) ; sans plan ⇒ le plan
     * SEASON (le socle). Et le champ redondant `calendarEntryId` a disparu de la sortie API.
     */
    public function testAVersionIsCreatedUnderANamedPlanWhichLinksAndTypesIt(): void
    {
        [$user, $club, $season] = $this->seed();

        // Sans schedulePlanId → le socle (plan SEASON). P2-7 : une version de saison ne naît
        // plus tant que le plan SEASON en pointe une — cette sous-assertion doit donc PRÉCÉDER
        // settleSeasonPlan (sinon le POST sans plan se heurte au 409 « socle en vigueur »).
        $seasonVersion = $this->postSchedule($user, ['name' => 'V saison', 'status' => 'DRAFT']);
        self::assertSame('SEASON', $this->getSchedule($user, (string) $seasonVersion['id'])['planType'], 'sans plan nommé, la version naît sous le socle');

        // inv. 13 : un overlay se bâtit sur un socle pointé.
        $chosen = $this->settleSeasonPlan($season);
        $entryId = $this->postClosurePeriod($user);
        $periodPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entryId);
        self::assertIsString($periodPlanId, 'la période née du geste porte un plan');

        // Sous le plan de la période → overlay lié au bon plan, sans calendarEntryId exposé.
        $overlay = $this->postSchedule($user, ['name' => 'V période', 'status' => 'DRAFT', 'schedulePlanId' => $periodPlanId]);
        self::assertSame($periodPlanId, $overlay['schedulePlanId'], 'la version se rattache au plan nommé');
        self::assertArrayNotHasKey('calendarEntryId', $overlay, 'le doublon d’ancre a disparu de la sortie API (C4)');
        // planType est dérivé + batché à la LECTURE (pas dans la réponse POST) — on le relit.
        self::assertSame('CLOSURE', $this->getSchedule($user, (string) $overlay['id'])['planType'], 'son type vient du plan');

        // …et la DISCRIMINATION entre plans : le POST sans plan doit résoudre le plan SEASON
        // même quand d'AUTRES plans existent pour le club/la saison (ici celui de la période).
        // C'est ce que prouvait la position d'origine de l'assertion ci-dessus ; la garde P2-7
        // l'a forcée à remonter, donc on la re-couvre ici en rouvrant d'abord le socle — sans
        // quoi une régression d'`ensureSeasonPlanId` (perte du prédicat `type = 'SEASON'`)
        // créerait des versions de saison sous un plan de période sans qu'un test bronche.
        // On l'exerce sur le RÉSOLVEUR lui-même plutôt que par un POST : depuis P2-7 le socle
        // est en vigueur ici, donc un POST prendrait le 409 d'unicité, et le rouvrir d'abord
        // détruirait le plan de période — c'est-à-dire l'autre plan dont la présence EST la
        // condition du test.
        $seasonPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($season->getId());
        self::assertIsString($seasonPlanId);
        self::assertNotSame($periodPlanId, $seasonPlanId, 'le plan par défaut ne retombe jamais sur celui d’une période');
        self::assertSame($chosen->getSchedulePlanId(), $seasonPlanId, 'c’est bien le plan SEASON, celui qui porte le socle');

        // Un plan inconnu/étranger est refusé (le back valide l’appartenance au club).
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'x', 'status' => 'DRAFT', 'schedulePlanId' => '99999999-9999-4999-8999-999999999999',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un plan inconnu/étranger au club est refusé');
    }

    /**
     * NR P4-41 (revue #339 round 2) — le POST « de saison » (SANS `schedulePlanId`) est
     * l'autre branche du nommage serveur : elle résout le plan SEASON puis en lit le nom.
     * C'est le chemin du WIZARD, et rien ne le couvrait.
     *
     * Ce test vit ici et non près des overlays : la création d'un overlay exige un socle
     * POINTÉ (inv. 13), or un socle pointé refuse justement tout POST de saison (P2-7, 409).
     */
    public function testASeasonVersionWithoutANameInheritsTheSeasonPlansName(): void
    {
        [$user, $club, $season] = $this->seed();

        // Le plan SEASON naît AU POST (`ensureSeasonPlanId`) : on ne peut lire son nom qu'après.
        $version = $this->postSchedule($user, ['status' => 'DRAFT']);

        $planName = (string) $this->em->getConnection()->fetchOne(
            'SELECT name FROM schedule_plan WHERE id = :pid',
            ['pid' => (string) $version['schedulePlanId']],
        );
        self::assertNotSame('', $planName, 'le plan porte bien un nom par défaut');
        self::assertSame($planName, $version['name'], 'la version de saison hérite du nom de son plan SEASON');
    }

    /**
     * NR PR2 (isolation saison) — nommer un plan d'une AUTRE saison est refusé (422). Sans ce
     * garde-fou, la sélection de saison par le corps du POST contournerait la garde archive :
     * la requête passe `assertWritable` sur la saison active, puis s'estampillait de la saison
     * (potentiellement archivée) du plan — une écriture dans une saison gelée. Avant C4, le
     * find() season-filtré de l'entrée refusait déjà ce cas ; C4 (SQL brut) doit le refaire.
     */
    public function testCreatingAVersionUnderAPlanOfAnotherSeasonIsRefused(): void
    {
        [$user, $club, $season] = $this->seed();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);

        // Une AUTRE saison du même club (N-1) et son plan SEASON.
        $this->scopeGucToClub($club->getId());
        $otherYear = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1;
        $otherSeason = new Season;
        $otherSeason->setClubId($club->getId());
        $otherSeason->setName((string) $otherYear);
        $otherSeason->setStartDate(new DateTimeImmutable($otherYear . '-08-01'));
        $otherSeason->setEndDate(new DateTimeImmutable(($otherYear + 1) . '-07-15'));
        $otherSeason->setStatus(SeasonStatus::ARCHIVED);
        $otherSeason->setTransitionData([]);
        $this->em->persist($otherSeason);
        $this->em->flush();
        $otherPlanId = $provisioner->ensureSeasonPlanId($otherSeason->getId());
        $this->em->flush();
        self::assertIsString($otherPlanId);

        // La saison active (résolue) est celle du seed (année courante) ; le plan est de N-1.
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'x', 'status' => 'DRAFT', 'schedulePlanId' => $otherPlanId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un plan d’une autre saison est refusé — pas de contournement de la garde archive');
    }

    /**
     * NR lot D-b (axe *planning lifecycle*) — LA VERSION ACTIVE D'UNE PÉRIODE EST BINAIRE, et
     * elle vient du POINTEUR de son plan (`chosenScheduleId`), plus d'un `overlayScheduleId`
     * posé sur l'entrée. Plan NON validé → AUCUNE version active (le cockpit AJUSTE) ; plan
     * validé → la version choisie (le cockpit MONTRE). Décision fondateur (2026-07-18) : « plan
     * en cours on ajuste, plan validé on montre » — on ne montre JAMAIS une version non validée.
     *
     * Vu P4-21, vérifié en CASSANT le code d'abord (re-poser un pointeur à la création) :
     * l'assertion « non validé → null » rougit, puis repasse au vert une fois le pointeur mort.
     */
    public function testThePeriodActiveVersionIsTheChosenPointerBinary(): void
    {
        [$user, $club, $season] = $this->seed();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        // inv. 13 : un overlay se bâtit sur un socle pointé.
        $this->settleSeasonPlan($season);

        // Une période née du geste porte un plan, mais rien n'est encore validé.
        [$overlay, $entryId] = $this->linkedOverlay($user, $club, $season);
        self::assertNull($provisioner->chosenOfPeriodPlan($entryId), 'plan non validé → aucune version active (le cockpit ajuste)');

        // Valider = POINTER : la version choisie devient la version active de la période.
        $this->choosePlanVersion($overlay);
        self::assertSame($overlay->getId(), $provisioner->chosenOfPeriodPlan($entryId), 'plan validé → la version choisie est montrée');
    }

    /**
     * NR lot D-b — LE POINTEUR INVERSE A DISPARU DE LA BASE : `calendar_entry.overlay_schedule_id`
     * n'existe plus. La « version active » d'une période ne se lit QUE du plan (chosenScheduleId) ;
     * il n'y a plus de doublon de pointeur à maintenir ni à désynchroniser. Clôt l'ADR-0002.
     */
    public function testTheOverlayPointerIsGoneFromTheSchema(): void
    {
        [, $club] = $this->seed();
        $this->scopeGucToClub($club->getId());

        $exists = $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_name = \'calendar_entry\' AND column_name = \'overlay_schedule_id\'',
        );
        self::assertFalse((bool) $exists, 'calendar_entry.overlay_schedule_id supprimée (lot D-b) — la version active vient du plan');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postSchedule(User $user, array $body): array
    {
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed> la version relue (planType dérivé + batché par le provider)
     */
    private function getSchedule(User $user, string $id): array
    {
        $this->client->request('GET', '/api/schedules/' . $id, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function linkedSeasonVersion(Season $season): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('V socle')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule); // lot D : pose le plan AVANT de persister

        return $schedule;
    }

    /**
     * @return array{0: Schedule, 1: string} la version overlay liée + l'id de sa période
     */
    private function linkedOverlay(User $user, Club $club, Season $season): array
    {
        $entryId = $this->postClosurePeriod($user);

        $overlay = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('V overlay')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($overlay, $entryId); // lot D : pose le plan AVANT de persister

        return [$overlay, $entryId];
    }

    private function postClosurePeriod(User $user): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Gymnase fermé',
            // Fenêtre lun→dim ALIGNÉE (2 semaines pleines = un seul segment) : « d'un bloc » permis
            // sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-01',
            'periodType' => 'closure',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        // ADR-0002 amendé 2026-07-24 : le plan naît du geste Adapter, plus de la
        // création de l'entrée — les appelants d'ici ont tous besoin du plan.
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsString($created['id']);
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $created['id']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $created['id'];
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club socle-from-plan');
        $club->setSlug('csfp-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CSFP' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('csfp-' . $uid . '@test.com');
        $user->setFirstName('So');
        $user->setLastName('Cle');
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

        return [$user, $club, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
