<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Les dates d'un document lu par des parents : « 1er novembre 2026 », « sam. 14 nov. ». Tables
 * locales plutôt qu'`IntlDateFormatter` : ces noms ne bougeront jamais.
 */
final class FrenchDate
{
    private const array MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private const array SHORT_MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    /** Indexé par jour ISO (1 = lundi). */
    private const array SHORT_DAYS = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    /** « 1er novembre 2026 », « 9 novembre 2026 ». */
    public static function long(DateTimeImmutable $date): string
    {
        return self::dayAndMonth($date) . ' ' . $date->format('Y');
    }

    /** « sam. 14 nov. » */
    public static function shortDay(DateTimeImmutable $date): string
    {
        return \sprintf('%s %s %s', self::SHORT_DAYS[(int) $date->format('N')], self::dayNumber($date), self::SHORT_MONTHS[(int) $date->format('n') - 1]);
    }

    /**
     * « du 9 au 15 novembre 2026 », « du 1er novembre au 31 décembre 2026 », et l'année sur les deux
     * bornes seulement quand elle change : « du 15 décembre 2026 au 15 janvier 2027 ». Un seul jour se lit
     * comme un jour : « du 14 novembre 2026 ».
     */
    public static function range(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return 'du ' . self::long($from);
        }
        if ($from->format('Y') !== $to->format('Y')) {
            return \sprintf('du %s au %s', self::long($from), self::long($to));
        }
        $start = $from->format('n') === $to->format('n') ? self::dayNumber($from) : self::dayAndMonth($from);

        return \sprintf('du %s au %s', $start, self::long($to));
    }

    private static function dayAndMonth(DateTimeImmutable $date): string
    {
        return self::dayNumber($date) . ' ' . self::MONTHS[(int) $date->format('n') - 1];
    }

    private static function dayNumber(DateTimeImmutable $date): string
    {
        $day = (int) $date->format('j');

        return 1 === $day ? '1er' : (string) $day;
    }
}
