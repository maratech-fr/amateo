<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Fixture;
use App\Entity\TeamMatchHabit;
use App\Enum\MatchWeek;
use App\Service\ConflictRadarLoader;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : l'écran Conflits et « Placer » lisent la même fenêtre d'un match du jour, parce que les
 * deux passent par `DayMatches`. Domicile sans échauffement, extérieur avec échauffement et trajet,
 * heure estimée, terrain neutre : si le radar dit qu'Emma est prise, le payload ne la désigne pas.
 *
 * Décor : U15 (Karim, Emma) s'entraîne vendredi 20:00-21:30, U18 (Karim) est posé par le solveur à
 * 20:30 (repart à placer côté payload), V (Emma) joue le match varié. Le radar dit qui tient la ligne
 * de Karim, le payload dit qui la séance retient.
 */
#[Group('integration')]
final class DayMatchesPayloadParityTest extends KernelTestCase
{
    use PlacementHoldingDecorTrait;

    private const string KARIM = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string EMMA = 'eeeeeeee-0000-4000-8000-000000000002';

    /**
     * @return iterable<string, array{0: Closure(self, string): mixed, 1: bool}>
     */
    public static function matchesOfEmma(): iterable
    {
        yield 'domicile qui finit dans la séance' => [static fn (self $t, string $v): string => $t->homeFixed($v, '18:30'), true];
        yield 'domicile qui finit au début de la séance' => [static fn (self $t, string $v): string => $t->homeFixed($v, '18:15'), false];
        yield 'domicile à la fin de la séance : pas d\'échauffement retenu' => [static fn (self $t, string $v): string => $t->homeFixed($v, '21:30'), false];
        yield 'extérieur dont l\'échauffement mord la séance' => [static fn (self $t, string $v): Fixture => $t->away($v, '21:45'), true];
        yield 'extérieur tardif sans trajet, hors de la séance' => [static fn (self $t, string $v): Fixture => $t->away($v, '23:00'), false];
        yield 'extérieur tardif dont seul le trajet mord la séance' => [static fn (self $t, string $v) => $t->awayWithOneWayTrip($v, '23:00', 120), true];
        yield 'terrain neutre dont l\'échauffement mord la séance' => [static fn (self $t, string $v) => $t->neutralGround($v, '21:45'), true];
        yield 'extérieur à heure estimée sur l\'habitude' => [static fn (self $t, string $v) => $t->awayEstimatedOnHabit($v, '20:30'), true];
    }

    /** @param Closure(self, string): mixed $match */
    #[DataProvider('matchesOfEmma')]
    public function testRadarAndPayloadReadTheSameWindow(Closure $match, bool $emmaIsTaken): void
    {
        $u15 = $this->team('U15');
        $this->link(self::KARIM, $u15);
        $this->link(self::EMMA, $u15);
        $this->session($u15, self::KARIM);
        $u18 = $this->team('U18');
        $this->link(self::KARIM, $u18);
        $fixtureId = $this->homePlacedBySolver($u18, '20:30');
        $v = $this->team('V');
        $this->link(self::EMMA, $v);
        $match($this, $v);

        self::assertSame($emmaIsTaken ? null : self::EMMA, $this->radarHolderOfKarimLine($fixtureId), 'le radar, source de la fenêtre');
        self::assertSame([$emmaIsTaken ? self::KARIM : self::EMMA], $this->occupiedOn($this->payload()), 'le payload lit la même fenêtre');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->seedDecor();
    }

    private function awayWithOneWayTrip(string $teamId, string $kickoff, int $oneWayMinutes): void
    {
        $fixture = $this->away($teamId, $kickoff);
        $code = 'TEST' . substr(md5($fixture->getId()), 0, 8);
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel('SALLE LOIN');
        $this->club->setLatitude(45.70);
        $this->club->setLongitude(4.90);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'INSERT INTO opponent_venue_link (id, version, created_at, updated_at, club_id, opponent_organisme_code, fbi_label, fbi_label_norm, venue_external_ref, venue_label, latitude, longitude, source)'
            . ' VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, \'SALLE LOIN\', \'salle loin\', NULL, \'Salle Loin\', 45.80, 5.00, \'MANUAL\')',
            [$this->club->getId(), $code],
        );
        $connection->executeStatement(
            'INSERT INTO club_travel_cache (id, club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon, minutes, resolved_at)'
            . ' VALUES (gen_random_uuid(), ?, \'car\', 45.70000, 4.90000, 45.80000, 5.00000, ?, now())',
            [$this->club->getId(), $oneWayMinutes],
        );
    }

    private function awayEstimatedOnHabit(string $teamId, string $habitKickoff): void
    {
        $habit = new TeamMatchHabit;
        $habit->setClubId($this->club->getId());
        $habit->setSeasonId($this->season->getId());
        $habit->setTeamId($teamId);
        $habit->setDayOfWeek(5);
        $habit->setKickoffTime(new DateTimeImmutable($habitKickoff));
        $habit->setWeek(MatchWeek::A);
        $this->em->persist($habit);
        $this->away($teamId, null);
    }

    private function radarHolderOfKarimLine(string $fixtureId): ?string
    {
        $this->em->clear();
        $radar = self::getContainer()->get(ConflictRadarLoader::class)->conflicts($this->club->getId(), $this->season->getId());
        foreach ($radar['held'] as $line) {
            if ('MATCH_TRAINING' === $line['type'] && $fixtureId === ($line['fixture']['fixtureId'] ?? null) && self::KARIM === $line['coachId']) {
                return $line['heldBy'];
            }
        }

        return null;
    }
}
