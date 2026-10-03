<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Annuaire FFBB d'un club par son code — maison UNIQUE du « contact officiel » et de
 * l'existence fédérale, au-dessus de {@see FfbbApiClient}.
 *
 * Deux questions, un seul endroit où elles se répondent (deux copies de la recherche
 * par code avaient déjà divergé entre l'approbation et l'inscription) :
 *  - `exists()` : le code désigne-t-il un vrai club à la FFBB ? (garde anti-squatting à
 *    l'inscription) — distingue « inconnu » (false) de « FFBB muette » (null, ne pas bloquer) ;
 *  - `lookupClubEmail()` : le mail institutionnel du club (approbation P3-4, rappels),
 *    best-effort, null = file superadmin / repli contactEmail selon l'appelant.
 */
final class FfbbClubDirectory
{
    public function __construct(
        private readonly FfbbApiClient $ffbbApi,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Le code désigne-t-il un club connu de la fédération ?
     *  - true  : correspondance EXACTE trouvée ;
     *  - false : format invalide, ou recherche aboutie SANS correspondance (code inconnu) ;
     *  - null  : la FFBB est muette (transport en échec) — l'appelant NE bloque PAS.
     */
    public function exists(string $code): ?bool
    {
        if (!FfbbApiClient::isValidClubCode($code)) {
            return false;
        }
        try {
            foreach ($this->ffbbApi->search($code) as $hit) {
                if (0 === strcasecmp((string) ($hit['code'] ?? ''), $code)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            $this->logger->warning('FFBB club existence lookup failed', ['code' => $code, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Le mail institutionnel FFBB du club — best-effort, null = introuvable/muette. */
    public function lookupClubEmail(string $ara): ?string
    {
        if (!FfbbApiClient::isValidClubCode($ara)) {
            return null;
        }
        try {
            foreach ($this->ffbbApi->search($ara) as $hit) {
                if (0 === strcasecmp((string) ($hit['code'] ?? ''), $ara)) {
                    $mail = $hit['mail'] ?? null;

                    return \is_string($mail) && false !== filter_var(trim($mail), \FILTER_VALIDATE_EMAIL) ? trim($mail) : null;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('FFBB club email lookup failed', ['ara' => $ara, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
