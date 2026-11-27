<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Constraint;
use App\Entity\PlanConstraint;
use App\Entity\PlanTeam;
use App\Entity\PlanVenue;
use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ConstraintScope;
use App\Enum\PlanTeamDefault;
use App\Enum\SchedulePlanType;
use App\Service\PlanTeamSelectionSeeder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Les équipes d'un plan (lot 4, PR 3a) : toute équipe a sa ligne dans chaque plan de sa saison, socle compris
 * ({@see PlanTeam}). Les gymnases d'une période (PR 3b) : tout gymnase a sa ligne dans chaque plan de période de sa
 * saison, aucune au plan de saison ({@see PlanVenue}). Les règles appliquées par une période (PR 4) : toute contrainte
 * permanente a sa ligne dans chaque plan de période de sa saison, aucune au plan de saison ni pour une datée
 * ({@see PlanConstraint}). Cette classe est la seule porte qui pose les lignes ({@see ensureLines}) et la seule qui dit
 * qu'une ligne de période « diffère du socle » (équipes) ou « du défaut » (gymnases, règles).
 *
 * Lire un plan où une ligne manque lève : jamais un défaut silencieux.
 *
 * SQL brut pour les lectures de structure : le plan, les équipes et les lignes d'une saison se lisent hors du
 * `season_filter` (la saison visée n'est pas forcément l'active de la requête) ; la RLS borne le club.
 */
final class PlanComposition
{
    /**
     * DQL : la ligne `e` (d'un plan de période) diffère de la ligne du socle de son équipe — elle ne participe pas,
     * ou ses séances ou son plafond ne sont pas ceux du socle du moment. Maison unique de la règle, lue aussi par la
     * cascade de suppression d'une équipe (le compte de la fenêtre).
     */
    public const string DIFFERS_FROM_SOCLE_DQL = 'EXISTS (SELECT 1 FROM App\Entity\PlanTeam socleLine, App\Entity\SchedulePlan soclePlan'
        . ' WHERE soclePlan.id = socleLine.schedulePlanId AND soclePlan.type = \'SEASON\' AND socleLine.teamId = e.teamId'
        . ' AND socleLine.schedulePlanId <> e.schedulePlanId'
        . ' AND (e.participates = false OR e.sessionsPerWeek <> socleLine.sessionsPerWeek OR e.maxSessionsPerDay <> socleLine.maxSessionsPerDay))';

    /**
     * DQL : la ligne de gymnase `e` diffère du défaut — le gymnase ne participe pas, l'effet de fermeture est levé, ou
     * un masque de jours est posé. Maison unique de la règle, lue par la route des réglages de gymnase et par la
     * cascade de suppression d'un gymnase (le compte de la fenêtre).
     */
    public const string VENUE_DIFFERS_FROM_DEFAULT_DQL = '(e.participates = false OR e.closureLifted = true OR e.dayOverrides IS NOT NULL)';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InForce $inForce,
    ) {}

    /**
     * Pose, dans chaque plan de la saison, les lignes qui manquent ; ne touche jamais une ligne existante. Écrit ce
     * qui attend d'être écrit avant de lire, puis écrit les lignes posées.
     *
     * - Ligne du socle : participe, aux valeurs données par le créateur pour cette équipe, sinon aux valeurs par
     *   défaut d'une équipe ({@see PlanTeam::DEFAULT_SESSIONS_PER_WEEK}).
     * - Ligne de période : les séances et le plafond du socle du moment ; elle participe, sauf à la naissance du plan
     *   (aucune ligne encore) où la nature dit qui est coché ({@see SchedulePlanType::defaultTeamSelection}).
     * - Ligne de gymnase, dans chaque plan de période seulement : participe, fermeture non levée, sans masque.
     * - Ligne de règle, dans chaque plan de période seulement et pour chaque contrainte permanente : le défaut de la
     *   nature du plan pour la portée de la règle ({@see SchedulePlanType::keepsSocleConstraintByDefault}).
     *
     * @param array<string, array{sessionsPerWeek?: int, maxSessionsPerDay?: int}> $socleValues par équipe : ce que le créateur a saisi
     */
    public function ensureLines(string $seasonId, array $socleValues = []): void
    {
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        /** @var list<array{id: string, type: string, club_id: string}> $plans */
        $plans = $connection->fetchAllAssociative(
            'SELECT id, type, club_id FROM schedule_plan WHERE season_id = :s ORDER BY (type = :season) DESC, id',
            ['s' => $seasonId, 'season' => SchedulePlanType::SEASON->value],
        );
        if ([] === $plans) {
            return;
        }
        $wrote = $this->ensureVenueLines($seasonId, $plans);
        $wrote = $this->ensureRuleLines($seasonId, $plans) || $wrote;
        // Les équipes de la saison, lues par la porte de l'en vigueur (triées par id).
        $teams = array_map(
            static fn (Team $team): array => ['id' => $team->getId(), 'club_id' => $team->getClubId(), 'priority_tier_id' => $team->getPriorityTierId()],
            $this->inForce->rows($seasonId, Team::class),
        );
        if ([] === $teams) {
            if ($wrote) {
                $this->entityManager->flush();
            }

            return;
        }

        /** @var array<string, array<string, array{sessions: int, max: int}>> $lines plan => équipe => valeurs */
        $lines = [];
        foreach ($connection->fetchAllAssociative('SELECT schedule_plan_id, team_id, sessions_per_week, max_sessions_per_day FROM plan_team WHERE season_id = :s', ['s' => $seasonId]) as $row) {
            $lines[(string) $row['schedule_plan_id']][(string) $row['team_id']] = ['sessions' => (int) $row['sessions_per_week'], 'max' => (int) $row['max_sessions_per_day']];
        }

        $socle = null;
        foreach ($plans as $plan) {
            $planId = (string) $plan['id'];
            $type = SchedulePlanType::from((string) $plan['type']);
            $planLines = $lines[$planId] ?? [];
            if (SchedulePlanType::SEASON === $type) {
                $socle = $planId;
                foreach ($teams as $team) {
                    $teamId = (string) $team['id'];
                    if (isset($planLines[$teamId])) {
                        continue;
                    }
                    $values = [
                        'sessions' => $socleValues[$teamId]['sessionsPerWeek'] ?? PlanTeam::DEFAULT_SESSIONS_PER_WEEK,
                        'max' => $socleValues[$teamId]['maxSessionsPerDay'] ?? PlanTeam::DEFAULT_MAX_SESSIONS_PER_DAY,
                    ];
                    $this->persistLine((string) $team['club_id'], $seasonId, $planId, $teamId, true, $values['sessions'], $values['max']);
                    $lines[$planId][$teamId] = $values;
                    $wrote = true;
                }

                continue;
            }

            if (null === $socle) {
                // Cas impossible (CLAUDE.md §6) : une saison naît avec son plan de saison.
                throw new LogicException(\sprintf('La saison %s n\'a pas de planning de saison : ses plannings de période n\'ont pas de socle à suivre.', $seasonId));
            }
            $kept = [] === $planLines ? $this->keptAtBirth($type->defaultTeamSelection(), $teams) : null;
            foreach ($teams as $team) {
                $teamId = (string) $team['id'];
                if (isset($planLines[$teamId])) {
                    continue;
                }
                $socleLine = $lines[$socle][$teamId];
                $this->persistLine((string) $team['club_id'], $seasonId, $planId, $teamId, null === $kept || isset($kept[$teamId]), $socleLine['sessions'], $socleLine['max']);
                $wrote = true;
            }
        }

        if ($wrote) {
            $this->entityManager->flush();
        }
    }

    /**
     * Les lignes d'un plan, par équipe ; lève si une équipe de la saison du plan n'a pas la sienne.
     *
     * @return array<string, PlanTeam>
     */
    public function teamsOf(string $planId): array
    {
        $seasonId = $this->entityManager->getConnection()->fetchOne('SELECT season_id FROM schedule_plan WHERE id = :p', ['p' => $planId]);
        if (!\is_string($seasonId)) {
            throw new LogicException(\sprintf('Le plan %s n\'existe pas.', $planId));
        }

        $lines = [];
        foreach ($this->entityManager->getRepository(PlanTeam::class)->findBy(['schedulePlanId' => $planId]) as $line) {
            $lines[$line->getTeamId()] = $line;
        }
        foreach ($this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM team WHERE season_id = :s', ['s' => $seasonId]) as $teamId) {
            if (!isset($lines[(string) $teamId])) {
                throw new LogicException(\sprintf('L\'équipe %s n\'a pas de ligne dans le plan %s.', (string) $teamId, $planId));
            }
        }

        return $lines;
    }

    /**
     * Les lignes de gymnase d'un plan de période, par gymnase ; lève si un gymnase de la saison du plan n'a pas la
     * sienne. Vide pour le plan de saison, qui n'en porte pas.
     *
     * @return array<string, PlanVenue>
     */
    public function venuesOf(string $planId): array
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative('SELECT season_id, type FROM schedule_plan WHERE id = :p', ['p' => $planId]);
        if (false === $plan) {
            throw new LogicException(\sprintf('Le plan %s n\'existe pas.', $planId));
        }
        if (SchedulePlanType::SEASON->value === $plan['type']) {
            return [];
        }

        $lines = [];
        foreach ($this->entityManager->getRepository(PlanVenue::class)->findBy(['schedulePlanId' => $planId]) as $line) {
            $lines[$line->getVenueId()] = $line;
        }
        foreach ($this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM venue WHERE season_id = :s', ['s' => $plan['season_id']]) as $venueId) {
            if (!isset($lines[(string) $venueId])) {
                throw new LogicException(\sprintf('Le gymnase %s n\'a pas de ligne dans le plan %s.', (string) $venueId, $planId));
            }
        }

        return $lines;
    }

    /**
     * Les lignes de règle d'un plan de période, par contrainte ; lève si une contrainte permanente de la saison du plan
     * n'a pas la sienne. Vide pour le plan de saison, qui n'en porte pas.
     *
     * @return array<string, PlanConstraint>
     */
    public function constraintsOf(string $planId): array
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative('SELECT season_id, type FROM schedule_plan WHERE id = :p', ['p' => $planId]);
        if (false === $plan) {
            throw new LogicException(\sprintf('Le plan %s n\'existe pas.', $planId));
        }
        if (SchedulePlanType::SEASON->value === $plan['type']) {
            return [];
        }

        $lines = [];
        foreach ($this->entityManager->getRepository(PlanConstraint::class)->findBy(['schedulePlanId' => $planId]) as $line) {
            $lines[$line->getConstraintId()] = $line;
        }
        foreach (array_keys($this->permanentConstraintsOfSeason((string) $plan['season_id'])) as $constraintId) {
            if (!isset($lines[$constraintId])) {
                throw new LogicException(\sprintf('La contrainte %s n\'a pas de ligne dans le plan %s.', $constraintId, $planId));
            }
        }

        return $lines;
    }

    /**
     * Le défaut d'une ligne de règle : la nature de son plan, pour la portée actuelle de sa contrainte.
     */
    public function ruleDefaultOf(PlanConstraint $line): bool
    {
        $type = $this->planTypesOf([$line->getSchedulePlanId()])[$line->getSchedulePlanId()] ?? throw new LogicException(\sprintf('Le plan %s n\'existe pas.', $line->getSchedulePlanId()));
        $scope = $this->scopesOf([$line->getConstraintId()])[$line->getConstraintId()] ?? throw new LogicException(\sprintf('La contrainte %s n\'existe pas.', $line->getConstraintId()));

        return $type->keepsSocleConstraintByDefault($scope);
    }

    /**
     * Parmi ces lignes de règle, celles qui diffèrent du défaut de leur plan (le réglage propre d'une période). Une
     * ligne dont la contrainte n'existe plus n'est pas un réglage.
     *
     * @param list<PlanConstraint> $lines
     *
     * @return list<PlanConstraint>
     */
    public function ruleLinesDifferingFromDefault(array $lines): array
    {
        $types = $this->planTypesOf(array_map(static fn (PlanConstraint $line): string => $line->getSchedulePlanId(), $lines));
        $scopes = $this->scopesOf(array_map(static fn (PlanConstraint $line): string => $line->getConstraintId(), $lines));

        return array_values(array_filter($lines, static function (PlanConstraint $line) use ($types, $scopes): bool {
            $type = $types[$line->getSchedulePlanId()] ?? null;
            $scope = $scopes[$line->getConstraintId()] ?? null;

            return $type instanceof SchedulePlanType && $scope instanceof ConstraintScope && $line->applies() !== $type->keepsSocleConstraintByDefault($scope);
        }));
    }

    /**
     * Les lignes d'une règle, dans chaque plan de période, qui diffèrent du défaut de leur plan pour la portée donnée
     * (la portée d'avant un changement, quand elle change dans la même écriture).
     *
     * @return list<PlanConstraint>
     */
    public function ruleLinesOfConstraintDifferingFromDefault(string $constraintId, ConstraintScope $scope): array
    {
        $lines = $this->entityManager->getRepository(PlanConstraint::class)->findBy(['constraintId' => $constraintId]);
        $types = $this->planTypesOf(array_map(static fn (PlanConstraint $line): string => $line->getSchedulePlanId(), $lines));

        return array_values(array_filter($lines, static fn (PlanConstraint $line): bool => isset($types[$line->getSchedulePlanId()])
            && $line->applies() !== $types[$line->getSchedulePlanId()]->keepsSocleConstraintByDefault($scope)));
    }

    /**
     * La portée d'une règle permanente vient de changer : chacune de ses lignes restée égale au défaut de l'ancienne
     * portée passe au défaut de la nouvelle ; une ligne que le gestionnaire a cochée ou décochée contre le défaut n'est
     * pas touchée. C'est ce qui garde le comportement d'avant les lignes, où le défaut se recalculait à chaque lecture.
     * Ne flush pas.
     */
    public function onScopeChanged(Constraint $constraint, ConstraintScope $previous): void
    {
        if (null !== $constraint->getCalendarEntryId()) {
            return;
        }
        $this->followScope($constraint->getId(), $previous, $constraint->getScope());
    }

    /**
     * La validation du planning de saison (lot 5) : les lignes de règle des plans de période suivent la version qui
     * entre en vigueur, comparée à celle qu'elle remplace. Une règle permanente sortie de la copie (supprimée ou datée)
     * perd ses lignes ; une règle dont la portée a changé fait suivre les lignes restées au défaut de l'ancienne portée
     * ({@see onScopeChanged}) ; une règle entrée reçoit ses lignes au défaut ({@see ensureLines}, qui lit l'en vigueur).
     * Sans version remplacée (première validation), les lignes ont suivi le travail au fil des gestes : seules les
     * manquantes sont posées. Toutes les périodes de la saison sont concernées, commencées comprises.
     */
    public function propagateSocleChanges(string $seasonId, ?PlanState $replaced, PlanState $inForce): void
    {
        $before = $replaced?->toArray()['Constraint'] ?? [];
        $after = $inForce->toArray()['Constraint'] ?? [];
        foreach ($before as $constraintId => $rule) {
            $constraintId = (string) $constraintId;
            if (!isset($after[$constraintId])) {
                $this->removeRuleLines($constraintId);

                continue;
            }
            $previous = ConstraintScope::tryFrom(\is_string($rule['scope'] ?? null) ? $rule['scope'] : '');
            $scope = ConstraintScope::tryFrom(\is_string($after[$constraintId]['scope'] ?? null) ? $after[$constraintId]['scope'] : '');
            if ($previous instanceof ConstraintScope && $scope instanceof ConstraintScope) {
                $this->followScope($constraintId, $previous, $scope);
            }
        }
        $this->entityManager->flush();
        $this->ensureLines($seasonId);
    }

    /** Retire les lignes d'une règle de chaque plan de période (elle devient datée). Ne flush pas. */
    public function removeRuleLines(string $constraintId): void
    {
        foreach ($this->entityManager->getRepository(PlanConstraint::class)->findBy(['constraintId' => $constraintId]) as $line) {
            $this->entityManager->remove($line);
        }
    }

    /** La ligne d'une équipe dans un plan ; lève si elle manque. */
    public function lineOf(string $planId, string $teamId): PlanTeam
    {
        $line = $this->entityManager->getRepository(PlanTeam::class)->findOneBy(['schedulePlanId' => $planId, 'teamId' => $teamId]);

        return $line instanceof PlanTeam ? $line : throw new LogicException(\sprintf('L\'équipe %s n\'a pas de ligne dans le plan %s.', $teamId, $planId));
    }

    /**
     * Les séances et le plafond du socle de chaque équipe d'une saison, lus hors du `season_filter` (la saison visée
     * n'est pas forcément l'active de la requête).
     *
     * @return array<string, array{sessionsPerWeek: int, maxSessionsPerDay: int}>
     */
    public function socleValuesOfSeason(string $seasonId): array
    {
        $values = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT l.team_id, l.sessions_per_week, l.max_sessions_per_day FROM plan_team l JOIN schedule_plan p ON p.id = l.schedule_plan_id WHERE p.season_id = :s AND p.type = :season',
            ['s' => $seasonId, 'season' => SchedulePlanType::SEASON->value],
        ) as $row) {
            $values[(string) $row['team_id']] = ['sessionsPerWeek' => (int) $row['sessions_per_week'], 'maxSessionsPerDay' => (int) $row['max_sessions_per_day']];
        }

        return $values;
    }

    /**
     * Les lignes du socle (plan de saison) de ces équipes, par équipe ; lève si une équipe n'a pas la sienne.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, PlanTeam>
     */
    public function socleLinesOf(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }
        /** @var list<PlanTeam> $found */
        $found = $this->entityManager->createQuery('SELECT e FROM App\Entity\PlanTeam e, App\Entity\SchedulePlan sp WHERE sp.id = e.schedulePlanId AND sp.type = :season AND e.teamId IN (:ids)')
            ->setParameter('season', SchedulePlanType::SEASON)
            ->setParameter('ids', $teamIds)
            ->getResult();
        $lines = [];
        foreach ($found as $line) {
            $lines[$line->getTeamId()] = $line;
        }
        foreach ($teamIds as $teamId) {
            if (!isset($lines[$teamId])) {
                throw new LogicException(\sprintf('L\'équipe %s n\'a pas de ligne au socle.', $teamId));
            }
        }

        return $lines;
    }

    /**
     * Les lignes d'un plan de période qui diffèrent du socle (le réglage propre de la période), par équipe. Vide pour
     * le plan de saison.
     *
     * @return array<string, PlanTeam>
     */
    public function teamLinesDifferingFromSocle(string $planId): array
    {
        /** @var list<PlanTeam> $differing */
        $differing = $this->entityManager->createQuery('SELECT e FROM App\Entity\PlanTeam e WHERE e.schedulePlanId = :p AND ' . self::DIFFERS_FROM_SOCLE_DQL)
            ->setParameter('p', $planId)
            ->getResult();
        $lines = [];
        foreach ($differing as $line) {
            $lines[$line->getTeamId()] = $line;
        }

        return $lines;
    }

    /** Les lignes d'une règle restées au défaut de l'ancienne portée passent au défaut de la nouvelle. Ne flush pas. */
    private function followScope(string $constraintId, ConstraintScope $previous, ConstraintScope $scope): void
    {
        if ($previous === $scope) {
            return;
        }
        $lines = $this->entityManager->getRepository(PlanConstraint::class)->findBy(['constraintId' => $constraintId]);
        $types = $this->planTypesOf(array_map(static fn (PlanConstraint $line): string => $line->getSchedulePlanId(), $lines));
        foreach ($lines as $line) {
            $type = $types[$line->getSchedulePlanId()] ?? null;
            if ($type instanceof SchedulePlanType && $line->applies() === $type->keepsSocleConstraintByDefault($previous)) {
                $line->setApplies($type->keepsSocleConstraintByDefault($scope));
            }
        }
    }

    /**
     * Pose, dans chaque plan de période de la saison, la ligne au défaut de chaque gymnase qui n'en a pas. Ne flush pas.
     *
     * @param list<array{id: string, type: string, club_id: string}> $plans
     *
     * @return bool vrai si une ligne a été posée
     */
    private function ensureVenueLines(string $seasonId, array $plans): bool
    {
        $connection = $this->entityManager->getConnection();
        // Les gymnases de la saison, lus par la porte de l'en vigueur (triés par id).
        $venues = array_map(static fn (Venue $venue): string => $venue->getId(), $this->inForce->rows($seasonId, Venue::class));
        if ([] === $venues) {
            return false;
        }
        $existing = [];
        foreach ($connection->fetchAllAssociative('SELECT schedule_plan_id, venue_id FROM plan_venue WHERE season_id = :s', ['s' => $seasonId]) as $row) {
            $existing[(string) $row['schedule_plan_id']][(string) $row['venue_id']] = true;
        }

        $wrote = false;
        foreach ($plans as $plan) {
            if (SchedulePlanType::SEASON->value === $plan['type']) {
                continue;
            }
            foreach ($venues as $venueId) {
                if (isset($existing[(string) $plan['id']][(string) $venueId])) {
                    continue;
                }
                $this->entityManager->persist((new PlanVenue)
                    ->setClubId((string) $plan['club_id'])
                    ->setSeasonId($seasonId)
                    ->setSchedulePlanId((string) $plan['id'])
                    ->setVenueId((string) $venueId));
                $wrote = true;
            }
        }

        return $wrote;
    }

    /**
     * Pose, dans chaque plan de période de la saison, la ligne de chaque contrainte permanente qui n'en a pas, au défaut
     * de la nature du plan pour la portée de la règle. Ne flush pas.
     *
     * @param list<array{id: string, type: string, club_id: string}> $plans
     *
     * @return bool vrai si une ligne a été posée
     */
    private function ensureRuleLines(string $seasonId, array $plans): bool
    {
        $constraints = $this->permanentConstraintsOfSeason($seasonId);
        if ([] === $constraints) {
            return false;
        }
        $existing = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT schedule_plan_id, constraint_id FROM plan_constraint WHERE season_id = :s', ['s' => $seasonId]) as $row) {
            $existing[(string) $row['schedule_plan_id']][(string) $row['constraint_id']] = true;
        }

        $wrote = false;
        foreach ($plans as $plan) {
            $type = SchedulePlanType::from((string) $plan['type']);
            if (SchedulePlanType::SEASON === $type) {
                continue;
            }
            foreach ($constraints as $constraintId => $scope) {
                if (isset($existing[(string) $plan['id']][$constraintId])) {
                    continue;
                }
                $this->entityManager->persist((new PlanConstraint)
                    ->setClubId((string) $plan['club_id'])
                    ->setSeasonId($seasonId)
                    ->setSchedulePlanId((string) $plan['id'])
                    ->setConstraintId($constraintId)
                    ->setApplies($type->keepsSocleConstraintByDefault($scope)));
                $wrote = true;
            }
        }

        return $wrote;
    }

    /**
     * Les contraintes permanentes (sans entrée de calendrier) d'une saison, avec leur portée, lues par la porte de
     * l'en vigueur (hors du `season_filter`, triées par id).
     *
     * @return array<string, ConstraintScope>
     */
    private function permanentConstraintsOfSeason(string $seasonId): array
    {
        $constraints = [];
        foreach ($this->inForce->rows($seasonId, Constraint::class) as $constraint) {
            if (null === $constraint->getCalendarEntryId()) {
                $constraints[$constraint->getId()] = $constraint->getScope();
            }
        }

        return $constraints;
    }

    /**
     * @param list<string> $planIds
     *
     * @return array<string, SchedulePlanType>
     */
    private function planTypesOf(array $planIds): array
    {
        if ([] === $planIds) {
            return [];
        }
        $types = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT id, type FROM schedule_plan WHERE id IN (:ids)', ['ids' => array_values(array_unique($planIds))], ['ids' => ArrayParameterType::STRING]) as $row) {
            $types[(string) $row['id']] = SchedulePlanType::from((string) $row['type']);
        }

        return $types;
    }

    /**
     * @param list<string> $constraintIds
     *
     * @return array<string, ConstraintScope>
     */
    private function scopesOf(array $constraintIds): array
    {
        if ([] === $constraintIds) {
            return [];
        }
        $scopes = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT id, scope FROM "constraint" WHERE id IN (:ids)', ['ids' => array_values(array_unique($constraintIds))], ['ids' => ArrayParameterType::STRING]) as $row) {
            $scopes[(string) $row['id']] = ConstraintScope::from((string) $row['scope']);
        }

        return $scopes;
    }

    private function persistLine(string $clubId, string $seasonId, string $planId, string $teamId, bool $participates, int $sessionsPerWeek, int $maxSessionsPerDay): void
    {
        $this->entityManager->persist((new PlanTeam)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setSchedulePlanId($planId)
            ->setTeamId($teamId)
            ->setParticipating($participates)
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setMaxSessionsPerDay($maxSessionsPerDay));
    }

    /**
     * Les équipes cochées à la naissance d'un plan, selon sa nature. La règle des rangs vit dans
     * {@see PlanTeamSelectionSeeder::keptTeamIds}, lue sur le référentiel des rangs.
     *
     * @param list<array{id: string, club_id: string, priority_tier_id: int|string}> $teams
     *
     * @return array<string, true>|null null = toutes
     */
    private function keptAtBirth(PlanTeamDefault $default, array $teams): ?array
    {
        if (PlanTeamDefault::ALL === $default) {
            return null;
        }

        $tiers = array_map(
            static fn (mixed $id): PriorityTier => (new PriorityTier)->setId((int) $id),
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM priority_tier ORDER BY id'),
        );

        return PlanTeamSelectionSeeder::keptTeamIds(
            array_map(static fn (array $team): Team => (new Team)->setId((string) $team['id'])->setPriorityTierId((int) $team['priority_tier_id']), $teams),
            $tiers,
            false,
        );
    }
}
