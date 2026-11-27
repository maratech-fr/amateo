<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Competition;
use App\Entity\Engagement;
use App\Entity\Fixture;
use App\Enum\CompetitionSource;
use App\Enum\CompetitionType;
use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * La seule porte vers « l'équipe d'une compétition, son libellé FBI, sa poule, ses journées attendues » :
 * les engagements ({@see Engagement}). Une compétition de division sert une seule équipe (Q8) : `engage()`
 * refuse un second engagement. « Pas deux compétitions de même nom pour une même équipe » est tenu ici, par
 * UNE comparaison normalisée ({@see findForTeam}), pour l'import, le canal API, l'appariement et
 * `POST /api/competitions`.
 *
 * Lectures dans le périmètre des filtres club et saison actifs ; les écritures en attente (persistées, pas
 * encore flushées) sont vues. Aucune écriture n'est flushée ici : compétition, engagement et match se
 * persistent dans cet ordre et partent dans le même flush (C36).
 */
final readonly class CompetitionRegistry
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VenueLabelNormalizer $labelNormalizer,
    ) {}

    public function isEngaged(Competition $competition, string $teamId): bool
    {
        return $this->engagementRow($competition->getId(), $teamId) instanceof Engagement;
    }

    /**
     * Les compétitions où l'équipe est engagée.
     *
     * @return list<Competition>
     */
    public function competitionsOf(string $teamId): array
    {
        $competitions = [];
        foreach ($this->rows(['teamId' => $teamId]) as $engagement) {
            $competition = $this->entityManager->find(Competition::class, $engagement->getCompetitionId());
            if ($competition instanceof Competition && !$this->entityManager->getUnitOfWork()->isScheduledForDelete($competition)) {
                $competitions[] = $competition;
            }
        }

        return $competitions;
    }

    /** @return list<EngagementView> */
    public function engagementsOf(Competition $competition): array
    {
        return array_map($this->view(...), $this->rows(['competitionId' => $competition->getId()]));
    }

    /**
     * Tous les engagements du périmètre, par compétition — une lecture pour les lecteurs qui balaient toutes les
     * compétitions (radar, import, canal API).
     *
     * @return array<string, list<EngagementView>>
     */
    public function engagementsByCompetition(): array
    {
        $byCompetition = [];
        foreach ($this->rows([]) as $engagement) {
            $byCompetition[$engagement->getCompetitionId()][] = $this->view($engagement);
        }

        return $byCompetition;
    }

    public function engagementOf(Competition $competition, string $teamId): ?EngagementView
    {
        $row = $this->engagementRow($competition->getId(), $teamId);

        return $row instanceof Engagement ? $this->view($row) : null;
    }

    /**
     * Une compétition neuve de la saison, persistée, sans engagement : {@see engage} suit. Sa source dit si ses
     * matchs sont suivis dans FBI : `FBI` pour l'import, l'appariement et le canal API, `MANUAL` pour la main du club.
     */
    public function create(string $clubId, string $seasonId, string $name, CompetitionType $type, CompetitionSource $source = CompetitionSource::MANUAL): Competition
    {
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setName($name);
        $competition->setCompetitionType($type);
        $competition->setSource($source);
        $this->entityManager->persist($competition);

        return $competition;
    }

    /**
     * Engage l'équipe dans la compétition (persisté, pas flushé). Une compétition de division n'a qu'une équipe
     * (Q8) : un second engagement, d'une autre équipe, est refusé. L'équipe déjà engagée garde son engagement.
     */
    public function engage(Competition $competition, string $teamId, ?string $fbiTeamLabel = null): EngagementView
    {
        $existing = $this->rows(['competitionId' => $competition->getId()]);
        foreach ($existing as $engagement) {
            if ($engagement->getTeamId() === $teamId) {
                return $this->view($engagement);
            }
        }
        if ([] !== $existing) {
            throw new LogicException(\sprintf('La compétition %s a déjà une équipe engagée.', $competition->getId()));
        }

        $engagement = new Engagement($competition->getClubId(), $competition->getSeasonId(), $competition->getId(), $teamId);
        $engagement->setFbiTeamLabel($fbiTeamLabel);
        $this->entityManager->persist($engagement);

        return $this->view($engagement);
    }

    /**
     * @param list<string>|null $opponents
     */
    public function writePoule(Competition $competition, string $teamId, ?string $pouleId, ?string $pouleName, ?array $opponents, ?int $expectedMatchdays): void
    {
        $engagement = $this->engagedRow($competition, $teamId);
        $engagement->setFfbbPouleId($pouleId);
        $engagement->setFfbbPouleName($pouleName);
        $engagement->setFfbbPouleOpponents($opponents);
        $engagement->setExpectedMatchdays($expectedMatchdays);
    }

    public function writeFbiTeamLabel(Competition $competition, string $teamId, ?string $label): void
    {
        $this->engagedRow($competition, $teamId)->setFbiTeamLabel($label);
    }

    /** La compétition de l'équipe dont le nom, normalisé, est celui-ci : la seule comparaison de noms. */
    public function findForTeam(string $teamId, string $label): ?Competition
    {
        $key = $this->labelNormalizer->normalize($label);
        foreach ($this->competitionsOf($teamId) as $competition) {
            if ($this->labelNormalizer->normalize($competition->getName()) === $key) {
                return $competition;
            }
        }

        return null;
    }

    /** La compétition de l'équipe qui porte cette référence fédérale. */
    public function findForTeamByFederalId(string $teamId, string $ffbbCompetitionId): ?Competition
    {
        foreach ($this->competitionsOf($teamId) as $competition) {
            if ($competition->getFfbbCompetitionId() === $ffbbCompetitionId) {
                return $competition;
            }
        }

        return null;
    }

    /**
     * L'engagement d'une division d'un fichier FBI (clé de nom normalisée), parmi les engagements des compétitions
     * de ce nom : (1) un seul engagement et une seule étiquette d'équipe dans le fichier : il est pris ; (2) sinon
     * celui dont le libellé FBI est celui de la ligne ; (3) aucun : null, la division est « à faire correspondre ».
     * Jamais « la première compétition de ce nom ».
     */
    public function resolveDivision(string $divisionKey, string $labelKey, bool $singleLabelInFile): ?EngagementView
    {
        $byCompetition = $this->engagementsByCompetition();
        $candidates = [];
        foreach ($this->entityManager->getRepository(Competition::class)->findAll() as $competition) {
            if ($this->labelNormalizer->normalize($competition->getName()) === $divisionKey) {
                array_push($candidates, ...$byCompetition[$competition->getId()] ?? []);
            }
        }
        if ($singleLabelInFile && 1 === \count($candidates)) {
            return $candidates[0];
        }
        foreach ($candidates as $candidate) {
            if (null !== $candidate->fbiTeamLabel && $this->labelNormalizer->normalize($candidate->fbiTeamLabel) === $labelKey) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Supprime les compétitions de la saison qui n'ont plus ni engagement ni match (écrit en base, après le flush
     * des suppressions qui les ont vidées), jamais « Amicaux », la compétition d'office. Rend le nombre de
     * compétitions supprimées.
     */
    public function pruneEmpty(string $seasonId): int
    {
        return (int) $this->entityManager->createQuery(
            'DELETE FROM ' . Competition::class . ' c WHERE c.seasonId = :seasonId AND c.defaultFriendly = false'
            . ' AND c.id NOT IN (SELECT e.competitionId FROM ' . Engagement::class . ' e WHERE e.seasonId = :seasonId)'
            . ' AND c.id NOT IN (SELECT f.competitionId FROM ' . Fixture::class . ' f WHERE f.seasonId = :seasonId AND f.competitionId IS NOT NULL)',
        )->setParameter('seasonId', $seasonId)->execute();
    }

    private function engagementRow(string $competitionId, string $teamId): ?Engagement
    {
        return $this->rows(['competitionId' => $competitionId, 'teamId' => $teamId])[0] ?? null;
    }

    private function engagedRow(Competition $competition, string $teamId): Engagement
    {
        return $this->engagementRow($competition->getId(), $teamId)
            ?? throw new LogicException(\sprintf('L\'équipe %s n\'est pas engagée dans la compétition %s.', $teamId, $competition->getId()));
    }

    /**
     * Les engagements qui répondent aux critères : ceux de la base (dans le périmètre des filtres), plus ceux
     * persistés et pas encore flushés, moins ceux promis à la suppression.
     *
     * @param array{competitionId?: string, teamId?: string} $criteria
     *
     * @return list<Engagement>
     */
    private function rows(array $criteria): array
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $rows = [];
        foreach ($this->entityManager->getRepository(Engagement::class)->findBy($criteria) as $engagement) {
            $rows[spl_object_id($engagement)] = $engagement;
        }
        foreach ($unitOfWork->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Engagement
                && (!isset($criteria['competitionId']) || $pending->getCompetitionId() === $criteria['competitionId'])
                && (!isset($criteria['teamId']) || $pending->getTeamId() === $criteria['teamId'])
            ) {
                $rows[spl_object_id($pending)] = $pending;
            }
        }

        return array_values(array_filter($rows, static fn (Engagement $engagement): bool => !$unitOfWork->isScheduledForDelete($engagement)));
    }

    private function view(Engagement $engagement): EngagementView
    {
        return new EngagementView(
            $engagement->getCompetitionId(),
            $engagement->getTeamId(),
            $engagement->getFbiTeamLabel(),
            $engagement->getFfbbPouleId(),
            $engagement->getFfbbPouleName(),
            $engagement->getFfbbPouleOpponents(),
            $engagement->getExpectedMatchdays(),
        );
    }
}
