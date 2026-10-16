<?php

declare(strict_types=1);

namespace App\Mercure;

use InvalidArgumentException;
use Symfony\Component\Mercure\Update;

/**
 * Single source of truth for club-scoped Mercure updates (SEC-06 / A14). Every
 * update on a `/clubs/{clubId}/…` topic MUST be private so the subscriber JWT's
 * per-topic authorization is enforced — a public update would leak one club's
 * status to any holder of a valid subscriber token. Building the Update here
 * (instead of `new Update(..., private: true)` copy-pasted across publishers)
 * means the flag can never be silently dropped at one call site, and a single
 * test guards it. Also fail-closed if the topic is not club-scoped.
 *
 * The club-scope prefix is `/clubs/` since the Mercure 1.0 cutover (lot 4 PR C):
 * topics moved from the URN form `club:{id}:…` to the web path `/clubs/{id}/…`
 * so the generation selector is a valid URLPattern ({@see MercureTopic}).
 */
final class ClubTopicUpdate
{
    public static function private(string $topic, string $data): Update
    {
        if (!str_starts_with($topic, '/clubs/')) {
            throw new InvalidArgumentException(\sprintf('Mercure topic "%s" is not club-scoped — refusing to publish.', $topic));
        }

        return new Update($topic, $data, private: true);
    }
}
