<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\EngineRequestRejectedException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the solver engine's POST /generate (BCK-04: extracted from
 * GenerateScheduleHandler). Exceptions propagate unchanged — the handler owns
 * the failure→status mapping (a TransportExceptionInterface = timeout, any other
 * throw = engine error).
 *
 * P4-340 — un REFUS DE CONTRAT du moteur (HTTP 422 Pydantic : un champ du payload hors contrat)
 * est traduit ici en {@see EngineRequestRejectedException}, que le handler mappe en échec terminal
 * LISIBLE (`engine_rejected`). Sans cette détection, `toArray(false)` rendrait le corps d'erreur
 * comme un résultat ordinaire (`status` absent → « failed » opaque). Limité à `solve()` : les rails
 * place-matches / validate-assignments gardent leur propagation inchangée (hors périmètre).
 */
final class EngineClient
{
    private const ENGINE_URL = 'http://engine:8000/generate';
    private const PLACE_MATCHES_URL = 'http://engine:8000/place-matches';
    private const VALIDATE_URL = 'http://engine:8000/validate-assignments';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestIdContext $requestIdContext,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function solve(array $payload, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', self::ENGINE_URL, [
            'json' => $payload,
            'timeout' => $timeoutSeconds,
            'headers' => $this->correlationHeaders(),
        ]);

        // getStatusCode() ne jette PAS sur un code HTTP (seul un échec TRANSPORT le fait, et le
        // handler le traite déjà en timeout) : un 422 de contrat Pydantic est détecté ici.
        if (422 === $response->getStatusCode()) {
            throw EngineRequestRejectedException::fromPydanticDetail($response->toArray(false));
        }

        return $response->toArray(false);
    }

    /**
     * P1-4 PR D — the dated match-placement solve (contract 2.2, ADR-0003).
     * Same propagation contract as solve(): exceptions bubble up unchanged.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function placeMatches(array $payload, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', self::PLACE_MATCHES_URL, [
            'json' => $payload,
            'timeout' => $timeoutSeconds,
            'headers' => $this->correlationHeaders(),
        ]);

        return $response->toArray(false);
    }

    /**
     * P2-2 F2a — le verdict moteur sur UN candidat de déplacement (contrat 2.4).
     * Mono-candidat, appel court (baseline figée côté engine). Même propagation
     * que solve() : les exceptions remontent inchangées, l'appelant décide.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function validateAssignment(array $payload, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', self::VALIDATE_URL, [
            'json' => $payload,
            'timeout' => $timeoutSeconds,
            'headers' => $this->correlationHeaders(),
        ]);

        return $response->toArray(false);
    }

    /**
     * P5-11 — propage l'id de corrélation au moteur pour qu'un même request_id
     * relie la requête HTTP, le worker et les logs du solve. Absent (aucun
     * contexte) → aucun header, comportement inchangé.
     *
     * @return array<string, string>
     */
    private function correlationHeaders(): array
    {
        $requestId = $this->requestIdContext->get();

        return null !== $requestId ? ['X-Request-Id' => $requestId] : [];
    }
}
