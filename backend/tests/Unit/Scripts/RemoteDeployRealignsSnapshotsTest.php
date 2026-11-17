<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : la mise en prod réaligne seule les empreintes des plannings
 * (`app:schedule:prune-unread-snapshot-fields`), une fois la nouvelle version en place, sans
 * jamais pouvoir faire échouer un déploiement déjà réussi ni laisser la commande tourner au-delà
 * de son échéance.
 *
 * Garde statique du câblage de `scripts/remote-deploy.sh` (patron `CiReleveWiringTest`) : le
 * script ne tourne ni en CI ni sur la stack d'épreuve. Le comportement de la commande elle-même
 * (échéance, clubs sautés, statuts) : `Integration/Command/PruneUnreadSnapshotFieldsCommandTest`.
 * Annoté `phase1` mais NON listé dans « Blocking suite » : il tourne dans `backend-tests`. Il part
 * avec l'appel, à la mise en prod de `main` qui supprime les colonnes mortes.
 */
#[Group('phase1')]
final class RemoteDeployRealignsSnapshotsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../../scripts/remote-deploy.sh';
    private const string COMMAND = 'app:schedule:prune-unread-snapshot-fields';

    /** L'appel existe, pour de bon (jamais à blanc), après « Deploy … OK » et avant le nettoyage d'images. */
    public function testTheCallRunsForRealAfterTheDeployIsOkAndBeforeTheImageCleanup(): void
    {
        [$call, $line] = $this->call();

        self::assertStringContainsString('exec -T php-fpm php bin/console ' . self::COMMAND, $call);
        self::assertStringNotContainsString('--dry-run', $call, 'la mise en prod réaligne pour de bon');

        $lines = $this->lines();
        $deployOk = $this->firstCodeLineMatching($lines, '/^echo "==> Deploy \$VERSION OK"$/');
        $prune = $this->firstCodeLineMatching($lines, '/^docker image prune\b/');
        self::assertGreaterThan($deployOk, $line, 'la commande tourne après les sondes de santé et la version persistée');
        self::assertLessThan($prune, $line, 'la commande tourne avant le nettoyage des images');
    }

    /** Un échec de la commande n'échoue pas le déploiement (`set -e` + `trap ERR`) : l'appel est neutralisé par `||`. */
    public function testAFailureOfTheCommandCannotFailTheDeploy(): void
    {
        [$call, , $fallback] = $this->call();

        self::assertNotNull($fallback, 'l\'appel est suivi de `|| …` : sans lui, `set -e` ferait échouer un déploiement réussi');
        self::assertMatchesRegularExpression('/^echo\b/', $fallback, 'le repli DIT que le réalignement est incomplet');
        self::assertStringNotContainsString('exit', $fallback);
        self::assertStringContainsString(self::COMMAND, $call);
    }

    /** L'appel ferme son entrée standard, comme tout appel du script (`</dev/null`). */
    public function testTheCallClosesItsStandardInput(): void
    {
        [$call] = $this->call();

        self::assertStringContainsString('</dev/null', $call);
    }

    /** L'échéance est interne à la commande (`--max-seconds=`) : aucun `timeout` externe, qui tuerait le client sans arrêter PHP. */
    public function testTheCallCarriesItsOwnDeadlineAndNoExternalTimeout(): void
    {
        [$call] = $this->call();

        self::assertMatchesRegularExpression('/--max-seconds=\d+/', $call);
        self::assertDoesNotMatchRegularExpression('/(^|[\s;&|(])timeout\s/', $call, 'aucun `timeout` devant l\'appel');
    }

    /**
     * La ligne logique de l'appel (continuations `\` jointes), découpée en appel / repli.
     *
     * @return array{0: string, 1: int, 2: string|null}
     */
    private function call(): array
    {
        $lines = $this->lines();
        $found = null;
        foreach ($lines as $index => $line) {
            if ($this->isCode($line) && str_contains($line, self::COMMAND)) {
                $found = $index;
                break;
            }
        }
        self::assertNotNull($found, \sprintf('scripts/remote-deploy.sh lance %s', self::COMMAND));

        $start = $found;
        while ($start > 0 && str_ends_with(rtrim($lines[$start - 1]), '\\')) {
            --$start;
        }
        $end = $found;
        while (str_ends_with(rtrim($lines[$end]), '\\') && isset($lines[$end + 1])) {
            ++$end;
        }
        $logical = '';
        for ($i = $start; $i <= $end; ++$i) {
            $logical .= ' ' . rtrim(trim($lines[$i]), '\\');
        }
        $logical = (string) preg_replace('/\s+/', ' ', trim($logical));

        $parts = explode('||', $logical, 2);

        return [trim($parts[0]), $start, isset($parts[1]) ? trim($parts[1]) : null];
    }

    /** @param list<string> $lines */
    private function firstCodeLineMatching(array $lines, string $pattern): int
    {
        foreach ($lines as $index => $line) {
            if ($this->isCode($line) && 1 === preg_match($pattern, trim($line))) {
                return $index;
            }
        }
        self::fail(\sprintf('aucune ligne de scripts/remote-deploy.sh ne correspond à %s', $pattern));
    }

    private function isCode(string $line): bool
    {
        $trimmed = trim($line);

        return '' !== $trimmed && !str_starts_with($trimmed, '#');
    }

    /** @return list<string> */
    private function lines(): array
    {
        $content = file_get_contents(self::SCRIPT);
        self::assertIsString($content);

        return explode("\n", $content);
    }
}
