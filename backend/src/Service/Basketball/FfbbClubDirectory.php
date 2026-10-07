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
 *    best-effort, null = file superadmin / repli contactEmail selon l'appelant ;
 *  - `lookupIdentity()` : le NOM + la ville du club (P4-298, affichage « c'est bien
 *    votre club ? » à l'inscription) — JAMAIS le mail, trois états francs
 *    found/unknown/unavailable pour que l'appelant n'affiche rien sur une FFBB muette.
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

    /**
     * Le NOM (+ la ville quand la FFBB la donne) du club derrière un code — pour
     * l'affichage « {nom} ({ville}) — c'est bien votre club ? » à l'inscription (P4-298).
     * N'expose JAMAIS le mail institutionnel. Trois états FRANCS, miroir d'`exists()` :
     *  - found       : correspondance EXACTE trouvée, nom présent ;
     *  - unknown     : format invalide, recherche aboutie SANS correspondance, ou hit
     *                  sans nom exploitable (le front affiche « code non reconnu ») ;
     *  - unavailable : la FFBB est muette (transport en échec) — le front N'AFFICHE RIEN.
     *
     * @return array{status: string, name: string|null, city: string|null}
     */
    public function lookupIdentity(string $code): array
    {
        if (!FfbbApiClient::isValidClubCode($code)) {
            // Format hors norme fédérale : jamais d'appel sortant (SSRF/format, comme exists()).
            return ['status' => 'unknown', 'name' => null, 'city' => null];
        }
        try {
            foreach ($this->ffbbApi->search($code) as $hit) {
                if (0 === strcasecmp((string) ($hit['code'] ?? ''), $code)) {
                    $name = $this->str($hit['nom'] ?? null);

                    return null === $name
                        ? ['status' => 'unknown', 'name' => null, 'city' => null]
                        : ['status' => 'found', 'name' => $name, 'city' => $this->city($hit)];
                }
            }

            return ['status' => 'unknown', 'name' => null, 'city' => null];
        } catch (Throwable $e) {
            $this->logger->warning('FFBB club identity lookup failed', ['code' => $code, 'error' => $e->getMessage()]);

            return ['status' => 'unavailable', 'name' => null, 'city' => null];
        }
    }

    /** Trim + null si vide — mêmes règles que {@see FfbbClubPopulator} (pas de refactor). */
    private function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * La ville d'un hit organisme : `commune.libelle` d'abord, repli `cartographie.ville`
     * (mêmes clés que {@see FfbbClubPopulator::city}).
     *
     * @param array<string, mixed> $hit
     */
    private function city(array $hit): ?string
    {
        $commune = \is_array($hit['commune'] ?? null) ? $hit['commune'] : null;
        $carto = \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : null;

        return $this->str($commune['libelle'] ?? null) ?? $this->str($carto['ville'] ?? null);
    }
}
