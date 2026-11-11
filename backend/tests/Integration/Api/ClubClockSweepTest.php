<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Service\ClubMailboxPurgerInterface;
use App\Tests\Double\RecordingClubMailboxPurger;
use App\Tests\PeriodPlanAutoValidationDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D1 (crochet horloge démo) — poser la date simulée d'un club de DÉMO valide aussitôt, dans la
 * requête, ses plannings de période dont le 7ᵉ jour est atteint à cette date : le cockpit rechargé
 * est à jour sans attendre le passage de 00:15. Le crochet ne vise que CE club, jamais sur `clear`,
 * jamais pour une date refusée (422) ; s'il échoue, l'horloge est posée quand même et la réponse
 * reste 200 (C4).
 *
 * Jumeau de fond : `Security/PeriodPlanAutoValidationTest` (le balayage lui-même) ; surface
 * historique de l'horloge : `Integration/Api/ClubClockEndpointTest`.
 */
#[Group('integration')]
final class ClubClockSweepTest extends WebTestCase
{
    use PeriodPlanAutoValidationDecorTrait;
    use StartsFreshBrowserSession;

    private KernelBrowser $client;

    /** Abus 1 — la date posée valide la période commencée de CE club, jamais celle d'un autre club démo portant la même période. */
    public function testSettingTheDateValidatesThisClubOnly(): void
    {
        ['club' => $club, 'token' => $token, 'planId' => $planId, 'versionId' => $versionId] = $this->demoWithPeriodStartingIn('CLK', 20);
        ['club' => $other, 'planId' => $otherPlan] = $this->demoWithPeriodStartingIn('CLKOTHER', 20);

        $this->postClock($token, ['date' => $this->decorDay(27)]);

        self::assertResponseIsSuccessful();
        self::assertSame($versionId, $this->decorChosenOf($club->getId(), $planId), 'J+7 atteint à la date posée : validé dans la requête');
        self::assertNull($this->decorChosenOf($other->getId(), $otherPlan), 'un autre club n\'est jamais balayé');
    }

    /** La veille du seuil, poser la date ne valide rien. */
    public function testTheEveOfTheThresholdValidatesNothing(): void
    {
        ['club' => $club, 'token' => $token, 'planId' => $planId] = $this->demoWithPeriodStartingIn('CLKEVE', 20);

        $this->postClock($token, ['date' => $this->decorDay(26)]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->decorChosenOf($club->getId(), $planId));
    }

    /** Relâcher l'horloge (`clear`) ne balaie jamais, même quand le jour réel rendrait le plan éligible. */
    public function testClearNeverSweeps(): void
    {
        ['club' => $club, 'token' => $token, 'planId' => $planId] = $this->demoWithPeriodStartingIn('CLKCLR', -10);

        $this->postClock($token, ['clear' => true]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'clear : aucun balayage');
    }

    /** Une date refusée (hors des bornes de saison, 422) n'atteint jamais le crochet. */
    public function testARefusedDateNeverSweeps(): void
    {
        ['club' => $club, 'token' => $token, 'planId' => $planId] = $this->demoWithPeriodStartingIn('CLKKO', -10);

        $this->postClock($token, ['date' => $this->decorDay(1500)]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'date refusée : aucun balayage');
    }

    /**
     * C4 — le balayage qui lève (verrou de plan-scope tenu par une autre session, `lock_timeout`)
     * ne casse jamais la requête : 200, horloge posée, plan intact, EntityManager utilisable.
     */
    public function testAFailingSweepStillAnswers200AndKeepsTheClock(): void
    {
        ['club' => $club, 'seasons' => [$season], 'manager' => $manager] = $this->seedDecorClub('CLKFAIL', seasons: [[$this->decorDay(-200), $this->decorDay(400)]], demo: true);
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriodWithoutPlanScopeLock($season, $this->decorDay(20), $this->decorDay(33));
        $token = $this->decorToken($manager);
        $date = $this->decorDay(27);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $blocker = DriverManager::getConnection($conn->getParams());
        try {
            $blocker->executeStatement('SELECT pg_advisory_lock(hashtext(:k))', ['k' => 'schedule-plan-link:' . $entryId]);
            $conn->executeStatement('SET lock_timeout = \'700ms\'');

            $this->postClock($token, ['date' => $date]);
        } finally {
            $conn->executeStatement('RESET lock_timeout');
            $blocker->executeStatement('SELECT pg_advisory_unlock(hashtext(:k))', ['k' => 'schedule-plan-link:' . $entryId]);
            $blocker->close();
        }

        self::assertResponseIsSuccessful('un balayage en échec ne casse jamais la pose de l\'horloge');
        self::assertTrue($em->isOpen(), 'l\'EntityManager reste utilisable');
        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'balayage en échec : rien écrit');
        $em->clear();
        $reloaded = $em->getRepository(Club::class)->find($club->getId());
        self::assertInstanceOf(Club::class, $reloaded);
        self::assertSame($date, $reloaded->getSimulatedToday()?->format('Y-m-d'), 'l\'horloge est posée malgré l\'échec du balayage');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(ClubMailboxPurgerInterface::class, new RecordingClubMailboxPurger);
    }

    /**
     * Un club de démo (horloge non posée) dont une période commence dans `$days` jours et porte
     * une version terminée. Saison large : toute date de test reste dans les bornes de l'horloge.
     *
     * @return array{club: Club, token: string, planId: string, versionId: string}
     */
    private function demoWithPeriodStartingIn(string $tag, int $days): array
    {
        ['club' => $club, 'seasons' => [$season], 'manager' => $manager] = $this->seedDecorClub($tag, seasons: [[$this->decorDay(-200), $this->decorDay(400)]], demo: true);
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, $this->decorDay($days), $this->decorDay($days + 13));
        $versionId = $this->seedDecorVersion($season, $entryId);

        return ['club' => $club, 'token' => $this->decorToken($manager), 'planId' => $planId, 'versionId' => $versionId];
    }

    /** @param array<string, mixed> $body */
    private function postClock(string $token, array $body): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/club/clock', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
