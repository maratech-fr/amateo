<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ArrayObject;

/**
 * Fabriques partagées des corps de requête et réponses JSON écrits à la main pour les routes
 * custom. **Foyer unique** de `jsonBody`/`jsonResponse` : injecté dans chaque
 * {@see CustomPathContributor} plutôt que dupliqué (le défaut corrigé ailleurs cette semaine).
 */
final readonly class OpenApiSchemas
{
    /**
     * @param array<string, mixed> $schema
     */
    public function jsonBody(array $schema): RequestBody
    {
        return new RequestBody(content: new ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function jsonResponse(string $description, array $schema): Response
    {
        return new Response($description, new ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }
}
