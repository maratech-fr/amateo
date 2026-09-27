<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\LeagueValidatedFixturesController;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Repository\SharedCompetitionDeadlineRepository;
use App\Service\Basketball\FbiDivisionSignature;
use App\State\Processor\FixtureStateProcessor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * MAISON UNIQUE de la lecture « validé ligue » en lot (lot O), extraite de
 * {@see LeagueValidatedFixturesController} pour être consommée aussi par le cockpit
 * ({@see EntryDeadlineOutlook}, qui a besoin du compte de validables) — une seconde
 * redérivation du prédicat serait exactement ce qu'on interdit ailleurs.
 *
 * Un championnat est « COMMENCÉ » (donc proposable) de DEUX façons :
 *   1. son échéance de saisie effective est passée (jour de l'échéance INCLUS,
 *      règle « club sinon communautaire », {@see CompetitionDeadlineResolver}) ;
 *   2. OU son PREMIER match est déjà joué (min(matchDate) STRICTEMENT dans le passé) —
 *      une compétition sans échéance mais dont les dates sont déjà tombées n'est plus
 *      provisoire, elle a démarré.
 * Tant qu'aucune des deux conditions n'est remplie (échéance future, dates provisoires
 * d'une nouvelle vague), on ne propose RIEN pour ce championnat.
 *
 * Les amicaux sont HORS du lot : un domicile sans compétition ne mûrit sous aucune
 * échéance, et un championnat dont le LIBELLÉ est un amical (« Amical PNF » typé
 * CHAMPIONSHIP côté import) l'est tout autant — reconnu par la maison unique
 * {@see FbiDivisionSignature::isFriendlyCode}. Ces amicaux passés se valident seuls
 * ({@see FriendlyAutoValidator}), jamais par ce lot.
 */
final class LeagueValidationOutlook
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedCompetitionDeadlineRepository $sharedDeadlineRepository,
        private readonly FbiDivisionSignature $divisionSignature,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * La lecture détaillée par championnat COMMENCÉ (échéance passée OU premier match joué) :
     * son nom, son échéance (nullable — un firstMatchPlayed n'en a pas forcément), sa
     * provenance, comment il a mûri (`deadline`/`firstMatchPlayed`), la date du premier match
     * si c'est ce qui l'a fait mûrir, et son compte de validables ; la liste NOMMÉE des
     * rencontres à traiter d'un championnat commencé ; les championnats SANS échéance ET pas
     * encore commencés qui ont pourtant des rencontres prêtes ; et le total à valider.
     *
     * @return array{
     *     matured: list<array{competitionId: string, name: string, deadline: string|null, deadlineSource: string, maturedBy: string, firstMatchDate: string|null, validatableCount: int}>,
     *     toTreat: list<array{fixtureId: string, teamId: string, competitionName: string, matchDate: string, opponentLabel: string, reason: string}>,
     *     missingDeadline: list<array{competitionId: string, name: string, validatableCount: int}>,
     *     totalValidatable: int,
     * }
     */
    public function compute(string $seasonId): array
    {
        $fixtures = $this->seasonFixtures($seasonId);
        $meta = $this->competitionMeta($seasonId, $fixtures);

        $validatableByComp = [];
        $missingByComp = [];
        $toTreat = [];
        foreach ($fixtures as $fixture) {
            if (!$this->isCandidate($fixture, $meta)) {
                continue;
            }
            $competitionId = (string) $fixture->getCompetitionId();
            $entry = $meta[$competitionId] ?? null;
            if (null === $entry) {
                continue; // compétition hors saison résolue (défensif)
            }
            $passes = $this->passesPredicate($fixture, $meta);
            if (!$entry['matured']) {
                // Pas encore commencé : on ne propose rien. Un championnat SANS échéance
                // ET pas encore commencé mais avec des rencontres prêtes est SIGNALÉ (à
                // renseigner) ; une échéance FUTURE (dates provisoires) reste muette.
                if (null === $entry['deadline'] && $passes) {
                    $missingByComp[$competitionId] = ($missingByComp[$competitionId] ?? 0) + 1;
                }

                continue;
            }
            if ($passes) {
                $validatableByComp[$competitionId] = ($validatableByComp[$competitionId] ?? 0) + 1;
            } else {
                $toTreat[] = [
                    'fixtureId' => $fixture->getId(),
                    'teamId' => $fixture->getTeamId(),
                    'competitionName' => $entry['name'],
                    'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
                    'opponentLabel' => $fixture->getOpponentLabel(),
                    'reason' => $this->reasonToTreat($fixture),
                ];
            }
        }

        $matured = [];
        foreach ($validatableByComp as $competitionId => $count) {
            $entry = $meta[$competitionId];
            $matured[] = [
                'competitionId' => $competitionId,
                'name' => $entry['name'],
                'deadline' => $entry['deadline'] instanceof DateTimeImmutable ? $entry['deadline']->format('Y-m-d') : null,
                'deadlineSource' => $entry['source'],
                'maturedBy' => (string) $entry['maturedBy'],
                'firstMatchDate' => $entry['firstMatchDate'],
                'validatableCount' => $count,
            ];
        }
        // Tri par la date qui « ancre » le championnat (échéance, sinon 1er match joué), puis nom.
        usort($matured, static fn (array $a, array $b): int => [$a['deadline'] ?? $a['firstMatchDate'] ?? '', $a['name']] <=> [$b['deadline'] ?? $b['firstMatchDate'] ?? '', $b['name']]);

        $missingDeadline = [];
        foreach ($missingByComp as $competitionId => $count) {
            $missingDeadline[] = [
                'competitionId' => $competitionId,
                'name' => $meta[$competitionId]['name'],
                'validatableCount' => $count,
            ];
        }
        usort($missingDeadline, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        usort($toTreat, static fn (array $a, array $b): int => [$a['matchDate'], $a['competitionName']] <=> [$b['matchDate'], $b['competitionName']]);

        return [
            'matured' => $matured,
            'toTreat' => $toTreat,
            'missingDeadline' => $missingDeadline,
            'totalValidatable' => array_sum($validatableByComp),
        ];
    }

    /**
     * Les domiciles à basculer « validé ligue » au POST : un domicile UNPLACED d'un
     * championnat COMMENCÉ, non amical, passant le prédicat (heure + gymnase identifié,
     * sans écart en attente). Recalculé au moment de l'application (l'horloge + les
     * échéances du moment font foi, jamais ce que l'écran affichait) — le client ne
     * choisit AUCUN championnat.
     *
     * @return list<Fixture>
     */
    public function fixturesToConfirm(string $seasonId): array
    {
        $fixtures = $this->seasonFixtures($seasonId);
        $meta = $this->competitionMeta($seasonId, $fixtures);

        $toConfirm = [];
        foreach ($fixtures as $fixture) {
            $competitionId = $fixture->getCompetitionId();
            if (null === $competitionId) {
                continue;
            }
            $entry = $meta[$competitionId] ?? null;
            if (null === $entry || !$entry['matured']) {
                continue; // `matured` exclut déjà l'amical (jamais mûr) et l'échéance future.
            }
            if ($this->passesPredicate($fixture, $meta)) {
                $toConfirm[] = $fixture;
            }
        }

        return $toConfirm;
    }

    /**
     * @return list<Fixture>
     */
    private function seasonFixtures(string $seasonId): array
    {
        return $this->entityManager->getRepository(Fixture::class)->findBy(['seasonId' => $seasonId]);
    }

    /**
     * Les métadonnées par compétition de la saison : nom, échéance effective (nullable),
     * provenance, si elle a mûri et COMMENT, la date de son premier match (quand c'est ce
     * qui l'a fait mûrir), et si son libellé est un amical (jamais candidate).
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, array{name: string, deadline: DateTimeImmutable|null, source: string, matured: bool, maturedBy: string|null, firstMatchDate: string|null, friendly: bool}>
     */
    private function competitionMeta(string $seasonId, array $fixtures): array
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy(['seasonId' => $seasonId]);

        $sharedByFfbbId = $this->sharedDeadlineRepository->mapByFfbbCompetitionIds(
            array_values(array_filter(array_map(
                static fn (Competition $c): ?string => $c->getFfbbCompetitionId(),
                $competitions,
            ))),
        );

        $minMatchDate = $this->minMatchDateByCompetition($fixtures);
        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);

        $meta = [];
        foreach ($competitions as $competition) {
            $ffbbCompetitionId = $competition->getFfbbCompetitionId();
            $shared = null !== $ffbbCompetitionId ? ($sharedByFfbbId[$ffbbCompetitionId] ?? null) : null;
            [$effective, $source] = CompetitionDeadlineResolver::resolve($competition, $shared);

            $friendly = $this->divisionSignature->isFriendlyCode($competition->getName());
            $first = $minMatchDate[$competition->getId()] ?? null;

            $matured = false;
            $maturedBy = null;
            $firstMatchDate = null;
            if ($friendly) {
                // Un amical n'est jamais candidat : ni mûr, ni signalé.
                $matured = false;
            } elseif ($effective instanceof DateTimeImmutable && $effective <= $today) {
                // Le jour de l'échéance est INCLUS : « à partir de la date d'échéance ».
                $matured = true;
                $maturedBy = 'deadline';
            } elseif ($first instanceof DateTimeImmutable && $first < $today) {
                // Le premier match est déjà joué (strict) : le championnat a démarré.
                $matured = true;
                $maturedBy = 'firstMatchPlayed';
                $firstMatchDate = $first->format('Y-m-d');
            }

            $meta[$competition->getId()] = [
                'name' => $competition->getName(),
                'deadline' => $effective,
                'source' => $source ?? '',
                'matured' => $matured,
                'maturedBy' => $maturedBy,
                'firstMatchDate' => $firstMatchDate,
                'friendly' => $friendly,
            ];
        }

        return $meta;
    }

    /**
     * La date (jour) du premier match de chaque compétition, tous domiciles/extérieurs et
     * statuts confondus : ce qui atteste qu'un championnat a DÉMARRÉ.
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, DateTimeImmutable>
     */
    private function minMatchDateByCompetition(array $fixtures): array
    {
        $min = [];
        foreach ($fixtures as $fixture) {
            $competitionId = $fixture->getCompetitionId();
            if (null === $competitionId) {
                continue;
            }
            $date = $fixture->getMatchDate()->setTime(0, 0);
            if (!isset($min[$competitionId]) || $date < $min[$competitionId]) {
                $min[$competitionId] = $date;
            }
        }

        return $min;
    }

    /**
     * Un candidat « validé ligue » : un domicile UNPLACED rattaché à un championnat qui
     * n'est PAS un amical (jamais un domicile sans compétition, jamais un libellé amical).
     *
     * @param array<string, array{friendly: bool, ...}> $meta
     */
    private function isCandidate(Fixture $fixture, array $meta): bool
    {
        if (FixtureHomeAway::HOME !== $fixture->getHomeAway()) {
            return false;
        }
        if (FixtureStatus::UNPLACED !== $fixture->getStatus()) {
            return false;
        }
        $competitionId = $fixture->getCompetitionId();
        if (null === $competitionId) {
            return false;
        }

        return true !== ($meta[$competitionId]['friendly'] ?? false);
    }

    /**
     * Le prédicat « validable » d'un candidat : heure + gymnase identifié, sans écart en
     * attente. La MATURITÉ du championnat (commencé) est vérifiée à part.
     *
     * ⚠ DIVERGENCE ASSUMÉE avec le contrôle d'accès du geste UNITAIRE
     * ({@see FixtureStateProcessor::assertVenueAccessAllowed}) : ce
     * prédicat ne contrôle PAS les créneaux d'accès match. La fédération a enregistré cette
     * réalité ; l'application la reflète, le radar signale l'incohérence (`ACCESS_WINDOW_LOST`).
     *
     * @param array<string, array{friendly: bool, ...}> $meta
     */
    private function passesPredicate(Fixture $fixture, array $meta): bool
    {
        return $this->isCandidate($fixture, $meta)
            && $fixture->getKickoffTime() instanceof DateTimeImmutable
            && null !== $fixture->getVenueId()
            && !$fixture->hasPendingDeviations();
    }

    /** Pourquoi un candidat d'un championnat commencé n'est pas validable — nommé, jamais tu. */
    private function reasonToTreat(Fixture $fixture): string
    {
        if ($fixture->hasPendingDeviations()) {
            return 'PENDING_DEVIATION';
        }
        if (!$fixture->getKickoffTime() instanceof DateTimeImmutable) {
            return 'NO_KICKOFF';
        }

        return 'NO_VENUE';
    }
}
