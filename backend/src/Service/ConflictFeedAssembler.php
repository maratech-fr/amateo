<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\ConflictCoachMailController;
use App\Controller\FixtureConflictsController;
use App\Entity\Coach;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le flux de conflits SERVI d'un club+saison : le radar ({@see ConflictRadarLoader}), décoré en
 * aval de l'empreinte stable de chaque conflit et du lieu adverse des côtés extérieurs.
 *
 * Plomberie déplacée de {@see FixtureConflictsController} (corps de `computeConflicts`,
 * `decorateOpponentPlace`, `awaySideKeys`) pour être partagée, à l'identique, par l'écran des
 * conflits et par l'envoi aux coachs ({@see ConflictCoachMailController}) : le mail dit ce que
 * l'écran montre. La saison est résolue par l'appelant.
 */
final class ConflictFeedAssembler
{
    public function __construct(
        private readonly ConflictRadarLoader $radarLoader,
        private readonly ConflictFingerprinter $fingerprinter,
        private readonly OpponentPlaceResolver $opponentPlaceResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Ce que l'envoi aux coachs lit en plus du flux, pour le GET (compteurs) comme pour le POST
     * (envoi) : les coachs PRINCIPAUX par équipe et les coachs qui existent encore — chargés sous
     * les filtres tenant+saison, comme le radar.
     *
     * @return array{mainCoachIdsByTeam: array<string, list<string>>, coachesById: array<string, Coach>}
     */
    public function mailingInputs(): array
    {
        $mains = [];
        /** @var list<TeamCoach> $rows */
        $rows = $this->entityManager->getRepository(TeamCoach::class)->findBy(['role' => TeamCoachRole::MAIN]);
        foreach ($rows as $row) {
            $mains[$row->getTeamId()][] = $row->getCoachId();
        }
        $coaches = [];
        /** @var list<Coach> $coachRows */
        $coachRows = $this->entityManager->getRepository(Coach::class)->findBy([]);
        foreach ($coachRows as $coach) {
            $coaches[$coach->getId()] = $coach;
        }

        return ['mainCoachIdsByTeam' => $mains, 'coachesById' => $coaches];
    }

    /**
     * @return array{conflicts: list<array<string, mixed>>, held: list<array<string, mixed>>, seasonPlanChosen: bool, groupsBySchedule: array<string, list<list<string>>>}
     */
    public function assemble(string $clubId, ?Season $season): array
    {
        $radar = $this->radarLoader->conflicts($clubId, $season?->getId());

        // RMM-3 — champ ADDITIF : l'empreinte stable de chaque conflit, calculée EN
        // AVAL par la maison unique (le détecteur reste intact). Le gardien s'en sert
        // pour dire ce qui est « nouveau depuis ta dernière visite ».
        $conflicts = array_map(
            fn (array $conflict): array => $conflict + ['fingerprint' => $this->fingerprinter->fingerprint($conflict)],
            $radar['conflicts'],
        );

        // P2-54 conflict side details — champ ADDITIF `opponentPlace` sur les côtés
        // AWAY des familles PERSONNE (MATCH_MATCH left/right, MATCH_TRAINING fixture).
        // Décoré EN AVAL (le détecteur ne le connaît pas), résolu en BATCH par
        // OpponentPlaceResolver ; un côté HOME ne porte jamais la clé.
        $held = $radar['held'];
        if ($season instanceof Season) {
            $conflicts = $this->decorateOpponentPlace($conflicts, $radar['fixtures'], $season->getId());
            $held = $this->decorateOpponentPlace($held, $radar['fixtures'], $season->getId());
        }

        return [
            'conflicts' => $conflicts,
            'held' => $held,
            'seasonPlanChosen' => null !== $radar['seasonScheduleId'],
            'groupsBySchedule' => $radar['groupsBySchedule'],
        ];
    }

    /**
     * Decorate the AWAY sides of the person families with a resolved place label
     * (`opponentPlace`, string|null). Two passes so the resolver batches once:
     * gather the away fixtures those sides reference, resolve, then stamp each.
     *
     * @param list<array<string, mixed>> $conflicts
     * @param list<Fixture>              $fixtures
     *
     * @return list<array<string, mixed>>
     */
    private function decorateOpponentPlace(array $conflicts, array $fixtures, string $seasonId): array
    {
        $fixtureById = [];
        foreach ($fixtures as $fixture) {
            $fixtureById[$fixture->getId()] = $fixture;
        }

        /** @var array<string, Fixture> $awayFixtures */
        $awayFixtures = [];
        foreach ($conflicts as $conflict) {
            foreach ($this->awaySideKeys($conflict) as $key) {
                $side = $conflict[$key];
                if (!\is_array($side) || 'AWAY' !== ($side['homeAway'] ?? null)) {
                    continue;
                }
                $fixtureId = $side['fixtureId'] ?? null;
                if (\is_string($fixtureId) && isset($fixtureById[$fixtureId])) {
                    $awayFixtures[$fixtureId] = $fixtureById[$fixtureId];
                }
            }
        }

        $places = $this->opponentPlaceResolver->resolveByFixture($seasonId, array_values($awayFixtures));

        return array_map(
            function (array $conflict) use ($places): array {
                foreach ($this->awaySideKeys($conflict) as $key) {
                    $side = $conflict[$key];
                    if (!\is_array($side) || 'AWAY' !== ($side['homeAway'] ?? null)) {
                        continue;
                    }
                    $fixtureId = $side['fixtureId'] ?? null;
                    $side['opponentPlace'] = \is_string($fixtureId) ? ($places[$fixtureId] ?? null) : null;
                    $conflict[$key] = $side;
                }

                return $conflict;
            },
            $conflicts,
        );
    }

    /**
     * The side keys that carry an away MATCH fixture, per conflict type:
     * MATCH_MATCH → left/right, MATCH_TRAINING → the match fixture. Every other
     * family (gym/link/…) keeps its rendering untouched, so it decorates nothing.
     *
     * @param array<string, mixed> $conflict
     *
     * @return list<string>
     */
    private function awaySideKeys(array $conflict): array
    {
        return match ($conflict['type'] ?? null) {
            'MATCH_MATCH' => ['left', 'right'],
            'MATCH_TRAINING' => ['fixture'],
            default => [],
        };
    }
}
