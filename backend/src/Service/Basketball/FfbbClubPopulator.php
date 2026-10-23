<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Basketball\FfbbCommittee;
use App\Entity\Basketball\FfbbLeague;
use App\Entity\Club;
use App\Repository\Basketball\FfbbCommitteeRepository;
use App\Repository\Basketball\FfbbLeagueRepository;
use App\Storage\LogoStorage;
use App\Storage\LogoUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills a club's institutional data from the FFBB API (lot C), from its FFBB
 * club code alone. Idempotent and best-effort: sub-parts (committee, league,
 * logos) each fail independently without aborting the rest, and a total miss
 * (invalid code / not found) leaves the club untouched. Shared by the async
 * register hook and the (future) superadmin refresh route.
 *
 * `Club.league` is deliberately NOT overwritten — it holds the internal
 * LeagueResolver key (e.g. AURA) that drives the match-window catalog, not the
 * FFBB league code (ARA). The league/committee reference rows are keyed on the
 * FFBB codes and resolved for display from the club's ffbbClubCode prefix +
 * committeeCode. See specs/evolution/import-ffbb-autofill.md + backend/docs/ffbb-api.md.
 */
final class FfbbClubPopulator
{
    // Surfaces du thème SOMBRE, recopiées de frontend/src/shared/lib/color.ts (SURFACES.dark) —
    // une 2e couleur de logo ne devient l'accent sombre que si elle y reste lisible (AA 4,5:1).
    private const string DARK_THEME_BG = '#151311';
    private const string DARK_THEME_CARD = '#1f1d1a';
    private const float WCAG_AA = 4.5;

    public function __construct(
        private readonly FfbbApiClient $api,
        private readonly FfbbLogoFetcher $logoFetcher,
        private readonly LogoStorage $logoStorage,
        private readonly FfbbLeagueRepository $leagues,
        private readonly FfbbCommitteeRepository $committees,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LogoPaletteExtractor $paletteExtractor,
    ) {}

    /**
     * @param bool $refresh false (async register hook) = cache-first, leave known
     *                      reference rows untouched; true (explicit import route) =
     *                      re-fetch and overwrite stale committee/league data
     *
     * @return bool true if the club organisme was found and applied
     */
    public function populate(Club $club, bool $refresh = false): bool
    {
        $code = (string) $club->getFfbbClubCode();
        if (!FfbbApiClient::isValidClubCode($code)) {
            return false;
        }

        $hit = $this->firstByCode($this->api->search($code), $code);
        if (null === $hit) {
            $this->logger->info('FFBB populate: club organisme not found', ['code' => $code]);

            return false;
        }

        // Persist the club data first, on its own flush: a later reference-row
        // race (unique-code conflict) must never roll back the club's own fields.
        $this->applyClub($club, $hit);
        $this->em->flush();

        $parent = $this->arr($hit['organisme_id_pere'] ?? null);
        if (null !== $parent) {
            $ligue = $this->arr($parent['organisme_id_pere'] ?? null);
            $this->upsertCommittee($parent, $ligue, $refresh);
            $this->upsertLeague($ligue, $refresh);
        }

        return true;
    }

    /** @param array<string, mixed> $hit */
    private function applyClub(Club $club, array $hit): void
    {
        // Set only when FFBB actually provides a value — never null out a
        // manager-entered (lot B) address/phone/email on a refresh with gaps.
        // Le NOM du club : FFBB fait autorité (décision fondateur 2026-07-18) — le
        // nom saisi au register n'est qu'un fallback ; dès que la fédération répond,
        // c'est SON nom qui prend la place (register ET re-import). Null-check inline
        // (setName n'accepte pas null, contrairement aux autres setters). Tronqué à
        // 180 (Club.name) : un nom FFBB plus long ferait échouer le flush et perdrait
        // AUSSI adresse/logo/coordonnées posés dans le même flush (revue #261 round 1).
        $ffbbName = $this->str($hit['nom'] ?? null);
        if (null !== $ffbbName) {
            $club->setName(mb_substr($ffbbName, 0, 180));
        }
        // Le SIÈGE (adresse/CP/ville + coordonnées) n'est posé QUE si le club n'a pas encore de
        // coordonnées : « Actualiser depuis la FFBB » ne doit jamais écraser un siège choisi à la
        // main (only-fill-when-empty, comme le seeder). Le contact (tél/mail/site) reste rafraîchi.
        if (null === $club->getLatitude() || null === $club->getLongitude()) {
            $this->setIfPresent($this->str($hit['adresse'] ?? null), $club->setAddress(...));
            $this->setIfPresent($this->postalCode($hit), $club->setPostalCode(...));
            $this->setIfPresent($this->city($hit), $club->setCity(...));

            [$lat, $lng] = $this->coordinates($hit);
            if (null !== $lat && null !== $lng) {
                $club->setLatitude($lat);
                $club->setLongitude($lng);
            }
        }
        $this->setIfPresent($this->str($hit['telephone'] ?? null), $club->setContactPhone(...));
        $this->setIfPresent($this->str($hit['mail'] ?? null), $club->setContactEmail(...));
        $this->setIfPresent($this->str($hit['urlSiteWeb'] ?? null), $club->setWebsite(...));

        $parent = $this->arr($hit['organisme_id_pere'] ?? null);
        $this->setIfPresent(null === $parent ? null : $this->str($parent['code'] ?? null), $club->setCommitteeCode(...));

        // Le logo du club ET l'accent dérivé du logo partagent les MÊMES octets : un
        // seul téléchargement sert les deux blocs (jamais un second fetch pour l'accent).
        $needsLogo = null === $club->getLogoUrl();
        $needsAccent = null === $club->getAccentColor();
        $logoBytes = $needsLogo || $needsAccent ? $this->logoBytes($hit) : null;

        // Club logo: only set it when the club has none (never clobber an upload).
        if ($needsLogo && null !== $logoBytes) {
            $this->logoStorage->store($club->getId(), $logoBytes);
            $club->setLogoUrl(LogoUrl::build($club->getId(), $logoBytes));
        }

        // P2-21 / retour Rillieux #14 — l'accent par défaut vient du LOGO quand il est
        // extractible : la couleur dominante devient l'accent (elle l'emporte sur le
        // `gradient_color` FFBB), la seconde devient l'accent SOMBRE si elle reste lisible
        // (AA 4,5:1) sur les deux surfaces du thème sombre. SEULEMENT si le club n'a encore
        // rien choisi : un accent posé à la main ne se fait jamais écraser par un ré-import.
        if ($needsAccent) {
            $palette = null === $logoBytes ? null : $this->paletteExtractor->extract($logoBytes);
            if (null !== $palette) {
                $club->setAccentColor($palette[0]);
                $second = $palette[1];
                if (null !== $second && $this->readableOnDarkSurfaces($second)) {
                    $club->setAccentColorDark($second);
                }
            } else {
                // Repli : le logo n'est pas extractible → la couleur dominante FFBB
                // (`logo.gradient_color`, mesuré : #c9102e pour BCCL). Format validé —
                // une valeur FFBB inattendue ne doit pas casser le thème.
                $logo = $this->arr($hit['logo'] ?? null);
                $gradient = null === $logo ? null : $this->str($logo['gradient_color'] ?? null);
                if (null !== $gradient && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $gradient)) {
                    $club->setAccentColor(strtolower($gradient));
                }
            }
        }
    }

    /**
     * Une couleur reste-t-elle lisible (contraste WCAG AA, 4,5:1) sur LES DEUX surfaces
     * du thème sombre ? Sinon le front garde sa dérivation par contraste (accentColorDark null).
     */
    private function readableOnDarkSurfaces(string $hex): bool
    {
        return $this->contrastRatio($hex, self::DARK_THEME_BG) >= self::WCAG_AA
            && $this->contrastRatio($hex, self::DARK_THEME_CARD) >= self::WCAG_AA;
    }

    /** Rapport de contraste WCAG (1..21) entre deux hex #rrggbb — miroir de color.ts::contrastRatio. */
    private function contrastRatio(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Luminance relative WCAG (0 noir, 1 blanc) d'un hex #rrggbb — miroir de color.ts::luminance. */
    private function luminance(string $hex): float
    {
        $n = (int) hexdec(ltrim($hex, '#'));
        $lin = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin(($n >> 16) & 0xFF) + 0.7152 * $lin(($n >> 8) & 0xFF) + 0.0722 * $lin($n & 0xFF);
    }

    /** @param callable(?string): mixed $setter */
    private function setIfPresent(?string $value, callable $setter): void
    {
        if (null !== $value) {
            $setter($value);
        }
    }

    /**
     * @param array<string, mixed>      $parent nested committee organisme
     * @param array<string, mixed>|null $ligue  nested league organisme (for leagueCode)
     */
    private function upsertCommittee(array $parent, ?array $ligue, bool $refresh): void
    {
        $code = $this->str($parent['code'] ?? null);
        if (null === $code || (!$refresh && $this->committees->findByCode($code) instanceof FfbbCommittee)) {
            return; // cache-first: already known → no fetch/write
        }

        try {
            $full = $this->firstByCode($this->api->search((string) $this->str($parent['nom'] ?? '')), $code) ?? $parent;
            $entity = (new FfbbCommittee)
                ->setCode($code)
                ->setLeagueCode(null === $ligue ? null : $this->str($ligue['code'] ?? null))
                ->setName((string) ($this->str($full['nom'] ?? $parent['nom'] ?? null) ?? $code));
            $this->applyOrganismeContact($full, $entity, 'ffbb-committee-' . $code, 'committee', $code);
            $this->committees->upsert($entity, $refresh);
        } catch (Throwable $e) {
            $this->logger->warning('FFBB populate: committee upsert failed', ['code' => $code, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed>|null $ligue nested league organisme */
    private function upsertLeague(?array $ligue, bool $refresh): void
    {
        if (null === $ligue) {
            return;
        }
        $code = $this->str($ligue['code'] ?? null);
        if (null === $code || (!$refresh && $this->leagues->findByCode($code) instanceof FfbbLeague)) {
            return;
        }

        try {
            $full = $this->firstByCode($this->api->search((string) $this->str($ligue['nom'] ?? '')), $code) ?? $ligue;
            $entity = (new FfbbLeague)
                ->setCode($code)
                ->setName((string) ($this->str($full['nom'] ?? $ligue['nom'] ?? null) ?? $code));
            $this->applyOrganismeContact($full, $entity, 'ffbb-league-' . $code, 'league', $code);
            $this->leagues->upsert($entity, $refresh);
        } catch (Throwable $e) {
            $this->logger->warning('FFBB populate: league upsert failed', ['code' => $code, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Shared mapping for the two reference entities (same setters).
     *
     * @param array<string, mixed> $hit
     */
    private function applyOrganismeContact(array $hit, FfbbLeague|FfbbCommittee $entity, string $storageKey, string $scope, string $code): void
    {
        $entity->setAddress($this->str($hit['adresse'] ?? null));
        $entity->setPostalCode($this->postalCode($hit));
        $entity->setCity($this->city($hit));
        $entity->setPhone($this->str($hit['telephone'] ?? null));
        $entity->setEmail($this->str($hit['mail'] ?? null));
        // `str()` trime — mesuré : la ligue ARA rend "…com " avec une espace finale.
        $entity->setWebsite($this->str($hit['urlSiteWeb'] ?? null));

        $bytes = $this->logoBytes($hit);
        if (null !== $bytes) {
            $this->logoStorage->store($storageKey, $bytes);
            $entity->setLogoUrl(\sprintf('/api/ffbb-logos/%s/%s?v=%s', $scope, $code, substr(md5($bytes), 0, 8)));
        }
    }

    /**
     * @param list<array<string, mixed>> $hits
     *
     * @return array<string, mixed>|null the hit whose `code` matches exactly
     *                                   (case-insensitive), or null — NEVER an
     *                                   arbitrary neighbour (Meilisearch is
     *                                   typo-tolerant; hits[0] could be a
     *                                   different organisation entirely)
     */
    private function firstByCode(array $hits, string $code): ?array
    {
        foreach ($hits as $hit) {
            if (0 === strcasecmp((string) ($hit['code'] ?? ''), $code)) {
                return $hit;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $hit */
    private function logoBytes(array $hit): ?string
    {
        $logo = $this->arr($hit['logo'] ?? null);
        $uuid = null === $logo ? null : $this->str($logo['id'] ?? null);

        return null === $uuid ? null : $this->logoFetcher->download($uuid);
    }

    /** @param array<string, mixed> $hit */
    private function postalCode(array $hit): ?string
    {
        $carto = $this->arr($hit['cartographie'] ?? null);
        $commune = $this->arr($hit['commune'] ?? null);

        return $this->str($carto['codePostal'] ?? null) ?? $this->str($commune['codePostal'] ?? null);
    }

    /** @param array<string, mixed> $hit */
    private function city(array $hit): ?string
    {
        $commune = $this->arr($hit['commune'] ?? null);
        $carto = $this->arr($hit['cartographie'] ?? null);

        return $this->str($commune['libelle'] ?? null) ?? $this->str($carto['ville'] ?? null);
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array{0: ?float, 1: ?float} [latitude, longitude]
     */
    private function coordinates(array $hit): array
    {
        $geo = $this->arr($hit['_geo'] ?? null);
        if (null !== $geo && isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng'])) {
            return [(float) $geo['lat'], (float) $geo['lng']];
        }

        return [null, null];
    }

    private function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arr(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }
}
