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
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — D3 v2 (P4-174, décision fondateur 2026-09-05), axe *planning lifecycle* : RE-DATER UNE
 * INDISPONIBILITÉ DÉCOUPÉE (mère CLOSURE à ≥ 1 enfant, sans plan-bloc) = APERÇU servi + CONFIRMATION.
 *
 * On annonce, on confirme, on applique — jamais de refus, jamais de destruction silencieuse. La
 * nouvelle fenêtre offre de nouveaux segments (mêmes trous : vacances, fenêtres d'autres familles) ;
 * on apparie les anciens enfants, le RÔLE primant (start↔start, end↔end, milieux par chevauchement
 * de lundis). Ce test verrouille les six cas Matéo (milieu 31/08→11/10 + fin 12/10→18/10, recréés en
 * décor jetable) plus Toussaint, les vacances qui ont la main, le jeton périmé, le PUT sans jeton, et
 * l'invariant « aperçu == effets appliqués » :
 *  ①  fin → ven 09/10 : milieu rétrécit, fin glisse 05-11/10, versions CONSERVÉES ;
 *  ②  fin → dim 18/10 : fin ABSORBÉE, milieu étendu ;
 *  ③  fin → dim 11/10 : fin DISPARAÎT ;
 *  ④  fin → mer 21/10 : fin glisse 19-25/10 (le RÔLE prime le chevauchement) ;
 *  ⑤  début mer→lun : start ABSORBÉ / lun→mer : start NAÎT vide ;
 *  ⑥  fenêtre déplacée sans recouvrement : tout DISPARAÎT, tout NAÎT ;
 *  + Toussaint (deux runs milieux : rétrécir → M2 disparaît ; étendre → M2 naît) ;
 *  + fenêtre sous vacances → lignes `holiday_takes_over`, enfants disparus ;
 *  + jeton périmé → 409 ; PUT sans jeton → 422 ; invariants post-apply.
 */
#[Group('phase1')]
#[Group('integration')]
final class SplitMotherRedateTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    // ── ① fin → vendredi 09/10 : milieu rétrécit (glissement), fin glisse 05-11/10, versions conservées.
    public function testEndToFridayShrinksMiddleAndGlidesEndKeepingVersions(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-10-11'); // milieu
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');     // fin
        $middleVersion = $this->seedCompletedVersion($club, $season, $this->planOf($club, $middleId));
        $endVersion = $this->seedCompletedVersion($club, $season, $this->planOf($club, $endId));

        $this->confirm($user, $motherId, '2026-08-31', '2026-10-09');

        // Milieu rétréci sur 31/08→04/10 ; fin glissée sur 05-11/10 ; les deux plans + versions survivent.
        self::assertSame(['2026-08-31', '2026-10-04'], $this->window($club, $middleId), 'le milieu rétrécit');
        self::assertSame(['2026-10-05', '2026-10-11'], $this->window($club, $endId), 'la fin glisse 05-11/10');
        self::assertTrue($this->scheduleExists($club, $middleVersion), 'la version du milieu survit au glissement');
        self::assertTrue($this->scheduleExists($club, $endVersion), 'la version de la fin survit au glissement');
        self::assertTrue($this->planExists($club, $this->planOf($club, $middleId)));
        self::assertTrue($this->planExists($club, $this->planOf($club, $endId)));
    }

    // ── ② fin → dimanche 18/10 : la fin est ABSORBÉE, le milieu s'étend.
    public function testEndToSundayAbsorbsEndAndExtendsMiddle(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');
        $endPlanId = $this->planOf($club, $endId);

        $this->confirm($user, $motherId, '2026-08-31', '2026-10-18');

        self::assertSame(['2026-08-31', '2026-10-18'], $this->window($club, $middleId), 'le milieu s’étend jusqu’au 18/10');
        self::assertNull($this->rowWindow($club, $endId), 'la fin absorbée est supprimée');
        self::assertFalse($this->planExists($club, $endPlanId), 'le plan de la fin absorbée part en cascade');
    }

    // ── ③ fin → dimanche 11/10 : la fin DISPARAÎT, le milieu ne change pas.
    public function testEndToSunday11VanishesEnd(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');
        $endPlanId = $this->planOf($club, $endId);

        $this->confirm($user, $motherId, '2026-08-31', '2026-10-11');

        self::assertSame(['2026-08-31', '2026-10-11'], $this->window($club, $middleId), 'le milieu ne change pas');
        self::assertNull($this->rowWindow($club, $endId), 'la fin disparue est supprimée');
        self::assertFalse($this->planExists($club, $endPlanId));
    }

    // ── ④ fin → mercredi 21/10 : la fin glisse 19-25/10 — le RÔLE prime le chevauchement.
    public function testEndToWednesdayGlidesEndByRole(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');

        $this->confirm($user, $motherId, '2026-08-31', '2026-10-21');

        // Le milieu s'étend (partage ses lundis), la fin glisse vers le NOUVEAU segment de fin
        // 19-25/10 (rôle prime : par chevauchement seul elle irait au milieu).
        self::assertSame(['2026-08-31', '2026-10-18'], $this->window($club, $middleId), 'le milieu s’étend');
        self::assertSame(['2026-10-19', '2026-10-25'], $this->window($club, $endId), 'la fin glisse 19-25/10 (rôle prime)');
    }

    // ── ⑤a début mercredi → lundi : le start est ABSORBÉ par le milieu.
    public function testStartWednesdayToMondayAbsorbsStart(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Colombier fermé', '2026-09-02', '2026-09-20'); // mercredi → dimanche
        $startId = $this->postChild($user, $motherId, '2026-08-31', '2026-09-06'); // start (semaine entamée de tête)
        $middleId = $this->postChild($user, $motherId, '2026-09-07', '2026-09-20');
        $startPlanId = $this->planOf($club, $startId);

        $this->confirm($user, $motherId, '2026-08-31', '2026-09-20'); // début décalé au lundi

        self::assertSame(['2026-08-31', '2026-09-20'], $this->window($club, $middleId), 'le milieu s’étend sur la semaine complète');
        self::assertNull($this->rowWindow($club, $startId), 'le start absorbé est supprimé');
        self::assertFalse($this->planExists($club, $startPlanId));
    }

    // ── ⑤b début lundi → mercredi : un start NAÎT vide.
    public function testStartMondayToWednesdayBirthsEmptyStart(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Colombier fermé', '2026-08-31', '2026-09-20'); // lundi → dimanche
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-09-20'); // un seul milieu

        $this->confirm($user, $motherId, '2026-09-02', '2026-09-20'); // début décalé au mercredi

        // Le milieu glisse sur 07-20/09 ; un enfant + plan neufs naissent pour la semaine de tête 31/08→06/09.
        self::assertSame(['2026-09-07', '2026-09-20'], $this->window($club, $middleId), 'le milieu glisse');
        $born = $this->childWithWindow($club, $motherId, '2026-08-31', '2026-09-06');
        self::assertNotNull($born, 'un enfant de tête naît');
        self::assertTrue($this->planExists($club, $this->planOf($club, $born)), 'le start neuf porte un plan VIDE');
        self::assertSame(0, $this->versionCount($club, $this->planOf($club, $born)), 'le plan neuf n’a aucune version');
    }

    // ── ⑥ fenêtre déplacée sans recouvrement : tout l'ancien DISPARAÎT, tout le nouveau NAÎT.
    public function testWindowMovedWithNoOverlapVanishesOldAndBirthsNew(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');

        $this->confirm($user, $motherId, '2026-11-30', '2026-12-06'); // une semaine bien plus loin

        self::assertNull($this->rowWindow($club, $middleId), 'l’ancien milieu disparaît');
        self::assertNull($this->rowWindow($club, $endId), 'l’ancienne fin disparaît');
        $born = $this->childWithWindow($club, $motherId, '2026-11-30', '2026-12-06');
        self::assertNotNull($born, 'un nouvel enfant naît sur la fenêtre déplacée');
        self::assertTrue($this->planExists($club, $this->planOf($club, $born)));
    }

    // ── Toussaint (deux runs milieux) : rétrécir → M2 disparaît.
    public function testToussaintShrinkVanishesSecondMiddleRun(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // Vacances de la Toussaint matérialisées (SANS plan) : elles trouent le milieu en deux runs.
        $this->postHoliday($user, 'Toussaint', '2026-10-19', '2026-11-01');
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-09-28', '2026-11-08');
        $m1 = $this->postChild($user, $motherId, '2026-09-28', '2026-10-18'); // milieu 1
        $m2 = $this->postChild($user, $motherId, '2026-11-02', '2026-11-08'); // milieu 2 (après vacances)
        $m2Plan = $this->planOf($club, $m2);

        $this->confirm($user, $motherId, '2026-09-28', '2026-10-18'); // rétréci avant les vacances

        self::assertSame(['2026-09-28', '2026-10-18'], $this->window($club, $m1), 'le premier run ne change pas');
        self::assertNull($this->rowWindow($club, $m2), 'le second run disparaît');
        self::assertFalse($this->planExists($club, $m2Plan));
    }

    // ── Toussaint : étendre → M2 naît.
    public function testToussaintExtendBirthsSecondMiddleRun(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $this->postHoliday($user, 'Toussaint', '2026-10-19', '2026-11-01');
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-09-28', '2026-10-18');
        $m1 = $this->postChild($user, $motherId, '2026-09-28', '2026-10-18'); // un seul run

        $this->confirm($user, $motherId, '2026-09-28', '2026-11-08'); // étendu par-delà les vacances

        self::assertSame(['2026-09-28', '2026-10-18'], $this->window($club, $m1), 'le premier run ne change pas');
        $born = $this->childWithWindow($club, $motherId, '2026-11-02', '2026-11-08');
        self::assertNotNull($born, 'un second run naît après les vacances');
    }

    // ── Fenêtre sous vacances : « les vacances ont la main » — enfants disparus + ligne holiday_takes_over.
    public function testWindowUnderHolidayTakesOver(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // Vacances matérialisées AVEC plan (adaptées) : elles produisent une ligne holiday_takes_over.
        $holidayId = $this->postHoliday($user, 'Toussaint', '2026-10-19', '2026-11-01');
        $this->adaptPeriod($user, $holidayId);

        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-09-28', '2026-10-11');
        $childId = $this->postChild($user, $motherId, '2026-09-28', '2026-10-11'); // un seul milieu, hors vacances

        // Aperçu du re-datage ONTO les vacances : pas de 409 (les vacances ont la main).
        $preview = $this->preview($user, $motherId, '2026-10-19', '2026-11-01');
        $kinds = array_column($preview['effects'], 'kind');
        self::assertContains('holiday_takes_over', $kinds, 'une ligne annonce que les vacances ont la main');
        self::assertContains('vanish', $kinds, 'l’enfant passé sous les vacances disparaît');

        $this->confirm($user, $motherId, '2026-10-19', '2026-11-01', $preview['token']);
        self::assertNull($this->rowWindow($club, $childId), 'l’enfant sous vacances est supprimé');
        self::assertTrue($this->planExists($club, $this->planOf($club, $holidayId)), 'le plan de vacances survit (à régénérer)');
    }

    // ── Jeton d'aperçu périmé (un enfant a changé depuis) → 409, période intacte.
    public function testStaleTokenIsRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');

        $preview = $this->preview($user, $motherId, '2026-08-31', '2026-10-09');
        // La période change APRÈS l'aperçu : on supprime un enfant → le recalcul diffère.
        $this->client->request('DELETE', '/api/calendar_entries/' . $endId, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();

        $this->put($user, $motherId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-08-31', 'endDate' => '2026-10-09', 'previewToken' => $preview['token']]);
        self::assertResponseStatusCodeSame(409, 'un jeton d’aperçu périmé est refusé');
        self::assertStringContainsString('aperçu', (string) $this->client->getResponse()->getContent());
    }

    // ── PUT d'une mère découpée SANS jeton → 422 « demandez l'aperçu ».
    public function testPutWithoutTokenIsRejected(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');

        $this->put($user, $motherId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-08-31', 'endDate' => '2026-10-09']);
        self::assertResponseStatusCodeSame(422, 'sans jeton d’aperçu, le re-datage d’une mère découpée est refusé');
        self::assertStringContainsString('aperçu', (string) $this->client->getResponse()->getContent());
    }

    // ── L'aperçu servi == les effets réellement appliqués (mêmes verdicts).
    public function testPreviewEqualsAppliedEffects(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'Barros en travaux', '2026-08-31', '2026-10-17');
        $middleId = $this->postChild($user, $motherId, '2026-08-31', '2026-10-11');
        $endId = $this->postChild($user, $motherId, '2026-10-12', '2026-10-18');

        // Aperçu du cas ② : un glissement (milieu) + une absorption (fin).
        $preview = $this->preview($user, $motherId, '2026-08-31', '2026-10-18');
        $kinds = array_column($preview['effects'], 'kind');
        sort($kinds);
        self::assertSame(['absorb', 'shift'], $kinds, 'l’aperçu annonce un glissement et une absorption');

        $this->confirm($user, $motherId, '2026-08-31', '2026-10-18', $preview['token']);
        // Effets appliqués : le milieu a glissé (existe, re-daté), la fin a été absorbée (supprimée).
        self::assertSame(['2026-08-31', '2026-10-18'], $this->window($club, $middleId), 'glissement appliqué');
        self::assertNull($this->rowWindow($club, $endId), 'absorption appliquée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * L'aperçu : POST /redate-preview → {effects, token}.
     *
     * @return array{effects: list<array{kind: string, label: string}>, token: string}
     */
    private function preview(User $user, string $entryId, string $start, string $end): array
    {
        $this->client->request('POST', '/api/calendar_entries/' . $entryId . '/redate-preview', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startDate' => $start, 'endDate' => $end], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['effects']);
        self::assertIsString($payload['token']);

        $effects = [];
        foreach ($payload['effects'] as $effect) {
            self::assertIsArray($effect);
            self::assertIsString($effect['kind']);
            self::assertIsString($effect['label']);
            $effects[] = ['kind' => $effect['kind'], 'label' => $effect['label']];
        }

        return ['effects' => $effects, 'token' => $payload['token']];
    }

    /** Aperçu + confirmation en un geste (jeton recalculé sauf s'il est fourni). */
    private function confirm(User $user, string $entryId, string $start, string $end, ?string $token = null): void
    {
        $token ??= $this->preview($user, $entryId, $start, $end)['token'];
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => $start, 'endDate' => $end, 'previewToken' => $token]);
        self::assertResponseIsSuccessful();
    }

    /** Le geste « Adapter » : POST /api/schedule_plans — rend l'id du plan. */
    private function adaptPeriod(User $user, string $entryId): string
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function seedCompletedVersion(Club $club, Season $season, ?string $planId): string
    {
        self::assertIsString($planId);
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setSchedulePlanId($planId);
        $version->setName('V1');
        $version->setVersionNumber(1);
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        $this->em->flush();

        return $version->getId();
    }

    private function postPeriod(User $user, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, ['kind' => 'period', 'title' => $title, 'startDate' => $start, 'endDate' => $end, 'periodType' => 'closure']);
    }

    private function postHoliday(User $user, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, ['kind' => 'period', 'title' => $title, 'startDate' => $start, 'endDate' => $end, 'periodType' => 'holiday']);
    }

    private function postChild(User $user, string $parentId, string $start, string $end): string
    {
        return $this->postEntry($user, ['kind' => 'period', 'title' => 'Segment', 'startDate' => $start, 'endDate' => $end, 'periodType' => 'closure', 'parentEntryId' => $parentId]);
    }

    /** @param array<string, mixed> $body */
    private function postEntry(User $user, array $body): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /** @param array<string, mixed> $body */
    private function put(User $user, string $entryId, array $body): void
    {
        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** Le plan d'une entrée (scopé club), ou null. */
    private function planOf(Club $club, string $entryId): ?string
    {
        $this->scopeGucToClub($club->getId());
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM schedule_plan WHERE calendar_entry_id = :eid', ['eid' => $entryId]);

        return false === $id ? null : (string) $id;
    }

    private function planExists(Club $club, ?string $planId): bool
    {
        if (null === $planId) {
            return false;
        }
        $this->scopeGucToClub($club->getId());

        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM schedule_plan WHERE id = :pid', ['pid' => $planId]);
    }

    private function scheduleExists(Club $club, string $scheduleId): bool
    {
        $this->scopeGucToClub($club->getId());

        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM schedule WHERE id = :sid', ['sid' => $scheduleId]);
    }

    private function versionCount(Club $club, ?string $planId): int
    {
        if (null === $planId) {
            return 0;
        }
        $this->scopeGucToClub($club->getId());

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = :pid', ['pid' => $planId]);
    }

    /** @return array{0: string, 1: string} [start, end] Y-m-d d'une entrée existante */
    private function window(Club $club, string $entryId): array
    {
        $win = $this->rowWindow($club, $entryId);
        self::assertNotNull($win, 'l’entrée existe encore');

        return $win;
    }

    /** @return array{0: string, 1: string}|null [start, end] Y-m-d, ou null si l'entrée n'existe plus */
    private function rowWindow(Club $club, string $entryId): ?array
    {
        $this->scopeGucToClub($club->getId());
        $row = $this->em->getConnection()->fetchAssociative('SELECT start_date, end_date FROM calendar_entry WHERE id = :id', ['id' => $entryId]);
        if (false === $row) {
            return null;
        }

        return [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)];
    }

    /** L'id de l'enfant de $motherId dont la fenêtre est exactement [$start, $end], ou null. */
    private function childWithWindow(Club $club, string $motherId, string $start, string $end): ?string
    {
        $this->scopeGucToClub($club->getId());
        $id = $this->em->getConnection()->fetchOne(
            'SELECT id FROM calendar_entry WHERE parent_entry_id = :pid AND start_date = :start AND end_date = :end',
            ['pid' => $motherId, 'start' => $start, 'end' => $end],
        );

        return false === $id ? null : (string) $id;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club re-datage découpé');
        $club->setSlug('club-split-redate-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SPL' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('split' . $uid . '@test.com');
        $user->setFirstName('Sp');
        $user->setLastName('Lit');
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

        // Saison 2026-2027 : les fenêtres d'août à décembre 2026 y vivent (indépendant de l'horloge).
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2026-2027');
        $season->setStartDate(new DateTimeImmutable('2026-08-01'));
        $season->setEndDate(new DateTimeImmutable('2027-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
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
