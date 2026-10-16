<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Évaluateur PUR des conditions d'alerte superadmin : tableaux en entrée, liste
 * d'alertes en sortie — aucune I/O, unit-testable à sec. Consommé par
 * app:health:alert, qui gère l'état (anti-spam) et l'envoi.
 *
 * Chaque alerte = {key, message}. La key est STABLE (c'est la clé d'état
 * admin_alert_state) : en changer une réarme l'alerte correspondante.
 */
final readonly class HealthAlertEvaluator
{
    /** Filet si le payload santé ne porte pas son seuil (backlogWarningThreshold). */
    private const BACKLOG_THRESHOLD_FALLBACK = 100;

    /** INFEASIBLE : > 50 % sur 24 h, avec un PLANCHER de volume — jamais d'alerte à vide. */
    private const INFEASIBLE_RATE_THRESHOLD = 0.5;
    private const INFEASIBLE_MIN_GENERATIONS = 5;

    /**
     * @param array<string, mixed>                                                                              $health    sortie d'AdminHealthService::health()
     * @param list<array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}> $freshness
     * @param array{generations24h: int, infeasible24h: int}                                                    $solver
     * @param list<array{key: string, label: string, command: string, lastStatus: ?string}>                     $jobs      dernier statut connu des jobs surveillés
     *
     * @return list<array{key: string, message: string}>
     */
    public function evaluate(array $health, array $freshness, array $solver, array $jobs = []): array
    {
        $alerts = [];

        // Services : alerte sur 'down' UNIQUEMENT. 'unknown' = indéterminé, pas un
        // incident — Mercure sans env configurée ou le heartbeat worker expiré pendant
        // un déploiement enverraient sinon un faux rouge (+ faux vert 10 min après) à
        // chaque deploy, et le dashboard affiche le même 'unknown' en neutre (revue
        // #257, finding 2 : mêmes sémantiques pour le même signal).
        $services = \is_array($health['services'] ?? null) ? $health['services'] : [];
        foreach (['database', 'redis', 'engine', 'mercure', 'worker'] as $service) {
            $status = \is_array($services[$service] ?? null) ? ($services[$service]['status'] ?? 'unknown') : 'unknown';
            if ('down' === $status) {
                $alerts[] = ['key' => 'service:' . $service, 'message' => \sprintf('Service « %s » indisponible.', $service)];
            }
        }

        $messenger = \is_array($health['messenger'] ?? null) ? $health['messenger'] : [];
        // Exception messenger : sa file ILLISIBLE (status unknown, compteurs null) est
        // significative — les générations peuvent s'empiler sans qu'aucun compteur ne le
        // dise. Alerter, sinon c'est le trou de silence du composant central (finding 1).
        if ('unknown' === ($messenger['status'] ?? 'unknown')) {
            $alerts[] = ['key' => 'messenger-status', 'message' => 'File Messenger illisible (transports injoignables) — l\'état des générations est invisible.'];
        }
        // Seuil lu du payload santé (source unique, dashboard et alerte alignés — finding 8).
        $threshold = \is_int($messenger['backlogWarningThreshold'] ?? null) ? $messenger['backlogWarningThreshold'] : self::BACKLOG_THRESHOLD_FALLBACK;
        $backlog = \is_int($messenger['backlog'] ?? null) ? $messenger['backlog'] : 0;
        if ($backlog > $threshold) {
            $alerts[] = ['key' => 'messenger-backlog', 'message' => \sprintf('File Messenger en retard : %d messages en attente (seuil %d) — les générations s\'empilent.', $backlog, $threshold)];
        }
        $failed = \is_int($messenger['failed'] ?? null) ? $messenger['failed'] : 0;
        if ($failed > 0) {
            $alerts[] = ['key' => 'messenger-failed', 'message' => \sprintf('%d message(s) en file d\'échec Messenger — des générations ont définitivement échoué.', $failed)];
        }

        if ($solver['generations24h'] >= self::INFEASIBLE_MIN_GENERATIONS
            && $solver['infeasible24h'] / max(1, $solver['generations24h']) > self::INFEASIBLE_RATE_THRESHOLD) {
            $alerts[] = ['key' => 'infeasible-rate', 'message' => \sprintf('Taux INFEASIBLE anormal : %d/%d générations sur 24 h — un bug ou une donnée corrompue frappe peut-être plusieurs clubs.', $solver['infeasible24h'], $solver['generations24h'])];
        }

        foreach ($freshness as $referential) {
            if (!$referential['stale']) {
                continue;
            }
            // Message dédié pour la sauvegarde : « import mort » serait trompeur — ici
            // c'est de l'ACTIVITÉ CLUB non couverte par un dump (revue #258, finding 6).
            // Cas spécial assumé sur la clé (petit et lisible) ; le seuil vient de la
            // CONSTANTE partagée, jamais d'un littéral (round 2, finding 7).
            // Formulation vraie dans les DEUX cas rouges (retard > 26 h ET bootstrap
            // jamais dumpé — round 3, finding 4) : « des données ne sont pas couvertes ».
            $alerts[] = 'db-backup' === $referential['key']
                ? ['key' => 'freshness:db-backup', 'message' => \sprintf('Sauvegarde base de données : des données ne sont couvertes par aucun dump (dernier dump : %s, seuil %d h) — vérifier le job db-backup (app:db:backup).', $referential['lastUpdatedAt'] ?? 'jamais', BackupCoverage::STALE_AFTER_HOURS)]
                : ['key' => 'freshness:' . $referential['key'], 'message' => \sprintf('Référentiel « %s » périmé (dernière mise à jour : %s, seuil %d j) — l\'import automatique est peut-être mort en silence.', $referential['label'], $referential['lastUpdatedAt'] ?? 'jamais', $referential['staleAfterDays'])];
        }

        // Dernier run d'un job surveillé en ÉCHEC : alerte dédiée et IMMÉDIATE, sans
        // attendre le seuil de fraîcheur (un backup qui ne se restaure plus est une
        // panne active, pas un référentiel qui vieillit). succeeded/null → silence :
        // l'absence prolongée est l'affaire de la ligne de fraîcheur (seuil propre).
        foreach ($jobs as $job) {
            if ('failed' === $job['lastStatus']) {
                $alerts[] = ['key' => 'job:' . $job['key'], 'message' => \sprintf('Le job « %s » (%s) a échoué à sa dernière exécution — à vérifier dans la console superadmin.', $job['label'], $job['command'])];
            }
        }

        return $alerts;
    }
}
