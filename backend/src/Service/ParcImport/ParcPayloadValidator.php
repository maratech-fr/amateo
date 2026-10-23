<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Service\ConstraintConfigValidator;
use JsonException;

/**
 * Chantier G — LE validateur du format `parc-v1` : le texte brut qu'un chatbot rend
 * et que le gestionnaire colle pour saisir tout son parc d'un coup (équipes, gymnases,
 * créneaux, mutualisations, coachs, réservations). C'est un ACCÉLÉRATEUR DE SAISIE, pas
 * la vérité : on refuse seulement ce qui ne peut pas être lu sans ambiguïté, et on NOMME
 * le fautif par élément (« équipe n°3 : genre inconnu ») pour que le gestionnaire corrige
 * dans l'aperçu sans jamais ouvrir le code.
 *
 * Décisions (cadrage G v2, 2026-10-23) :
 *  - `format` est un VERROU de version : seule « parc-v1 » est lue — une version inconnue
 *    (« parc-v2 », un ancien « socle-v1 ») est refusée, jamais lue « au mieux ».
 *  - Sections REQUISES (listes, éventuellement VIDES) : `equipes`, `gymnases`, `creneaux`,
 *    `mutualisations`, `reservations`. `coachs` est OPTIONNELLE (absente = aucun coach).
 *  - Champs HORS PÉRIMÈTRE (niveau, adresse, effectif, toute clé ou section inconnue) :
 *    IGNORÉS, jamais une erreur — le chatbot n'est pas sous notre contrôle et peut enrichir
 *    sa sortie ; on ne lit QUE la liste blanche, le reste tombe en silence.
 *
 * Patron {@see ConstraintConfigValidator} : le validateur POSSÈDE le décodage
 * (il prend le JSON BRUT), est PUR (aucune DB), et rend la liste des motifs de refus, VIDE
 * si le parc est recevable. L'appariement aux entités existantes vit, lui, dans l'analyzer.
 */
final class ParcPayloadValidator
{
    private const string FORMAT = 'parc-v1';

    /** Sections requises : présentes et listes (éventuellement vides). */
    private const array REQUIRED_SECTIONS = ['equipes', 'gymnases', 'creneaux', 'mutualisations', 'reservations'];

    private const array GENDERS = ['M', 'F', 'MIXTE'];

    /** Le nom HUMAIN de chaque section, pour préfixer un motif par le rang de l'élément. */
    private const array ITEM_LABELS = [
        'equipes' => 'équipe',
        'gymnases' => 'gymnase',
        'creneaux' => 'créneau',
        'reservations' => 'réservation',
        'coachs' => 'coach',
    ];

    /**
     * @return list<string> les motifs de refus, vide si le parc est recevable
     */
    public function errors(string $json): array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['Le contenu collé n\'est pas du JSON valide — collez le texte rendu par l\'assistant sans le modifier.'];
        }

        if (!\is_array($data)) {
            return ['Le contenu collé n\'est pas un parc ' . self::FORMAT . ' : un objet est attendu.'];
        }

        $errors = [];

        if (self::FORMAT !== ($data['format'] ?? null)) {
            // Le message NOMME « format » (cas absent) ET « parc-v1 » (cas version inconnue).
            $errors[] = 'Le champ « format » doit valoir « ' . self::FORMAT . ' ».';
        }

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (!\array_key_exists($section, $data)) {
                $errors[] = \sprintf('La section « %s » est requise.', $section);
                continue;
            }
            if (!\is_array($data[$section]) || !array_is_list($data[$section])) {
                $errors[] = \sprintf('La section « %s » doit être une liste.', $section);
            }
        }

        $errors = array_merge(
            $errors,
            $this->listErrors($data, 'equipes', $this->teamErrors(...)),
            $this->listErrors($data, 'gymnases', $this->venueErrors(...)),
            $this->listErrors($data, 'creneaux', $this->slotErrors(...)),
            $this->listErrors($data, 'reservations', $this->reservationErrors(...)),
        );

        // `coachs` est optionnelle : absente, aucun contrôle ; présente, elle doit être une
        // liste d'éléments nommés comme le reste.
        if (\array_key_exists('coachs', $data)) {
            if (!\is_array($data['coachs']) || !array_is_list($data['coachs'])) {
                $errors[] = 'La section « coachs » doit être une liste.';
            } else {
                $errors = array_merge($errors, $this->listErrors($data, 'coachs', $this->coachErrors(...)));
            }
        }

        return $errors;
    }

    /**
     * Applique un contrôle par élément à une section-liste, en préfixant chaque motif par le
     * rang HUMAIN (1-based) de l'élément — « équipe n°3 : … ». Une section absente ou mal
     * formée est ignorée ici : son motif de forme a déjà été relevé plus haut.
     *
     * @param array<string, mixed>          $data
     * @param callable(mixed): list<string> $check rend les motifs (sans le préfixe de rang)
     *
     * @return list<string>
     */
    private function listErrors(array $data, string $section, callable $check): array
    {
        $list = $data[$section] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            return [];
        }

        $label = self::ITEM_LABELS[$section];
        $errors = [];
        foreach ($list as $i => $item) {
            foreach ($check($item) as $motif) {
                $errors[] = \sprintf('%s n°%d : %s', $label, $i + 1, $motif);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function teamErrors(mixed $team): array
    {
        $errors = [];
        if (!$this->hasName($team)) {
            $errors[] = 'le nom est requis.';
        }
        if (\is_array($team) && \array_key_exists('genre', $team) && !\in_array($team['genre'], self::GENDERS, true)) {
            $errors[] = 'genre inconnu (attendu M, F ou MIXTE).';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function venueErrors(mixed $venue): array
    {
        return $this->hasName($venue) ? [] : ['le nom est requis.'];
    }

    /**
     * @return list<string>
     */
    private function slotErrors(mixed $slot): array
    {
        $slot = \is_array($slot) ? $slot : [];
        $errors = [];
        if (!$this->isWeekday($slot['jour'] ?? null)) {
            $errors[] = 'le jour doit être un entier de 1 à 7 (lundi = 1).';
        }
        if (!$this->isTime($slot['debut'] ?? null)) {
            $errors[] = 'l\'heure de début doit être au format HH:MM.';
        }
        if (!$this->isPositiveInt($slot['duree'] ?? null)) {
            $errors[] = 'la durée doit être un entier de minutes d\'au moins 1.';
        }
        if (\array_key_exists('equipes_en_parallele', $slot) && !$this->isParallelCount($slot['equipes_en_parallele'])) {
            $errors[] = 'le nombre d\'équipes en parallèle doit être de 1 à 3.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function reservationErrors(mixed $reservation): array
    {
        $r = \is_array($reservation) ? $reservation : [];
        $errors = [];
        if (!$this->isNonEmptyString($r['equipe'] ?? null)) {
            $errors[] = 'l\'équipe est requise.';
        }
        if (!$this->isNonEmptyString($r['gymnase'] ?? null)) {
            $errors[] = 'le gymnase est requis.';
        }
        if (!$this->isWeekday($r['jour'] ?? null)) {
            $errors[] = 'le jour doit être un entier de 1 à 7 (lundi = 1).';
        }
        if (!$this->isTime($r['debut'] ?? null)) {
            $errors[] = 'l\'heure de début doit être au format HH:MM.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function coachErrors(mixed $coach): array
    {
        return $this->hasName($coach) ? [] : ['le nom est requis.'];
    }

    private function hasName(mixed $item): bool
    {
        return \is_array($item) && $this->isNonEmptyString($item['nom'] ?? null);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return \is_string($value) && '' !== trim($value);
    }

    private function isWeekday(mixed $value): bool
    {
        // `is_int` strict : "2" passerait un test lâche puis casserait côté métier, qui
        // compare des entiers de jour.
        return \is_int($value) && $value >= 1 && $value <= 7;
    }

    private function isTime(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    private function isPositiveInt(mixed $value): bool
    {
        return \is_int($value) && $value >= 1;
    }

    private function isParallelCount(mixed $value): bool
    {
        return \is_int($value) && $value >= 1 && $value <= 3;
    }
}
