<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLinkType;

/**
 * Une demande au moteur sous une forme que `diff -r` compare ligne à ligne, qui nomme ce qui a changé
 * et ne laisse aucun nom en clair.
 *
 *   - une ligne par feuille, `<chemin>\t<valeur>`, lignes triées (ordre d'octets), saut de ligne final ;
 *   - un élément de liste est désigné par son `id` (`teams[<id>]`), `<id>#1`, `<id>#2`… quand l'id se
 *     répète dans la liste, son index (`[0]`) quand il n'en a pas ; une ligne `<chemin>#order` par
 *     liste porte l'ordre (un changement de contenu ne se noie jamais dans un décalage d'index) ;
 *   - en clair : nombres (forme JSON, `1.0` garde sa décimale), booléens, `null`, UUID, heures, dates,
 *     les valeurs d'énumération d'une liste FERMÉE, la version du contrat ; toute autre chaîne devient
 *     `sha256:<empreinte>`. La liste est fermée exprès : un nom d'équipe comme `SM1` a la forme d'une
 *     énumération, il reste un nom.
 *
 * Limite : l'empreinte d'une chaîne courte se retrouve par dictionnaire — les fichiers produits se
 * traitent comme la base dont ils viennent.
 */
final class EngineRequestFingerprint
{
    private const string UUID = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';
    private const string TIME = '/\A\d{2}:\d{2}(:\d{2})?\z/';
    private const string DATE = '/\A\d{4}-\d{2}-\d{2}\z/';
    private const string CONTRACT_VERSION = '/\A\d+(\.\d+)+\z/';

    /** Mots d'un identifiant composé (`team-coach:<uuid>`, `<uuid>:<uuid>:1:19:30`). */
    private const string ID_WORD = '/\A[a-z]+(-[a-z]+)*\z/';

    /** Valeurs littérales des constructeurs de demande, hors énumérations PHP. */
    private const array LITERALS = ['TEAM_COACH', 'COACH_PLAYER_UNAVAILABILITY', 'PRIORITY_TIER', 'TO_PLACE', 'AWAY', 'FIXED'];

    /** Énumérations dont les valeurs entrent dans une demande. */
    private const array ENUMS = [LockLevel::class, ConstraintRuleType::class, ConstraintScope::class, ConstraintFamily::class, TeamLinkType::class, TeamCoachRole::class];

    /** @var array<string, true>|null */
    private static ?array $clearWords = null;

    /** @param array<mixed> $request */
    public static function render(array $request): string
    {
        $lines = [];
        self::walk($request, '', $lines);
        sort($lines, \SORT_STRING);

        return [] === $lines ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $lines
     */
    private static function walk(array $node, string $path, array &$lines): void
    {
        if (array_is_list($node)) {
            $designations = self::designations($node);
            $lines[] = $path . "#order\t" . implode(',', $designations);
            foreach ($node as $index => $value) {
                self::leafOrWalk($value, $path . '[' . $designations[$index] . ']', $lines);
            }

            return;
        }

        foreach ($node as $key => $value) {
            self::leafOrWalk($value, '' === $path ? (string) $key : $path . '.' . $key, $lines);
        }
    }

    /** @param list<string> $lines */
    private static function leafOrWalk(mixed $value, string $path, array &$lines): void
    {
        if (\is_array($value)) {
            self::walk($value, $path, $lines);

            return;
        }

        $lines[] = $path . "\t" . self::value($value, $path);
    }

    /**
     * @param list<mixed> $list
     *
     * @return list<string>
     */
    private static function designations(array $list): array
    {
        $ids = [];
        foreach ($list as $index => $element) {
            $id = \is_array($element) ? ($element['id'] ?? null) : null;
            $ids[$index] = \is_string($id) || \is_int($id) ? self::idDesignation((string) $id) : null;
        }
        $counts = array_count_values(array_filter($ids, static fn (?string $id): bool => null !== $id));

        $seen = [];
        $designations = [];
        foreach ($ids as $index => $id) {
            if (null === $id) {
                $designations[] = (string) $index;

                continue;
            }
            if ($counts[$id] > 1) {
                $seen[$id] = ($seen[$id] ?? 0) + 1;
                $designations[] = $id . '#' . $seen[$id];

                continue;
            }
            $designations[] = $id;
        }

        return $designations;
    }

    /**
     * Un id désigne en clair s'il ne fait qu'assembler des valeurs en clair (UUID, nombres, heures)
     * et des mots d'identifiant (`team-coach`) ; sinon c'est son empreinte qui le désigne.
     */
    private static function idDesignation(string $id): string
    {
        foreach (explode(':', $id) as $part) {
            if (1 !== preg_match(self::UUID, $part) && !ctype_digit($part) && 1 !== preg_match(self::ID_WORD, $part)) {
                return 'sha256:' . hash('sha256', $id);
            }
        }

        return $id;
    }

    private static function value(mixed $value, string $path): string
    {
        return match (true) {
            null === $value => 'null',
            true === $value => 'true',
            false === $value => 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => json_encode($value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR),
            \is_string($value) => self::isClear($value, $path) ? $value : 'sha256:' . hash('sha256', $value),
            default => 'sha256:' . hash('sha256', json_encode($value, \JSON_THROW_ON_ERROR)),
        };
    }

    private static function isClear(string $value, string $path): bool
    {
        if (1 === preg_match(self::UUID, $value) || 1 === preg_match(self::TIME, $value) || 1 === preg_match(self::DATE, $value)) {
            return true;
        }
        if ('version' === $path && 1 === preg_match(self::CONTRACT_VERSION, $value)) {
            return true;
        }

        return isset(self::clearWords()[$value]);
    }

    /** @return array<string, true> */
    private static function clearWords(): array
    {
        if (null === self::$clearWords) {
            $words = self::LITERALS;
            foreach (self::ENUMS as $enum) {
                foreach ($enum::cases() as $case) {
                    $words[] = $case->value;
                }
            }
            self::$clearWords = array_fill_keys($words, true);
        }

        return self::$clearWords;
    }
}
