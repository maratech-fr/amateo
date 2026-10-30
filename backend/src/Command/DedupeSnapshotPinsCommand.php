<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Service\ScheduleConstraintBuilder;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Répare les snapshots de version DÉJÀ doublés en base par le défaut corrigé à la source
 * (l'import du résultat réécrivait chaque réservation en `ScheduleSlotTemplate` HARD d'origine
 * RESERVATION, redondant avec la réservation durable — le builder sérialisait alors la même
 * épingle deux fois ; cf. ScheduleConstraintBuilder::buildPayload).
 *
 * Pour chaque `Schedule` qui porte un snapshot, les entrées HARD de `snapshotData['slotTemplates']`
 * sont dédoublonnées par PLACEMENT (teamId, venueId, dayOfWeek, startTime H:i, durationMinutes) :
 * dans un groupe où une entrée est de forme réservation (son `id` appartient aux réservations du
 * périmètre), on GARDE l'entrée réservation et on laisse tomber le(s) template(s) redondant(s). Le
 * `snapshot_hash` est ensuite recalculé DEPUIS le snapshot, jamais depuis la structure courante
 * ({@see ScheduleConstraintBuilder::structureHash}, qui ignore les séances NONE) : une version dont
 * la structure a vraiment changé reste périmée. Un snapshot déjà à jour n'est pas retouché, et un
 * second passage ne retouche plus rien (idempotente).
 *
 * Patron `PurgeOverlaysCommand` : commande manuelle, elle parcourt les clubs sur la connexion
 * runtime (RLS), chacun sous son propre GUC ; un échec sur un club ne bloque pas les autres.
 */
#[AsCommand(
    name: 'app:schedule:dedupe-snapshot-pins',
    description: 'De-duplicate redundant HARD pins doubled in schedule snapshots and recompute their hash. Manual, never auto-runs.',
)]
final class DedupeSnapshotPinsCommand extends Command
{
    private bool $hadFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count affected snapshots without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->hadFailure = false;
        $dryRun = (bool) $input->getOption('dry-run');

        $clubs = $this->entityManager->getRepository(Club::class)->findAll();
        $this->entityManager->clear();

        $touched = 0;
        foreach ($clubs as $club) {
            try {
                $touched += $this->dedupeClub($club->getId(), $dryRun);
            } catch (Throwable $e) {
                $this->hadFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d schedule(s) %s.', $touched, $dryRun ? 'would be repaired (dry-run)' : 'repaired'));

        return $this->hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function dedupeClub(string $clubId, bool $dryRun): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        // Les ids des réservations du périmètre (club entier, socle ET périodes) : ce sont elles
        // qu'on GARDE dans un groupe doublé — le template redondant les shadowe au même placement.
        $reservationIds = [];
        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['clubId' => $clubId]) as $reservation) {
            $reservationIds[$reservation->getId()] = true;
        }

        $touched = 0;
        foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['clubId' => $clubId]) as $schedule) {
            if ($this->dedupeSchedule($schedule, $reservationIds, apply: !$dryRun)) {
                ++$touched;
            }
        }

        if (!$dryRun && $touched > 0) {
            $this->entityManager->flush();
        }

        return $touched;
    }

    /**
     * Répare le snapshot d'UNE version. Rend vrai si un doublon est tombé ou si l'empreinte a changé ;
     * n'écrit sur l'entité que si `$apply` (en dry-run on compte sans muter).
     *
     * @param array<string, true> $reservationIds
     */
    private function dedupeSchedule(Schedule $schedule, array $reservationIds, bool $apply): bool
    {
        $snapshot = $schedule->getSnapshotData();
        $slots = $snapshot['slotTemplates'] ?? null;
        if (!\is_array($slots)) {
            return false;
        }
        $slots = array_values($slots);

        // Grouper les épingles HARD par placement ; mémoriser lesquelles sont de forme réservation.
        /** @var array<string, list<array{index: int, reservation: bool}>> $groups */
        $groups = [];
        foreach ($slots as $index => $slot) {
            if (!\is_array($slot) || 'HARD' !== ($slot['lockLevel'] ?? null)) {
                continue;
            }
            $groups[$this->placementKey($slot)][] = [
                'index' => $index,
                'reservation' => isset($reservationIds[(string) ($slot['id'] ?? '')]),
            ];
        }

        $drop = [];
        foreach ($groups as $members) {
            if (\count($members) < 2) {
                continue; // placement unique : rien à dédoublonner
            }
            $hasReservation = false;
            foreach ($members as $member) {
                if ($member['reservation']) {
                    $hasReservation = true;
                    break;
                }
            }
            if (!$hasReservation) {
                continue; // aucun ancrage réservation : pas le doublon né de l'import, on ne touche pas
            }
            foreach ($members as $member) {
                if (!$member['reservation']) {
                    $drop[$member['index']] = true; // template redondant qui shadowe la réservation
                }
            }
        }

        if ([] !== $drop) {
            $snapshot['slotTemplates'] = array_values(array_filter(
                $slots,
                static fn (mixed $slot, int $index): bool => !isset($drop[$index]),
                \ARRAY_FILTER_USE_BOTH,
            ));
        }
        $hash = ScheduleConstraintBuilder::structureHash($snapshot);
        if ([] === $drop && $hash === $schedule->getSnapshotHash()) {
            return false;
        }

        if ($apply) {
            if ([] !== $drop) {
                $schedule->setSnapshotData($snapshot);
            }
            $schedule->setSnapshotHash($hash);
        }

        return true;
    }

    /**
     * La clé de placement d'une entrée `slotTemplates` : team/gymnase/jour/heure (H:i)/durée.
     * L'heure est tronquée à H:i car une réservation et son template sont sérialisés à l'identique
     * dans un même snapshot — mais la troncature met aussi à l'abri d'un éventuel H:i:s vs H:i.
     *
     * @param array<mixed> $slot
     */
    private function placementKey(array $slot): string
    {
        return implode('|', [
            (string) ($slot['teamId'] ?? ''),
            (string) ($slot['venueId'] ?? ''),
            (string) ($slot['dayOfWeek'] ?? ''),
            substr((string) ($slot['startTime'] ?? ''), 0, 5),
            (string) ($slot['durationMinutes'] ?? ''),
        ]);
    }
}
