<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * P2-14 — LA source unique de « quelles contraintes partent au solveur pour cette
 * période ». Avant, la réponse vivait en DEUX exemplaires entretenus à la main :
 * `ScheduleConstraintBuilder::buildForPeriodPlan` (le payload) et
 * `ValidateConstraintsController::constraintsForPeriod` (le gate pré-solve) — dont les
 * commentaires assumaient l'aveu (« miroir EXACT du filtre gymnases »). Deux endroits
 * qui répondent à la même question, c'est le motif qui a produit 40 défauts en 4 rounds
 * sur la bascule ADR-0002 ; ici il avait déjà produit deux divergences réelles, alignées
 * par cette classe :
 *
 * - une contrainte DATÉE visant une équipe désactivée restait validée par le gate alors
 *   que le payload la filtrait (le gate ne filtrait que les permanentes) ;
 * - une contrainte CLUB+tag HARD à gymnase dédié dont toutes les équipes taguées sont en
 *   pause était sortie du gate, alors que le payload émet encore ses lignes « interdit
 *   hors tag » pour les autres équipes.
 *
 * La sélection opère sur les ENTITÉS — ce dont le gate a besoin (`validate()`,
 * `detectConflicts()`, ids d'erreurs). Le builder sérialise ensuite `kept` et garde ses
 * post-filtres sur lignes SÉRIALISÉES en défense en profondeur (ils attrapent les
 * expansions par équipe, invisibles au niveau entité).
 */
final class PeriodConstraintSelector
{
    private const TAG_KEEP = 'keep';

    private const TAG_DROP_DISABLED_VENUE = 'drop_disabled_venue';

    private const TAG_DROP_INERT = 'drop_inert';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConstraintRepository $constraintRepository,
        private readonly TeamRepository $teamRepository,
        private readonly TeamTagResolver $tagResolver,
        private readonly PlanVenueClosures $planVenueClosures,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<Team>|null $clubSeasonTeams les équipes du club/saison si l'appelant
     *                                         les a DÉJÀ chargées (le builder les charge
     *                                         pour le payload) — évite une requête doublon
     */
    public function selectForPeriodPlan(string $clubId, string $seasonId, string $schedulePlanId, CalendarEntry $entry, ?array $clubSeasonTeams = null): PeriodConstraintSelection
    {
        $periodType = $entry->getPeriodType();
        if (!\in_array($periodType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            throw new LogicException('Period constraint selection supports only closure and holiday periods.');
        }

        // P2-59 — modèle FAIT/GENÈSE : un plan lit SES genèses (pendues à l'entrée-enfant)
        // ∪ les faits de SA mère (source unique CalendarEntry::datedConstraintSourceIds).
        // Une racine lit ses seules datées. findBy avec une liste → IN.
        /** @var list<Constraint> $dated */
        $dated = $this->constraintRepository->findBy(['calendarEntryId' => $entry->datedConstraintSourceIds(), 'clubId' => $clubId]);

        $overrides = [];
        foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            $overrides[$override->getConstraintId()] = $override->isActive();
        }

        $deactivatedTeamIds = [];
        $sessionOverrides = [];
        foreach ($this->entityManager->getRepository(TeamPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $teamOverride) {
            if (!$teamOverride->isActive()) {
                $deactivatedTeamIds[$teamOverride->getTeamId()] = true;
            }
            if (null !== $teamOverride->getSessionsPerWeek()) {
                $sessionOverrides[$teamOverride->getTeamId()] = $teamOverride->getSessionsPerWeek();
            }
        }

        // L'ÉTAT EFFECTIF (gymnases désactivés + jours effectivement fermés) vient de la MAISON
        // UNIQUE `PlanVenueClosures` : le gate et le payload le partagent PAR CONSTRUCTION via la
        // sélection, jamais deux calculs. `disabledVenueIds` (mode DISABLED) reste ce qui SORT un
        // gymnase entier ; les jours fermés composés (incident × masque) servent au filtre créneaux.
        $effectiveState = $this->planVenueClosures->effectiveStateForEntry($entry, $schedulePlanId);
        $disabledVenueIds = $effectiveState['disabledVenueIds'];
        $effectiveClosedWeekdaysByVenue = $effectiveState['effectiveClosedWeekdaysByVenue'];

        $activeTeamIds = [];
        $allSeasonTeamIds = [];
        foreach ($clubSeasonTeams ?? $this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $allSeasonTeamIds[] = $team->getId();
            if (!isset($deactivatedTeamIds[$team->getId()])) {
                $activeTeamIds[$team->getId()] = true;
            }
        }

        // Fermeture : TOUT hérité par défaut. Reprise : défaut intelligent qui suit la
        // sélection d'équipes — les FACILITY sont droppées (la période possède sa grille).
        // Un override explicite dévie du défaut dans les deux sens.
        $permanent = [];
        foreach ($this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId) as $constraint) {
            $keepByDefault = CalendarEntryPeriodType::CLOSURE === $periodType || ConstraintScope::FACILITY !== $constraint->getScope();
            if (\array_key_exists($constraint->getId(), $overrides) ? $overrides[$constraint->getId()] : $keepByDefault) {
                $permanent[] = $constraint;
            }
        }

        $kept = [];
        $droppedForDisabledVenue = [];
        $droppedForInertTag = [];
        $partiallyAppliedForDisabledVenue = [];
        foreach ([...$permanent, ...$dated] as $constraint) {
            // Équipe désactivée : sa contrainte TEAM ne produit aucune ligne — permanente
            // OU datée (le gate ne filtrait que les permanentes : divergence alignée ici).
            if (ConstraintScope::TEAM === $constraint->getScope() && isset($deactivatedTeamIds[$constraint->getScopeTargetId() ?? ''])) {
                continue;
            }

            // Une CLUB+targetTag a son propre verdict : ses LIGNES ne portent pas toutes
            // la config d'origine (les « interdit hors tag » la remplacent), donc ni le
            // filtre gymnase ni le filtre équipe ci-dessus ne disent seuls si elle émet
            // encore quelque chose (revue #340 round 1 — un drop entité aveugle effaçait
            // l'exclusivité de gymnase dédié quand une clé SECONDAIRE visait un gymnase
            // désactivé, un cas que le post-filtre par ligne préservait).
            $config = $constraint->getConfig();
            if (ConstraintScope::CLUB === $constraint->getScope() && TeamTagResolver::targetsTags($config)) {
                switch ($this->clubTagVerdict($constraint, $clubId, $seasonId, $activeTeamIds, $disabledVenueIds, $allSeasonTeamIds)) {
                    case self::TAG_KEEP:
                        $kept[] = $constraint;
                        // KEEP ne veut pas dire INTACTE (revue #340 round 2) : si ses lignes
                        // PAR ÉQUIPE meurent parce qu'une clé de config vise un gymnase
                        // désactivé, seule l'exclusivité du gymnase dédié survit — l'ancien
                        // gate avertissait ce cas, on ne le laisse pas redevenir muet.
                        $partialVenueId = $this->disabledVenueNamedBy($constraint, $disabledVenueIds);
                        if (null !== $partialVenueId) {
                            $partiallyAppliedForDisabledVenue[] = ['constraint' => $constraint, 'venueId' => $partialVenueId];
                        }
                        break;
                    case self::TAG_DROP_DISABLED_VENUE:
                        $droppedForDisabledVenue[] = ['constraint' => $constraint, 'venueId' => (string) $this->disabledVenueNamedBy($constraint, $disabledVenueIds)];
                        break;
                    default: // TAG_DROP_INERT
                        // Miroir du log que le builder émettait à la sérialisation — l'entité
                        // ne l'atteignant plus, le silence reviendrait sans lui (revue #340).
                        $this->logger->warning('Tag targeting "{label}" resolves to no active team — constraint {id} dropped from the period selection.', [
                            'label' => TeamTagResolver::tagTargetLabel($config),
                            'id' => $constraint->getId(),
                        ]);
                        // Une DATÉE inerte est un geste explicite du gestionnaire POUR cette
                        // période : la faire disparaître en silence est le motif que le gate
                        // combat (#8, « avertir plutôt que disparaître »). Une permanente
                        // héritée reste silencieuse — comme le gate l'a toujours fait.
                        if (null !== $constraint->getCalendarEntryId()) {
                            $droppedForInertTag[] = $constraint;
                        }
                }

                continue;
            }

            // Gymnase désactivé nommé (scope FACILITY, ou une clé de config) : la
            // contrainte ne partira pas — le gestionnaire en est AVERTI, pas mis devant
            // un silence (raison exposée, le gate en fait son warning).
            $disabledVenueId = $this->disabledVenueNamedBy($constraint, $disabledVenueIds);
            if (null !== $disabledVenueId) {
                $droppedForDisabledVenue[] = ['constraint' => $constraint, 'venueId' => $disabledVenueId];

                continue;
            }
            $kept[] = $constraint;
        }

        return new PeriodConstraintSelection(
            schedulePlanId: $schedulePlanId,
            kept: $kept,
            droppedForDisabledVenue: $droppedForDisabledVenue,
            droppedForInertTag: $droppedForInertTag,
            partiallyAppliedForDisabledVenue: $partiallyAppliedForDisabledVenue,
            dated: $dated,
            disabledVenueIds: $disabledVenueIds,
            effectiveClosedWeekdaysByVenue: $effectiveClosedWeekdaysByVenue,
            deactivatedTeamIds: $deactivatedTeamIds,
            sessionOverrides: $sessionOverrides,
        );
    }

    /**
     * Le verdict d'une CLUB+targetTag, calqué sur les LIGNES que le builder émettrait —
     * pas sur l'entité, car ses lignes n'ont pas toutes la même config :
     *
     * - lignes PAR ÉQUIPE (config d'origine, moins le tag) : elles survivent s'il reste
     *   une équipe taguée ACTIVE et qu'aucune clé de config ne vise un gymnase désactivé ;
     * - lignes « INTERDIT HORS TAG » (HARD + gymnase dédié ; config REMPLACÉE par
     *   `forbiddenVenueId` = le dédié) : elles survivent si le gymnase DÉDIÉ n'est pas
     *   désactivé — même si une clé secondaire l'est, ou si toutes les taguées sont en
     *   pause (divergence n° 2 alignée).
     *
     * Tag inconnu ou résolution vide : le builder saute la contrainte entière (aucune ligne).
     *
     * P2-29 : passe par le MÊME foyer que le builder (`resolveConstraintTeamIds`) — cibles
     * multiples (intersection) et exclusions comprises, D13 (l'exclusivité de gymnase dédié
     * s'applique au set résolu FINAL).
     *
     * @param array<string, true> $activeTeamIds
     * @param array<string, true> $disabledVenueIds
     * @param list<string>        $allSeasonTeamIds base D8 (exclusion sans cible)
     */
    private function clubTagVerdict(Constraint $constraint, string $clubId, string $seasonId, array $activeTeamIds, array $disabledVenueIds, array $allSeasonTeamIds): string
    {
        $tagTeamIds = $this->tagResolver->resolveConstraintTeamIds($constraint->getConfig(), $seasonId, $clubId, $allSeasonTeamIds);
        $hasActiveTagged = false;
        foreach ($tagTeamIds as $teamId) {
            if (isset($activeTeamIds[$teamId])) {
                $hasActiveTagged = true;
                break;
            }
        }

        $config = $constraint->getConfig();
        $perTeamRowsSurvive = [] !== $tagTeamIds && $hasActiveTagged && null === $this->disabledVenueNamedBy($constraint, $disabledVenueIds);

        // Les lignes « interdit hors tag » n'existent que s'il reste une équipe active HORS
        // du tag (le builder itère les actives en sautant les taguées — revue #340 round 2 :
        // un tag couvrant TOUTES les actives gardait une entité à zéro ligne).
        $tagTeamIdSet = array_flip($tagTeamIds);
        $hasActiveNonTagged = false;
        foreach (array_keys($activeTeamIds) as $teamId) {
            if (!isset($tagTeamIdSet[$teamId])) {
                $hasActiveNonTagged = true;
                break;
            }
        }

        $dedicatedVenueId = $config['forcedVenueId'] ?? $config['preferredVenueId'] ?? null;
        $forbiddenRowsSurvive = [] !== $tagTeamIds
            && $hasActiveNonTagged
            && ConstraintRuleType::HARD === $constraint->getRuleType()
            && \is_string($dedicatedVenueId) && '' !== $dedicatedVenueId
            && !isset($disabledVenueIds[$dedicatedVenueId]);

        if ($perTeamRowsSurvive || $forbiddenRowsSurvive) {
            return self::TAG_KEEP;
        }

        // Plus aucune ligne. La raison à annoncer : un gymnase désactivé nommé (le gate
        // avertissait déjà ce cas), sinon l'inertie du tag.
        return null !== $this->disabledVenueNamedBy($constraint, $disabledVenueIds)
            ? self::TAG_DROP_DISABLED_VENUE
            : self::TAG_DROP_INERT;
    }

    /**
     * L'id du gymnase désactivé que cette contrainte NOMME, ou null. Les deux façons de
     * nommer un gymnase sont celles du builder : le scope FACILITY, et les clés de config
     * de `ScheduleConstraintBuilder::VENUE_CONFIG_KEYS` (source unique).
     *
     * @param array<string, true> $disabledVenueIds
     */
    private function disabledVenueNamedBy(Constraint $constraint, array $disabledVenueIds): ?string
    {
        $scopeTargetId = $constraint->getScopeTargetId();
        if (ConstraintScope::FACILITY === $constraint->getScope() && \is_string($scopeTargetId) && isset($disabledVenueIds[$scopeTargetId])) {
            return $scopeTargetId;
        }

        $config = $constraint->getConfig();
        foreach (ScheduleConstraintBuilder::VENUE_CONFIG_KEYS as $venueKey) {
            $venueId = $config[$venueKey] ?? null;
            if (\is_string($venueId) && isset($disabledVenueIds[$venueId])) {
                return $venueId;
            }
        }

        return null;
    }
}
