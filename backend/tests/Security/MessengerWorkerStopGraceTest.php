<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Promesse : un déploiement laisse au worker Messenger de PRODUCTION le temps de finir un message
 * court avant d'être tué. Un SIGTERM ne coupe jamais le message en cours (il lève un drapeau lu
 * ENTRE deux messages) ; sans grâce explicite, Docker envoie SIGKILL au bout de 10 s et tout
 * message plus long devient un run orphelin. La grâce est d'au moins 60 s — le solve long, lui,
 * est tué puis rattrapé au redémarrage (`PlacementRunStartupReconcilerTest`,
 * `StuckPlacementRunReconcilerTest`).
 *
 * Garde aussi que le commentaire qui promettait à tort que `--memory-limit` s'arrêtait « avant
 * l'OOM-killer » a disparu : la limite n'est lue qu'entre deux messages (patron de lecture :
 * `ProdComposeVersionEnvTest`).
 */
#[Group('phase1')]
final class MessengerWorkerStopGraceTest extends TestCase
{
    private const string COMPOSE = __DIR__ . '/../../../docker-compose.prod.yml';

    private const int MIN_GRACE_SECONDS = 60;

    public function testTheProductionWorkerGetsAtLeastAMinuteToFinishItsMessage(): void
    {
        $compose = Yaml::parseFile(self::COMPOSE, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $worker = $compose['services']['messenger-worker'] ?? null;
        self::assertIsArray($worker, 'le service messenger-worker a disparu de docker-compose.prod.yml');

        $grace = $worker['stop_grace_period'] ?? null;
        self::assertIsString($grace, 'messenger-worker doit déclarer stop_grace_period : sans lui, Docker tue le worker au bout de 10 s');
        self::assertGreaterThanOrEqual(self::MIN_GRACE_SECONDS, $this->seconds($grace), 'la grâce d\'arrêt du worker doit laisser finir un message d\'une minute');
    }

    public function testTheMisleadingMemoryLimitCommentIsGone(): void
    {
        $raw = file_get_contents(self::COMPOSE);
        self::assertIsString($raw);

        // Le commentaire court sur deux lignes (`# … graceful stop` / `# fires before …`) : on
        // tolère le saut de ligne et le `#` entre les mots.
        self::assertDoesNotMatchRegularExpression(
            '/graceful stop\s*(?:#\s*)?fires before the kernel OOM-killer/',
            $raw,
            '--memory-limit n\'est lu qu\'ENTRE deux messages : il ne devance pas l\'OOM-killer en plein message',
        );
    }

    /** Durée compose (`60s`, `1m`, `1m30s`, `90`) → secondes. */
    private function seconds(string $duration): int
    {
        if (ctype_digit($duration)) {
            return (int) $duration;
        }
        self::assertSame(1, preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $duration, $m), 'durée compose illisible : ' . $duration);

        return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
    }
}
