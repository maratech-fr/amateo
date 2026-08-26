<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTagAssignment;
use App\Entity\TenantOwnedInterface;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintScope;
use BackedEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * planning-versions D3: restore the club STRUCTURE to the exact state captured
 * when a version was generated (its ScheduleStructureSnapshot, D2), so the
 * manager can regenerate under those conditions. Season-scoped.
 *
 * Replaces the current permanent structure (teams/venues/slots/coaches/links/
 * permanent constraints/base reservations) with the photo — re-inserting each
 * row with its ORIGINAL id so the graph (reservations→team/venue, links,
 * dated constraints' scopeTargetId) stays consistent. NEVER touches the
 * schedules/versions themselves, nor the calendar (calendar entries, dated
 * constraints, overlay reservations) — those are not "structure".
 *
 * Symmetric to StructureSnapshotter::serializeRow (dates ATOM ↔ DateTimeImmutable,
 * enums value ↔ ::from).
 */
final class StructureRestorer
{
    use DisablesTenantFilters;

    /** Short family key → entity class (mirror of StructureSnapshotter::FAMILIES). */
    private const FAMILY_CLASS = [
        'SportCategory' => SportCategory::class,
        'Team' => Team::class,
        'Venue' => Venue::class,
        'VenueTrainingSlot' => VenueTrainingSlot::class,
        'Coach' => Coach::class,
        'TeamCoach' => TeamCoach::class,
        'CoachPlayerMembership' => CoachPlayerMembership::class,
        'Constraint' => Constraint::class,
        'Reservation' => Reservation::class,
        'TeamTagAssignment' => TeamTagAssignment::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamEngagementGuard $teamEngagementGuard,
    ) {}

    /**
     * The version's captured structure photo (D2).
     *
     * @throws ConflictHttpException when the source has no photo (pre-D2)
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function readSnapshot(Schedule $source): array
    {
        $snapshot = $this->entityManager->getRepository(ScheduleStructureSnapshot::class)
            ->findOneBy(['scheduleId' => $source->getId()]);
        if (!$snapshot instanceof ScheduleStructureSnapshot) {
            throw new ConflictHttpException('Cette version n\'a pas de photo de structure (générée avant l\'historique) — impossible de restaurer ses conditions.');
        }

        return $snapshot->getData();
    }

    /**
     * Replace the current permanent structure with the photo. NO transaction of
     * its own — the caller wraps this AND the new-version creation in one
     * transaction so the destructive wipe is never committed without a version
     * to show for it.
     *
     * @param array<string, list<array<string, mixed>>> $data
     */
    public function apply(string $clubId, string $seasonId, array $data): void
    {
        $this->disableTenantFilters($this->entityManager);
        $this->assertRestoreKeepsEngagedTeams($clubId, $seasonId, $data);
        $this->wipeStructure($clubId, $seasonId);
        // Bulk DQL DELETE removes the rows but leaves the (now-stale) objects in
        // the identity map — re-inserting the SAME ids would raise an identity
        // collision. Detach everything first.
        $this->entityManager->clear();

        foreach (self::FAMILY_CLASS as $family => $entityClass) {
            foreach ($data[$family] ?? [] as $rowData) {
                $entity = $this->hydrate($entityClass, $rowData);
                // Une photo PRISE AVANT qu'une famille devienne tenant ne porte pas
                // `clubId` : hydratée telle quelle, elle échouerait au NOT NULL (et,
                // pire, resterait invisible sous RLS si la colonne l'acceptait). Le
                // club de la restauration est le seul possible — c'est celui dont on
                // vient d'effacer la structure. Règle générale, pas un rustine pour
                // une famille : toute famille qui gagnera un tenant en hérite (BCK-11).
                if ($entity instanceof TenantOwnedInterface && !\array_key_exists('clubId', $rowData)) {
                    $entity->setClubId($clubId);
                }
                $this->entityManager->persist($entity);
            }
        }
        $this->entityManager->flush();

        // A dated (calendar) constraint / overlay reservation created for an
        // entity that did NOT exist at the photo's moment now dangles (its
        // target is gone) — clean those ghosts, like the E1 cascade would.
        $this->purgeDanglingCalendarRefs($clubId, $seasonId);
        $this->entityManager->flush();
    }

    private function purgeDanglingCalendarRefs(string $clubId, string $seasonId): void
    {
        $tenant = ['c' => $clubId, 's' => $seasonId];

        // A dated Constraint whose scope target (team/coach/venue) is gone.
        $this->deleteDangling(
            'SELECT c.id FROM App\Entity\Constraint c WHERE c.clubId = :c AND c.seasonId = :s AND c.calendarEntryId IS NOT NULL AND ('
            . '(c.scope = :team AND NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = c.scopeTargetId)) '
            . 'OR (c.scope = :coach AND NOT EXISTS (SELECT 1 FROM App\Entity\Coach co WHERE co.id = c.scopeTargetId)) '
            . 'OR (c.scope = :facility AND NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = c.scopeTargetId)))',
            $tenant + ['team' => ConstraintScope::TEAM, 'coach' => ConstraintScope::COACH, 'facility' => ConstraintScope::FACILITY],
            'DELETE App\Entity\Constraint c WHERE c.id IN (:ids)',
        );

        // A dated Reservation whose team or venue is gone.
        $this->deleteDangling(
            'SELECT r.id FROM App\Entity\Reservation r WHERE r.clubId = :c AND r.seasonId = :s AND r.schedulePlanId IS NOT NULL AND ('
            . 'NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = r.teamId) OR NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = r.venueId))',
            $tenant,
            'DELETE App\Entity\Reservation r WHERE r.id IN (:ids)',
        );

        // Period-editable structure: a period's team override / borrowed slot whose
        // target team/venue is gone after the restore — mirror the E1 cascade
        // (team/venue delete) on the restore path.
        $this->deleteDangling(
            'SELECT o.id FROM App\Entity\TeamPeriodOverride o WHERE o.clubId = :c AND o.seasonId = :s AND NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = o.teamId)',
            $tenant,
            'DELETE App\Entity\TeamPeriodOverride o WHERE o.id IN (:ids)',
        );
        // A period's constraint toggle whose permanent constraint is gone after the restore.
        $this->deleteDangling(
            'SELECT o.id FROM App\Entity\ConstraintPeriodOverride o WHERE o.clubId = :c AND o.seasonId = :s AND NOT EXISTS (SELECT 1 FROM App\Entity\Constraint co WHERE co.id = o.constraintId)',
            $tenant,
            'DELETE App\Entity\ConstraintPeriodOverride o WHERE o.id IN (:ids)',
        );
        $this->deleteDangling(
            'SELECT vts.id FROM App\Entity\VenueTrainingSlot vts WHERE vts.clubId = :c AND vts.seasonId = :s AND vts.schedulePlanId IS NOT NULL AND NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = vts.venueId)',
            $tenant,
            'DELETE App\Entity\VenueTrainingSlot vts WHERE vts.id IN (:ids)',
        );

        // P2-52 (RMM-10) — L'EXPLORATION NE DÉTRUIT PLUS. Un match dont le gymnase est absent
        // de la photo restaurée SURVIT en nommant un venue_id transitoirement mort : on le
        // LAISSE tel quel. Pointeur pendouillant assumé (revenir à une version qui porte ce
        // gymnase le rend valide de nouveau — le radar peut signaler ACCESS_WINDOW_LOST, signal
        // VRAI et transitoire). Ce n'est plus le restore qui dépointe : la VALIDATION est la
        // seule gâchette (FixtureVenueLossMarker), et elle seule pose la raison persistante.
        //
        // L'inscription en compétition d'une équipe que le restore a balayée. Une équipe
        // balayée n'a AUCUN match (sinon elle serait engagée, et `assertRestoreKeepsEngagedTeams`
        // aurait refusé) — il n'y a donc pas de match orphelin à craindre ici, mais son
        // inscription, elle, survivrait en nommant un team_id mort et s'afficherait dans
        // le module matchs pour une équipe fantôme.
        $this->deleteDangling(
            'SELECT co.id FROM App\Entity\Competition co WHERE co.clubId = :c AND co.seasonId = :s '
            . 'AND NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = co.teamId)',
            $tenant,
            'DELETE App\Entity\Competition co WHERE co.id IN (:ids)',
        );
    }

    /**
     * Two-step ghost cleanup: resolve dangling ids via `$selectDql` (a correlated
     * DQL DELETE on the reserved-word `constraint` table emits invalid SQL — SELECT
     * supports the alias, so resolve ids first), then `DELETE … WHERE id IN`.
     *
     * @param array<string, mixed> $params
     */
    private function deleteDangling(string $selectDql, array $params, string $deleteDql): void
    {
        $query = $this->entityManager->createQuery($selectDql);
        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }
        $ids = $query->getSingleColumnResult();
        if ([] !== $ids) {
            $this->entityManager->createQuery($deleteDql)->setParameter('ids', $ids)->execute();
        }
    }

    /**
     * REFUS avant destruction : une équipe ENGAGÉE ne peut pas disparaître d'un restore : ses matchs sont déposés
     * à la fédération, et `Fixture` n'est ni dans le wipe ni dans la photo — elle
     * survivrait en nommant un `team_id` mort (aucune FK ne l'en empêche).
     *
     * `wipeStructure` supprime les Team en DQL de MASSE : il ne passe donc pas par
     * TeamStateProcessor, où vit la garde de suppression. Sans ce contrôle, l'invariant
     * « une équipe qui joue est intouchable » serait vrai par l'API et faux ici — donc
     * faux tout court, et contournable en un clic sur « Charger cette version ».
     *
     * On refuse plutôt que d'épargner l'équipe : la garder hors de la photo rendrait la
     * structure restaurée incohérente avec la version qu'on prétend recharger.
     *
     * @param array<string, list<array<string, mixed>>> $data
     */
    private function assertRestoreKeepsEngagedTeams(string $clubId, string $seasonId, array $data): void
    {
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        if ([] === $teams) {
            return;
        }

        $engaged = $this->teamEngagementGuard->engagedTeamIds(array_map(static fn (Team $t): string => $t->getId(), $teams));
        if ([] === $engaged) {
            return;
        }

        // La clé de famille est le nom COURT de la classe ('Team'), pas un pluriel :
        // la lire en dur ferait taire la garde sur un tableau vide. array_search sur la
        // table de correspondance qui sert déjà à l'hydratation — une seule source.
        $teamFamily = array_search(Team::class, self::FAMILY_CLASS, true);
        $inPhoto = [];
        foreach ($data[$teamFamily] ?? [] as $row) {
            if (isset($row['id']) && \is_string($row['id'])) {
                $inPhoto[$row['id']] = $row;
            }
        }

        $lost = [];
        $releveled = [];
        foreach ($teams as $team) {
            if (!isset($engaged[$team->getId()])) {
                continue;
            }
            $row = $inPhoto[$team->getId()] ?? null;
            if (null === $row) {
                $lost[] = $team->getName();
                continue;
            }
            // Présente ne suffit pas : `level` est un champ mappé, donc la photo le
            // porte et `hydrate()` le réinsère tel quel. Une photo antérieure à la
            // correction du niveau le RÉÉCRIRAIT en silence — la divergence même que
            // le gel total du niveau existe pour rendre impossible, atteinte par
            // l'autre bout. Et le processor refuse ensuite (409) toute correction :
            // le club resterait avec un niveau faux, sans issue par l'UI.
            $photoLevel = $row['level'] ?? null;
            if ($team->getLevel()?->value !== (\is_string($photoLevel) ? $photoLevel : null)) {
                $releveled[] = $team->getName();
            }
        }

        if ([] !== $lost) {
            throw new ConflictHttpException(\sprintf('Cette version est antérieure à %s, qui joue%s déjà en compétition : la charger %s supprimerait et laisserait %s matchs sans équipe.', implode(', ', $lost), 1 === \count($lost) ? '' : 'nt', 1 === \count($lost) ? 'la' : 'les', 1 === \count($lost) ? 'ses' : 'leurs'));
        }

        if ([] !== $releveled) {
            throw new ConflictHttpException(\sprintf('Cette version a été générée quand %s %s un autre niveau de jeu. %s joue%s déjà en compétition : la charger rétablirait un niveau qui ne correspond plus à %s engagement.', implode(', ', $releveled), 1 === \count($releveled) ? 'avait' : 'avaient', 1 === \count($releveled) ? 'Elle' : 'Elles', 1 === \count($releveled) ? '' : 'nt', 1 === \count($releveled) ? 'son' : 'leur'));
        }
    }

    /** Delete the current permanent structure (NOT schedules, NOT the calendar). */
    private function wipeStructure(string $clubId, string $seasonId): void
    {
        $this->deleteFamily(TeamCoach::class, $clubId, $seasonId);
        $this->deleteFamily(CoachPlayerMembership::class, $clubId, $seasonId);
        // BCK-11 : borné au club depuis que la table porte son `club_id` (il
        // valait `null` ici tant qu'elle n'en avait pas).
        $this->deleteFamily(TeamTagAssignment::class, $clubId, $seasonId);
        $this->deleteFamily(Reservation::class, $clubId, $seasonId, permanentOnly: true);
        $this->deleteFamily(Constraint::class, $clubId, $seasonId, permanentOnly: true);
        $this->deleteFamily(Team::class, $clubId, $seasonId);
        // permanentOnly: a period's own slots (schedulePlanId set) belong to the
        // calendar, not the base structure — a base-version restore must not wipe them.
        $this->deleteFamily(VenueTrainingSlot::class, $clubId, $seasonId, permanentOnly: true);
        $this->deleteFamily(Venue::class, $clubId, $seasonId);
        $this->deleteFamily(Coach::class, $clubId, $seasonId);
        $this->deleteFamily(SportCategory::class, $clubId, null);
    }

    /** @param class-string $entityClass */
    private function deleteFamily(string $entityClass, ?string $clubId, ?string $seasonId, bool $permanentOnly = false): void
    {
        $qb = $this->entityManager->createQueryBuilder()->delete($entityClass, 'e');
        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }
        if (null !== $seasonId) {
            $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
        }
        if ($permanentOnly) {
            // Ne toucher QUE la structure partagée : les lignes de période appartiennent au
            // calendrier (overlays / fermetures) et survivent. L'ancre diffère selon
            // l'entité depuis les lots C2-C3 (ADR-0002 inv. 5, correction du 2026-07-17) :
            // créneaux et réservations = des RÉPONSES → ancrés au PLAN ; contraintes datées
            // = le FAIT → restées sur la CalendarEntry. La deviner ici la ferait diverger en
            // silence — on la nomme.
            $qb->andWhere(\sprintf('e.%s IS NULL', StructureAnchor::of($entityClass)));
        }
        $qb->getQuery()->execute();
    }

    /**
     * @param class-string         $entityClass
     * @param array<string, mixed> $rowData
     */
    private function hydrate(string $entityClass, array $rowData): object
    {
        $metadata = $this->entityManager->getClassMetadata($entityClass);
        /** @var object $entity */
        $entity = $metadata->getReflectionClass()->newInstanceWithoutConstructor();
        foreach ($metadata->getFieldNames() as $field) {
            if (!\array_key_exists($field, $rowData)) {
                continue;
            }
            $mapping = $metadata->getFieldMapping($field);
            $enumType = $mapping->enumType;
            $metadata->setFieldValue($entity, $field, $this->typedValue((string) $mapping->type, \is_string($enumType) ? $enumType : null, $rowData[$field]));
        }

        return $entity;
    }

    /** @param class-string<BackedEnum>|null $enumType */
    private function typedValue(string $type, ?string $enumType, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if (null !== $enumType) {
            return $enumType::from($value);
        }
        if (str_contains($type, 'date') || str_contains($type, 'time')) {
            return new DateTimeImmutable((string) $value);
        }

        return $value;
    }
}
