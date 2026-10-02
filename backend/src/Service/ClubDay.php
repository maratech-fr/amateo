<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\ClubClock;
use App\Entity\Club;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * « Quel jour est-on POUR CE CLUB ? » — foyer unique (P4-46, 2026-08-09).
 *
 * ⚑ La logique existait DEUX fois copiée quasi à l'identique (`TransitionReminderCommand::clubToday`,
 * `CoachWishDigestCommand` inline) — et DEUX consommateurs l'ignoraient :
 *
 *  · `PublicCoachWishController::isExpired` comparait la deadline au jour du SERVEUR. Pour un
 *    coach en Guadeloupe (UTC−4), le soir du dernier jour — 21h chez lui, déjà le lendemain à
 *    Paris — le lien rendait **410** alors que la règle promet « deadline INCLUSE, le jour même
 *    est ouvert ». Il perdait sa dernière soirée. C'est l'écart qui a motivé le foyer.
 *  · `SeasonResolver` faisait basculer le pivot du 15 juillet à minuit du serveur.
 *
 * **Horloge simulée par club (P4-16 / P2-4).** Le « jour du club » consulte D'ABORD
 * {@see ClubClock::simulatedTodayFor()} — le point d'entrée unique « ce club a-t-il une
 * horloge active ? », qui lit l'ENTITÉ et NON le contexte de requête. Un club à horloge
 * posée vit donc à SA date simulée partout où ce foyer est appelé, y compris HORS d'un
 * contexte tenant : la page publique des doléances (pas de `_club_id` en requête) et les
 * tâches de nuit (aucune requête) suivent chacune l'horloge DU club qu'elles traitent,
 * club par club dans une même exécution. Sans horloge, la date civile réelle au fuseau du
 * club — le comportement d'origine, inchangé pour tous les vrais clubs.
 *
 * **Pourquoi une date CIVILE et rien d'autre.** Tout le métier temporel de l'app est soit une
 * heure de MUR (créneaux : « 18:00 au gymnase » est local par nature, aucune conversion n'a de
 * sens), soit une date CIVILE (deadlines, périodes, pivot de saison). Le seul point où le fuseau
 * mord, c'est « quel jour est-on ? » — et la réponse dépend du club. Ce service ne fait QUE ça ;
 * un `ClubTimeService` généraliste (v3 §6.2) n'a pas d'objet.
 *
 * ⚠ Gardé par `ClubDayIsNotRebuiltTest` : réécrire ce calcul inline ailleurs fait rougir la CI —
 * c'est la réécriture qui recrée la dérive, pas la copie d'origine.
 */
final readonly class ClubDay
{
    /** Le fuseau FFBB par défaut — et le repli d'une timezone absente ou invalide. */
    private const string FALLBACK_TIMEZONE = 'Europe/Paris';

    public function __construct(
        private ClockInterface $clock,
        private ClubClock $clubClock,
    ) {}

    /** La date civile courante du club, à minuit — comparable en `Y-m-d`. */
    public function todayFor(Club $club): DateTimeImmutable
    {
        // Horloge simulée : la date du club prime, lue sur l'entité — donc valable hors de
        // tout contexte tenant (page publique à token, cron). L'HEURE n'intervient pas ici,
        // ce foyer ne rend qu'une date civile à minuit.
        $simulated = $this->clubClock->simulatedTodayFor($club);
        if ($simulated instanceof DateTimeImmutable) {
            return new DateTimeImmutable($simulated->format('Y-m-d'));
        }

        $timezone = self::FALLBACK_TIMEZONE;
        if ('' !== $club->getTimezone()) {
            try {
                new DateTimeZone($club->getTimezone());
                $timezone = $club->getTimezone();
            } catch (Throwable) {
                // Fuseau stocké invalide → repli FFBB, jamais d'explosion sur une donnée.
            }
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        return new DateTimeImmutable($now->setTimezone(new DateTimeZone($timezone))->format('Y-m-d'));
    }

    /** Raccourci pour les comparaisons date-à-date, la forme que tous les appelants utilisent. */
    public function todayYmdFor(Club $club): string
    {
        return $this->todayFor($club)->format('Y-m-d');
    }
}
