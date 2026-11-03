<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Schedule;
use App\Entity\VenuePeriodOverride;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\VenuePeriodMode;
use App\Exception\ScheduleGenerationInProgressException;
use App\Service\ClosurePinRemover;
use App\Service\ClubGenerationLock;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ClosurePinDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Enregistrer une fermeture vaut geste » (plan fermetures C3bis) — LA RÈGLE du retrait, au niveau
 * du service `ClosurePinRemover` : pour les dates fermées NOUVELLES d'un gymnase, chaque plan de
 * période qui les gouverne perd ses verrous HARD (toute origine, version CHARGÉE) et ses réservations
 * sur ce gymnase à ces jours ; jamais le socle, jamais un gymnase désactivé, jamais un autre club ;
 * l'aperçu (`preview`) annonce EXACTEMENT ce que `apply` retire, réservations comprises même sans
 * version (correction E3).
 *
 * Jumeau GATE par l'API (les trois portes, l'atomicité, l'audit) : `Security/ClosureRemovesPinsAtomicallyTest`.
 * Décor : {@see ClosurePinDecorTrait} (Mistral fermé le mardi des vacances de mars).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClosurePinRemoverTest extends WebTestCase
{
    use ClosurePinDecorTrait;

    private EntityManagerInterface $em;

    /** `preview` annonce, `apply` retire la même chose ; relu après, l'aperçu n'annonce plus rien. */
    public function testPreviewAnnouncesExactlyWhatApplyRemoves(): void
    {
        $d = $this->seedClosurePinDecor('CPA');
        $dates = [$d['closedTuesday'] => true];

        $preview = $this->planLine($this->remover()->preview($d['club']->getId(), $d['season']->getId(), $d['mistral'], $dates, null), $d['holidayEntryId']);
        self::assertSame(1, $preview['locks']);
        self::assertSame([$d['sm1']], array_column($preview['reservations'], 'teamId'));
        self::assertSame($d['holidayVersionId'], $preview['chosenScheduleId']);
        self::assertSame($d['holidayVersionId'], $preview['loadedScheduleId']);

        $removed = $this->remover()->apply($d['club']->getId(), $d['season']->getId(), $d['mistral'], $dates, null);

        self::assertSame(['locks' => $preview['locks'], 'reservations' => \count($preview['reservations'])], $removed);
        $after = $this->remover()->preview($d['club']->getId(), $d['season']->getId(), $d['mistral'], $dates, null);
        foreach ($after as $line) {
            self::assertSame(0, $line['locks'], 'plus rien à retirer une fois retiré');
            self::assertSame([], $line['reservations']);
        }
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($d['club']->getId(), $d['socleLockTueMistral']), 'le socle n’est jamais touché');
    }

    /** Seules les dates NOUVELLES comptent : fermer le jeudi ne retire rien du mardi. */
    public function testOnlyTheNewClosedDatesCount(): void
    {
        $d = $this->seedClosurePinDecor('CPB');
        $clubId = $d['club']->getId();

        $removed = $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], [$this->decorDay(8, 4) => true], null);

        self::assertSame(['locks' => 1, 'reservations' => 0], $removed);
        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($clubId, $d['lockThuMistral']));
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']), 'le mardi n’est pas une date nouvelle');
        self::assertTrue($this->decorReservationExists($clubId, $d['reservationTueMistral']));
    }

    /** Une date hors de toute fenêtre de plan de période ne touche à rien (le socle gouverne : jamais touché). */
    public function testADateGovernedOnlyByTheSocleRemovesNothing(): void
    {
        $d = $this->seedClosurePinDecor('CPC');

        self::assertSame(['locks' => 0, 'reservations' => 0], $this->remover()->apply($d['club']->getId(), $d['season']->getId(), $d['mistral'], [$this->decorDay(10, 2) => true], null));
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($d['club']->getId(), $d['socleLockTueMistral']));
    }

    /** Un gymnase DÉSACTIVÉ dans le plan (P3-20) garde tout : rien annoncé, rien retiré. */
    public function testADisabledVenueKeepsEverything(): void
    {
        $d = $this->seedClosurePinDecor('CPD');
        $clubId = $d['club']->getId();
        $this->em->persist((new VenuePeriodOverride)->setClubId($clubId)->setSeasonId($d['season']->getId())
            ->setSchedulePlanId($d['holidayPlanId'])->setVenueId($d['mistral'])->setMode(VenuePeriodMode::DISABLED));
        $this->em->flush();
        $dates = [$d['closedTuesday'] => true];

        foreach ($this->remover()->preview($clubId, $d['season']->getId(), $d['mistral'], $dates, null) as $line) {
            self::assertSame(0, $line['locks']);
            self::assertSame([], $line['reservations']);
        }
        self::assertSame(['locks' => 0, 'reservations' => 0], $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], $dates, null));
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']));
        self::assertTrue($this->decorReservationExists($clubId, $d['reservationTueMistral']));
    }

    /**
     * La version CHARGÉE est visée, pas la pointée : une V2 COMPLETED plus récente (repli de
     * `lockSourceScheduleIdOfPlan`) perd son verrou, la V1 pointée garde le sien.
     */
    public function testTheLoadedVersionIsTheOneThatLosesItsLocks(): void
    {
        $d = $this->seedClosurePinDecor('CPE');
        $clubId = $d['club']->getId();
        $v2 = (new Schedule)->setClubId($clubId)->setSeasonId($d['season']->getId())->setName('Vacances de mars V2')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($v2, $d['holidayEntryId']);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE schedule SET created_at = created_at + INTERVAL \'1 hour\' WHERE id = :id', ['id' => $v2->getId()]);
        $v2Lock = $this->decorTemplate($d['club'], $d['season'], $v2->getId(), $d['u13'], $d['mistral'], 2, '18:00', LockLevel::HARD);
        $this->em->flush();
        self::assertSame($v2->getId(), self::getContainer()->get(SchedulePlanProvisioner::class)->lockSourceScheduleIdOfPlan($d['holidayPlanId']), 'décor : V2 est la version chargée');

        $line = $this->planLine($this->remover()->preview($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null), $d['holidayEntryId']);
        self::assertSame($d['holidayVersionId'], $line['chosenScheduleId']);
        self::assertSame($v2->getId(), $line['loadedScheduleId']);

        $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null);

        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($clubId, $v2Lock), 'la version chargée perd son verrou');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']), 'une version non chargée n’est pas touchée');
    }

    /** Un plan EN COURS (rouvert, rien de pointé) : même retrait, sur sa version chargée. */
    public function testAPlanInProgressLosesItsPinsToo(): void
    {
        $d = $this->seedClosurePinDecor('CPF');
        $clubId = $d['club']->getId();
        self::assertTrue(self::getContainer()->get(SchedulePlanProvisioner::class)->releaseSchedule($d['holidayVersionId']), 'décor : le plan est rouvert');
        $this->em->flush();

        $removed = $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null);

        self::assertSame(['locks' => 1, 'reservations' => 1], $removed);
        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($clubId, $d['lockTueMistral']));
    }

    /**
     * Correction E3 — les RÉSERVATIONS s'annoncent dès qu'elles existent dans un plan touché, AVEC OU
     * SANS version (seuls les verrous dépendent d'une version chargée) ; `preview == apply` là aussi.
     */
    public function testReservationsOfAPlanWithoutAnyVersionAreAnnouncedAndRemoved(): void
    {
        $d = $this->seedClosurePinDecor('CPG');
        $clubId = $d['club']->getId();
        // Plus aucune version : le plan des vacances ne porte que sa réservation.
        $this->em->getConnection()->executeStatement('UPDATE schedule_plan SET chosen_schedule_id = NULL, lock_source_schedule_id = NULL WHERE id = :id', ['id' => $d['holidayPlanId']]);
        $this->em->getConnection()->executeStatement('DELETE FROM schedule_slot_template WHERE schedule_id = :sid', ['sid' => $d['holidayVersionId']]);
        $this->em->getConnection()->executeStatement('DELETE FROM schedule WHERE id = :sid', ['sid' => $d['holidayVersionId']]);
        $this->em->clear();

        $line = $this->planLine($this->remover()->preview($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null), $d['holidayEntryId']);
        self::assertNull($line['loadedScheduleId']);
        self::assertSame(0, $line['locks']);
        self::assertSame([$d['sm1']], array_column($line['reservations'], 'teamId'), 'la réservation s’annonce sans version');

        self::assertSame(['locks' => 0, 'reservations' => 1], $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null));
        self::assertFalse($this->decorReservationExists($clubId, $d['reservationTueMistral']));
    }

    /**
     * Une réservation retirée emporte son verrou HARD MATÉRIALISÉ dans le plan — mais JAMAIS celui du
     * SOCLE au même triplet (équipe, gymnase, jour, heure). ⚠ `ReservationStateProcessor::
     * purgeMaterialisedHardTemplates` filtre par club+saison SANS borne de version : réutilisé tel quel,
     * il effacerait aussi le verrou du socle.
     */
    public function testAReservationGoesWithItsMaterialisedLockButNeverTheSocleOne(): void
    {
        $d = $this->seedClosurePinDecor('CPH');
        $clubId = $d['club']->getId();
        // Les placements NONE de SM1 le mardi 19:30 à Mistral (plan ET socle) deviennent les verrous
        // matérialisés qu'un import de résultat écrit pour une réservation (HARD, origine RESERVATION).
        $materialise = 'UPDATE schedule_slot_template SET lock_level = :h, lock_origin = :o '
            . 'WHERE schedule_id = :sid AND team_id = :tid AND venue_id = :vid AND day_of_week = 2 RETURNING id';
        $params = ['h' => LockLevel::HARD->value, 'o' => LockOrigin::RESERVATION->value, 'tid' => $d['sm1'], 'vid' => $d['mistral']];
        $materialised = (string) $this->em->getConnection()->fetchOne($materialise, $params + ['sid' => $d['holidayVersionId']]);
        $socleTwin = (string) $this->em->getConnection()->fetchOne($materialise, $params + ['sid' => $d['socleVersionId']]);
        self::assertSame($d['placementTueMistral'], $materialised, 'décor : le placement du plan est matérialisé');
        self::assertNotSame('', $socleTwin, 'décor : le socle porte son jumeau au même triplet');

        $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null);

        self::assertFalse($this->decorReservationExists($clubId, $d['reservationTueMistral']));
        self::assertNotSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $materialised), 'le verrou matérialisé du plan part avec sa réservation');
        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $socleTwin), 'le verrou du SOCLE au même triplet reste');
    }

    /** Tenant : le retrait d'un club ne touche jamais le plan d'un autre club. */
    public function testAnotherClubIsNeverTouched(): void
    {
        $theirs = $this->seedClosurePinDecor('CPI');
        $mine = $this->seedClosurePinDecor('CPJ');

        $this->scopeGucToClub($mine['club']->getId());
        $this->remover()->apply($mine['club']->getId(), $mine['season']->getId(), $mine['mistral'], [$mine['closedTuesday'] => true], null);

        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($theirs['club']->getId(), $theirs['lockTueMistral']));
        self::assertTrue($this->decorReservationExists($theirs['club']->getId(), $theirs['reservationTueMistral']));
        self::assertSame(LockLevel::NONE->value, $this->decorLockLevel($mine['club']->getId(), $mine['lockTueMistral']), 'témoin : mon club, lui, a bien été traité');
    }

    /** E1 — le retrait PREND le verrou de génération : déjà pris → exception, rien d'écrit. */
    public function testApplyRefusesWhileAGenerationHoldsTheClubLock(): void
    {
        $d = $this->seedClosurePinDecor('CPK');
        $clubId = $d['club']->getId();
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($clubId, 120);
        self::assertIsString($token);

        try {
            $this->remover()->apply($clubId, $d['season']->getId(), $d['mistral'], [$d['closedTuesday'] => true], null);
            self::fail('une génération en vol doit refuser le retrait');
        } catch (ScheduleGenerationInProgressException) {
            // attendu
        } finally {
            $lock->release($clubId, $token);
        }

        self::assertSame(LockLevel::HARD->value, $this->decorLockLevel($clubId, $d['lockTueMistral']));
        self::assertTrue($this->decorReservationExists($clubId, $d['reservationTueMistral']));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pinDecorClock();
    }

    protected function tearDown(): void
    {
        $this->unpinDecorClock();
        parent::tearDown();
    }

    private function remover(): ClosurePinRemover
    {
        return self::getContainer()->get(ClosurePinRemover::class);
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function planLine(array $lines, string $entryId): array
    {
        foreach ($lines as $line) {
            if (($line['entryId'] ?? null) === $entryId) {
                return $line;
            }
        }
        self::fail('le plan des vacances gouverne le mardi fermé : il doit figurer dans l’aperçu');
    }
}
