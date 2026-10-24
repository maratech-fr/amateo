<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Feedback screenshot bytes storage. One implementation per target (local disk in
 * dev, object storage in prod) — swap the service alias in services.yaml, no caller
 * change. Keyed by the feedback id (a UUID); mime is detected on read.
 *
 * The blob lives OUT of the `feedback` table and OUT of RLS: its only read door is
 * the super-admin serve route (no club door), so one club's capture never leaks to
 * another — same pattern as {@see LogoStorage}.
 */
interface FeedbackScreenshotStorage
{
    public function store(string $id, string $bytes): void;

    /** Raw bytes, or null if no screenshot is stored for this id. */
    public function read(string $id): ?string;

    public function delete(string $id): void;
}
