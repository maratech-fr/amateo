<?php

declare(strict_types=1);

namespace App\Clock;

use App\Entity\Club;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-16 / P2-4 — l'horloge d'un club : la capacité générique « ce club vit-il à
 * une date simulée ? ».
 *
 * Décore le service `clock` : tout consommateur de {@see ClockInterface}
 * (SeasonResolver, OverlayManager, SeasonTransitionService, guards…) reçoit,
 * pour un club dont l'horloge est posée, cette date-là — et l'heure RÉELLE de la
 * journée (seule la DATE est simulée : les durées, TTL et horodatages
 * intra-journée gardent un sens).
 *
 * {@see self::simulatedTodayFor()} est LE point d'entrée unique « ce club a-t-il
 * une horloge active ? » : il lit l'ENTITÉ (jamais le memo de requête), pour que
 * tout appelant hors du chemin `now()` (foyer du jour civil, visibilité d'un
 * écran, interception…) obtienne la même réponse sans dépendre du contexte de
 * requête.
 *
 * Périmètre de `now()` STRICTEMENT tenant : la date simulée est lue sur le club
 * de la REQUÊTE (`_club_id`, posé par TenantFilterListener APRÈS le firewall — un
 * header spoofé est déjà refusé en amont). Hors requête (workers, crons,
 * commandes) ou hors club (firewall admin — le superadmin ne porte jamais de
 * `_club_id`), l'horloge est VRAIE. Un club sans horloge a `simulated_today` NULL :
 * passage direct, zéro changement de comportement.
 *
 * ⚠ P4-304 — une route PUBLIQUE n'est PAS « hors club » : TenantFilterListener pose
 * `_club_id` dès qu'un JWT est présent, même sur /api/register ou /api/club-approvals.
 * Un gestionnaire de club démo qui porte son cookie/JWT sur ces routes fait donc
 * retourner la date SIMULÉE par `now()`. Toute DURÉE ou tout HORODATAGE de SÉCURITÉ
 * doit pour cela s'injecter l'horloge réelle `app.clock.real`, jamais ce service
 * décoré (cf. config/services.yaml, la liste des consommateurs).
 *
 * ⚠ Mémoïsé par (requête → date) et non par service : le worker et les tests
 * réutilisent le même service sur plusieurs contextes — un memo global
 * servirait la date d'un club au suivant.
 *
 * Drapeau DEV `APP_CLUB_CLOCK_ALL` : quand il est actif, la capacité d'horloge
 * est ouverte à TOUS les clubs de l'environnement — un club sans `simulated_today`
 * emprunte alors le pin GLOBAL du widget DevClock ({@see DevClockStore}, réglé au
 * widget ou à la console, jamais dans le `.env`). Garde de sûreté : le drapeau est
 * IGNORÉ si `kernel.environment === 'prod'` — l'horloge simulée ne peut jamais
 * s'ouvrir à tous en production, même drapeau mal posé. Câblé par le paramètre
 * conteneur `%app.club_clock_all%` (`%env(bool:APP_CLUB_CLOCK_ALL)%`, défaut 0),
 * jamais lu via `$_ENV`. Le DevClock existant reste inchangé (il pilote toujours
 * `ClockInterface` en dev).
 */
final class ClubClock implements ClockInterface
{
    /** @var array<string, DateTimeImmutable|null> clubId → date simulée (memo par club) */
    private array $simulatedByClub = [];

    public function __construct(
        private readonly ClockInterface $inner,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly DevClockStore $devClockStore,
        private readonly bool $clockAllEnabled,
        private readonly string $environment,
    ) {}

    public function now(): DateTimeImmutable
    {
        $real = DateTimeImmutable::createFromInterface($this->inner->now());
        $simulated = $this->currentSimulatedToday();
        if (!$simulated instanceof DateTimeImmutable) {
            return $real;
        }

        // La DATE simulée, l'HEURE réelle — dans le fuseau de l'horloge réelle.
        return $real->setDate(
            (int) $simulated->format('Y'),
            (int) $simulated->format('n'),
            (int) $simulated->format('j'),
        );
    }

    public function sleep(float|int $seconds): void
    {
        $this->inner->sleep($seconds);
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return new self($this->inner->withTimeZone($timezone), $this->requestStack, $this->entityManager, $this->devClockStore, $this->clockAllEnabled, $this->environment);
    }

    /**
     * La date simulée d'UN club donné, ou null s'il vit à l'heure réelle.
     *
     * LE point d'entrée unique « ce club a-t-il une horloge active ? » : lit
     * l'entité directement (pas le memo de requête), donc utilisable hors de tout
     * contexte tenant.
     *
     * Ordre : (1) `simulated_today` du club s'il est posé ET que le club est un
     * club de DÉMONSTRATION (SEC-30, décision fondateur 2026-10-02 : l'horloge
     * simulée est réservée aux démos — un vrai club vit toujours à l'heure réelle ;
     * la contrainte CHECK `simulated_today IS NULL OR is_demo` garantit déjà qu'un
     * vrai club n'en porte jamais en base, ce garde est la ceinture côté lecture et
     * couvre un club non persisté) ; (2) sinon, en DEV et drapeau
     * `APP_CLUB_CLOCK_ALL` actif, la DATE du pin global DevClock s'il y en a un ;
     * (3) sinon null (heure réelle). Le drapeau est neutralisé en prod.
     */
    public function simulatedTodayFor(Club $club): ?DateTimeImmutable
    {
        $pinned = $club->getSimulatedToday();
        if ($pinned instanceof DateTimeImmutable && $club->isDemo()) {
            return $pinned;
        }

        if ($this->clockAllEnabled && 'prod' !== $this->environment) {
            $devPin = $this->devClockStore->get();
            if ($devPin instanceof DateTimeImmutable) {
                // La DATE du pin (l'heure réelle de la journée reste gérée par now()).
                return $devPin->setTime(0, 0);
            }
        }

        return null;
    }

    private function currentSimulatedToday(): ?DateTimeImmutable
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return null;
        }

        if (!\array_key_exists($clubId, $this->simulatedByClub)) {
            // find() passe par l'identity map : dans une requête déjà tenant-résolue le
            // club est généralement chargé — coût marginal nul, et RLS s'applique.
            $club = $this->entityManager->find(Club::class, $clubId);
            $this->simulatedByClub[$clubId] = $club instanceof Club ? $this->simulatedTodayFor($club) : null;
        }

        return $this->simulatedByClub[$clubId];
    }
}
