<?php

declare(strict_types=1);

namespace App\AdminJob;

use LogicException;

/** Closed allowlist: neither the CLI nor the future admin API accepts a raw command name. */
final class AdminJobCatalog
{
    /** @var array<string, AdminJobDefinition> */
    private array $definitions = [];

    /** @param list<AdminJobDefinition>|null $definitions Test-only override; production uses the closed defaults. */
    public function __construct(?array $definitions = null)
    {
        foreach ($definitions ?? $this->defaults() as $definition) {
            if (isset($this->definitions[$definition->key])) {
                throw new LogicException(\sprintf('Duplicate admin job key "%s".', $definition->key));
            }
            $this->definitions[$definition->key] = $definition;
        }
    }

    public function find(string $key): ?AdminJobDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @return list<AdminJobDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<AdminJobDefinition> */
    private function defaults(): array
    {
        return [
            new AdminJobDefinition('reconcile-stuck-schedules', 'Réconciliation des générations bloquées', 'app:schedules:reconcile-stuck', AdminJobSchedule::everyTenMinutes(), ['--older-than' => 60]),
            new AdminJobDefinition('health-alerts', 'Alertes santé & fraîcheur', 'app:health:alert', AdminJobSchedule::everyTenMinutes()),
            // Tick nocturne pas cher : la commande SKIPPE sans activité — la cadence
            // réelle des dumps suit l'activité des clubs, pas le calendrier.
            new AdminJobDefinition('db-backup', 'Sauvegarde base de données', 'app:db:backup', AdminJobSchedule::daily(1)),
            new AdminJobDefinition('coach-wish-digest', 'Digest des doléances coachs', 'app:coach-wishes:digest', AdminJobSchedule::daily(7)),
            // P5-6 — digest quotidien des signalements au support ; créneau libre
            // entre coach-wish (7:00) et period-reminders (8:00).
            new AdminJobDefinition('feedback-digest', 'Digest des signalements', 'app:feedback:digest', AdminJobSchedule::daily(7, 30)),
            new AdminJobDefinition('period-reminders', 'Rappels de périodes', 'app:periods:remind', AdminJobSchedule::daily(8)),
            // P3-4 PR B : relances des demandes de création de club (3 j restants +
            // jour J) et expiration à 7 j — la console superadmin garde la main après.
            new AdminJobDefinition('club-approval-digest', 'Relances d’approbation de club', 'app:club-approvals:digest', AdminJobSchedule::daily(8, 30), manualTriggerAllowed: true),
            new AdminJobDefinition('transition-reminders', 'Rappels de transition de saison', 'app:seasons:remind-transition', AdminJobSchedule::daily(8)),
            new AdminJobDefinition('purge-unverified-users', 'Purge des comptes non vérifiés', 'app:users:purge-unverified', AdminJobSchedule::daily(2)),
            new AdminJobDefinition('purge-erased-clubs', 'Purge des clubs effacés', 'app:clubs:purge-erased', AdminJobSchedule::daily(2, 15)),
            new AdminJobDefinition('clubs-erasure-reminders', 'Rappels avant suppression de club', 'app:clubs:erasure-remind', AdminJobSchedule::daily(8, 45)),
            new AdminJobDefinition('purge-inactive-users', 'Purge des comptes inactifs', 'app:users:purge-inactive', AdminJobSchedule::daily(2, 30)),
            // P4-301 — comptes sans club : préavis puis suppression à 30 j. Créneau libre
            // après purge-inactive-users (2:30), avant purge-seasons (3:00).
            new AdminJobDefinition('purge-orphan-users', 'Purge des comptes sans club', 'app:users:purge-orphaned', AdminJobSchedule::daily(2, 45)),
            new AdminJobDefinition('purge-seasons', 'Purge des anciennes saisons', 'app:seasons:purge', AdminJobSchedule::daily(3)),
            // Démos — détruit chaque nuit les clubs démo PROSPECT de la veille (libère le
            // code FFBB) ; la démo BCCL permanente n'est jamais concernée. Créneau libre
            // entre purge-seasons (3:00) et purge-audit (3:30).
            new AdminJobDefinition('demo-purge-stale', 'Purge des démos prospect périmées', 'app:demo:purge-stale', AdminJobSchedule::daily(3, 15)),
            new AdminJobDefinition('purge-audit-log', 'Purge du journal d’audit', 'app:audit:purge', AdminJobSchedule::daily(3, 30)),
            // P4-52 — les rendus d'export ne repartaient jamais. 3h45 : après les purges de
            // données, avant les imports du matin.
            new AdminJobDefinition('purge-exports', 'Purge des rendus d’export', 'app:exports:purge', AdminJobSchedule::daily(3, 45)),
            new AdminJobDefinition('import-school-holidays', 'Import des vacances scolaires', 'app:school-holidays:import', AdminJobSchedule::quarterly(4), manualTriggerAllowed: true),
            new AdminJobDefinition('import-public-holidays', 'Import des jours fériés', 'app:public-holidays:import', AdminJobSchedule::quarterly(4, 30), manualTriggerAllowed: true),
        ];
    }
}
