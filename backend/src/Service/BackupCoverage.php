<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * LA règle de couverture des sauvegardes — source unique partagée par la commande
 * app:db:backup ET la ligne « Sauvegarde » du board (revue #258, findings 5 & 9 :
 * deux implémentations divergeaient d'une seconde et produisaient un rouge permanent).
 *
 * Signal d'activité : club.last_activity_at + solver_metrics + audit_log.
 * Couverture : dump.mtime (posé au DÉBUT du snapshot, cf. DatabaseBackupCommand)
 * + 1 s de tolérance — TIMESTAMP(0) ARRONDIT à la seconde quand filemtime TRONQUE.
 */
final readonly class BackupCoverage
{
    /** Alerte : activité non couverte depuis plus de 26 h. */
    public const STALE_AFTER_HOURS = 26;
    /** Tolérance d'arrondi TIMESTAMP(0) (round-up) vs mtime (troncature). */
    private const TOLERANCE_SECONDS = 1;

    public function __construct(private ManagerRegistry $registry) {}

    public function latestActivity(): ?DateTimeImmutable
    {
        $value = $this->admin()->fetchOne(
            'SELECT GREATEST(
                (SELECT MAX(last_activity_at) FROM club),
                (SELECT MAX(created_at) FROM solver_metrics),
                (SELECT MAX(occurred_at) FROM audit_log)
            )',
        );

        return \is_string($value) ? new DateTimeImmutable($value) : null;
    }

    /** La base contient-elle des données à protéger, même sans signal d'activité ?
     *  Clubs OU comptes utilisateurs (un inscrit non vérifié n'a pas encore de club —
     *  ses identifiants sont déjà des données à ne pas perdre, revue #258 round 2). */
    public function hasAnyData(): bool
    {
        return (bool) $this->admin()->fetchOne('SELECT 1 WHERE EXISTS (SELECT 1 FROM club) OR EXISTS (SELECT 1 FROM app_user)');
    }

    /** Dernier dump COMPLET du dossier (les .part de dumps interrompus sont ignorés). */
    public function latestDumpTime(string $dir): ?DateTimeImmutable
    {
        $latest = null;
        foreach (glob(rtrim($dir, '/') . '/amateo-*.dump') ?: [] as $file) {
            $mtime = filemtime($file);
            if (false !== $mtime && (null === $latest || $mtime > $latest)) {
                $latest = $mtime;
            }
        }

        return null === $latest ? null : new DateTimeImmutable('@' . $latest);
    }

    /** Le dump couvre-t-il cette activité ? (tolérance d'arrondi comprise) */
    public function covers(?DateTimeImmutable $dump, ?DateTimeImmutable $activity): bool
    {
        if (!$activity instanceof DateTimeImmutable) {
            return true;
        }
        if (!$dump instanceof DateTimeImmutable) {
            return false;
        }

        return (int) $dump->format('U') + self::TOLERANCE_SECONDS >= (int) $activity->format('U');
    }

    /**
     * BOOTSTRAP — la règle vit ICI, source unique (round 3, finding 5) : « aucun
     * signal d'activité » ≠ « base vide ». Des données présentes (clubs OU comptes)
     * sans AUCUN dump doivent recevoir un premier dump — et le board doit être
     * rouge tant qu'il n'a pas eu lieu (cron cassé = visible).
     */
    public function bootstrapNeeded(?DateTimeImmutable $dump, ?DateTimeImmutable $activity): bool
    {
        return !$activity instanceof DateTimeImmutable && !$dump instanceof DateTimeImmutable && $this->hasAnyData();
    }

    private function admin(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
