<?php

declare(strict_types=1);

namespace App\Seed;

use JsonException;
use RuntimeException;

/**
 * AUD-SEC-29 — identités RÉELLES du club du fondateur, lues depuis un fichier LOCAL gitignoré
 * (jamais au dépôt : le dépôt public ne porte QUE du fictif). Absent (CI, autre dev, prod sans
 * fichier) → {@see loadFromFile()} rend `null`, et les seeds repartent sur leurs identités
 * fictives déterministes (surnoms « Coach … », gestionnaire `dev-bccl@amateo.local`).
 *
 * Format attendu (`config/seed/bccl.identities.local.json`) — AUCUNE valeur réelle n'est
 * documentée ici, seulement la forme :
 *
 *     {
 *       "manager": { "email": "…", "firstName": "…", "lastName": "…", "password": "…" },
 *       "additionalManagers": [
 *         { "email": "…", "firstName": "…", "lastName": "…", "password": "…" }
 *       ],
 *       "coaches": [ { "firstName": "…", "lastName": "…" }, … ]
 *     }
 *
 * - `manager` (optionnel) : remplace le gestionnaire fictif (dev), ou fournit prénom/nom en prod.
 * - `additionalManagers` (optionnel) : co-gestionnaires EN PLUS — find-or-create par e-mail.
 * - `coaches` (optionnel) : remplacement POSITIONNEL des coachs du seed, DANS L'ORDRE ; la liste
 *   doit être AU MOINS aussi longue que celle du seed, sinon le seeder lève (jamais « en partie »).
 */
final readonly class BcclSeedIdentities
{
    /**
     * @param array{email: string, firstName: string, lastName: string, password: string}|null  $manager
     * @param list<array{email: string, firstName: string, lastName: string, password: string}> $additionalManagers
     * @param list<array{firstName: string, lastName: string}>                                  $coachNames
     */
    public function __construct(
        public ?array $manager,
        public array $additionalManagers,
        public array $coachNames,
    ) {}

    public static function defaultPath(string $projectDir): string
    {
        return rtrim($projectDir, '/') . '/config/seed/bccl.identities.local.json';
    }

    /**
     * Charge le fichier local s'il existe ; `null` sinon (le cas normal au dépôt public).
     */
    public static function loadFromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local illisible : %s', $path));
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : %s', $path, $e->getMessage()), 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : un objet JSON est attendu.', $path));
        }

        return new self(
            self::readManager($data['manager'] ?? null, $path),
            self::readAdditionalManagers($data['additionalManagers'] ?? [], $path),
            self::readCoaches($data['coaches'] ?? [], $path),
        );
    }

    /**
     * @return array{email: string, firstName: string, lastName: string, password: string}|null
     */
    private static function readManager(mixed $value, string $path): ?array
    {
        if (null === $value) {
            return null;
        }

        return self::readFullManager($value, $path, 'manager');
    }

    /**
     * @return list<array{email: string, firstName: string, lastName: string, password: string}>
     */
    private static function readAdditionalManagers(mixed $value, string $path): array
    {
        if (!\is_array($value)) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : « additionalManagers » doit être une liste.', $path));
        }

        $managers = [];
        foreach (array_values($value) as $index => $entry) {
            $managers[] = self::readFullManager($entry, $path, \sprintf('additionalManagers[%d]', $index));
        }

        return $managers;
    }

    /**
     * @return list<array{firstName: string, lastName: string}>
     */
    private static function readCoaches(mixed $value, string $path): array
    {
        if (!\is_array($value)) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : « coaches » doit être une liste.', $path));
        }

        $coaches = [];
        foreach (array_values($value) as $index => $entry) {
            if (!\is_array($entry)) {
                throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : coaches[%d] doit être un objet.', $path, $index));
            }
            $coaches[] = [
                'firstName' => self::readString($entry['firstName'] ?? null, $path, \sprintf('coaches[%d].firstName', $index)),
                'lastName' => self::readString($entry['lastName'] ?? '', $path, \sprintf('coaches[%d].lastName', $index)),
            ];
        }

        return $coaches;
    }

    /**
     * @return array{email: string, firstName: string, lastName: string, password: string}
     */
    private static function readFullManager(mixed $value, string $path, string $field): array
    {
        if (!\is_array($value)) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : « %s » doit être un objet.', $path, $field));
        }

        return [
            'email' => self::readString($value['email'] ?? null, $path, $field . '.email'),
            'firstName' => self::readString($value['firstName'] ?? null, $path, $field . '.firstName'),
            'lastName' => self::readString($value['lastName'] ?? '', $path, $field . '.lastName'),
            'password' => self::readString($value['password'] ?? null, $path, $field . '.password'),
        ];
    }

    private static function readString(mixed $value, string $path, string $field): string
    {
        if (!\is_string($value)) {
            throw new RuntimeException(\sprintf('Fichier d\'identités local invalide (%s) : « %s » doit être une chaîne.', $path, $field));
        }

        return $value;
    }
}
