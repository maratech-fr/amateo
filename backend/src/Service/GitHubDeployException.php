<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Une réponse non-2xx d'api.github.com pendant la « mise en prod programmée ».
 *
 * ⚠ Son message est EXPURGÉ par construction : il ne porte que le verbe HTTP, le
 * CHEMIN appelé (jamais l'hôte complet avec d'éventuels paramètres sensibles) et le
 * code de statut. Le jeton de déploiement voyage dans l'en-tête `Authorization`,
 * jamais dans une URL, un corps ni un message — il ne peut donc pas fuiter ici, ni
 * dans la trace rendue en chaîne (les options HTTP sont un argument TABLEAU, que PHP
 * rend « Array » dans la trace, jamais déplié).
 */
final class GitHubDeployException extends RuntimeException
{
    public static function fromResponse(string $method, string $endpoint, int $statusCode): self
    {
        return new self(\sprintf('GitHub a répondu %d sur %s %s.', $statusCode, $method, $endpoint));
    }
}
