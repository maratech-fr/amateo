<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintScope;

/**
 * ⚠ Les messages de ce service sont AFFICHÉS TELS QUELS au gestionnaire (récap,
 * « À corriger avant de générer ») : FRANÇAIS, vocabulaire gestionnaire, jamais
 * de clé de config (minAtVenueId…). La carte de traduction frontend a été
 * SUPPRIMÉE (2026-08-05) après une dérive silencieuse — le backend est la
 * source unique du fond ET de la forme.
 */
final class ConstraintValidationService
{
    public function __construct(private readonly ConstraintConfigValidator $configValidator) {}

    /**
     * @return list<string>
     */
    public function validate(Constraint $constraint): array
    {
        // SEC-13 — LA FORME du config est jugée par le MÊME validateur qu'à
        // l'écriture. Ce service n'énonce plus ses propres règles de forme : deux
        // endroits qui disent presque la même chose finissent par diverger (sept
        // écarts mesurés sur le miroir de capacité, revue #341). Il reste ici ce
        // que l'écriture ne peut PAS voir : la cohérence entre champs d'une même
        // contrainte, les contradictions ENTRE contraintes, et l'état du club.
        // Utile malgré le 422 à l'écriture : les fixtures, imports et écritures
        // SQL directes n'y passent pas.
        $errors = $this->configValidator->errors($constraint->getFamily(), $constraint->getConfig());

        // Validate scope + scope_target_id consistency
        $scope = $constraint->getScope();
        $scopeTargetId = $constraint->getScopeTargetId();
        $family = $constraint->getFamily();
        $config = $constraint->getConfig();

        // Une fermeture datée de gymnase (`venue_closed`) porte son gymnase dans le
        // SCOPE, jamais dans le config — elle a donc son propre message de cible manquante
        // (plus bas), et le contrôle générique lui céderait un DOUBLON.
        $isVenueClosure = ConstraintFamily::FACILITY === $family && 'venue_closed' === ($config['type'] ?? null);

        if (ConstraintScope::CLUB !== $scope && null === $scopeTargetId && !$isVenueClosure) {
            $errors[] = 'Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.';
        }

        if (ConstraintScope::CLUB === $scope && null !== $scopeTargetId) {
            $errors[] = 'Une contrainte « toutes les équipes » ne doit pas cibler une équipe précise.';
        }

        switch ($family) {
            case ConstraintFamily::TIME:
                // maxEndTime = "finir avant X h" (l'engine calcule fin = début + durée).
                if (!isset($config['maxStartTime']) && !isset($config['minStartTime']) && !isset($config['maxEndTime'])) {
                    $errors[] = 'Une contrainte d\'horaire doit préciser au moins une heure (début au plus tôt, au plus tard, ou fin).';
                }
                // maxEndTime is honored by the engine ONLY on HARD/LOCK rules (the
                // soft path add_preferred_time_bonus reads only min/maxStartTime).
                // A PREFERRED end-bound would be accepted here yet silently ignored.
                if (isset($config['maxEndTime']) && !\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                    $errors[] = '« Fini avant » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire, sinon elle serait ignorée.';
                }
                break;

            case ConstraintFamily::DAY:
                if (!isset($config['allowedDays']) && !isset($config['forbiddenDays']) && !isset($config['forcedDays'])) {
                    $errors[] = 'Une contrainte de jour doit préciser au moins un jour (autorisé, à éviter ou imposé).';
                }
                // forcedDays (« au moins une séance l'un de ces jours ») n'est honoré par
                // l'engine QUE sur HARD/LOCK : les règles DAY ne sont collectées que pour ces
                // types (constraints.py), et le chemin soft ne lit que preferredDays
                // (objective.py) — un forcedDays PREFERRED serait un placebo muet. Même patron
                // que maxEndTime ci-dessus.
                if (isset($config['forcedDays']) && !\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                    $errors[] = 'La règle « au moins une séance » n\'existe qu\'en règle obligatoire.';
                }
                break;

            case ConstraintFamily::FACILITY:
                // Une fermeture datée (`venue_closed`) est un cas À PART : le gymnase fermé
                // vit dans `scopeTargetId`, PAS dans le config, et elle ne produit AUCUNE
                // ligne moteur (les créneaux du gymnase disparaissent du payload les jours
                // fermés — `VenueClosureDays` / `ScheduleConstraintBuilder`). Le gate ne peut
                // donc PAS bloquer la génération pour elle : parité gate == payload. Exiger
                // ici une clé de gymnase du config était un FAUX bloqueur (bug fondateur
                // 2026-08-19) tombant sur CHAQUE indisponibilité déclarée. On valide À LA
                // PLACE ce qui compte pour CE type : le gymnase (scope) et la cohérence des
                // dates si elles sont là.
                if ($isVenueClosure) {
                    if (null === $scopeTargetId) {
                        $errors[] = 'Une fermeture de gymnase doit désigner le gymnase concerné.';
                    }
                    // Dates cohérentes SI présentes : un config nu (donnée legacy) reste
                    // valide — `VenueClosureDays` ferme alors toute la fenêtre. Deux dates
                    // inversées, elles, ne ferment aucun jour (no-op silencieux) : on le dit.
                    $start = $config['startDate'] ?? null;
                    $end = $config['endDate'] ?? null;
                    if (\is_string($start) && \is_string($end) && $start > $end) {
                        $errors[] = 'La date de début de la fermeture doit précéder sa date de fin.';
                    }
                    break;
                }
                // A FACILITY rule names a VENUE via one of the keys the ENGINE actually
                // reads: forcedVenueId (must-be-at), preferredVenueId (soft/forced when
                // HARD), forbiddenVenueId (avoid), or minAtVenueId (au moins N séances
                // ici — un compte, pas un forçage). A bare `venueId` is honored by NO
                // engine branch, so it is not accepted here.
                if (!isset($config['forcedVenueId']) && !isset($config['forbiddenVenueId']) && !isset($config['preferredVenueId']) && !isset($config['minAtVenueId'])) {
                    $errors[] = 'Une contrainte de gymnase doit désigner un gymnase.';
                }
                // minAtVenueId ("au moins N ici") is honored by the engine ONLY as
                // a per-TEAM, HARD/LOCK count. A CLUB-scoped or PREFERRED one is
                // accepted nowhere in parse_v2_constraints → silently dropped.
                if (isset($config['minAtVenueId'])) {
                    if (!\in_array($constraint->getRuleType()->value, ['HARD', 'LOCK'], true)) {
                        $errors[] = '« Au moins N séances dans ce gymnase » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire.';
                    }
                    // Une équipe précise, OU un groupe (tag) : l'éclatement CLUB+targetTag
                    // produit des lignes PAR ÉQUIPE qui portent minAtVenueId — l'engine
                    // les lit (retour fondateur 2026-08-05 : le groupe est légitime).
                    // Seul « toutes les équipes » sans tag reste fermé : rien n'éclate,
                    // l'engine jette la ligne en silence.
                    if (ConstraintScope::TEAM !== $scope && !TeamTagResolver::targetsTags($config)) {
                        $errors[] = '« Au moins N séances dans ce gymnase » se pose sur une équipe ou un groupe — pas sur « toutes les équipes ».';
                    }
                }
                break;

            case ConstraintFamily::COACH_AVAILABILITY:
                // SEC-13 : la cible du coach est le SCOPE, plus une clé du config
                // (doublon exact retiré par Version20260807190000). `targetTag`
                // reste une cible légitime — il désigne un GROUPE de coachs.
                if (null === $constraint->getScopeTargetId() && !TeamTagResolver::targetsTags($config)) {
                    $errors[] = 'Une contrainte de disponibilité doit cibler un coach.';
                }
                // Lot C: optional time window (fromTime / untilTime, HH:MM). Absent = whole day.
                $from = $config['fromTime'] ?? null;
                $until = $config['untilTime'] ?? null;
                $bothValid = true;
                foreach (['fromTime' => $from, 'untilTime' => $until] as $key => $value) {
                    if (null === $value) {
                        continue;
                    }
                    if (!\is_string($value) || 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                        $errors[] = \sprintf('L\'heure « %s » doit être au format HH:MM.', 'fromTime' === $key ? 'à partir de' : 'jusqu\'à');
                        $bothValid = false;
                    }
                }
                // Only compare bounds once both parse as HH:MM — otherwise a
                // malformed "25:99" would emit a second, misleading "before" error.
                if ($bothValid && \is_string($from) && \is_string($until) && $from >= $until) {
                    $errors[] = 'L\'heure de début doit précéder l\'heure de fin.';
                }
                break;
        }

        // Validate rule type consistency
        $ruleType = $constraint->getRuleType();
        if ('LOCK' === $ruleType->value && ConstraintFamily::TIME !== $family && ConstraintFamily::DAY !== $family) {
            $errors[] = 'Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.';
        }

        return $errors;
    }

    /**
     * Fail-fast pre-generation check: "au moins N séances dans tel gymnase" is
     * provably impossible when N exceeds the team's weekly sessions. Surface it as
     * an ERROR BEFORE generation rather than a silent INFEASIBLE at solve time.
     * Returns an error string, or null when the rule is fine / not a venue-minimum.
     */
    public function venueMinimumError(Constraint $constraint, ?int $teamSessionsPerWeek): ?string
    {
        $config = $constraint->getConfig();
        if (ConstraintFamily::FACILITY !== $constraint->getFamily() || !isset($config['minAtVenueId'])) {
            return null;
        }

        $count = isset($config['minAtVenueCount']) ? (int) $config['minAtVenueCount'] : 1;
        if (null !== $teamSessionsPerWeek && $count > $teamSessionsPerWeek) {
            return \sprintf('Le minimum de %d séance(s) dans ce gymnase dépasse les %d séance(s)/semaine de l\'équipe — impossible.', $count, $teamSessionsPerWeek);
        }

        return null;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<array{constraint1: Constraint, constraint2: Constraint, reason: string}>
     */
    public function detectConflicts(array $constraints): array
    {
        $conflicts = [];
        $counter = \count($constraints);

        for ($i = 0; $i < $counter; ++$i) {
            for ($j = $i + 1; $j < \count($constraints); ++$j) {
                $c1 = $constraints[$i];
                $c2 = $constraints[$j];

                $conflict = $this->checkConflict($c1, $c2);
                if (null !== $conflict) {
                    $conflicts[] = [
                        'constraint1' => $c1,
                        'constraint2' => $c2,
                        'reason' => $conflict,
                    ];
                }
            }
        }

        return $conflicts;
    }

    private function checkConflict(Constraint $c1, Constraint $c2): ?string
    {
        $config1 = $c1->getConfig();
        $config2 = $c2->getConfig();

        // Two rules can only contradict if their TARGET SETS overlap. P2-29 D14 — the
        // verdict est CONSERVATEUR (sur-avertir, jamais sous-avertir) et se décide SANS
        // résoudre les équipes : deux cibles se recouvrent SAUF si les deux nomment un
        // ensemble de tags non vide et que ces NOMS sont disjoints. Un côté sans tag (tout
        // le club) ou un nom de tag partagé ⇒ recouvrement.
        //
        // ⚠ Les EXCLUSIONS ne comptent PAS contre la disjonction — corrigé après un faux
        // conflit BLOQUANT vécu sur le club réel : « Groupe EMB · pas après 17:30 » était
        // déclaré en contradiction avec « Groupe Adulte sauf Loisir adulte · pas avant
        // 18:50 », deux cibles pourtant sans une seule équipe commune. La raison est
        // mathématique : une exclusion ne peut que RÉTRÉCIR un ensemble — (A − X) ⊆ A et
        // (B − Y) ⊆ B, donc A ∩ B = ∅ implique (A−X) ∩ (B−Y) = ∅. Elle ne peut jamais
        // créer un recouvrement, seulement en supprimer un. Le conservatisme reste entier
        // dans l'autre sens : quand les tags SE PARTAGENT un nom, l'exclusion est ignorée
        // (elle pourrait retirer les équipes communes — on préfère l'avertissement de trop).
        $targets1 = TeamTagResolver::targetTagNames($config1);
        $targets2 = TeamTagResolver::targetTagNames($config2);
        $targetsOverlap = [] === $targets1
            || [] === $targets2
            || [] !== array_intersect($targets1, $targets2);

        if ($c1->getScopeTargetId() === $c2->getScopeTargetId()
            && $c1->getScope() === $c2->getScope()
            && $targetsOverlap
            && $c1->getFamily() === $c2->getFamily()
            && 'HARD' === $c1->getRuleType()->value
            && 'HARD' === $c2->getRuleType()->value
        ) {
            // Contradictory day constraints — checked BOTH ways (allowed on one side
            // forbidden on the other) so the verdict does not depend on array order.
            if (ConstraintFamily::DAY === $c1->getFamily()) {
                $allowed1 = $config1['allowedDays'] ?? [];
                $forbidden2 = $config2['forbiddenDays'] ?? [];
                $allowed2 = $config2['allowedDays'] ?? [];
                $forbidden1 = $config1['forbiddenDays'] ?? [];

                if (\count(array_intersect($allowed1, $forbidden2)) > 0
                    || \count(array_intersect($allowed2, $forbidden1)) > 0
                ) {
                    return 'Contradiction : un même jour est à la fois autorisé et interdit pour la même cible.';
                }
            }

            // Contradictory time constraints — symmetric: a max on either side below
            // the other side's min is impossible, regardless of iteration order.
            if (ConstraintFamily::TIME === $c1->getFamily()) {
                $max1 = $config1['maxStartTime'] ?? null;
                $min2 = $config2['minStartTime'] ?? null;
                $max2 = $config2['maxStartTime'] ?? null;
                $min1 = $config1['minStartTime'] ?? null;

                if ((null !== $max1 && null !== $min2 && $max1 < $min2)
                    || (null !== $max2 && null !== $min1 && $max2 < $min1)
                ) {
                    return 'Contradiction : l\'heure de début au plus tard est AVANT l\'heure de début au plus tôt pour la même cible.';
                }
            }
        }

        return null;
    }
}
