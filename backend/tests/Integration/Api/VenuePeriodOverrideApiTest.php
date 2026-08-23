<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #8 (décision fondateur 2026-07-24) — UNE PÉRIODE POSSÈDE SA GRILLE DE GYMNASES.
 *
 * À la naissance du plan de période, les créneaux de saison sont COPIÉS ; la période ne
 * lit ensuite QUE les siens. Les modes sont donc des ACTIONS sur cette copie, bornées à
 * UN gymnase, et l'invariant fondateur n°1 tient : le planning principal (la structure de
 * saison, `schedule_plan_id IS NULL`) n'est JAMAIS modifié.
 *
 * Ce que ces cas prouvent : POST d'un mode vide la grille du gymnase pour cette période
 * (et emporte les épinglages qu'elle portait), DELETE = retour à « hériter » et RECOPIE le
 * modèle de saison, le doublon rend un 422 propre, et rien de tout cela ne franchit la
 * frontière du club.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenuePeriodOverrideApiTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    /** Gymnase A : 2 créneaux de saison — c'est lui qu'on bascule. */
    private Venue $venueA;

    /** Gymnase B : 1 créneau de saison — témoin, il ne doit jamais bouger. */
    private Venue $venueB;

    private string $planId;

    private string $entryId;

    private ?Schedule $periodSchedule = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function gridActionRoutes(): iterable
    {
        yield 'reset-grid' => ['/api/venue_period_overrides/reset-grid'];
        yield 'clear-grid' => ['/api/venue_period_overrides/clear-grid'];
    }

    public function testBlankEmptiesOnlyThatVenuesPeriodGridAndNeverTheSeason(): void
    {
        // État de naissance : la période a reçu une COPIE de toute la grille de saison.
        self::assertSame(2, $this->countPeriodSlots($this->venueA));
        self::assertSame(1, $this->countPeriodSlots($this->venueB));
        self::assertSame(3, $this->countSeasonSlots());

        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('BLANK', $created['mode']);

        // Le gymnase basculé repart d'une grille vierge POUR CETTE PÉRIODE…
        self::assertSame(0, $this->countPeriodSlots($this->venueA), 'la grille du gymnase basculé est vidée pour la période');
        // …son voisin de la même période n'a pas bougé…
        self::assertSame(1, $this->countPeriodSlots($this->venueB), 'la bascule est bornée à UN gymnase');
        // …et le planning principal est intact (invariant fondateur n°1).
        self::assertSame(3, $this->countSeasonSlots(), 'les créneaux de saison survivent TOUS — le planning principal n’est jamais modifié');
    }

    /**
     * NR — DÉSACTIVÉ CONSERVE LA GRILLE, il ne la SERT pas (décision fondateur
     * 2026-07-24) : « ça doit se voir que l'on a désactivé à l'écran et ça ne doit pas la
     * servir côté backend pour l'engine ». Vider serait doublement fautif : le
     * gestionnaire perdrait la saisie qu'il avait faite, et réactiver ne la lui rendrait
     * pas. VIERGE, lui, vide — c'est ce qu'on lui demande.
     */
    public function testDisabledKeepsTheVenuesGridInsteadOfEmptyingIt(): void
    {
        $before = $this->countPeriodSlots($this->venueA);
        self::assertGreaterThan(0, $before, 'le gymnase a bien une grille avant la bascule');

        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($before, $this->countPeriodSlots($this->venueA), 'désactiver ne détruit pas la grille — elle reste visible à l’écran');
        self::assertSame(3, $this->countSeasonSlots(), 'et le planning principal reste intact');
    }

    public function testSwitchingToBlankCarriesTheReservationsAndHardLocksItHeld(): void
    {
        // Un créneau de la période porte une réservation et le verrou HARD qu'elle a
        // matérialisé. Les laisser derrière produirait exactement l'orphelin que
        // OrphanPinGuard refuse ensuite : la bascule doit les emporter.
        $slot = $this->periodSlots($this->venueA)[0];
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venueA->getId())
            ->setDayOfWeek($slot->getDayOfWeek())->setStartTime($slot->getStartTime())->setDurationMinutes(90);
        $hard = $this->slotTemplate($slot, LockLevel::HARD);
        // Un placement SOFT au même horaire est un RÉSULTAT de solve, pas un épinglage :
        // la cascade ne l'emporte pas (EntityCascadeDeleter, hardOnly).
        $soft = $this->slotTemplate($slot, LockLevel::SOFT);
        $this->em->persist($reservation);
        $this->em->persist($hard);
        $this->em->persist($soft);
        $this->em->flush();
        $reservationId = $reservation->getId();
        $hardId = $hard->getId();
        $softId = $soft->getId();

        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Reservation::class)->find($reservationId), 'la réservation du créneau supprimé part avec lui');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($hardId), 'le verrou HARD matérialisé part avec le créneau');
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($softId), 'un placement SOFT est un résultat de solve, pas un épinglage — il survit');
    }

    /**
     * NR — vider la grille d'une PÉRIODE ne touche jamais les épinglages du PLANNING
     * PRINCIPAL au même horaire. La cascade identifie un épinglage par (gymnase, jour,
     * heure) : sans borne de couche, elle emportait la réservation de base au même
     * créneau — le planning principal était donc modifié par un réglage de période
     * (invariant fondateur n°1). Fuite trouvée en écrivant ces tests.
     */
    public function testEmptyingThePeriodGridSparesTheSeasonPins(): void
    {
        $slot = $this->periodSlots($this->venueA)[0];
        // Une réservation de BASE (schedulePlanId null = structure partagée de la saison)
        // au MÊME gymnase/jour/heure que le créneau de période qu'on va supprimer.
        $seasonPin = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(null)
            ->setTeamId('22222222-2222-4222-8222-222222222222')
            ->setVenueId($this->venueA->getId())
            ->setDayOfWeek($slot->getDayOfWeek())->setStartTime($slot->getStartTime())->setDurationMinutes(90);
        $this->em->persist($seasonPin);
        $this->em->flush();
        $seasonPinId = $seasonPin->getId();

        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(Reservation::class)->find($seasonPinId),
            'la réservation du planning principal survit — une période ne le modifie jamais',
        );
    }

    public function testDuplicateOverrideIsRejectedWithValidationNotServerError(): void
    {
        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        // Un second POST sur le même (période, gymnase) → 422 propre, pas un 500 remonté
        // de l'index unique. L'édition passe par PUT.
        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(422);
        // P4-126 — le motif voyage jusque dans le corps (un 422 muet rendrait `violations: []`).
        self::assertStringContainsString('Ce gymnase a déjà un réglage pour cette période — modifiez-le.', (string) $this->client->getResponse()->getContent());
    }

    // ── Indispo INFORMATIVE (fondateur 2026-08-18) : le verrou P2-37 D2 SAUTE ──
    // Un gymnase entièrement fermé par une indisponibilité déclarée redevient PILOTABLE :
    // la coche du plan fait foi. Ces trois cas gardaient l'inverse (refus 422) — ils s'inversent.

    public function testPostAModeOnAFullyClosedVenueIsNowAllowed(): void
    {
        // Fermeture couvrant TOUTE la fenêtre 2025-12-22 → 2025-12-28 : l'indisponibilité est
        // désormais INFORMATIVE — le gestionnaire peut quand même régler le gymnase.
        $this->closeVenueForWholeWindow($this->venueA, 'Fermeture de fin d’année');

        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201, 'le réglage n’est plus verrouillé par l’indisponibilité (D2 supplanté)');
        self::assertSame('DISABLED', $created['mode']);
    }

    public function testPutAModeOnAFullyClosedVenueIsNowAllowed(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);
        // La fermeture totale tombe : le PUT reste possible — plus de verrou de façade.
        $this->closeVenueForWholeWindow($this->venueA, 'Réquisition');

        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
            'mode' => 'BLANK',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('BLANK', json_decode((string) $this->client->getResponse()->getContent(), true)['mode']);
    }

    public function testDeleteAnOverrideOnAFullyClosedVenueIsNowAllowed(): void
    {
        // « Réactiver le gymnase entier malgré l'indispo » n'est plus refusé : l'indisponibilité
        // ne fait que pré-remplir le défaut, elle ne verrouille rien.
        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);
        $this->closeVenueForWholeWindow($this->venueA, 'Réquisition');

        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(VenuePeriodOverride::class)->find($created['id']),
            'l’override est bien supprimé : le retour à « hériter » n’est plus verrouillé',
        );
    }

    // ── Masque manuel jour tri-état (OPEN|CLOSED) — décision fondateur 2026-08-18 ──

    public function testAMaskOnlyRowIsAcceptedWithoutAMode(): void
    {
        // Une ligne peut n'exister QUE pour son masque : mode NULL (hériter), masque forçant
        // le samedi fermé et le mercredi ouvert.
        $created = $this->post([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
            'dayOverrides' => ['6' => 'CLOSED', '3' => 'OPEN'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($created['mode'], 'mode facultatif : une ligne peut n’avoir qu’un masque');
        self::assertSame(['6' => 'CLOSED', '3' => 'OPEN'], $created['dayOverrides']);

        // La grille n’a pas bougé : le masque ne vide/recopie rien (contrairement à BLANK).
        self::assertSame(2, $this->countPeriodSlots($this->venueA), 'le masque n’agit pas sur la grille');
    }

    public function testAnEmptyRowWithNeitherModeNorMaskIsRejected(): void
    {
        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId()]);
        self::assertResponseStatusCodeSame(422, 'une ligne sans mode ni masque n’exprime rien');
    }

    public function testAnInvalidMaskKeyIsRejected(): void
    {
        // Clé hors 1..7 (8 = pas un jour ISO) → 422, la colonne JSON n’est jamais un fourre-tout.
        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'dayOverrides' => ['8' => 'OPEN']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnInvalidMaskValueIsRejected(): void
    {
        // Valeur hors OPEN|CLOSED → 422.
        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'dayOverrides' => ['2' => 'MAYBE']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteReturnsToInheritAndPurgesBothModeAndMask(): void
    {
        // DELETE = retour complet au défaut : mode ET masque disparaissent (sparse : pas de ligne).
        $created = $this->post([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
            'mode' => 'DISABLED',
            'dayOverrides' => ['6' => 'CLOSED'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(VenuePeriodOverride::class)->find($created['id']), 'la ligne — mode ET masque — a disparu');
    }

    public function testAPartialClosureStillLetsTheVenueBeConfigured(): void
    {
        // Une fermeture PARTIELLE (un seul jour) laisse évidemment le réglage permis.
        $this->closeVenue($this->venueA, 'Un seul jour', '2025-12-24', '2025-12-24');

        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);
    }

    public function testCollectionIsScopedToTheRequestedPeriod(): void
    {
        $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        // Un réglage d'une AUTRE période ne doit pas apparaître dans cette période.
        $otherPlanId = $this->planIdOf($this->period('Toussaint', '2025-10-20', '2025-10-26'));
        $this->post(['schedulePlanId' => $otherPlanId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);

        $members = $this->getCollection('?schedulePlanId=' . $this->planId);
        self::assertCount(1, $members, 'la collection est cloisonnée à la période demandée');
        self::assertSame($this->venueA->getId(), $members[0]['venueId']);
        self::assertSame('BLANK', $members[0]['mode']);

        // Le filtre venueId affine encore.
        self::assertCount(0, $this->getCollection('?schedulePlanId=' . $this->planId . '&venueId=' . $this->venueB->getId()));
    }

    public function testPutChangesTheMode(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);

        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
            'mode' => 'DISABLED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('DISABLED', $body['mode']);

        $this->client->request('GET', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertSame('DISABLED', json_decode((string) $this->client->getResponse()->getContent(), true)['mode']);
    }

    public function testDeleteReturnsToInheritAndRecopiesTheSeasonGrid(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->countPeriodSlots($this->venueA));

        // DELETE = retour au défaut « hériter » (sparse : pas de ligne = hériter).
        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        // La grille du gymnase est RECOPIÉE depuis la saison : même compte qu'en saison,
        // sans doublon (vider AVANT recopier — l'ordre inverse doublerait les créneaux).
        self::assertSame(2, $this->countPeriodSlots($this->venueA), 'la grille du gymnase est recopiée depuis le modèle de saison');
        self::assertSame(1, $this->countPeriodSlots($this->venueB), 'le voisin n’a pas bougé');
        self::assertSame(3, $this->countSeasonSlots(), 'le planning principal reste intact');

        // Les horaires recopiés sont bien ceux de la saison — pas des créneaux quelconques.
        self::assertSame($this->seasonSlotKeys($this->venueA), $this->periodSlotKeys($this->venueA));

        // Et la ligne a disparu : la période hérite de nouveau.
        self::assertCount(0, $this->getCollection('?schedulePlanId=' . $this->planId));
    }

    public function testReactivatingADisabledVenueKeepsItsPinsInstead0fWipingThem(): void
    {
        // Revue #8 round 4 — DÉSACTIVÉ conserve la grille, et supprimer la ligne est la
        // SEULE façon de réactiver le gymnase. Y rejouer la purge détruisait les
        // réservations et les verrous posés AVANT la désactivation, en rendant une grille
        // d'apparence identique : le gestionnaire ne voyait rien, et la génération
        // suivante déplaçait la séance ailleurs.
        $slot = $this->periodSlots($this->venueA)[0];
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venueA->getId())
            ->setDayOfWeek($slot->getDayOfWeek())->setStartTime($slot->getStartTime())->setDurationMinutes(90);
        $hard = $this->slotTemplate($slot, LockLevel::HARD);
        $this->em->persist($reservation);
        $this->em->persist($hard);
        $this->em->flush();
        $reservationId = $reservation->getId();
        $hardId = $hard->getId();
        $before = $this->periodSlotKeys($this->venueA);

        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'DISABLED']);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertSame($before, $this->periodSlotKeys($this->venueA), 'réactiver rend la grille telle quelle');
        self::assertNotNull($this->em->getRepository(Reservation::class)->find($reservationId), 'la réservation posée avant la désactivation survit');
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($hardId), 'et le verrou HARD aussi — désactiver ne coûte pas la saisie faite');
    }

    public function testReSavingTheSameModeLeavesTheHandTypedGridAlone(): void
    {
        // Revue #8 round 4 — le correctif d'idempotence du PUT n'était couvert par aucun
        // test : retirer la comparaison au mode précédent laissait tout le fichier au vert
        // tout en réintroduisant la perte de données. Un double-submit, ou un simple
        // ré-enregistrement du formulaire, rejouait la purge sur ce que le gestionnaire
        // venait de ressaisir.
        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->countPeriodSlots($this->venueA), 'VIERGE vide bien la grille du gymnase');

        // Le gestionnaire ressaisit son créneau à la main dans la grille vierge.
        $manual = (new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)->setVenueId($this->venueA->getId())
            ->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('20:30'))
            ->setDurationMinutes(60)->setCapacity(1);
        $this->em->persist($manual);
        $this->em->flush();

        // Re-enregistrer le MÊME mode : action d'apparence idempotente, elle doit l'être.
        $this->client->request('PUT', '/api/venue_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
            'mode' => 'BLANK',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(1, $this->countPeriodSlots($this->venueA), 'le créneau ressaisi à la main survit au ré-enregistrement');
    }

    // ── PR-B : « Reprendre la grille du planning principal » (action atomique) ──

    public function testResetGridRecopiesTheSeasonModelForThatVenueOnly(): void
    {
        // Le geste que l'UI propose sur un gymnase en mode « hériter » — donc SANS ligne
        // d'override, le cas courant. Le contournement client (poser VIERGE puis
        // supprimer la ligne) laisserait une grille vidée si le second appel échouait.
        $slot = $this->periodSlots($this->venueA)[0];
        $this->em->remove($slot);
        $this->em->flush();
        self::assertSame(1, $this->countPeriodSlots($this->venueA), 'la grille de la période a été amputée d’un créneau');

        $this->client->request('POST', '/api/venue_period_overrides/reset-grid', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame($this->seasonSlotKeys($this->venueA), $this->periodSlotKeys($this->venueA), 'la grille du gymnase est celle du planning principal');
        self::assertSame(1, $this->countPeriodSlots($this->venueB), 'le gymnase voisin n’a pas bougé');
        self::assertSame(3, $this->countSeasonSlots(), 'et le planning principal reste intact');
    }

    public function testResetGridIsIdempotentAndNeverDuplicates(): void
    {
        // Rejouer le geste (double-clic, retry réseau) doit redonner la MÊME grille.
        // Recopier sans vider d’abord doublerait chaque créneau — et un créneau en double
        // au même horaire, c’est un gymnase réservé deux fois.
        for ($i = 0; $i < 3; ++$i) {
            $this->client->request('POST', '/api/venue_period_overrides/reset-grid', [], [], $this->headers(), json_encode([
                'schedulePlanId' => $this->planId,
                'venueId' => $this->venueA->getId(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
        }

        $this->em->clear();
        self::assertSame($this->seasonSlotKeys($this->venueA), $this->periodSlotKeys($this->venueA));
    }

    public function testResetGridIsRefusedOnTheSeasonPlan(): void
    {
        // Invariant fondateur n°1 : le planning principal n’est JAMAIS modifié par une
        // période. Visé sur le plan de saison, ce geste viderait la grille du club puis
        // recopierait ses propres créneaux par-dessus eux-mêmes.
        $seasonPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($this->season->getId());
        self::assertIsString($seasonPlanId);

        $this->client->request('POST', '/api/venue_period_overrides/reset-grid', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $seasonPlanId,
            'venueId' => $this->venueA->getId(),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame(3, $this->countSeasonSlots(), 'la grille de saison est intacte');
    }

    public function testClearGridEmptiesTheVenueGridEvenWithNoOverrideRow(): void
    {
        // Le c\u0153ur du correctif finding #1 : « vider » est une ACTION atomique, pas un PUT
        // de mode BLANK. Sur un gymnase SANS ligne d'override (« h\u00e9riter », le cas
        // courant), router par le mode ne cr\u00e9erait rien \u00e0 vider ; l'endpoint clear-grid,
        // lui, vide bel et bien. Et il est idempotent : rejou\u00e9, il ne l\u00e8ve pas.
        self::assertGreaterThan(0, $this->countPeriodSlots($this->venueA));

        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/api/venue_period_overrides/clear-grid', [], [], $this->headers(), json_encode([
                'schedulePlanId' => $this->planId,
                'venueId' => $this->venueA->getId(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
        }

        $this->em->clear();
        self::assertSame(0, $this->countPeriodSlots($this->venueA), 'la grille du gymnase est vid\u00e9e');
        self::assertSame(1, $this->countPeriodSlots($this->venueB), 'le voisin n\'a pas boug\u00e9');
        self::assertSame(3, $this->countSeasonSlots(), 'le planning principal reste intact');
    }

    public function testClearGridIsRefusedOnTheSeasonPlan(): void
    {
        // Invariant n\u00b01 : viser le plan de saison viderait la grille du club.
        $seasonPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($this->season->getId());
        self::assertIsString($seasonPlanId);

        $this->client->request('POST', '/api/venue_period_overrides/clear-grid', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $seasonPlanId,
            'venueId' => $this->venueA->getId(),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame(3, $this->countSeasonSlots(), 'la grille de saison est intacte');
    }

    public function testResetGridCarriesTheReservationsAndPinsOfTheVenue(): void
    {
        // Reprendre la grille DÉTRUIT : les réservations du gymnase et les verrous HARD
        // qu’elles ont matérialisés partent avec ses créneaux. C’est assumé — c’est
        // l’écran qui prévient avant (« un changement de créneau supprime tous les
        // créneaux réservés du gymnase »). Ce qu’on garantit ici, c’est qu’ils ne
        // survivent pas ORPHELINS : OrphanPinGuard refuserait ensuite toute génération.
        $slot = $this->periodSlots($this->venueA)[0];
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venueA->getId())
            ->setDayOfWeek($slot->getDayOfWeek())->setStartTime($slot->getStartTime())->setDurationMinutes(90);
        $hard = $this->slotTemplate($slot, LockLevel::HARD);
        $this->em->persist($reservation);
        $this->em->persist($hard);
        $this->em->flush();
        $reservationId = $reservation->getId();
        $hardId = $hard->getId();

        $this->client->request('POST', '/api/venue_period_overrides/reset-grid', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertNull($this->em->getRepository(Reservation::class)->find($reservationId), 'la réservation part avec le créneau repris');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($hardId), 'et le verrou HARD qu’elle avait matérialisé');
    }

    public function testAnotherClubNeitherSeesNorWritesTheOverride(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'venueId' => $this->venueA->getId(), 'mode' => 'BLANK']);
        self::assertResponseStatusCodeSame(201);

        $intruder = $this->seedIntruderClub();

        // Ni en lecture (collection filtrée sur la période de l'autre club)…
        $this->client->request('GET', '/api/venue_period_overrides?schedulePlanId=' . $this->planId, [], [], $intruder);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(0, $body['member'] ?? [], 'un autre club ne voit pas les réglages du club voisin');

        // …ni à l'unité…
        $this->client->request('GET', '/api/venue_period_overrides/' . $created['id'], [], [], $intruder);
        self::assertResponseStatusCodeSame(404);

        // …ni en écriture — et la grille du voisin n'a pas bougé.
        $this->client->request('DELETE', '/api/venue_period_overrides/' . $created['id'], [], [], $intruder);
        self::assertResponseStatusCodeSame(404);

        $this->scopeGucToClub($this->club->getId());
        self::assertSame(0, $this->countPeriodSlots($this->venueA), 'la tentative de l’intrus n’a rien recopié chez le voisin');
        self::assertNotNull($this->em->getRepository(VenuePeriodOverride::class)->find($created['id']));
    }

    #[DataProvider('gridActionRoutes')]
    public function testGridActionIsRefusedForANonManager(string $route): void
    {
        // SEC-07 — les actions de grille sont DESTRUCTIVES : un membre non-management
        // (editor) doit \u00eatre refus\u00e9 en 403, comme la suppression d'un planning.
        $editor = $this->addActiveMember('editor');
        $this->client->request('POST', $route, [], [], $editor, json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertGreaterThan(0, $this->countPeriodSlots($this->venueA), 'la grille n\'a pas boug\u00e9');
    }

    #[DataProvider('gridActionRoutes')]
    public function testGridActionFromAnotherClubIsRefusedAndTouchesNothing(string $route): void
    {
        // Isolation tenant — le plan est r\u00e9solu sous RLS scop\u00e9e sur le club appelant : un
        // autre club ne le VOIT pas (404, pas 403 : on ne divulgue m\u00eame pas son existence),
        // et ne peut donc ni le vider ni le reprendre.
        $intruder = $this->seedIntruderClub();

        $this->client->request('POST', $route, [], [], $intruder, json_encode([
            'schedulePlanId' => $this->planId,
            'venueId' => $this->venueA->getId(),
        ], \JSON_THROW_ON_ERROR));

        // 404 = le plan est INVISIBLE \u00e0 l'intrus (RLS) : le contr\u00f4leur sort \u00e0
        // fetchPlanContext == null, AVANT tout appel \u00e0 VenuePeriodGrid. Rien n'a donc pu
        // toucher la grille du club voisin \u2014 le 404 le prouve par construction.
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('VPO ' . $uid)->setSlug('vpo-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('vpo' . $uid . '@test.com')->setFirstName('V')->setLastName('P');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->venueA = $this->venue('Gymnase A');
        $this->venueB = $this->venue('Gymnase B');
        // La grille de SAISON (schedule_plan_id IS NULL) — le modèle que la période copie.
        $this->seasonSlot($this->venueA, 2, '18:00');
        $this->seasonSlot($this->venueA, 4, '20:00');
        $this->seasonSlot($this->venueB, 3, '19:00');
        $this->em->flush();

        // La période et son plan naissent du geste ; la naissance copie la grille.
        $entry = $this->period('Vacances', '2025-12-22', '2025-12-28');
        $this->entryId = $entry->getId();
        $this->planId = $this->planIdOf($entry);

        $this->periodSchedule = null;
        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function addActiveMember(string $role): array
    {
        $uid = uniqid('', true);
        $user = (new User)->setEmail('vpo-' . $role . $uid . '@test.com')->setFirstName('E')->setLastName('D');
        $user->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole($role)->setIsActive(true));
        $this->em->flush();

        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    private function seedIntruderClub(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('VPO Intrus ' . $uid)->setSlug('vpo-intrus-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $user = (new User)->setEmail('vpo-intrus' . $uid . '@test.com')->setFirstName('I')->setLastName('N');
        $user->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [
            'HTTP_X-Club-Id' => $club->getId(),
            'HTTP_X-Season-Id' => $season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    private function venue(string $name): Venue
    {
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($name)->setSource('manual')->setIsActive(true);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /** Ferme le gymnase sur TOUTE la fenêtre de la période (indisponibilité totale, D1). */
    private function closeVenueForWholeWindow(Venue $venue, string $title): void
    {
        $this->closeVenue($venue, $title, '2025-12-22', '2025-12-28');
    }

    /** Une fermeture datée `venue_closed` sur ce gymnase, rattachée à l'entrée de la période. */
    private function closeVenue(Venue $venue, string $title, string $startDate, string $endDate): void
    {
        $constraint = (new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($title)
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId($venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($this->entryId);
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $startDate, 'endDate' => $endDate]);
        $this->em->persist($constraint);
        $this->em->flush();
        $this->em->clear();
    }

    private function seasonSlot(Venue $venue, int $dayOfWeek, string $startTime): VenueTrainingSlot
    {
        $slot = (new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($startTime))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);

        return $slot;
    }

    private function period(string $title, string $start, string $end): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle($title)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * Un verrou appartient toujours à une VERSION, et une version à un plan : la cascade
     * ne touche que les versions du plan concerné (sinon vider une période emporterait les
     * verrous du planning principal). La fixture doit donc porter une vraie version de
     * CETTE période — un scheduleId fictif ne modélisait rien de réel.
     */
    private function periodSchedule(): Schedule
    {
        if (!$this->periodSchedule instanceof Schedule) {
            $this->periodSchedule = (new Schedule)
                ->setClubId($this->club->getId())
                ->setSeasonId($this->season->getId())
                ->setSchedulePlanId($this->planId)
                ->setName('V1 période');
            $this->periodSchedule->setStatus(ScheduleStatus::COMPLETED);
            $this->em->persist($this->periodSchedule);
            $this->em->flush();
        }

        return $this->periodSchedule;
    }

    private function slotTemplate(VenueTrainingSlot $slot, LockLevel $lockLevel): ScheduleSlotTemplate
    {
        return (new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setScheduleId($this->periodSchedule()->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($slot->getVenueId())
            ->setDayOfWeek($slot->getDayOfWeek())->setStartTime($slot->getStartTime())->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
    }

    /** @return list<VenueTrainingSlot> */
    private function periodSlots(Venue $venue): array
    {
        return $this->em->getRepository(VenueTrainingSlot::class)
            ->findBy(['schedulePlanId' => $this->planId, 'venueId' => $venue->getId()]);
    }

    private function countPeriodSlots(Venue $venue): int
    {
        $this->em->clear();

        return \count($this->periodSlots($venue));
    }

    private function countSeasonSlots(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => null]));
    }

    /** @return list<string> */
    private function seasonSlotKeys(Venue $venue): array
    {
        $this->em->clear();

        return $this->keys($this->em->getRepository(VenueTrainingSlot::class)
            ->findBy(['schedulePlanId' => null, 'venueId' => $venue->getId()]));
    }

    /** @return list<string> */
    private function periodSlotKeys(Venue $venue): array
    {
        $this->em->clear();

        return $this->keys($this->periodSlots($venue));
    }

    /**
     * @param list<VenueTrainingSlot> $slots
     *
     * @return list<string>
     */
    private function keys(array $slots): array
    {
        $keys = array_map(
            static fn (VenueTrainingSlot $slot): string => $slot->getDayOfWeek() . '@' . $slot->getStartTime()->format('H:i'),
            $slots,
        );
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/venue_period_overrides', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    private function getCollection(string $query): array
    {
        $this->client->request('GET', '/api/venue_period_overrides' . $query, [], [], $this->headers());
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
