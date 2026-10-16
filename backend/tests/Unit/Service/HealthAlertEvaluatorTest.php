<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\HealthAlertEvaluator;
use PHPUnit\Framework\TestCase;

final class HealthAlertEvaluatorTest extends TestCase
{
    private HealthAlertEvaluator $evaluator;

    public function testAllGreenYieldsNoAlert(): void
    {
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 50, 'infeasible24h' => 2]));
    }

    public function testDownFiresButUnknownStaysSilent(): void
    {
        // 'unknown' = indéterminé (Mercure sans env, heartbeat worker expiré pendant un
        // déploiement) — PAS un incident : sinon faux rouge + faux vert à chaque deploy.
        $health = $this->healthyHealth();
        $health['services']['engine']['status'] = 'down';
        $health['services']['worker']['status'] = 'unknown';
        $health['services']['mercure']['status'] = 'unknown';

        $keys = array_column($this->evaluator->evaluate($health, $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0]), 'key');
        self::assertSame(['service:engine'], $keys);
    }

    public function testMessengerBacklogFailedAndUnreadableFire(): void
    {
        $health = $this->healthyHealth();
        $health['messenger']['backlog'] = 101;
        $health['messenger']['failed'] = 3;

        $keys = array_column($this->evaluator->evaluate($health, $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0]), 'key');
        self::assertSame(['messenger-backlog', 'messenger-failed'], $keys);

        // File ILLISIBLE (status unknown, compteurs null) : c'est le trou de silence du
        // composant central — alerte dédiée, même sans aucun compteur.
        $health['messenger'] = ['status' => 'unknown', 'backlog' => null, 'failed' => null, 'backlogWarningThreshold' => 100];
        $keys = array_column($this->evaluator->evaluate($health, $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0]), 'key');
        self::assertSame(['messenger-status'], $keys);
    }

    public function testBacklogThresholdComesFromTheHealthPayload(): void
    {
        // Source unique : le dashboard et l'alerte lisent LE MÊME seuil.
        $health = $this->healthyHealth();
        $health['messenger']['backlogWarningThreshold'] = 10;
        $health['messenger']['backlog'] = 11;

        $keys = array_column($this->evaluator->evaluate($health, $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0]), 'key');
        self::assertSame(['messenger-backlog'], $keys);
    }

    public function testInfeasibleRateNeedsBothRateAndVolume(): void
    {
        // 2/3 infaisables mais SOUS le plancher de volume → silence (jamais d'alerte à vide).
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 3, 'infeasible24h' => 2]));
        // Plancher atteint + taux > 50 % → alerte.
        $keys = array_column($this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 10, 'infeasible24h' => 6]), 'key');
        self::assertSame(['infeasible-rate'], $keys);
        // Volume élevé mais taux sous le seuil → silence.
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 100, 'infeasible24h' => 50]));
    }

    public function testStaleReferentialFires(): void
    {
        $freshness = $this->freshFreshness();
        $freshness[1]['stale'] = true;

        $alerts = $this->evaluator->evaluate($this->healthyHealth(), $freshness, ['generations24h' => 0, 'infeasible24h' => 0]);
        self::assertSame(['freshness:public-holidays'], array_column($alerts, 'key'));
        self::assertStringContainsString('Jours fériés', $alerts[0]['message']);
    }

    public function testStaleBackupGetsItsDedicatedMessageNotTheImportOne(): void
    {
        // « Import mort » serait trompeur pour la sauvegarde : c'est de l'activité club
        // non couverte par un dump (revue #258, finding 6).
        $freshness = [['key' => 'db-backup', 'label' => 'Sauvegarde base de données', 'lastUpdatedAt' => null, 'staleAfterDays' => 1, 'stale' => true]];

        $alerts = $this->evaluator->evaluate($this->healthyHealth(), $freshness, ['generations24h' => 0, 'infeasible24h' => 0]);
        self::assertSame(['freshness:db-backup'], array_column($alerts, 'key'));
        self::assertStringContainsString('app:db:backup', $alerts[0]['message']);
        self::assertStringNotContainsString('import automatique', $alerts[0]['message']);
    }

    public function testFailedJobRunFiresADedicatedAlertWithoutWaitingForStaleness(): void
    {
        // Promesse (Q2) : un run de job en ÉCHEC déclenche une alerte dédiée dès le tick
        // suivant, sans attendre le seuil de fraîcheur de 8 jours ; un run réussi n'en lève
        // aucune — c'est ce retour au vert que l'anti-spam (AdminAlertStateStore) traduit en « rétabli ».
        $failed = [['key' => 'db-restore-check', 'label' => 'Contrôle de restauration', 'command' => 'app:db:restore-check', 'lastStatus' => 'failed']];

        $alerts = $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0], $failed);
        self::assertSame(['job:db-restore-check'], array_column($alerts, 'key'));
        self::assertStringContainsString('Contrôle de restauration', $alerts[0]['message']);
        self::assertStringContainsString('app:db:restore-check', $alerts[0]['message']);

        // Dernier run RÉUSSI → aucune alerte d'échec.
        $succeeded = [['key' => 'db-restore-check', 'label' => 'Contrôle de restauration', 'command' => 'app:db:restore-check', 'lastStatus' => 'succeeded']];
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0], $succeeded));

        // Jamais exécuté (null) → pas d'alerte d'ÉCHEC : l'absence prolongée est l'affaire de
        // la ligne de fraîcheur (seuil 8 j), pas de ce signal d'échec immédiat.
        $never = [['key' => 'db-restore-check', 'label' => 'Contrôle de restauration', 'command' => 'app:db:restore-check', 'lastStatus' => null]];
        self::assertSame([], $this->evaluator->evaluate($this->healthyHealth(), $this->freshFreshness(), ['generations24h' => 0, 'infeasible24h' => 0], $never));
    }

    protected function setUp(): void
    {
        $this->evaluator = new HealthAlertEvaluator;
    }

    /** @return array<string, mixed> */
    private function healthyHealth(): array
    {
        return [
            'services' => [
                'database' => ['status' => 'up'],
                'redis' => ['status' => 'up'],
                'engine' => ['status' => 'up'],
                'mercure' => ['status' => 'up'],
                'worker' => ['status' => 'up'],
            ],
            'messenger' => ['status' => 'up', 'backlog' => 3, 'failed' => 0, 'backlogWarningThreshold' => 100],
        ];
    }

    /** @return list<array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}> */
    private function freshFreshness(): array
    {
        return [
            ['key' => 'school-holidays', 'label' => 'Vacances scolaires', 'lastUpdatedAt' => '2026-07-01', 'staleAfterDays' => 100, 'stale' => false],
            ['key' => 'public-holidays', 'label' => 'Jours fériés', 'lastUpdatedAt' => '2026-07-01', 'staleAfterDays' => 100, 'stale' => false],
        ];
    }
}
