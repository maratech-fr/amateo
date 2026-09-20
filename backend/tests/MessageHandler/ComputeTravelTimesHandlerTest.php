<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\SeasonStatus;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\MessageHandler\ComputeTravelTimesHandler;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Service\SeasonResolver;
use App\Service\TravelComputeLock;
use App\Tests\Double\IgnRoutingHttpClientStub;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * C6 — NR TENANT du worker de calcul des trajets (axe §7.1 « tenant isolation »). Le
 * handler tourne SANS requête HTTP : aucun GUC posé par le listener. Il DOIT scoper la
 * connexion au club du message (et le clear après), sinon un calcul lancé pour un club
 * lirait/écrirait les trajets d'un autre. Gardé BLOQUANT (step nommé de `blocking-tests`
 * dans `ci.yml` + ligne dans `docs/testing/blocking-tests.md`).
 */
#[Group('phase1')]
#[Group('integration')]
final class ComputeTravelTimesHandlerTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testTheComputationIsScopedToTheMessageClubAndNeverTouchesAnother(): void
    {
        // Club A et Club B : chacun un adversaire AWAY localisable (annuaire), trajet non
        // encore en cache. Un calcul lancé pour A ne doit JAMAIS remplir le cache de B.
        [$clubA, $seasonA] = $this->seedClubWithGeolocatedAwayOpponent('AAA');
        [$clubB] = $this->seedClubWithGeolocatedAwayOpponent('BBB');
        $this->em->clear();

        // Le handler pose son PROPRE GUC (aucune requête HTTP ici) : on ne le pré-scope pas.
        $this->handler()->__invoke(new ComputeTravelTimesMessage($clubA->getId(), $seasonA->getId(), TravelComputeScope::OPPONENTS));

        $cache = $this->cache();
        // A : le trajet siège(45.70,4.90) → lieu de l'annuaire (45.76,4.86) est en cache (stub IGN).
        $this->scopeGucToClub($clubA->getId());
        self::assertSame(
            IgnRoutingHttpClientStub::DRIVING_MINUTES,
            $cache->lookup($clubA->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.76, 4.86),
            'le trajet de A est calculé et mis en cache',
        );

        // B : son cache reste VIDE (jamais touché par le calcul de A — frontière tenant).
        $this->scopeGucToClub($clubB->getId());
        self::assertNull(
            $cache->lookup($clubB->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.76, 4.86),
            'le cache d\'un autre club n\'est jamais rempli',
        );
    }

    public function testASecondComputationIsRefusedWhileTheLockIsHeld(): void
    {
        [$clubA, $seasonA] = $this->seedClubWithGeolocatedAwayOpponent('LOCK');
        // Un calcul tient déjà le verrou de ce club : le message repart en file (Recoverable).
        $token = self::getContainer()->get(TravelComputeLock::class)->acquire($clubA->getId(), 60);
        self::assertIsString($token);

        try {
            $this->expectException(RecoverableMessageHandlingException::class);
            $this->handler()->__invoke(new ComputeTravelTimesMessage($clubA->getId(), $seasonA->getId(), TravelComputeScope::OPPONENTS));
        } finally {
            self::getContainer()->get(TravelComputeLock::class)->release($clubA->getId(), $token);
        }
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function handler(): ComputeTravelTimesHandler
    {
        return self::getContainer()->get(ComputeTravelTimesHandler::class);
    }

    private function cache(): TravelTimeCache
    {
        return self::getContainer()->get(TravelTimeCache::class);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithGeolocatedAwayOpponent(string $suffix): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club worker ' . $suffix . ' ' . $uid);
        $club->setSlug('club-worker-' . strtolower($suffix) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adverse ' . $suffix);
        $fixture->setOpponentOrganismeCode('ARA0069' . $suffix);
        $this->em->persist($fixture);
        $this->em->flush();

        // L'annuaire est GLOBAL (hors tenant) → seedé sans GUC.
        $entry = new OpponentDirectoryEntry('ARA0069' . $suffix, 'Adverse ' . $suffix, OpponentLocationPrecision::CITY);
        $entry->setLatitude(45.76)->setLongitude(4.86)->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();

        return [$club, $season];
    }
}
