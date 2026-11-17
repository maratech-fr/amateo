<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Tests\OneSessionPerDayDecorTrait;
use App\Tests\Security\OneSessionPerDayGesturesTest;
use App\Tests\Unit\Service\PreSolvePreventionWarningsTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR (axes constraint semantics et generation pipeline) : réserver deux fois une équipe le même jour passe au geste, mais le Récap
 * bloque la génération de saison : la réservation gagne contre les règles, pas contre cette règle de base, humainement impossible.
 * Il faut au moins une réservation : deux verrous seuls ne bloquent pas, ni le comblement, ni une période. Le verrou compté est celui
 * de la version chargée, jamais un autre. Là où ce blocage couvre l'équipe et le jour, l'alerte de chevauchement se tait ; ailleurs
 * elle reste telle que la prouve {@see PreSolvePreventionWarningsTest}.
 *
 * Jumeau VOULU (point sensible génération) : {@see OneSessionPerDayGesturesTest} (les gestes du planning, refusés à la source).
 *
 * @phpstan-import-type Decor from OneSessionPerDayDecorTrait
 */
#[Group('phase1')]
#[Group('integration')]
final class RecapRefusesASecondSessionSameDayTest extends WebTestCase
{
    use OneSessionPerDayDecorTrait;

    private const string RULE = 'une seule séance par jour';

    /** Le renvoi [R] vers le réglage de l'équipe, au singulier. */
    private const string WHERE = '(étape Équipes, bouton Liens de l\'équipe, onglet « Séances par jour »)';

    /** Le renvoi [R] au pluriel, quand le motif nomme plusieurs équipes. */
    private const string WHERE_PLURAL = '(étape Équipes, bouton Liens de chaque équipe, onglet « Séances par jour »)';

    private const string TWO_RESERVATIONS = 'U15 est réservée deux fois le mardi : 18:00-19:30 à Armand et 20:30-22:00 à JDR — une seule séance par jour. Retirez l\'une des deux au récapitulatif, ou autorisez 2 séances par jour pour cette équipe ' . self::WHERE . '.';

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    /** @return iterable<string, array{string, ScheduleStatus}> */
    public static function seasonCounters(): iterable
    {
        yield '/generate' => ['generate', ScheduleStatus::DRAFT];
        yield '/regenerate' => ['regenerate', ScheduleStatus::COMPLETED];
    }

    /**
     * Plafond 1, N réservations disjointes le mardi : la phrase dit combien en retirer, et propose d'en autoriser N tant que N ≤ 4.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function overTheDefaultCap(): iterable
    {
        yield 'N = 2' => [['18:00', '20:30'], self::TWO_RESERVATIONS];
        yield 'N = 3' => [['10:00', '18:00', '20:30'], 'U15 est réservée trois fois le mardi : 10:00-11:30 à Armand, 18:00-19:30 à Armand et 20:30-22:00 à JDR — une seule séance par jour. Retirez-en 2 au récapitulatif, ou autorisez 3 séances par jour pour cette équipe ' . self::WHERE . '.'];
        yield 'N = 5, sans proposition' => [['08:00', '10:00', '13:00', '18:00', '20:30'], 'U15 est réservée 5 fois le mardi : 08:00-09:30 à Armand, 10:00-11:30 à Armand, 13:00-14:30 à Armand, 18:00-19:30 à Armand et 20:30-22:00 à JDR — une seule séance par jour. Retirez-en 4 au récapitulatif.'];
    }

    /** Deux réservations le même jour : la phrase exacte, invalide, et les deux lignes désignées pour la poubelle du Récap. */
    public function testTwoReservationsTheSameDayBlockTheRecap(): void
    {
        $ctx = $this->seasonDecor();
        $first = $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $second = $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');

        $body = $this->recap($ctx);

        self::assertFalse($body['valid']);
        self::assertContains(self::TWO_RESERVATIONS, $body['blockers']);
        $this->assertDoubled([$first, $second], $body);
    }

    /** Le geste de réserver passe, deux fois le même jour ; c'est le Récap qui arrête, pas la saisie. */
    public function testReservingTwiceTheSameDayPassesAndTheRecapStops(): void
    {
        $ctx = $this->seasonDecor();
        foreach ([[$ctx['armand'], '18:00'], [$ctx['jdr'], '20:30']] as [$venueId, $start]) {
            $this->post($ctx, '/api/reservations', ['teamId' => $ctx['u15'], 'venueId' => $venueId, 'dayOfWeek' => self::TUESDAY, 'startTime' => $start, 'durationMinutes' => 90]);
            self::assertResponseStatusCodeSame(201);
        }

        self::assertContains(self::TWO_RESERVATIONS, $this->recap($ctx)['blockers']);
    }

    /** Les guichets de saison refusent mot pour mot : le motif du Récap, 422 `blocker: true`, rien en file. */
    #[DataProvider('seasonCounters')]
    public function testTheSeasonCountersRefuse(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seasonDecor();
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');
        $version = $this->version($ctx, $status);

        $this->post($ctx, "/api/schedules/{$version}/{$counter}", []);

        self::assertResponseStatusCodeSame(422);
        $body = $this->body();
        self::assertTrue($body['blocker'] ?? false);
        self::assertSame(self::TWO_RESERVATIONS, $body['error'] ?? null);
        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertSame($status, $this->em->getRepository(Schedule::class)->find($version)?->getStatus(), 'rien n\'est mis en file');
    }

    /** Trois cases le même jour : les trois lignes désignées, et il faut en retirer deux. */
    public function testThreeCasesAskToRemoveTwo(): void
    {
        $ctx = $this->seasonDecor();
        $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setVenueId($ctx['armand'])
            ->setDayOfWeek(self::TUESDAY)->setStartTime(new DateTimeImmutable('10:00'))->setDurationMinutes(90)->setCapacity(3));
        $this->em->flush();
        $ids = [
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '10:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30'),
        ];

        $body = $this->recap($ctx);

        $found = $this->ruleBlockers($body);
        self::assertCount(1, $found);
        self::assertStringContainsString('Retirez-en 2', $found[0]);
        $this->assertDoubled($ids, $body);
    }

    /** Le groupe vaut une équipe : les mêmes deux cases pour ses deux membres donnent UN motif, qui les nomme. */
    public function testAGroupReservedOnTwoCasesIsOneBlockerNamingBothTeams(): void
    {
        $ctx = $this->seasonDecor();
        $this->group($ctx, [$ctx['u15'], $ctx['u18']], commonSessions: 2);
        $ids = [];
        foreach ([$ctx['u15'], $ctx['u18']] as $teamId) {
            $ids[] = $this->reservation($ctx, $teamId, $ctx['armand'], self::TUESDAY, '18:00');
            $ids[] = $this->reservation($ctx, $teamId, $ctx['jdr'], self::TUESDAY, '20:30');
        }

        $body = $this->recap($ctx);

        $found = $this->ruleBlockers($body);
        self::assertCount(1, $found, 'un seul motif pour le groupe');
        self::assertStringContainsString('U15', $found[0]);
        self::assertStringContainsString('U18', $found[0]);
        self::assertStringEndsWith('ou autorisez 2 séances par jour pour ces équipes ' . self::WHERE_PLURAL . '.', $found[0]);
        $this->assertDoubled($ids, $body);
    }

    /** Membre du groupe réservé aussi seul ce jour-là : lui seul est nommé. */
    public function testAGroupMemberAlsoReservedAloneIsNamedAlone(): void
    {
        $ctx = $this->seasonDecor();
        $this->group($ctx, [$ctx['u15'], $ctx['u18']]);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u18'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');

        $found = $this->ruleBlockers($this->recap($ctx));

        self::assertCount(1, $found);
        self::assertStringContainsString('U15', $found[0]);
        self::assertStringNotContainsString('U18', $found[0]);
    }

    /** TÉMOINS : rien de doublé dans ces décors sains. La règle est par équipe et par jour, un groupe tient sur une case. */
    public function testHealthyReservationsDoNotBlock(): void
    {
        $ctx = $this->seasonDecor();
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');
        $this->group($ctx, [$ctx['u18'], $ctx['u20']]);
        $this->reservation($ctx, $ctx['u20'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->assertNotDoubled($this->recap($ctx));
    }

    /** Variante libre : un créneau libre n'a pas d'équipe. Deux le même jour, ou un à côté d'une équipe : rien à reprocher. */
    public function testFreeSlotsAreNotASession(): void
    {
        $ctx = $this->seasonDecor();
        foreach ([[$ctx['armand'], '18:00'], [$ctx['jdr'], '20:30']] as [$venueId, $start]) {
            $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setLabel('Loto du club')
                ->setVenueId($venueId)->setDayOfWeek(self::TUESDAY)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90));
        }
        $this->em->flush();
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->assertNotDoubled($this->recap($ctx));
    }

    /** Réservation posée après un verrou de la version chargée, même jour, autre case : phrase exacte, et seule la réservation part à la poubelle. */
    public function testAReservationAfterALoadedLockBlocksAndNamesBoth(): void
    {
        $ctx = $this->seasonDecor();
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $reservation = $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:30');

        $body = $this->recap($ctx);

        $found = $this->ruleBlockers($body);
        self::assertCount(1, $found);
        self::assertMatchesRegularExpression(
            '/^U15 a deux séances le jeudi : 20:30-22:00 à JDR \(réservation\) et 19:00-20:30 à Armand \(verrou de la version V?1\) — une seule séance par jour\. Retirez la réservation au récapitulatif, déverrouillez la séance du jeudi dans la version V?1, ou autorisez 2 séances par jour pour cette équipe ' . preg_quote(self::WHERE, '/') . '\.$/u',
            $found[0],
        );
        $this->assertDoubled([$reservation], $body);
    }

    /** La régénération s'arrête aussi sur ce mélange : c'est le geste qui l'aurait produit. */
    public function testRegeneratingOverAReservationAndALoadedLockIsRefused(): void
    {
        $ctx = $this->seasonDecor();
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:30');

        $this->post($ctx, '/api/schedules/' . $ctx['scheduleId'] . '/regenerate', []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::RULE, (string) ($this->body()['error'] ?? ''));
    }

    /** La réservation et le verrou sur le MÊME placement sont une seule séance : silence. */
    public function testAReservationAndALockOnTheSamePlacementAreOneSession(): void
    {
        $ctx = $this->seasonDecor();
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->assertNotDoubled($this->recap($ctx));
    }

    /** Une séance NON verrouillée de la version chargée n'est pas un fait : le moteur la refera. Silence. */
    public function testAnUnlockedSessionDoesNotCount(): void
    {
        $ctx = $this->seasonDecor();
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:30');

        $this->assertNotDoubled($this->recap($ctx));
    }

    /**
     * Seul compte le verrou de la version CHARGÉE : le même décor bloque quand la version du verrou est chargée, se tait sinon.
     * Le témoin dans les deux sens prouve que c'est bien le pointeur qui décide.
     */
    public function testALockOfANonLoadedVersionDoesNotCount(): void
    {
        $ctx = $this->seasonDecor();
        $other = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('V2')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($other);
        $this->em->flush();
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD, scheduleId: $other->getId());
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:30');

        $this->loadVersion($ctx, $ctx['scheduleId']);
        $this->assertNotDoubled($this->recap($ctx));

        $this->loadVersion($ctx, $other->getId());
        $this->em->clear();
        self::assertCount(1, $this->ruleBlockers($this->recap($ctx)));
    }

    /** Deux verrous sans réservation : plus aucun chemin ne les produit, aucun blocage, la régénération part. */
    public function testTwoLocksWithoutReservationDoNotBlock(): void
    {
        $ctx = $this->seasonDecor();
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00', LockLevel::HARD);
        $this->session($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30', LockLevel::HARD);

        $this->assertNotDoubled($this->recap($ctx));

        $this->post($ctx, '/api/schedules/' . $ctx['scheduleId'] . '/regenerate', []);
        self::assertResponseStatusCodeSame(202);
    }

    /**
     * Réservation et verrou chargé qui se chevauchent le même jour : le blocage « une seule séance » dit déjà tout. L'alerte de
     * chevauchement ne s'y ajoute pas, et elle n'appelle jamais « réservée » une séance verrouillée.
     */
    public function testAnOverlappingReservationAndLoadedLockGiveOnlyTheOneSessionBlocker(): void
    {
        $ctx = $this->seasonDecor();
        $this->window($ctx, $ctx['jdr'], self::THURSDAY, '20:00');
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:00');

        $body = $this->recap($ctx);

        self::assertCount(1, $this->ruleBlockers($body));
        self::assertSame([], $this->overlapWarnings($body));
    }

    /** Deux réservations qui se chevauchent le même jour : même recouvrement, le blocage seul parle. */
    public function testTwoOverlappingReservationsGiveOnlyTheOneSessionBlocker(): void
    {
        $ctx = $this->seasonDecor();
        $this->window($ctx, $ctx['jdr'], self::TUESDAY, '19:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '19:00');

        $body = $this->recap($ctx);

        self::assertCount(1, $this->ruleBlockers($body));
        self::assertStringNotContainsString('autorisez', $this->ruleBlockers($body)[0], 'des cases qui se chevauchent : relever le plafond ne réglerait rien');
        self::assertSame([], $this->overlapWarnings($body));
    }

    /** Deux verrous qui se chevauchent, sans réservation : rien ne bloque, et aucune alerte ne les dit « réservée ». */
    public function testOverlappingLocksAreNeverCalledReserved(): void
    {
        $ctx = $this->seasonDecor();
        $this->window($ctx, $ctx['jdr'], self::THURSDAY, '20:00');
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $this->session($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:00', LockLevel::HARD);

        $body = $this->recap($ctx);

        self::assertSame([], $this->ruleBlockers($body));
        self::assertSame([], array_values(array_filter($this->warnings($body), static fn (string $w): bool => str_contains($w, 'U15 est réservée'))));
    }

    /** TÉMOIN hors recouvrement : en période, rien ne bloque, et l'alerte de chevauchement reste, mot pour mot. */
    public function testOutsideTheBlockerTheOverlapWarningStays(): void
    {
        $ctx = $this->periodDecor();
        $this->window($ctx, $ctx['jdr'], self::TUESDAY, '19:00', $ctx['planId']);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '19:00');

        $body = $this->recap($ctx, $ctx['entryId']);

        self::assertSame([], $this->ruleBlockers($body));
        self::assertSame(['U15 est réservée deux fois le mardi sur des créneaux qui se chevauchent : 18:00-19:30 à Armand et 19:00-20:30 à JDR.'], $this->overlapWarnings($body));
    }

    /** Variante période : les mêmes deux réservations ne bloquent pas le Récap de la période. */
    public function testAPeriodDoesNotBlock(): void
    {
        $ctx = $this->periodDecor();
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->assertNotDoubled($this->recap($ctx, $ctx['entryId']));
    }

    /** Variante comblement : les mêmes deux réservations, le guichet part. */
    public function testFillingAPeriodDoesNotBlock(): void
    {
        $ctx = $this->periodDecor();
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedules/' . $ctx['scheduleId'] . '/fill', []);

        self::assertResponseStatusCodeSame(202);
    }

    /**
     * T2a : au-delà du plafond de 1, la phrase exacte, et la proposition de relever le plafond seulement quand c'est permis (≤ 4).
     *
     * @param list<string> $starts
     */
    #[DataProvider('overTheDefaultCap')]
    public function testOverTheDefaultCapTheSentenceSaysHowManyToRemoveOrToAllow(array $starts, string $expected): void
    {
        $ctx = $this->seasonDecor();
        $ids = [];
        foreach ($starts as $start) {
            $venue = '20:30' === $start ? $ctx['jdr'] : $ctx['armand'];
            if (!\in_array($start, ['18:00', '20:30'], true)) {
                $this->extraWindow($ctx, $venue, self::TUESDAY, $start);
            }
            $ids[] = $this->reservation($ctx, $ctx['u15'], $venue, self::TUESDAY, $start);
        }

        $body = $this->recap($ctx);

        self::assertSame([$expected], $this->dayBlockers($body));
        $this->assertDoubled($ids, $body);
    }

    /** Plafond 2 : deux réservations disjointes le même jour sont permises, le Récap ne bloque plus. */
    public function testACapOfTwoAllowsTwoDisjointReservations(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');

        $body = $this->recap($ctx);

        self::assertSame([], $this->dayBlockers($body));
        self::assertSame([], $body['doubledReservationIds'] ?? []);
    }

    /** Plafond 2 : deux réservations collées (18:00-19:30 puis 19:30-21:00) ne se chevauchent pas : ni blocage, ni alerte. */
    public function testACapOfTwoAcceptsTwoBackToBackReservations(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->extraWindow($ctx, $ctx['armand'], self::TUESDAY, '19:30');
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '19:30');

        $body = $this->recap($ctx);

        self::assertSame([], $this->dayBlockers($body));
        self::assertSame([], $this->overlapWarnings($body));
    }

    /** T2b : plafond 2 et trois réservations disjointes : le motif dit le plafond, demande d'en retirer une ou d'en autoriser 3. */
    public function testThreeReservationsOverACapOfTwoBlock(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->extraWindow($ctx, $ctx['armand'], self::TUESDAY, '10:00');
        $ids = [
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '10:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30'),
        ];

        $body = $this->recap($ctx);

        self::assertSame(['U15 est réservée trois fois le mardi : 10:00-11:30 à Armand, 18:00-19:30 à Armand et 20:30-22:00 à JDR — 2 séances par jour au plus pour cette équipe. Retirez-en une au récapitulatif, ou autorisez 3 séances par jour pour cette équipe ' . self::WHERE . '.'], $this->dayBlockers($body));
        $this->assertDoubled($ids, $body);
    }

    /** T2c à plafond 2 : deux réservations et un verrou chargé le même jour, le motif dit le plafond et propose 3. */
    public function testAReservationAndALoadedLockOverACapOfTwoSayTheCap(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->extraWindow($ctx, $ctx['armand'], self::THURSDAY, '10:00');
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '10:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:30');

        $found = $this->dayBlockers($this->recap($ctx));

        self::assertCount(1, $found);
        self::assertStringStartsWith('U15 a trois séances le jeudi : ', $found[0]);
        self::assertStringContainsString(' — 2 séances par jour au plus pour cette équipe. ', $found[0]);
        self::assertStringEndsWith('ou autorisez 3 séances par jour pour cette équipe ' . self::WHERE . '.', $found[0]);
    }

    /** Retour à 1 séance par jour : le blocage revient, mot pour mot. */
    public function testLoweringTheCapBackToOneBringsTheBlockerBack(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');
        self::assertSame([], $this->dayBlockers($this->recap($ctx)));

        $this->dailyCap($ctx, $ctx['u15'], 1);
        $this->em->clear();

        self::assertSame([self::TWO_RESERVATIONS], $this->dayBlockers($this->recap($ctx)));
    }

    /** Groupe {U15 à 2, U18 à 1} réservé deux fois le mardi : seul le membre en dépassement est nommé. */
    public function testAGroupWithMixedCapsNamesOnlyTheMemberOverItsCap(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->group($ctx, [$ctx['u15'], $ctx['u18']], commonSessions: 2);
        foreach ([$ctx['u15'], $ctx['u18']] as $teamId) {
            $this->reservation($ctx, $teamId, $ctx['armand'], self::TUESDAY, '18:00');
            $this->reservation($ctx, $teamId, $ctx['jdr'], self::TUESDAY, '20:30');
        }

        $found = $this->dayBlockers($this->recap($ctx));

        self::assertCount(1, $found);
        self::assertStringStartsWith('U18 est réservée deux fois le mardi', $found[0]);
        self::assertStringNotContainsString('U15', $found[0]);
    }

    /**
     * T2d : plafond 2, deux réservations qui se chevauchent : blocage nommé, sans proposer d'autoriser davantage ; les deux lignes
     * sont désignées, et l'alerte de chevauchement se tait (l'équipe et le jour sont couverts).
     */
    public function testTwoOverlappingReservationsUnderACapOfTwoBlock(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->window($ctx, $ctx['jdr'], self::TUESDAY, '19:00');
        $ids = [
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '19:00'),
        ];

        $body = $this->recap($ctx);

        self::assertSame(['U15 est réservée deux fois le mardi sur des créneaux qui se chevauchent : 18:00-19:30 à Armand et 19:00-20:30 à JDR. Une équipe ne peut pas être à deux séances en même temps : retirez l\'une des deux au récapitulatif.'], $this->dayBlockers($body));
        $this->assertOverlapping($ids, $body);
        self::assertSame([], $body['doubledReservationIds'] ?? null, 'un chevauchement n\'est jamais dit « trop de séances »');
        self::assertSame([], $this->overlapWarnings($body));
    }

    /** T2d avec verrou : plafond 2, une réservation chevauche un verrou de la version chargée ; seule la réservation part à la poubelle. */
    public function testAReservationOverlappingALoadedLockUnderACapOfTwoBlocks(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->window($ctx, $ctx['jdr'], self::THURSDAY, '20:00');
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $reservation = $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:00');

        $body = $this->recap($ctx);

        $found = $this->dayBlockers($body);
        self::assertCount(1, $found);
        self::assertMatchesRegularExpression(
            '/^U15 a deux séances le jeudi sur des créneaux qui se chevauchent : 20:00-21:30 à JDR \(réservation\) et 19:00-20:30 à Armand \(verrou de la version V?1\)\. Une équipe ne peut pas être à deux séances en même temps : retirez la réservation au récapitulatif, ou déverrouillez la séance du jeudi dans la version V?1\.$/u',
            $found[0],
        );
        $this->assertOverlapping([$reservation], $body);
        self::assertSame([], $body['doubledReservationIds'] ?? null, 'un chevauchement n\'est jamais dit « trop de séances »');
        self::assertSame([], $this->overlapWarnings($body));
    }

    /** Plafond 2, trois réservations dont deux se chevauchent : un seul blocage, celui du chevauchement, sans « autorisez ». */
    public function testExcessAndOverlapTheSameDayGiveOnlyTheOverlapBlocker(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->window($ctx, $ctx['jdr'], self::TUESDAY, '19:00');
        $ids = [
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '19:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30'),
        ];

        $body = $this->recap($ctx);

        $found = $this->dayBlockers($body);
        self::assertCount(1, $found);
        self::assertStringContainsString('se chevauchent', $found[0]);
        self::assertStringNotContainsString('autorisez', $found[0]);
        // Les deux qui se chevauchent portent la cause « chevauche » (elle l'emporte) ; la troisième n'est
        // désignée qu'une fois au plus. Sa cause propre n'est pas fixée par le plan : non vérifiée ici.
        self::assertIsArray($body['overlappingReservationIds'] ?? null, 'le Récap dit quelles lignes chevauchent');
        self::assertContains($ids[0], $body['overlappingReservationIds']);
        self::assertContains($ids[1], $body['overlappingReservationIds']);
        self::assertNotContains($ids[2], $body['overlappingReservationIds']);
        $this->assertOneCausePerLine($body);
    }

    /** Plafond 2, deux verrous chargés qui se chevauchent sans réservation : rien ne bloque, l'alerte de chevauchement parle. */
    public function testTwoOverlappingLocksUnderACapOfTwoAreWarnedNotBlocked(): void
    {
        $ctx = $this->seasonDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->window($ctx, $ctx['jdr'], self::THURSDAY, '20:00');
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', LockLevel::HARD);
        $this->session($ctx, $ctx['u15'], $ctx['jdr'], self::THURSDAY, '20:00', LockLevel::HARD);

        $body = $this->recap($ctx);

        self::assertSame([], $this->dayBlockers($body));
        self::assertSame(['U15 a deux séances le jeudi sur des créneaux qui se chevauchent : 19:00-20:30 à Armand et 20:00-21:30 à JDR.'], $this->overlapWarnings($body));
    }

    /** Plafond 2, en période : deux réservations qui se chevauchent ne bloquent pas, l'alerte reste, mot pour mot. */
    public function testInAPeriodTwoOverlappingReservationsUnderACapOfTwoAreWarnedNotBlocked(): void
    {
        $ctx = $this->periodDecor();
        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->window($ctx, $ctx['jdr'], self::TUESDAY, '19:00', $ctx['planId']);
        $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '19:00');

        $body = $this->recap($ctx, $ctx['entryId']);

        self::assertSame([], $this->dayBlockers($body));
        self::assertSame(['U15 est réservée deux fois le mardi sur des créneaux qui se chevauchent : 18:00-19:30 à Armand et 19:00-20:30 à JDR.'], $this->overlapWarnings($body));
    }

    /**
     * Contrat d'API du Récap : chaque ligne bloquée dit sa cause, lue telle quelle par l'écran. Plafond 2 : trois réservations
     * disjointes → toutes dans `doubledReservationIds` (trop de séances) ; deux qui se chevauchent → toutes deux dans
     * `overlappingReservationIds` ; jamais une ligne dans les deux ; les deux champs présents dans le corps 422 comme dans le 200.
     * Jumeau écran : `frontend/src/features/wizard/steps/RecapStep.test.tsx` (le motif de la ligne suit la liste lue).
     */
    public function testEachBlockedLineCarriesOneCauseInBothResponseBodies(): void
    {
        $ctx = $this->seasonDecor();

        $healthy = $this->recap($ctx);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $healthy['doubledReservationIds'] ?? null, 'corps 200 : le champ « trop de séances » est présent, vide');
        self::assertSame([], $healthy['overlappingReservationIds'] ?? null, 'corps 200 : le champ « chevauche » est présent, vide');

        $this->dailyCap($ctx, $ctx['u15'], 2);
        $this->dailyCap($ctx, $ctx['u18'], 2);

        $this->extraWindow($ctx, $ctx['armand'], self::TUESDAY, '10:00');
        $tooMany = [
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '10:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00'),
            $this->reservation($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30'),
        ];
        $this->window($ctx, $ctx['jdr'], self::THURSDAY, '20:00');
        $overlapping = [
            $this->reservation($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00'),
            $this->reservation($ctx, $ctx['u18'], $ctx['jdr'], self::THURSDAY, '20:00'),
        ];

        $blocked = $this->recap($ctx);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertDoubled($tooMany, $blocked);
        $this->assertOverlapping($overlapping, $blocked);
        $this->assertOneCausePerLine($blocked);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param Decor $ctx
     *
     * @return array<string, mixed>
     */
    private function recap(array $ctx, ?string $calendarEntryId = null): array
    {
        $this->post($ctx, '/api/constraints/validate', null === $calendarEntryId ? [] : ['calendarEntryId' => $calendarEntryId]);

        return $this->body();
    }

    /**
     * @param Decor                $ctx
     * @param array<string, mixed> $body
     */
    private function post(array $ctx, string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($ctx['user']),
            'CONTENT_TYPE' => '/api/reservations' === $url ? 'application/ld+json' : 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function ruleBlockers(array $body): array
    {
        return array_values(array_filter(
            \is_array($body['blockers'] ?? null) ? $body['blockers'] : [],
            static fn (mixed $blocker): bool => \is_string($blocker) && str_contains($blocker, self::RULE),
        ));
    }

    /**
     * Les motifs « séances d'une équipe le même jour », quelle qu'en soit la cause (plafond dépassé ou chevauchement).
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function dayBlockers(array $body): array
    {
        self::assertArrayHasKey('blockers', $body, 'le Récap a bien répondu');

        return array_values(array_filter(
            \is_array($body['blockers']) ? $body['blockers'] : [],
            static fn (mixed $blocker): bool => \is_string($blocker) && 1 === preg_match('/ (fois|séances) le (lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\b/u', $blocker),
        ));
    }

    /**
     * @param list<string>         $expected
     * @param array<string, mixed> $body
     */
    private function assertDoubled(array $expected, array $body): void
    {
        self::assertIsArray($body['doubledReservationIds'] ?? null, 'le Récap désigne les lignes à retirer');
        self::assertEqualsCanonicalizing($expected, $body['doubledReservationIds']);
    }

    /**
     * Les lignes bloquées parce que leur créneau chevauche une autre séance de l'équipe ce jour-là.
     *
     * @param list<string>         $expected
     * @param array<string, mixed> $body
     */
    private function assertOverlapping(array $expected, array $body): void
    {
        self::assertIsArray($body['overlappingReservationIds'] ?? null, 'le Récap dit quelles lignes chevauchent');
        self::assertEqualsCanonicalizing($expected, $body['overlappingReservationIds']);
    }

    /**
     * Une ligne bloquée porte une seule cause : jamais dans les deux listes à la fois.
     *
     * @param array<string, mixed> $body
     */
    private function assertOneCausePerLine(array $body): void
    {
        self::assertIsArray($body['doubledReservationIds'] ?? null, 'le champ « trop de séances » est toujours présent');
        self::assertIsArray($body['overlappingReservationIds'] ?? null, 'le champ « chevauche » est toujours présent');
        self::assertSame([], array_values(array_intersect($body['doubledReservationIds'], $body['overlappingReservationIds'])), 'une ligne bloquée porte une seule cause');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function warnings(array $body): array
    {
        self::assertIsArray($body['warnings'] ?? null, 'le Récap a bien répondu');

        return array_values(array_filter($body['warnings'], is_string(...)));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function overlapWarnings(array $body): array
    {
        return array_values(array_filter($this->warnings($body), static fn (string $w): bool => str_contains($w, 'se chevauchent')));
    }

    /**
     * Une case de plus à la grille : de saison, ou de la période quand `$planId` est donné.
     *
     * @param Decor $ctx
     */
    private function window(array $ctx, string $venueId, int $day, string $start, ?string $planId = null): void
    {
        $this->scopeGucToClub($ctx['clubId']);
        $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setVenueId($venueId)->setSchedulePlanId($planId)
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(3));
        $this->em->flush();
    }

    /** @param array<string, mixed> $body */
    private function assertNotDoubled(array $body): void
    {
        self::assertArrayHasKey('blockers', $body, 'le Récap a bien répondu');
        self::assertSame([], $this->ruleBlockers($body));
        self::assertSame([], $body['doubledReservationIds'] ?? []);
    }

    /**
     * La version chargée d'une saison : le pointeur que lit `SchedulePlanProvisioner::loadedVersionIdOfSeason`.
     *
     * @param Decor $ctx
     */
    private function loadVersion(array $ctx, string $versionId): void
    {
        $this->scopeGucToClub($ctx['clubId']);
        $this->em->getConnection()->executeStatement('UPDATE season SET live_context_schedule_id = ? WHERE id = ?', [$versionId, $ctx['seasonId']]);
    }

    /** @param Decor $ctx */
    private function version(array $ctx, ScheduleStatus $status): string
    {
        if (ScheduleStatus::COMPLETED === $status) {
            return $ctx['scheduleId'];
        }
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Brouillon')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule->getId();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data;
    }
}
