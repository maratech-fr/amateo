<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model\Paths;

/**
 * One domain\'s worth of custom `#[Route]` OpenAPI declarations.
 *
 * ⚠ **L\'ORDRE dans lequel `CustomRoutesOpenApiFactory` compose ses contributeurs est
 * significatif** : `Paths::addPath()` est un APPEND, et `specs/courantes/openapi-snapshot.json`
 * fige l\'ordre exact des chemins. Réordonner un contributeur, c\'est faire rougir
 * `OpenApiSnapshotMatchesTheLiveContractTest`. Chaque contributeur reçoit `OpenApiSchemas`
 * pour bâtir corps de requête et réponses JSON — aucun helper n\'est dupliqué.
 */
interface CustomPathContributor
{
    public function contribute(Paths $paths): void;
}
