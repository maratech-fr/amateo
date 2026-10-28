<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fbi;

use App\Clock\ClubClock;
use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Service\ClubDay;
use App\Service\Fbi\FbiArrivalReview;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * D3 (retours BCCL M15, 2026-10-27) — « FBI fait foi » (la source écrite D'OFFICE
 * sur un domicile placé déphasé) est RESTREINT à la SEMAINE ISO EN COURS du club :
 * un domicile dont NI la date Amateo NI la date du fichier ne tombe entre le LUNDI
 * et le DIMANCHE de la semaine ISO courante n'est PLUS appliqué d'office — c'est un
 * écart À TRANCHER. C'est la racine du bug M15 : un match passé de plusieurs semaines
 * (10/10) était re-daté d'office au 14/11 parce que la borne n'était qu'un plafond
 * (« ≤ dimanche ») — toute date passée la franchissait. La borne devient un INTERVALLE
 * (lundi ≤ date ≤ dimanche), calculé dans le FUSEAU du club ({@see ClubDay}).
 *
 * Prédicat unique partagé par les deux canaux (import xlsx {@see FbiFixtureImporter}
 * + canal API {@see App\Service\Basketball\FfbbRencontreReconciler:213}). Témoin pur
 * (MockClock, zéro base) des bornes lundi/dimanche ; l'effet de bout en bout sur une
 * rencontre vit dans {@see App\Tests\Integration\Service\FbiFixtureImporterTest}.
 */
#[Group('phase1')]
final class FbiArrivalReviewWindowTest extends TestCase
{
    /**
     * M15 — un match de plusieurs semaines (hors de la semaine ISO) que le fichier
     * re-date loin devant : AUCUNE des deux dates n'est dans la semaine → la source
     * ne fait PLUS foi (bug M15 : elle réécrivait le match passé).
     */
    public function testAMatchWeeksOldReDatedFarAheadIsNoLongerAuthoritative(): void
    {
        // Mercredi 28/10/2026 → semaine ISO lundi 26/10 … dimanche 01/11.
        $review = $this->review(new MockClock('2026-10-28 10:00:00', 'UTC'));
        $club = $this->club('Europe/Paris');

        self::assertFalse(
            $review->sourceIsAuthoritativeForWindow(
                $this->fixtureOn('2026-10-10'), // date app : passée, hors semaine
                $this->rowOn('2026-11-14'),      // date fichier : future, hors semaine
                $review->currentIsoWeekEnd($club),
            ),
            'ni la date app ni la date fichier dans la semaine ISO → écart à trancher, jamais réécrit',
        );
    }

    /** Le dernier jour avant le lundi de la semaine en cours est DEHORS (borne basse). */
    public function testTheDayBeforeTheCurrentIsoMondayIsOutsideTheWindow(): void
    {
        $review = $this->review(new MockClock('2026-10-28 10:00:00', 'UTC'));
        $club = $this->club('Europe/Paris');

        self::assertFalse(
            $review->sourceIsAuthoritativeForWindow(
                $this->fixtureOn('2026-12-01'), // app : future hors semaine
                $this->rowOn('2026-10-25'),      // source : dimanche de la semaine PRÉCÉDENTE
                $review->currentIsoWeekEnd($club),
            ),
            'une date la veille du lundi courant est hors fenêtre — borne basse exclusive de la semaine passée',
        );
    }

    /** Le lundi ET le dimanche de la semaine en cours sont DEDANS (bornes incluses). */
    public function testMondayAndSundayOfTheCurrentWeekAreInclusive(): void
    {
        $review = $this->review(new MockClock('2026-10-28 10:00:00', 'UTC'));
        $weekEnd = $review->currentIsoWeekEnd($this->club('Europe/Paris'));
        $farFutureApp = $this->fixtureOn('2026-12-01'); // app hors semaine : seule la source décide

        self::assertTrue(
            $review->sourceIsAuthoritativeForWindow($farFutureApp, $this->rowOn('2026-10-26'), $weekEnd),
            'le LUNDI de la semaine ISO en cours est dans la fenêtre',
        );
        self::assertTrue(
            $review->sourceIsAuthoritativeForWindow($farFutureApp, $this->rowOn('2026-11-01'), $weekEnd),
            'le DIMANCHE de la semaine ISO en cours est dans la fenêtre',
        );
    }

    /** Une date au-delà du dimanche courant reste DEHORS (borne haute préservée, régime RMM-4). */
    public function testTheDayAfterTheCurrentSundayStaysOutsideTheWindow(): void
    {
        $review = $this->review(new MockClock('2026-10-28 10:00:00', 'UTC'));
        $club = $this->club('Europe/Paris');

        self::assertFalse(
            $review->sourceIsAuthoritativeForWindow(
                $this->fixtureOn('2026-11-03'), // app : lundi de la semaine suivante, hors
                $this->rowOn('2026-11-02'),      // source : lendemain du dimanche courant, hors
                $review->currentIsoWeekEnd($club),
            ),
            'un déphasage entièrement futur reste un arbitrage — la borne haute ne bouge pas',
        );
    }

    /** « app OU source » : il suffit que la date APP tombe dans la semaine en cours. */
    public function testAnAppDateInsideTheCurrentWeekIsAuthoritativeEvenWhenTheSourceIsFar(): void
    {
        $review = $this->review(new MockClock('2026-10-28 10:00:00', 'UTC'));
        $club = $this->club('Europe/Paris');

        self::assertTrue(
            $review->sourceIsAuthoritativeForWindow(
                $this->fixtureOn('2026-10-27'), // app : mardi de la semaine en cours
                $this->rowOn('2027-03-01'),      // source : loin devant
                $review->currentIsoWeekEnd($club),
            ),
            'la date Amateo est dans la semaine → la source fait foi (symétrique « dans la semaine »)',
        );
    }

    /**
     * Les bornes sont lues dans le FUSEAU du club : au même instant réel, le même
     * dimanche 01/11 est DANS la semaine d'un club encore dimanche (Guadeloupe, UTC−4)
     * et DEHORS pour un club déjà lundi (Paris). Sans ce fuseau, la restriction D3
     * trancherait sur le jour du serveur.
     */
    public function testTheIsoWeekBoundsAreComputedInTheClubTimezone(): void
    {
        // 02:00 UTC le lundi 02/11 : déjà lundi 03h à Paris (semaine du 02 au 08),
        // encore dimanche 22h 01/11 en Guadeloupe (semaine du 26/10 au 01/11).
        $review = $this->review(new MockClock('2026-11-02 02:00:00', 'UTC'));
        $sourceSunday = $this->rowOn('2026-11-01');
        $appFarFuture = $this->fixtureOn('2026-12-15');

        self::assertFalse(
            $review->sourceIsAuthoritativeForWindow($appFarFuture, $sourceSunday, $review->currentIsoWeekEnd($this->club('Europe/Paris'))),
            'pour Paris (déjà lundi) le dimanche 01/11 appartient à la semaine PASSÉE → hors fenêtre',
        );
        self::assertTrue(
            $review->sourceIsAuthoritativeForWindow($appFarFuture, $sourceSunday, $review->currentIsoWeekEnd($this->club('America/Guadeloupe'))),
            'pour la Guadeloupe (encore dimanche) le 01/11 est le dernier jour de SA semaine → dans la fenêtre',
        );
    }

    private function review(MockClock $clock): FbiArrivalReview
    {
        // Même montage inerte que ClubDayTest : sans drapeau ni pin, ClubClock ne lit que
        // l'entité (pas d'EM ni de RequestStack touchés), le ClubDay ne rend qu'une date civile.
        $clubClock = new ClubClock(
            $clock,
            new RequestStack,
            $this->createMock(EntityManagerInterface::class),
            new DevClockStore(new ArrayAdapter),
            false,
            'test',
        );

        return new FbiArrivalReview(new ClubDay($clock, $clubClock));
    }

    private function club(string $timezone): Club
    {
        return new Club()->setTimezone($timezone);
    }

    private function fixtureOn(string $ymd): Fixture
    {
        $fixture = new Fixture;
        $fixture->setMatchDate(new DateTimeImmutable($ymd));
        $fixture->setHomeAway(FixtureHomeAway::HOME);

        return $fixture;
    }

    /**
     * @return array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}
     */
    private function rowOn(string $ymd): array
    {
        return [
            'numero' => '1',
            'matchDate' => new DateTimeImmutable($ymd),
            'homeAway' => FixtureHomeAway::HOME,
            'opponentLabel' => 'AS Voisins',
            'kickoffTime' => null,
            'venueLabel' => null,
        ];
    }
}
