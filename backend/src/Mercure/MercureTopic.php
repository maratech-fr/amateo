<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Service\MatchPlacementLock;
use App\Service\TravelComputeLock;

/**
 * Le topic Mercure d'une génération — foyer unique (audit D-10, 2026-08-08).
 *
 * ⚑ Le topic était construit par **six `sprintf`** dispersés (publieur de progression ×2,
 * export PDF ×2, listener d'échec, commande de réconciliation) face à **un seul** sélecteur,
 * celui que le JWT d'abonnement autorise (`MercureAuthController`).
 *
 * ⚠ **Une divergence y est parfaitement silencieuse.** Un publieur qui dérive publie sur un
 * topic auquel personne n'est abonné : le hub ne matche rien, l'événement n'arrive nulle
 * part — et le front **dégrade en polling par conception** (`scheduleStream.ts`), donc l'écran
 * continue de se mettre à jour, en retard. Aucune erreur, aucun log, aucun test rouge. On perd
 * le temps réel sans jamais l'apprendre.
 *
 * ⚠ **Format WEB `/clubs/{clubId}/…` (Mercure 1.0, lot 4 PR C).** Le sélecteur d'abonnement du
 * flux de génération est désormais une **URLPattern** (`match_urlpattern`, protocole 1.0), et
 * l'ancienne forme URN `club:{clubId}:schedule:{id}` ne matche PAS une URLPattern : le hub traite
 * un topic à deux-points comme un schéma d'URL opaque (`club:` = protocole), et même une URLPattern
 * littérale identique n'y matche rien (vérifié contre le hub réel v1.x). On passe donc tous les
 * topics au format CHEMIN `/clubs/{clubId}/…`, où `:id` est un groupe nommé URLPattern valide
 * (segment de chemin) et le `clubId` littéral reste la frontière tenant (SEC-05/06). Les topics
 * FIXES (trajets, placement) restent appariés en EXACT (`match`), le chemin leur convient aussi.
 *
 * Le sélecteur d'abonnement se dérive du même endroit : le club est interpolé, l'identifiant
 * de planning reste le groupe URLPattern `:id` que le hub remplit.
 */
final class MercureTopic
{
    /** Le topic d'un planning précis — celui sur lequel on PUBLIE (apparié EXACT par le hub). */
    public static function for(string $clubId, string $scheduleId): string
    {
        return \sprintf('/clubs/%s/schedules/%s', $clubId, $scheduleId);
    }

    /**
     * Le sélecteur d'un club — celui que le JWT d'abonnement autorise, en URLPattern (1.0).
     *
     * `:id` est le groupe nommé URLPattern : un abonné match TOUS les plannings de SON club
     * (`/clubs/{clubId}/schedules/<n'importe quel id>`), et rien d'un autre (SEC-05/06 — le
     * `clubId` est un littéral dans le chemin).
     */
    public static function selectorForClub(string $clubId): string
    {
        return \sprintf('/clubs/%s/schedules/:id', $clubId);
    }

    /**
     * Un topic construit sur des identifiants vides — la sentinelle que le publieur teste
     * avant d'émettre. `/clubs//schedules/` ne serait écouté par personne.
     */
    public static function isEmpty(string $topic): bool
    {
        return self::for('', '') === $topic;
    }

    /**
     * C6 — le topic du CALCUL DES TRAJETS d'un club : `/clubs/{clubId}/travel`. UN topic FIXE
     * par club (pas de groupe `:id` : un seul calcul de trajets à la fois par club, cf.
     * {@see TravelComputeLock}), sur lequel on PUBLIE la progression ET auquel
     * le JWT d'abonnement autorise à s'abonner tel quel, apparié en EXACT (aucune interpolation
     * d'identifiant variable — la frontière de sécurité reste le club).
     */
    public static function forTravel(string $clubId): string
    {
        return \sprintf('/clubs/%s/travel', $clubId);
    }

    /**
     * Le topic du PLACEMENT ASYNCHRONE des matchs d'un club : `/clubs/{clubId}/placement`.
     * UN topic FIXE par club (pas de groupe `:id` : un seul placement à la fois par club,
     * cf. {@see MatchPlacementLock}), sur lequel on PUBLIE la bascule terminale
     * du run (COMPLETED/FAILED) ET auquel le JWT d'abonnement autorise à s'abonner tel quel,
     * apparié en EXACT (aucune interpolation d'identifiant variable — la frontière reste le club).
     */
    public static function forPlacement(string $clubId): string
    {
        return \sprintf('/clubs/%s/placement', $clubId);
    }
}
