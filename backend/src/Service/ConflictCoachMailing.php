<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\ConflictCoachMailController;
use App\Controller\FixtureConflictsController;
use App\Entity\Coach;
use DateTimeImmutable;

/**
 * Envoi des conflits aux coachs — le service UNIQUE qui dit, pour le GET (compteurs du bouton,
 * {@see FixtureConflictsController}) comme pour l'aperçu et le POST (envoi,
 * {@see ConflictCoachMailController}), QUI reçoit QUOI, qui est encore à prévenir et, par conflit,
 * ce qui est nouveau ou modifié depuis le dernier envoi ({@see classify}). PUR : aucune lecture,
 * aucune écriture.
 *
 *  - Périmètre : les trois familles « personne sur un match » (`MATCH_MATCH`, `MATCH_TRAINING`,
 *    `MATCH_COACH_UNAVAILABLE`), traitées ou non ; une ligne tenue par un autre coach n'est jamais
 *    envoyée.
 *  - Destinataires d'un conflit : la personne + les coachs PRINCIPAUX de chaque équipe d'un côté ;
 *    pour un côté entraînement, de chaque équipe du groupe mutualisé du créneau DANS SA VERSION.
 *    Adjoints : non. Un coach supprimé (absent de `$coachesById`) ne reçoit rien.
 *  - Mémoire : voir {@see nextMemory}.
 */
final class ConflictCoachMailing
{
    /** Les familles qui portent une personne sur un match — les seules envoyées. */
    public const array MAILED_FAMILIES = ['MATCH_MATCH', 'MATCH_TRAINING', 'MATCH_COACH_UNAVAILABLE'];

    /** Une adresse que l'envoi accepte (le filet de forme, la saisie fait foi). */
    public static function hasValidAddress(Coach $coach): bool
    {
        $email = $coach->getEmail();

        return null !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL);
    }

    /**
     * L'empreinte du CONTENU qu'affiche la tuile du mail ({@see ConflictCoachMailBuilder}), en
     * valeurs affichées : deux conflits de même empreinte et de même digest se lisent pareil. Le
     * rôle d'un côté compte comme la tuile le dit (« joueur/joueuse » ou « coach » : un passage
     * principal → adjoint ne change rien) ; les deux côtés d'un `MATCH_MATCH` sont NON ORDONNÉS (à
     * début égal, l'ordre d'itération peut les permuter). Exclus : identifiants internes, fenêtres de
     * calcul, relief (déjà dans la gravité), traitement, empreinte ; les NOMS (équipe, gymnase,
     * coach) et le genre viennent des entités, pas du conflit.
     *
     * @param array<string, mixed> $conflict
     */
    public static function digest(array $conflict): string
    {
        $type = $conflict['type'] ?? null;
        $projection = [
            'type' => $type,
            'coachId' => $conflict['coachId'] ?? null,
            'severity' => $conflict['severity'] ?? null,
        ];

        if ('MATCH_COACH_UNAVAILABLE' === $type) {
            // Tuile d'indisponibilité : ni domicile/extérieur ni lieu affichés.
            $fixture = \is_array($conflict['fixture'] ?? null) ? $conflict['fixture'] : [];
            $projection['fixture'] = self::pick($fixture, ['teamId', 'matchDate', 'kickoffTime', 'estimatedKickoffTime', 'estimatedKickoff', 'opponentLabel']);

            return self::hash($projection);
        }

        $projection['start'] = $conflict['start'] ?? null;
        $projection['end'] = $conflict['end'] ?? null;
        $sides = [];
        foreach (['left', 'right', 'fixture'] as $key) {
            if (\is_array($conflict[$key] ?? null)) {
                $sides[$key] = self::canonicalJson(self::sideProjection($conflict[$key]));
            }
        }
        if ('MATCH_MATCH' === $type) {
            $sides = array_values($sides);
            sort($sides, \SORT_STRING);
        }
        $projection['sides'] = $sides;
        if (\is_array($conflict['training'] ?? null)) {
            $projection['training'] = self::pick($conflict['training'], ['teamId', 'venueId', 'startTime', 'durationMinutes']);
        }

        return self::hash($projection);
    }

    /**
     * Les reçus d'une liste envoyée : empreinte → digest, une ligne par empreinte.
     *
     * @param list<array<string, mixed>> $conflicts
     *
     * @return array<string, string>
     */
    public static function receiptRows(array $conflicts): array
    {
        $rows = [];
        foreach ($conflicts as $conflict) {
            $fingerprint = $conflict['fingerprint'] ?? null;
            if (\is_string($fingerprint) && '' !== $fingerprint) {
                $rows[$fingerprint] = self::digest($conflict);
            }
        }

        return $rows;
    }

    /**
     * Un côté de match tel que la tuile l'affiche — le rôle LU (« joueur/joueuse » ou « coach »).
     *
     * @param array<mixed> $side
     *
     * @return array<string, mixed>
     */
    private static function sideProjection(array $side): array
    {
        $role = $side['role'] ?? null;
        $projection = self::pick($side, ['teamId', 'homeAway', 'matchDate', 'kickoffTime', 'estimatedKickoffTime', 'estimatedKickoff', 'opponentLabel', 'opponentPlace', 'matchDurationMinutes', 'travelOneWayMinutes']);
        $projection['roleShown'] = \is_string($role) ? ('PLAYER' === $role ? 'PLAYER' : 'coach') : null;

        return $projection;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private static function pick(array $values, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $values[$key] ?? null;
        }

        return $out;
    }

    /** @param array<string, mixed> $projection */
    private static function hash(array $projection): string
    {
        return sha1(self::canonicalJson($projection));
    }

    /** JSON à clés TRIÉES (récursif) : deux projections égales donnent la même chaîne. */
    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortKeys($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }

    /**
     * @param list<array<string, mixed>>        $conflicts          le flux servi (lignes tenues comprises ou non)
     * @param array<string, list<string>>       $mainCoachIdsByTeam teamId → coachs principaux
     * @param array<string, list<list<string>>> $groupsBySchedule   version → groupes mutualisés déclarés
     * @param list<string>                      $memory             les coachs qui détiennent encore une liste
     * @param array<string, Coach>              $coachesById        les coachs qui existent encore
     *
     * @return array{byCoach: array<string, list<array<string, mixed>>>, cleared: list<string>, pendingRecipientCount: int, personConflictCount: int, recipientCount: int}
     */
    public function plan(array $conflicts, array $mainCoachIdsByTeam, array $groupsBySchedule, array $memory, array $coachesById): array
    {
        $byCoach = [];
        $personConflictCount = 0;
        foreach ($conflicts as $conflict) {
            if (!\in_array($conflict['type'] ?? null, self::MAILED_FAMILIES, true) || isset($conflict['heldBy'])) {
                continue;
            }
            ++$personConflictCount;

            $recipients = [];
            $person = $conflict['coachId'] ?? null;
            if (\is_string($person)) {
                $recipients[$person] = true;
            }
            foreach ($this->teamsOf($conflict, $groupsBySchedule) as $teamId) {
                foreach ($mainCoachIdsByTeam[$teamId] ?? [] as $mainId) {
                    $recipients[$mainId] = true;
                }
            }
            foreach (array_keys($recipients) as $coachId) {
                if (isset($coachesById[$coachId])) {
                    $byCoach[$coachId][] = $conflict;
                }
            }
        }

        $cleared = [];
        $pending = 0;
        foreach (array_values(array_unique($memory)) as $coachId) {
            if (isset($byCoach[$coachId]) || !isset($coachesById[$coachId])) {
                continue;
            }
            $cleared[] = $coachId;
            if (self::hasValidAddress($coachesById[$coachId])) {
                ++$pending;
            }
        }

        // Les coachs à adresse valide qui détiennent une liste ou sont à prévenir — servi par le GET
        // conflits (contrat inchangé) ; l'écran ne le lit plus : l'aperçu avant envoi compte, lui,
        // ce qui partira vraiment (nouveau ou modifié, ou tout renvoyé).
        $listed = 0;
        foreach (array_keys($byCoach) as $coachId) {
            if (self::hasValidAddress($coachesById[$coachId])) {
                ++$listed;
            }
        }

        return [
            'recipientCount' => $listed + $pending,
            'byCoach' => $byCoach,
            'cleared' => $cleared,
            'pendingRecipientCount' => $pending,
            'personConflictCount' => $personConflictCount,
        ];
    }

    /**
     * La règle de mémoire, unique : nouvelle mémoire = (ancienne ∪ coachs à qui une liste a été
     * enfilée) − coachs à qui « plus aucun conflit » a été enfilé − coachs supprimés. Un coach ne
     * sort que lorsqu'il a réellement été informé ; jamais de doublon.
     *
     * @param list<string>         $memory
     * @param list<string>         $enqueuedConflictLists
     * @param list<string>         $enqueuedCleared
     * @param array<string, Coach> $coachesById
     *
     * @return list<string>
     */
    public function nextMemory(array $memory, array $enqueuedConflictLists, array $enqueuedCleared, array $coachesById): array
    {
        $out = [];
        foreach ([...$memory, ...$enqueuedConflictLists] as $coachId) {
            if (\in_array($coachId, $enqueuedCleared, true) || !isset($coachesById[$coachId]) || \in_array($coachId, $out, true)) {
                continue;
            }
            $out[] = $coachId;
        }

        return $out;
    }

    /**
     * Nouveau / modifié / déjà envoyé, PAR COACH et par empreinte : nouveau = aucun reçu de cette
     * empreinte pour ce coach ; modifié = reçu dont le digest diffère du contenu courant ; inchangé =
     * reçu identique. Un coach sans AUCUN reçu (`receiptKnown` faux) n'a ni nouveau ni modifié : on
     * ne prétend pas savoir ce qu'il a reçu. Un conflit disparu n'est rien (il n'est pas listé).
     * `lastSentAt` = le plus récent des reçus des conflits COURANTS du coach (null sinon).
     *
     * @param array<string, list<array<string, mixed>>>                                      $byCoach
     * @param array<string, array<string, array{digest: string, sentAt: DateTimeImmutable}>> $receipts
     *
     * @return array<string, array{receiptKnown: bool, new: list<string>, changed: list<string>, unchanged: list<string>, lastSentAt: DateTimeImmutable|null}>
     */
    public function classify(array $byCoach, array $receipts): array
    {
        $out = [];
        foreach ($byCoach as $coachId => $conflicts) {
            $known = $receipts[$coachId] ?? [];
            $verdict = ['receiptKnown' => [] !== $known, 'new' => [], 'changed' => [], 'unchanged' => [], 'lastSentAt' => null];
            foreach (self::receiptRows($conflicts) as $fingerprint => $digest) {
                $receipt = $known[$fingerprint] ?? null;
                if ([] === $known) {
                    $verdict['unchanged'][] = $fingerprint;
                } elseif (null === $receipt) {
                    $verdict['new'][] = $fingerprint;
                } else {
                    $verdict[$receipt['digest'] === $digest ? 'unchanged' : 'changed'][] = $fingerprint;
                    if (!$verdict['lastSentAt'] instanceof DateTimeImmutable || $receipt['sentAt'] > $verdict['lastSentAt']) {
                        $verdict['lastSentAt'] = $receipt['sentAt'];
                    }
                }
            }
            $out[$coachId] = $verdict;
        }

        return $out;
    }

    /**
     * Les équipes des côtés d'un conflit ; un côté entraînement vaut pour toutes les équipes du
     * groupe mutualisé du créneau dans SA version.
     *
     * @param array<string, mixed>              $conflict
     * @param array<string, list<list<string>>> $groupsBySchedule
     *
     * @return list<string>
     */
    private function teamsOf(array $conflict, array $groupsBySchedule): array
    {
        $teams = [];
        foreach (['left', 'right', 'fixture'] as $key) {
            $side = $conflict[$key] ?? null;
            if (\is_array($side) && \is_string($side['teamId'] ?? null)) {
                $teams[] = $side['teamId'];
            }
        }

        $training = $conflict['training'] ?? null;
        if (\is_array($training) && \is_string($training['teamId'] ?? null)) {
            $teamId = $training['teamId'];
            $scheduleId = \is_string($training['scheduleId'] ?? null) ? $training['scheduleId'] : '';
            $teams[] = $teamId;
            foreach ($groupsBySchedule[$scheduleId] ?? [] as $group) {
                if (\in_array($teamId, $group, true)) {
                    array_push($teams, ...$group);
                }
            }
        }

        return array_values(array_unique($teams));
    }
}
