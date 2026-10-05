<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConstraintFamily;

/**
 * SEC-13 — LA liste blanche du `config` d'une contrainte : noms ET types.
 *
 * Le `config` était le seul champ du formulaire sans aucune validation
 * (`Dto/ConstraintInput.php` : `?array`, zéro `Assert`, quand scope/family/
 * ruleType/scopeTargetId en avaient tous un). Mesuré le 2026-08-07 sur l'API
 * réelle : `{"maxStartTme":"19:00"}` (une lettre en moins) rendait **201**, la
 * contrainte s'affichait « Rien après 19h · HARD · active »… et le solveur
 * plaçait la séance à 20:00. Le gestionnaire distribue un planning en croyant
 * une règle appliquée ; elle n'existe pas. C'est le motif « déclaré ≠ effectif »
 * — le même que P4-44 ou le verrou SOFT placebo, sur l'objet le plus sensible.
 *
 * ⚠ CETTE LISTE VIENT DES LECTEURS, PAS DES DONNÉES. Un premier inventaire tiré
 * de la base a manqué `type`/`startDate`/`endDate` : aucune ligne ne les portait
 * ce jour-là, mais le cockpit en crée à chaque fermeture de gymnase
 * (`frontend/src/features/cockpit/queries.ts:257`). Livrer la liste déduite des
 * données aurait cassé ce geste au premier usage. Toute clé ajoutée ici doit
 * citer QUI la lit — et le job CI « Engine semantics » exige, pour les clés
 * moteur, la preuve qu'elles changent le résultat du solveur.
 *
 * Ce que la liste NE contient PAS, et pourquoi :
 * - `dateStart`/`dateEnd` : elles n'étaient lues que par `ConstraintSerializer`,
 *   une classe MORTE (aucun appelant, supprimée le 2026-08-08) — et de surcroît
 *   MAL NOMMÉES : le geste réel écrit `startDate`/`endDate` (fermeture de gymnase
 *   datée, cf. FACILITY). Deux mots inversés dans du code que personne n'appelait :
 *   rien ne pouvait le révéler. Zéro ligne en base ;
 * - `coachId` : doublon exact du scope, supprimé par `Version20260807190000` ;
 * - les alias snake_case (`forbidden_days`, `preferred_days`) : le moteur les
 *   lisait, ils sont retirés côté moteur dans la même PR — une seule orthographe
 *   partout (décision fondateur).
 */
final class ConstraintConfigValidator
{
    /** Une clé lisible par TOUTES les familles : le groupe d'équipes visé (legacy, un seul tag). */
    private const string TAG_KEY = 'targetTag';

    /** P2-29 — cibler PLUSIEURS groupes (INTERSECTION) ou en EXCLURE (UNION soustraite). */
    private const string TARGET_TAGS_KEY = 'targetTags';

    private const string EXCLUDE_TAGS_KEY = 'excludeTags';

    /**
     * famille => clé => type attendu.
     *
     * Types : `time` = HH:MM · `days` = liste d'entiers 1-7 (ISO, lundi=1) ·
     * `uuid` · `count` = entier ≥ 1 · `tag` = libellé non vide · `date` = Y-m-d ·
     * `closure` = la constante `venue_closed`.
     *
     * @var array<string, array<string, string>>
     */
    private const array SPEC = [
        // Lues par le moteur : `constraints.py` (fenêtres min/max) — `maxEndTime`
        // n'existe qu'en HARD, le chemin soft ne lit que min/maxStartTime.
        'TIME' => [
            'minStartTime' => 'time',
            'maxStartTime' => 'time',
            'maxEndTime' => 'time',
        ],
        // Lues par le moteur : `constraints.py` (règles DAY) + `objective.py`
        // (bonus préféré). ⚠ `allowedDays` est une WHITELIST (interdit tous les
        // autres jours), `forcedDays` veut dire « au moins une séance ces
        // jours-là » — deux sémantiques, deux clés (ENG-16).
        'DAY' => [
            'preferredDays' => 'days',
            'forbiddenDays' => 'days',
            'forcedDays' => 'days',
            'allowedDays' => 'days',
        ],
        // Lues par le moteur : `constraints.py` (règles de gymnase).
        // `type`/`startDate`/`endDate` sont BACKEND SEULS : une fermeture datée
        // (`VenueClosureDays`) ne produit aucune ligne de payload, elle ferme des
        // jours — le moteur ne les voit jamais.
        'FACILITY' => [
            'forcedVenueId' => 'uuid',
            'forbiddenVenueId' => 'uuid',
            'preferredVenueId' => 'uuid',
            'minAtVenueId' => 'uuid',
            'minAtVenueCount' => 'count',
            'type' => 'closure',
            'startDate' => 'date',
            'endDate' => 'date',
        ],
        // Lues par le moteur : `constraints.py` (jours + fenêtre horaire, lot C).
        // La CIBLE est le scope, jamais une clé d'ici (SEC-13 PR B).
        'COACH_AVAILABILITY' => [
            'unavailableDays' => 'days',
            'availableDays' => 'days',
            'fromTime' => 'time',
            'untilTime' => 'time',
        ],
    ];

    /** La seule valeur que `type` accepte — cf. `VenueClosureDays::isVenueClosure`. */
    private const string CLOSURE_TYPE = 'venue_closed';

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string> les motifs de refus, vide si le config est recevable
     */
    public function errors(ConstraintFamily $family, array $config): array
    {
        $allowed = self::SPEC[$family->value];
        $errors = [];

        foreach ($config as $key => $value) {
            $key = (string) $key;

            // ALIGN-21 — le ciblage par groupe (`targetTag`/`targetTags`/`excludeTags`) ne
            // résout QUE des lignes TEAM (`ScheduleConstraintBuilder::serializeUnifiedConstraints`,
            // le tag → teamIds, sur scope CLUB) : posé sur une disponibilité de COACH il serait
            // ACCEPTÉ puis sans aucune ligne moteur ni avertissement — le motif « déclaré ≠
            // effectif ». On le refuse à l'écriture : une disponibilité vise un coach (le scope).
            if (ConstraintFamily::COACH_AVAILABILITY === $family
                && \in_array($key, [self::TAG_KEY, self::TARGET_TAGS_KEY, self::EXCLUDE_TAGS_KEY], true)) {
                $errors[] = \sprintf('« %s » ne peut pas cibler une contrainte de disponibilité : une disponibilité vise un coach précis, pas un groupe.', $key);
                continue;
            }

            if (self::TAG_KEY === $key) {
                if (!\is_string($value) || '' === trim($value)) {
                    $errors[] = \sprintf('« %s » attend un libellé de groupe non vide.', $key);
                }
                continue;
            }

            // P2-29 : `targetTags`/`excludeTags` = une LISTE de libellés de groupe non vides.
            if (self::TARGET_TAGS_KEY === $key || self::EXCLUDE_TAGS_KEY === $key) {
                if (!$this->isNonEmptyStringList($value)) {
                    $errors[] = \sprintf('« %s » attend une liste non vide de libellés de groupe.', $key);
                }
                continue;
            }

            if (!isset($allowed[$key])) {
                // Le message NOMME la clé ET la famille : une faute de frappe se
                // corrige en la lisant, sans aller ouvrir le code.
                $errors[] = \sprintf(
                    '« %s » n\'est pas un réglage connu pour une contrainte %s. Réglages acceptés : %s.',
                    $key,
                    $family->value,
                    implode(', ', array_keys($allowed)),
                );
                continue;
            }

            $error = $this->typeError($key, $allowed[$key], $value);
            if (null !== $error) {
                $errors[] = $error;
            }
        }

        return array_merge($errors, $this->tagFormErrors($config));
    }

    /**
     * Les clés acceptées pour une famille — lu par le test de parité moteur.
     *
     * @return list<string>
     */
    public function allowedKeys(ConstraintFamily $family): array
    {
        return array_keys(self::SPEC[$family->value]);
    }

    /**
     * Les clés que le MOTEUR doit lire, famille par famille — c'est-à-dire la
     * liste blanche MOINS ce qui est backend-seul. Le job CI « Engine semantics »
     * s'en sert pour exiger, de chacune, la preuve qu'elle change le résultat.
     *
     * @return array<string, list<string>>
     */
    /**
     * Les clés de `config` qui portent un UUID d'entité, toutes familles confondues.
     *
     * ⚑ Deux listes manuscrites les recopiaient, et les deux avaient dérivé (audit D-08) :
     *  - `SeasonTransitionService::CONFIG_ID_KEYS` **oubliait `forcedVenueId` et
     *    `minAtVenueId`** — une contrainte « impose ce gymnase » recopiée en saison N+1
     *    gardait l'uuid du gymnase de l'ANCIENNE saison, `$configDangling` restant `false` :
     *    aucun skip, aucun log, un pointeur mort en base ;
     *  - `ScheduleConstraintBuilder::VENUE_CONFIG_KEYS` portait `setVenueId`, une clé
     *    FANTÔME (aucun writer, aucun reader, refusée à l'écriture depuis SEC-13).
     *
     * La liste se dérive donc du `SPEC`, seule source qui décide ce qu'un `config` accepte.
     *
     * @return list<string>
     */
    public function uuidKeys(): array
    {
        $keys = [];
        foreach (self::SPEC as $spec) {
            foreach ($spec as $key => $type) {
                if ('uuid' === $type) {
                    $keys[$key] = true;
                }
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, list<string>>
     */
    public function engineKeysByFamily(): array
    {
        $backendOnly = ['type', 'startDate', 'endDate'];
        $out = [];
        foreach (self::SPEC as $family => $keys) {
            $out[$family] = array_values(array_diff(array_keys($keys), $backendOnly));
        }

        return $out;
    }

    /**
     * P2-29 D7/D10 — la COHÉRENCE des clés de ciblage par tag (forme seule, sans DB) :
     *
     * - D7 : `targetTag` (ancienne forme, un seul tag) et `targetTags`/`excludeTags`
     *   (nouvelle forme) ne coexistent JAMAIS — sinon deux façons de dire la cible dans
     *   un même config, et une ambiguïté silencieuse sur laquelle fait foi.
     * - D10 : un même groupe ciblé ET exclu (`targetTags ∩ excludeTags`) se contredit.
     *
     * La cible vide contre la saison (tag inconnu, résolution nulle) est un refus DB : il
     * vit dans le state processor, qui a le club et la saison.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function tagFormErrors(array $config): array
    {
        $errors = [];

        $hasSingular = \array_key_exists(self::TAG_KEY, $config);
        $hasNewForm = \array_key_exists(self::TARGET_TAGS_KEY, $config) || \array_key_exists(self::EXCLUDE_TAGS_KEY, $config);
        if ($hasSingular && $hasNewForm) {
            $errors[] = \sprintf(
                'Un même réglage ne peut pas mélanger « %s » (ancienne forme) et « %s »/« %s » : choisissez l\'une ou l\'autre.',
                self::TAG_KEY,
                self::TARGET_TAGS_KEY,
                self::EXCLUDE_TAGS_KEY,
            );
        }

        $overlap = array_values(array_intersect(
            $this->stringList($config[self::TARGET_TAGS_KEY] ?? null),
            $this->stringList($config[self::EXCLUDE_TAGS_KEY] ?? null),
        ));
        if ([] !== $overlap) {
            $errors[] = \sprintf('Un même groupe ne peut pas être à la fois ciblé et exclu : %s.', implode(', ', $overlap));
        }

        return $errors;
    }

    private function isNonEmptyStringList(mixed $value): bool
    {
        if (!\is_array($value) || [] === $value || array_keys($value) !== range(0, \count($value) - 1)) {
            return false;
        }
        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les libellés de groupe NON vides d'une valeur brute (ignore le reste) — pour comparer
     * `targetTags` et `excludeTags` sans dépendre de leur validité de forme.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function typeError(string $key, string $type, mixed $value): ?string
    {
        return match ($type) {
            'time' => $this->isTime($value) ? null : \sprintf('« %s » attend une heure au format HH:MM.', $key),
            'days' => $this->isDayList($value) ? null : \sprintf('« %s » attend une liste de jours (entiers de 1 à 7, lundi = 1).', $key),
            'uuid' => $this->isUuid($value) ? null : \sprintf('« %s » attend l\'identifiant d\'un gymnase.', $key),
            'count' => $this->isCount($value) ? null : \sprintf('« %s » attend un nombre entier d\'au moins 1.', $key),
            'date' => $this->isDate($value) ? null : \sprintf('« %s » attend une date au format AAAA-MM-JJ.', $key),
            'closure' => self::CLOSURE_TYPE === $value ? null : \sprintf('« %s » n\'accepte que « %s ».', $key, self::CLOSURE_TYPE),
            default => null,
        };
    }

    private function isTime(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    private function isDayList(mixed $value): bool
    {
        if (!\is_array($value) || [] === $value || array_keys($value) !== range(0, \count($value) - 1)) {
            return false;
        }
        foreach ($value as $day) {
            // `is_int` strict : "3" passerait un `in_array` lâche puis casserait
            // côté moteur, qui compare des entiers.
            if (!\is_int($day) || $day < 1 || $day > 7) {
                return false;
            }
        }

        return true;
    }

    private function isUuid(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function isCount(mixed $value): bool
    {
        return \is_int($value) && $value >= 1;
    }

    private function isDate(mixed $value): bool
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
