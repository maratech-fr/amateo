<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Dev/default feedback screenshot storage: one file per feedback on local disk.
 * Prod swaps this alias (services.yaml) for an object-storage implementation
 * without touching callers.
 *
 * The id is validated as a UUID before it ever touches the filesystem: a non-UUID
 * key can neither store nor read (defence against path traversal — the key comes
 * from the feedback id, always a UUID, but the storage never trusts that alone).
 * Mime is NOT stored; it is re-sniffed on read (patron ClubLogoController::serve).
 */
final class LocalFeedbackScreenshotStorage implements FeedbackScreenshotStorage
{
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(private readonly string $dir) {}

    public function store(string $id, string $bytes): void
    {
        $path = $this->path($id);
        if (null === $path) {
            throw new RuntimeException('Refusing to store a feedback screenshot under a non-UUID key.');
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create feedback screenshot storage dir: {$this->dir}");
        }
        if (false === file_put_contents($path, $bytes)) {
            throw new RuntimeException("Cannot write feedback screenshot: {$path}");
        }
    }

    public function read(string $id): ?string
    {
        $path = $this->path($id);
        if (null === $path || !is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);

        return false === $bytes ? null : $bytes;
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (null !== $path && is_file($path)) {
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- chemin construit sur une clé validée UUID (id de signalement)
        }
    }

    /** Absolute path for a UUID key, or null when the key is not a UUID (no traversal possible). */
    private function path(string $id): ?string
    {
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return null;
        }

        return rtrim($this->dir, '/') . '/' . $id;
    }
}
