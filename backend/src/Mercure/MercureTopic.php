<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Service\TravelComputeLock;

/**
 * Le topic Mercure d'une génération — foyer unique (audit D-10, 2026-08-08).
 *
 * ⚑ Le format `club:{clubId}:schedule:{scheduleId}` était construit par **six `sprintf`**
 * dispersés (publieur de progression ×2, export PDF ×2, listener d'échec, commande de
 * réconciliation) face à **un seul** sélecteur, celui que le JWT d'abonnement autorise
 * (`MercureAuthController`).
 *
 * ⚠ **Une divergence y est parfaitement silencieuse.** Un publieur qui dérive publie sur un
 * topic auquel personne n'est abonné : le hub ne matche rien, l'événement n'arrive nulle
 * part — et le front **dégrade en polling par conception** (`scheduleStream.ts`), donc l'écran
 * continue de se mettre à jour, en retard. Aucune erreur, aucun log, aucun test rouge. On perd
 * le temps réel sans jamais l'apprendre.
 *
 * Le sélecteur d'abonnement se dérive du même endroit : le club est interpolé, l'identifiant
 * de planning reste le joker `{id}` que Mercure remplit.
 */
final class MercureTopic
{
    /** Le topic d'un planning précis — celui sur lequel on PUBLIE. */
    public static function for(string $clubId, string $scheduleId): string
    {
        return \sprintf('club:%s:schedule:%s', $clubId, $scheduleId);
    }

    /**
     * Le sélecteur d'un club — celui que le JWT d'abonnement autorise.
     *
     * `{id}` est le joker Mercure : un abonné match TOUS les plannings de SON club, et rien
     * d'un autre (SEC-05/06).
     */
    public static function selectorForClub(string $clubId): string
    {
        return \sprintf('club:%s:schedule:{id}', $clubId);
    }

    /**
     * Un topic construit sur des identifiants vides — la sentinelle que le publieur teste
     * avant d'émettre. `club::schedule:` ne serait écouté par personne.
     */
    public static function isEmpty(string $topic): bool
    {
        return self::for('', '') === $topic;
    }

    /**
     * C6 — le topic du CALCUL DES TRAJETS d'un club : `club:{clubId}:travel`. UN topic FIXE
     * par club (pas de joker `{id}` : un seul calcul de trajets à la fois par club, cf.
     * {@see TravelComputeLock}), sur lequel on PUBLIE la progression ET auquel
     * le JWT d'abonnement autorise à s'abonner tel quel (aucune interpolation d'identifiant
     * variable — la frontière de sécurité reste le club).
     */
    public static function forTravel(string $clubId): string
    {
        return \sprintf('club:%s:travel', $clubId);
    }
}
