<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use finfo;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Downloads an FFBB organisme logo from the fixed asset host (lot C), validated
 * for rehosting via LogoStorage. Best-effort: any failure (bad uuid, non-image,
 * oversized, transport) returns null and the caller simply skips the logo.
 *
 * Host is hard-coded (SSRF, A12); the asset uuid is format-validated; redirects
 * are disabled; the response MIME is checked from the actual bytes (SVG rejected,
 * cf. ClubLogoController) and the size is bounded.
 */
final class FfbbLogoFetcher implements FfbbLogoFetcherInterface
{
    private const ASSET_BASE = 'https://api.ffbb.com/assets/';
    private const TIMEOUT = 8.0;
    private const MAX_BYTES = 512_000; // 500 KB, same bound as club logo upload

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string, true> allowed real MIME types (raster only, no SVG) */
    private const ALLOWED = ['image/png' => true, 'image/jpeg' => true, 'image/webp' => true];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /** Validated logo bytes ready to store, or null if unavailable/rejected. */
    public function download(string $uuid): ?string
    {
        if (1 !== preg_match(self::UUID_RE, $uuid)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::ASSET_BASE . $uuid, [
                'query' => ['format' => 'webp', 'height' => 220, 'fit' => 'contain'],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }
            // Reject oversized responses before buffering the whole body — and
            // stream-cap even when the server omits Content-Length (chunked), so a
            // rogue endpoint cannot OOM the worker.
            $declared = $response->getHeaders(false)['content-length'][0] ?? null;
            if (null !== $declared && (int) $declared > self::MAX_BYTES) {
                return null;
            }
            $bytes = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (\strlen($bytes) > self::MAX_BYTES) {
                    return null;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('FFBB logo download failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return null;
        }

        if ('' === $bytes) {
            return null;
        }
        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);

        return isset(self::ALLOWED[$mime]) ? $bytes : null;
    }
}
