<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
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
use App\Enum\VenuePeriodMode;
use App\Service\OrphanPinGuard;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #8 (décision fondateur 2026-07-24) — UN ÉPINGLAGE ORPHELIN BLOQUE LA GÉNÉRATION.
 *
 * Une période possède sa grille ; la refaire (page blanche, gymnase désactivé, recopie)
 * peut laisser un verrou HARD ou une réservation qui ne retombe sur AUCUN créneau. « On ne
 * fait pas de chose magique, on voit et on informe le gestionnaire » : on refuse et on
 * NOMME le gymnase et le jour, plutôt que de replacer la séance en silence.
 *
 * Le dernier cas prouve le BRANCHEMENT : `POST /api/schedules/{id}/generate` rend 422 avec
 * ce message et ne met RIEN en file. Il vit ici, avec le reste du contrat de la garde,
 * plutôt que dans GenerateScheduleComplexityCapTest — ce fichier-là est le NR d'une autre
 * garde (A10, plafonds de complexité) et n'a pas de seed d'overlay.
 */
#[Group('phase1')]
#[Group('integration')]
final class OrphanPinGuardTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private OrphanPinGuard $guard;

    private Club $club;

    private User $user;

    private Season $season;

    private Venue $venue;

    private string $planId;

    private string $entryId;

    /** La version de période : c'est ELLE qu'on cherche à générer. */
    private Schedule $overlay;

    public function testHardLockWithoutAMatchingPeriodSlotIsRefusedAndNamesTheVenueAndDay(): void
    {
        // Jeudi 20:00 : la période n'a que le mardi 18:00 — le verrou ne retombe sur rien.
        $this->em->persist($this->slotTemplate(4, '20:00', LockLevel::HARD));
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message);
        self::assertStringContainsString('jeudi', $message, 'le message nomme le jour');
        self::assertStringContainsString($this->venue->getName(), $message, 'le message nomme le gymnase');
    }

    public function testOrphanReservationIsRefusedToo(): void
    {
        $this->em->persist($this->reservation(5, '19:30'));
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message);
        self::assertStringContainsString('vendredi', $message);
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    /**
     * Quand la cause de l'orphelin est une FERMETURE (le gymnase ne sert pas ce jour-là),
     * le message le DIT — bornes et titre de la fermeture — au lieu du générique « redéfinissez
     * vos créneaux » : ici le gestionnaire n'a rien à redéfinir, c'est la fermeture qu'il gère.
     */
    public function testAnOrphanFallingOnAClosedDayNamesTheClosureCause(): void
    {
        // Gymnase fermé le mardi 2025-10-21 de la période ; la réservation du mardi 18:00
        // (un créneau qui EXISTE dans la grille) devient orpheline par cette fermeture.
        $constraint = (new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Gymnase réquisitionné')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId($this->venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($this->entryId);
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => '2025-10-21', 'endDate' => '2025-10-21']);
        $this->em->persist($constraint);
        $this->em->persist($this->reservation(2, '18:00')); // mardi 18:00 : le créneau de la période, mais fermé
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message);
        self::assertStringContainsString('fermé', $message, 'la cause fermeture est nommée');
        self::assertStringContainsString('21/10', $message, 'les bornes de la fermeture');
        self::assertStringContainsString('Gymnase réquisitionné', $message, 'le titre de la fermeture');
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    public function testPinsThatLandOnAPeriodSlotPassThrough(): void
    {
        // Mardi 18:00 : exactement le créneau que la période possède.
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->persist($this->reservation(2, '18:00'));
        $this->em->flush();

        self::assertNull($this->guard->firstOrphanMessage($this->overlay), 'un épinglage qui retombe sur un créneau de la période ne bloque rien');
    }

    public function testSoftAndNoneOrphansArePlacementsNotPinsSoTheyPassThrough(): void
    {
        // Un placement SOFT/NONE est un RÉSULTAT de solve. Le refuser bloquerait toute
        // régénération après le moindre changement de grille.
        $this->em->persist($this->slotTemplate(4, '20:00', LockLevel::SOFT));
        $this->em->persist($this->slotTemplate(6, '09:00', LockLevel::NONE));
        $this->em->flush();

        self::assertNull($this->guard->firstOrphanMessage($this->overlay));
    }

    /**
     * NR — P3-20 (décision fondateur 2026-08-06) : UN ÉPINGLAGE SUR UN GYMNASE DÉSACTIVÉ
     * NE BLOQUE PLUS.
     *
     * Ce cas gardait l'inverse (revue #8 round 4) : le verrou étant retiré du payload avec
     * son gymnase, on refusait la génération plutôt que de déplacer la séance en silence.
     * Le terrain a montré l'effet de bord : désactiver un gymnase qui portait une
     * réservation rendait TOUTE génération impossible, avec un message nommant un gymnase
     * que l'écran ne montre plus — le geste de désactivation fabriquait l'impasse (P3-20).
     *
     * Ce qui a changé dans le raisonnement : « déplacée en silence » ne s'applique pas
     * ici. Un gymnase désactivé ne sert AUCUNE séance — il n'y a pas d'ailleurs où la
     * séance glisserait sans le dire. L'épinglage est simplement INERTE, et il revient
     * intact à la réactivation. Les autres causes d'orphelin (grille vidée, jour de
     * fermeture) bloquent toujours : là, la séance partirait vraiment ailleurs.
     */
    public function testAPinOnADisabledVenueNoLongerBlocksTheGeneration(): void
    {
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD)); // le créneau que la période possède
        $override = new VenuePeriodOverride;
        $override->setClubId($this->club->getId());
        $override->setSeasonId($this->season->getId());
        $override->setSchedulePlanId((string) $this->overlay->getSchedulePlanId());
        $override->setVenueId($this->venue->getId());
        $override->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($override);
        $this->em->flush();

        self::assertNull(
            $this->guard->firstOrphanMessage($this->overlay),
            'un épinglage sur un gymnase désactivé est inerte, pas orphelin : il ne doit plus refuser la génération',
        );
    }

    /**
     * TÉMOIN du cas ci-dessus — sans lui, « ça ne bloque plus » passerait aussi si le
     * garde s'était tu pour une raison étrangère (données absentes, plan mal résolu).
     * MÊME contexte, seule différence : le gymnase n'est pas désactivé, le créneau
     * n'existe pas. Le garde doit toujours refuser, et se nommer.
     */
    public function testAnOrphanOnAnEnabledVenueStillBlocks(): void
    {
        $this->em->persist($this->slotTemplate(3, '20:00', LockLevel::HARD)); // aucun créneau ce jour-là
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message, 'un épinglage sans créneau reste orphelin');
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    /**
     * NR — P2-37 D4 : un gymnase ENTIÈREMENT fermé sur la fenêtre du plan ne bloque PLUS.
     * Il ne sert AUCUN jour : l'épinglage y est inerte (rien où le déplacer en silence), même
     * doctrine que le gymnase désactivé (P3-20). La fermeture PARTIELLE, elle, bloque encore.
     */
    public function testAFullyClosedVenueNoLongerBlocks(): void
    {
        // Fermeture couvrant toute la fenêtre 2025-10-20 → 2025-10-26.
        $this->em->persist($this->closureConstraint('Réquisition totale', '2025-10-20', '2025-10-26'));
        // Verrou sur le seul créneau de la période (mardi 18:00), désormais dans un gymnase fermé-total.
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->flush();

        self::assertNull(
            $this->guard->firstOrphanMessage($this->overlay),
            'un gymnase entièrement fermé est inerte comme un désactivé : il ne bloque plus la génération',
        );
    }

    /**
     * NR — P2-37 D1/D4 : DEUX fermetures partielles qui se RELAIENT couvrent la fenêtre à
     * elles deux → gymnase fermé-total → non bloquant. Un test « incident ⊇ fenêtre » naïf
     * raterait ce cas (aucune des deux fermetures ne couvre seule la fenêtre).
     */
    public function testTwoRelayingClosuresFullyCloseTheVenueAndNoLongerBlock(): void
    {
        $this->em->persist($this->closureConstraint('Première moitié', '2025-10-20', '2025-10-23'));
        $this->em->persist($this->closureConstraint('Seconde moitié', '2025-10-24', '2025-10-26'));
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->flush();

        self::assertNull(
            $this->guard->firstOrphanMessage($this->overlay),
            'deux fermetures qui se relaient ferment tout le gymnase : inerte, non bloquant',
        );
    }

    /**
     * NR — P2-37 D4 : un jour fermé d'un gymnase PARTIELLEMENT ouvert reste BLOQUANT (la
     * séance SERAIT replacée ailleurs en silence), ET le message NOMME l'équipe épinglée.
     */
    public function testAPartialDayClosureBlocksAndNamesTheTeam(): void
    {
        $this->seedTeam('Poussins 1'); // l'équipe que l'épinglage désigne (teamId hardcodé du fichier)
        // Gymnase fermé le SEUL mardi 2025-10-21 (fenêtre de 7 jours) → fermeture PARTIELLE.
        $this->em->persist($this->closureConstraint('Gymnase réquisitionné', '2025-10-21', '2025-10-21'));
        $this->em->persist($this->reservation(2, '18:00')); // mardi 18:00 : créneau existant, mais fermé ce jour-là
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message);
        self::assertStringContainsString('fermé', $message, 'la cause fermeture partielle est nommée');
        self::assertStringContainsString('Poussins 1', $message, 'le message nomme l’ÉQUIPE épinglée');
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    /**
     * NR — P2-38 (R5) : un gymnase ENTIÈREMENT fermé par la fermeture d'une AUTRE entrée (fait
     * transversal) est inerte comme un désactivé — l'épinglage n'a nulle part où glisser, donc
     * il ne bloque pas. C'est le cas terrain : « Matéo en travaux » déclaré sur la période racine
     * ferme le gymnase pendant la semaine de reprise, transparente au gestionnaire.
     */
    public function testAVenueFullyClosedByAnotherEntryIsInert(): void
    {
        $other = $this->otherEntry('2025-10-01', '2025-11-30');
        // Fermeture portée par l'AUTRE entrée, couvrant TOUTE la fenêtre du plan (2025-10-20→26).
        $this->em->persist($this->closureOnEntry('Réquisition totale (autre entrée)', '2025-10-20', '2025-10-26', $other));
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD)); // le créneau de la période
        $this->em->flush();

        self::assertNull(
            $this->guard->firstOrphanMessage($this->overlay),
            'un gymnase fermé-total par la fermeture d’une AUTRE entrée est inerte : il ne bloque pas (R5)',
        );
    }

    /**
     * NR — P2-38 : une fermeture PARTIELLE (un jour) portée par une AUTRE entrée reste BLOQUANTE
     * — la séance SERAIT replacée ailleurs en silence. La transversalité n'affaiblit pas la
     * doctrine P2-37 sur les fermetures partielles.
     */
    public function testAPartialDayClosureFromAnotherEntryStillBlocks(): void
    {
        $this->seedTeam('Poussins 1');
        $other = $this->otherEntry('2025-10-01', '2025-11-30');
        // Fermeture du SEUL mardi 2025-10-21, portée par l'AUTRE entrée → fermeture PARTIELLE.
        $this->em->persist($this->closureOnEntry('Gymnase réquisitionné (autre entrée)', '2025-10-21', '2025-10-21', $other));
        $this->em->persist($this->reservation(2, '18:00')); // mardi 18:00 : créneau existant, fermé ce jour-là
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message, 'un jour fermé partiel, même déclaré sur une AUTRE entrée, reste bloquant');
        self::assertStringContainsString('fermé', $message, 'la cause fermeture est nommée');
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    /**
     * NR indispo informative (fondateur 2026-08-18) — un jour ROUVERT par le masque (OPEN) sert
     * de nouveau : un épinglage qui y retombe n'est plus orphelin. L'incident est informatif.
     */
    public function testAPinOnADayReopenedByTheMaskNoLongerBlocks(): void
    {
        // L'incident ferme le mardi (le seul créneau de la période), mais le gestionnaire l'a
        // ROUVERT (masque OPEN) : le verrou du mardi 18:00 retombe sur un créneau servi.
        $this->em->persist($this->closureConstraint('Réquisition levée', '2025-10-21', '2025-10-21')); // mardi
        $this->mask([2 => 'OPEN']);
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->flush();

        self::assertNull(
            $this->guard->firstOrphanMessage($this->overlay),
            'un jour rouvert OPEN sert de nouveau : l’épinglage n’est plus orphelin',
        );
    }

    /**
     * NR indispo informative — un jour DÉCOCHÉ à la main (masque CLOSED) ferme son créneau : un
     * épinglage qui y retombe redevient orphelin BLOQUANT (la séance serait replacée en silence).
     */
    public function testAPinOnADayManuallyClosedByTheMaskBlocks(): void
    {
        $this->seedTeam('Poussins 1');
        // Aucune indisponibilité déclarée, mais le mardi est DÉCOCHÉ (masque CLOSED).
        $this->mask([2 => 'CLOSED']);
        $this->em->persist($this->reservation(2, '18:00')); // mardi 18:00 : créneau existant, décoché
        $this->em->flush();

        $message = $this->guard->firstOrphanMessage($this->overlay);
        self::assertIsString($message, 'un jour décoché à la main ferme son créneau : l’épinglage bloque');
        self::assertStringContainsString($this->venue->getName(), $message);
    }

    public function testSeasonScheduleIsNeverBlocked(): void
    {
        // Le socle définit lui-même sa grille : la notion d'orphelin n'y a pas de sens.
        $seasonVersion = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Socle bis')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($seasonVersion);
        $this->em->flush();

        $orphan = $this->slotTemplate(4, '20:00', LockLevel::HARD)->setScheduleId($seasonVersion->getId());
        $this->em->persist($orphan);
        $this->em->flush();

        self::assertNull($this->guard->firstOrphanMessage($seasonVersion), 'un planning de SAISON n’est jamais bloqué');
    }

    /**
     * Branchement bout-en-bout : la garde est lue par GenerateScheduleController AVANT le
     * passage en PENDING, donc rien n'est mis en file.
     */
    public function testGenerateIsRefusedWith422AndTheGuardMessage(): void
    {
        $this->em->persist($this->slotTemplate(4, '20:00', LockLevel::HARD));
        $this->em->flush();
        $overlayId = $this->overlay->getId();

        $this->client->request('POST', '/api/schedules/' . $overlayId . '/generate', [], [], $this->headers());

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('jeudi', (string) ($body['error'] ?? ''));
        self::assertStringContainsString($this->venue->getName(), (string) ($body['error'] ?? ''));

        // Rien n'a été mis en file : la version est restée DRAFT (le contrôleur rend AVANT
        // setStatus(PENDING) + flush + dispatch, qui sont séquentiels).
        $this->em->clear();
        self::assertSame(ScheduleStatus::DRAFT, $this->em->getRepository(Schedule::class)->find($overlayId)?->getStatus());
    }

    public function testGenerateProceedsOnceEveryPinLandsOnASlot(): void
    {
        // Contre-épreuve du cas précédent : même overlay, épinglage qui retombe bien.
        $this->em->persist($this->slotTemplate(2, '18:00', LockLevel::HARD));
        $this->em->flush();
        $overlayId = $this->overlay->getId();

        $this->client->request('POST', '/api/schedules/' . $overlayId . '/generate', [], [], $this->headers());
        self::assertResponseStatusCodeSame(202);

        $this->em->clear();
        self::assertSame(ScheduleStatus::PENDING, $this->em->getRepository(Schedule::class)->find($overlayId)?->getStatus());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->guard = $container->get(OrphanPinGuard::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('OPG ' . $uid)->setSlug('opg-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->user = (new User)->setEmail('opg' . $uid . '@test.com')->setFirstName('O')->setLastName('P');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Halle Coubertin')->setSource('manual')->setIsActive(true);
        $this->em->persist($this->venue);
        // Grille de SAISON : un seul créneau, mardi 18:00 — la période en héritera.
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($this->venue->getId())->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();

        // Un plan secondaire n'est autorisé qu'au-dessus d'un socle POINTÉ (inv. 13).
        $this->settleSeasonPlan($this->season);

        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2025-10-20'))->setEndDate(new DateTimeImmutable('2025-10-26'));
        $this->em->persist($entry);
        $this->entryId = $entry->getId();
        $this->planId = $this->planIdOf($entry);

        $this->overlay = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Overlay Toussaint')->setStatus(ScheduleStatus::DRAFT)->setSchedulePlanId($this->planId);
        $this->em->persist($this->overlay);
        $container->get(SchedulePlanProvisioner::class)->linkSchedule($this->overlay);
        $this->em->flush();
    }

    private function slotTemplate(int $dayOfWeek, string $startTime, LockLevel $lockLevel): ScheduleSlotTemplate
    {
        return (new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setScheduleId($this->overlay->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venue->getId())
            ->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($startTime))->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
    }

    private function reservation(int $dayOfWeek, string $startTime): Reservation
    {
        return (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId($this->venue->getId())
            ->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($startTime))->setDurationMinutes(90);
    }

    /** Une fermeture datée `venue_closed` sur ce gymnase, rattachée à l'entrée de période. */
    private function closureConstraint(string $name, string $startDate, string $endDate): Constraint
    {
        return $this->closureOnEntry($name, $startDate, $endDate, $this->entryId);
    }

    /** Idem, mais portée par une entrée ARBITRAIRE (P2-38 — fermeture transversale). */
    private function closureOnEntry(string $name, string $startDate, string $endDate, string $carrierEntryId): Constraint
    {
        $constraint = (new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($name)
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId($this->venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($carrierEntryId);
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $startDate, 'endDate' => $endDate]);

        return $constraint;
    }

    /**
     * Pose le masque manuel du gymnase de ce fichier sur le plan de la période (jour → OPEN|CLOSED).
     *
     * @param array<int, string> $dayOverrides
     */
    private function mask(array $dayOverrides): void
    {
        $override = (new VenuePeriodOverride)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)->setVenueId($this->venue->getId())
            ->setDayOverrides($dayOverrides);
        $this->em->persist($override);
    }

    /** Une AUTRE entrée de période du club/saison, porteuse d'une fermeture transversale. */
    private function otherEntry(string $start, string $end): string
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Autre période')
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry->getId();
    }

    /** L'équipe portée par les épinglages de ce fichier (teamId hardcodé) — pour que le message la NOMME. */
    private function seedTeam(string $name): void
    {
        $team = (new Team)
            ->setId('11111111-1111-4111-8111-111111111111')
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')
            ->setPriorityTierId(1)
            ->setName($name)
            ->setSessionsPerWeek(1)
            ->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
