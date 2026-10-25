<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Service\ConstraintConfigValidator;

/**
 * Lot correctif import IA (« G2 ») PR1 — LE validateur du format `parc-v2`, qui REMPLACE parc-v1
 * (jamais déployé). Le gestionnaire colle le texte brut qu'un chatbot (GPT / Gemini / Claude /
 * Copilot) rend à partir de l'Excel / image / PDF de son club. parc-v2 n'a plus de sections
 * `equipes`/`creneaux`/`mutualisations`/`reservations` structurées : UNE LIGNE PAR CASE
 * (`{gymnase, jour, debut, duree?, equipes[], groupe?, terrains_separes?}`), et l'appli DÉDUIT
 * équipes / séances / genre / catégorie / mutualisations / réservations (cadrage G2 D1).
 *
 * Décisions (cadrage G2, 2026-10-25) :
 *  - `format` est un VERROU de version : seul « parc-v2 » est lu — « parc-v1 » (l'ancien) et toute
 *    autre version sont REFUSÉS en nommant « parc-v2 », jamais lus « au mieux » ;
 *  - `cases` est REQUISE (liste) ; `gymnases` est OPTIONNELLE (plusieurs chatbots réels l'omettent,
 *    l'appli déduit alors les gymnases des cases) ;
 *  - D4 normalisation de RÉCUPÉRATION (guillemets typographiques « “ ” ‘ ’ » + dé-habillage d'un
 *    bloc Markdown ```json) SEULEMENT si le parse BRUT échoue : un JSON déjà valide n'est JAMAIS
 *    retouché ({@see parse}) ;
 *  - plafond de {@see MAX_TEAMS_PER_CASE} équipes par case (bombe) : au-delà → refus nommant la case ;
 *  - champs / sections HORS PÉRIMÈTRE (une vieille section `coachs`, une clé inconnue par case,
 *    `groupe` / `terrains_separes` optionnels) : IGNORÉS, jamais une erreur.
 *
 * Patron {@see ConstraintConfigValidator} : le validateur POSSÈDE le décodage (il prend le JSON
 * BRUT), est PUR (aucune DB), et rend la liste des motifs de refus, VIDE si le parc est recevable.
 * La dérivation et l'appariement aux entités existantes vivent, eux, dans l'analyzer / l'executor.
 */
final class ParcPayloadValidator
{
    private const string FORMAT = 'parc-v2';

    /** Plafond d'équipes par case (bombe) — gardé côté prompt par PARC_V2_SPEC.maxTeamsPerCase. */
    private const int MAX_TEAMS_PER_CASE = 6;

    /**
     * @return list<string> les motifs de refus, vide si le parc est recevable
     */
    public function errors(string $json): array
    {
        $data = $this->parse($json);
        if (null === $data) {
            return ['Le contenu collé n\'est pas du JSON valide — collez le texte rendu par l\'assistant sans le modifier.'];
        }

        $errors = [];

        if (self::FORMAT !== ($data['format'] ?? null)) {
            // Le message NOMME « parc-v2 » (cas absent ET cas version inconnue, « parc-v1 » compris).
            $errors[] = 'Le champ « format » doit valoir « ' . self::FORMAT . ' ».';
        }

        if (!\array_key_exists('cases', $data)) {
            $errors[] = 'La section « cases » est requise.';

            return $errors;
        }
        if (!\is_array($data['cases']) || !array_is_list($data['cases'])) {
            $errors[] = 'La section « cases » doit être une liste.';

            return $errors;
        }

        foreach ($data['cases'] as $i => $case) {
            foreach ($this->caseErrors($case) as $motif) {
                $errors[] = \sprintf('case n°%d : %s', (int) $i + 1, $motif);
            }
        }

        // `gymnases` est optionnelle ; présente, elle doit être une liste de gymnases nommés.
        if (\array_key_exists('gymnases', $data)) {
            if (!\is_array($data['gymnases']) || !array_is_list($data['gymnases'])) {
                $errors[] = 'La section « gymnases » doit être une liste.';
            } else {
                foreach ($data['gymnases'] as $i => $venue) {
                    if (!$this->hasName($venue)) {
                        $errors[] = \sprintf('gymnase n°%d : le nom est requis.', (int) $i + 1);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Décode le JSON BRUT. La RÉCUPÉRATION (guillemets typographiques, dé-habillage d'un bloc
     * Markdown ```json) ne tourne QUE si le parse brut échoue — un JSON déjà valide est rendu TEL
     * QUEL (ses valeurs, guillemets typographiques compris, survivent intactes, D4). Rend le payload
     * décodé en tableau, ou null s'il reste illisible après récupération.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (\JSON_ERROR_NONE === json_last_error() && \is_array($decoded)) {
            return $decoded;
        }

        // Récupération : seulement maintenant que le parse brut a échoué.
        $recovered = $this->unwrapMarkdownFence($json);
        $recovered = strtr($recovered, ['“' => '"', '”' => '"', '‘' => '\'', '’' => '\'']);

        $decoded = json_decode($recovered, true);
        if (\JSON_ERROR_NONE === json_last_error() && \is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Retire un habillage Markdown ```json … ``` (ou ``` … ```) autour du JSON — un chatbot qui
     * désobéit à « rends UNIQUEMENT le JSON ». Sans habillage, la chaîne revient telle quelle.
     */
    private function unwrapMarkdownFence(string $text): string
    {
        $trimmed = trim($text);
        if (1 === preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $trimmed, $m)) {
            return $m[1];
        }

        return $text;
    }

    /**
     * @return list<string> les motifs (sans le préfixe de rang de case)
     */
    private function caseErrors(mixed $case): array
    {
        $c = \is_array($case) ? $case : [];
        $errors = [];

        if (!$this->isNonEmptyString($c['gymnase'] ?? null)) {
            $errors[] = 'le gymnase est requis.';
        }
        if (!$this->isWeekday($c['jour'] ?? null)) {
            $errors[] = 'le jour doit être un entier de 1 à 7 (lundi = 1).';
        }
        if (!$this->isTime($c['debut'] ?? null)) {
            $errors[] = 'l\'heure de début doit être au format HH:MM.';
        }
        // `duree` est OPTIONNELLE (défaut 90 déduit en aval) ; présente, un entier de minutes ≥ 1.
        if (\array_key_exists('duree', $c) && !$this->isPositiveInt($c['duree'])) {
            $errors[] = 'la durée doit être un entier de minutes d\'au moins 1.';
        }
        // `groupe` est OPTIONNEL (label d'un bloc mutualisé, ex. « CEC ») ; présent, un libellé texte.
        if (\array_key_exists('groupe', $c) && null !== $c['groupe'] && !\is_string($c['groupe'])) {
            $errors[] = 'le groupe doit être un libellé texte.';
        }

        $equipes = $c['equipes'] ?? null;
        if (null !== $equipes) {
            if (!\is_array($equipes) || !array_is_list($equipes)) {
                $errors[] = 'le champ « equipes » doit être une liste de noms.';
            } else {
                if (\count($equipes) > self::MAX_TEAMS_PER_CASE) {
                    $errors[] = \sprintf('%d équipes au maximum par case (%d).', self::MAX_TEAMS_PER_CASE, self::MAX_TEAMS_PER_CASE);
                }
                foreach ($equipes as $team) {
                    if (!$this->isNonEmptyString($team)) {
                        $errors[] = 'un nom d\'équipe est vide — retirez-le ou nommez l\'équipe.';
                        break;
                    }
                }
            }
        }

        return $errors;
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
        // `is_int` strict : "2" passerait un test lâche puis casserait côté métier, qui compare des
        // entiers de jour.
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
}
