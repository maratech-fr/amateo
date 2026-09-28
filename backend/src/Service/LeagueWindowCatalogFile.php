<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;

/**
 * Reads and validates the federation league-match-window catalog JSON
 * (`data/league-match-windows.aura.json`). Dependency-free ON PURPOSE: shared by
 * the seed command ({@see \App\Command\SeedLeagueWindowsCommand}) AND by the data
 * migration that loads the catalog where it is still empty
 * (`Version20260929130000`) — one validated reader, no duplication.
 *
 * Every row is validated before ANY is returned: a malformed file yields a
 * RuntimeException and NOTHING partial. Times are returned as `HH:MM` strings
 * (the JSON format); callers convert as they need (entity vs SQL literal).
 */
final class LeagueWindowCatalogFile
{
    public const DEFAULT_PATH = __DIR__ . '/../../data/league-match-windows.aura.json';

    /**
     * @return list<array{league: string, category: string, level: string, gender: string|null, dayOfWeek: int, kickoffMin: string, kickoffMax: string}>
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(\sprintf('League-window catalog file not found: %s', $path));
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new RuntimeException('Could not read the league-window catalog file.');
        }

        try {
            /** @var array{windows?: list<array<string, mixed>>} $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('League-window catalog file is not valid JSON.', 0, $e);
        }

        $rawWindows = $data['windows'] ?? [];
        $parsed = [];
        foreach ($rawWindows as $row) {
            $league = \is_string($row['league'] ?? null) ? $row['league'] : '';
            $category = \is_string($row['category'] ?? null) ? $row['category'] : '';
            $level = \is_string($row['level'] ?? null) ? $row['level'] : '';
            $gender = \is_string($row['gender'] ?? null) ? $row['gender'] : null;
            $dayOfWeek = \is_int($row['dayOfWeek'] ?? null) ? $row['dayOfWeek'] : 0;
            $min = $this->normalizeTime($row['kickoffMin'] ?? null);
            $max = $this->normalizeTime($row['kickoffMax'] ?? null);

            if (\in_array('', [$league, $category, $level], true) || $dayOfWeek < 1 || $dayOfWeek > 7 || null === $min || null === $max) {
                throw new RuntimeException(\sprintf('Malformed league-window row (nothing loaded): %s', json_encode($row)));
            }

            $parsed[] = ['league' => $league, 'category' => $category, 'level' => $level, 'gender' => $gender, 'dayOfWeek' => $dayOfWeek, 'kickoffMin' => $min, 'kickoffMax' => $max];
        }

        return $parsed;
    }

    /** Accepts a `HH:MM` string, returns it unchanged, else null. */
    private function normalizeTime(mixed $value): ?string
    {
        if (\is_string($value) && 1 === preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }
}
