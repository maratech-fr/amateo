<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P5-18 — two hosts, two OPPOSITE indexing needs, one policy. The landing page
 * (bare domain, served by Caddy) is a sales page and MUST be crawlable; the app
 * (subdomain) MUST NOT be. Both robots.txt files are PUBLIC, so the app's must
 * forbid crawling WITHOUT naming any route — a listed token path (club-approval,
 * doléances) would only advertise it. Non-INDEXING of a discovered URL is the
 * job of the X-Robots-Tag header (docker/frontend/security-headers.conf, guarded
 * by frontend/tests/config/security-headers.config.test.ts), not of this file.
 *
 * Static guard on the tracked files: a live crawl can't run in CI, and the
 * regression to catch is an edit flipping a directive or leaking a path.
 */
#[Group('phase1')]
final class IndexationPolicyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /**
     * Sensitive public token routes (frontend/src/app/routes.tsx). None of them
     * may ever appear in the public app robots.txt.
     */
    private const TOKEN_ROUTE_MARKERS = ['club-approval', 'doleances', 'doléances'];

    public function testLandingRobotsAllowsCrawling(): void
    {
        $robots = $this->read('/landing/robots.txt');

        self::assertMatchesRegularExpression(
            '/^\s*User-agent:\s*\*\s*$/mi',
            $robots,
            'The landing robots.txt must address every crawler (User-agent: *).',
        );
        // Allow-all = an EMPTY Disallow value (or none). A non-empty Disallow
        // would fence the sales page off from search engines.
        foreach ($this->disallowValues($robots) as $value) {
            self::assertSame(
                '',
                $value,
                'The landing must stay fully crawlable: no non-empty Disallow directive.',
            );
        }
    }

    public function testLandingRobotsReferencesNoSitemap(): void
    {
        // Decision: no sitemap exists, so none is advertised (a dangling
        // Sitemap: line would point crawlers at a 404).
        self::assertDoesNotMatchRegularExpression(
            '/^\s*Sitemap:/mi',
            $this->read('/landing/robots.txt'),
            'No sitemap exists — the landing robots.txt must not reference one.',
        );
    }

    public function testAppRobotsForbidsCrawlingEntirely(): void
    {
        $robots = $this->read('/frontend/public/robots.txt');

        self::assertMatchesRegularExpression(
            '/^\s*User-agent:\s*\*\s*$/mi',
            $robots,
            'The app robots.txt must address every crawler (User-agent: *).',
        );
        self::assertMatchesRegularExpression(
            '#^\s*Disallow:\s*/\s*$#mi',
            $robots,
            'The app robots.txt must forbid the whole tree (Disallow: /).',
        );
    }

    public function testAppRobotsNamesNoPath(): void
    {
        $robots = $this->read('/frontend/public/robots.txt');

        // "Forbid without naming a path": the only Disallow directive is the
        // root "/". Anything more specific would publish a route's existence.
        foreach ($this->disallowValues($robots) as $value) {
            self::assertSame(
                '/',
                $value,
                'The app robots.txt must name no path — only "Disallow: /" is allowed.',
            );
        }

        // Belt-and-braces against a leaked token route, comment included.
        foreach (self::TOKEN_ROUTE_MARKERS as $marker) {
            self::assertStringNotContainsStringIgnoringCase(
                $marker,
                $robots,
                \sprintf('The public app robots.txt must never mention the "%s" route.', $marker),
            );
        }
    }

    /**
     * Every `Disallow:` directive value found in a robots.txt (comments dropped),
     * trimmed. An empty array means the file declares no Disallow at all.
     *
     * @return list<string>
     */
    private function disallowValues(string $robots): array
    {
        $values = [];
        foreach (explode("\n", $robots) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (1 === preg_match('/^Disallow:\s*(.*)$/i', $line, $matches)) {
                $values[] = trim($matches[1]);
            }
        }

        return $values;
    }

    private function read(string $relativePath): string
    {
        $path = self::ROOT . $relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "File not found at {$path}");

        return $contents;
    }
}
