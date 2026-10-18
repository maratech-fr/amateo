<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Un échec de lecture de l'instance Umami interne (onglet « Statistiques » de la console).
 *
 * ⚠ Son message est EXPURGÉ par construction : il ne porte que le verbe HTTP, le CHEMIN appelé
 * (jamais l'hôte complet ni la query) et, le cas échéant, le code de statut. La clé API voyage
 * dans l'en-tête `Authorization`, jamais dans une URL, un corps ni un message — elle ne peut donc
 * pas fuiter ici, ni dans la trace rendue en chaîne (les options HTTP sont un argument TABLEAU,
 * que PHP rend « Array » dans la trace, jamais déplié). Aucune exception du transport (qui porte
 * les options, donc la clé) n'est jamais chaînée en `previous`.
 */
final class UmamiStatsException extends RuntimeException
{
    public static function unexpectedStatus(string $method, string $endpoint, int $statusCode): self
    {
        return new self(\sprintf('Umami a répondu %d sur %s %s.', $statusCode, $method, $endpoint));
    }

    public static function unreachable(string $method, string $endpoint): self
    {
        return new self(\sprintf('Umami est injoignable sur %s %s.', $method, $endpoint));
    }

    public static function malformed(string $method, string $endpoint): self
    {
        return new self(\sprintf('Umami a renvoyé une réponse illisible sur %s %s.', $method, $endpoint));
    }
}
