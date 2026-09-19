<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\MessageHandler\ComputeTravelTimesHandler;
use App\Repository\OpponentTravelRepository;
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
        // Club A : un adversaire AWAY localisable, aucun trajet encore (à calculer).
        [$clubA, $seasonA] = $this->seedClubWithGeolocatedAwayOpponent('AAA');
        // Club B : une ligne de trajet AUTO déjà présente, SANS minutes — un calcul lancé
        // pour A ne doit JAMAIS la toucher (frontière tenant).
        [$clubB, $seasonB] = $this->seedClubWithGeolocatedAwayOpponent('BBB');
        $this->scopeGucToClub($clubB->getId());
        $bRow = (new OpponentTravel)
            ->setClubId($clubB->getId())->setSeasonId($seasonB->getId())
            ->setOpponentOrganismeCode('ARA0069BBB')
            ->setSource(OpponentTravelSource::AUTO)->setTravelMinutes(null)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($bRow);
        $this->em->flush();
        $this->em->clear();

        // Le handler pose son PROPRE GUC (aucune requête HTTP ici) : on ne le pré-scope pas.
        $this->handler()->__invoke(new ComputeTravelTimesMessage($clubA->getId(), $seasonA->getId(), TravelComputeScope::OPPONENTS));

        // A : le trajet de son adversaire est calculé (stub IGN → DRIVING_MINUTES).
        $this->scopeGucToClub($clubA->getId());
        $aRow = $this->travelRepository()->findOneByCode($seasonA->getId(), 'ARA0069AAA');
        self::assertInstanceOf(OpponentTravel::class, $aRow, 'le trajet de A est calculé');
        self::assertSame(IgnRoutingHttpClientStub::DRIVING_MINUTES, $aRow->getTravelMinutes());

        // B : sa ligne pré-existante est INTACTE (jamais touchée par le calcul de A).
        $this->scopeGucToClub($clubB->getId());
        $bAfter = $this->travelRepository()->findOneByCode($seasonB->getId(), 'ARA0069BBB');
        self::assertInstanceOf(OpponentTravel::class, $bAfter);
        self::assertNull($bAfter->getTravelMinutes(), 'la ligne d\'un autre club n\'est jamais touchée');
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

    private function travelRepository(): OpponentTravelRepository
    {
        return self::getContainer()->get(OpponentTravelRepository::class);
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
