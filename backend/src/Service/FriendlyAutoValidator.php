<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Service\Basketball\FbiDivisionSignature;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Auto-validation minimale des AMICAUX passés (lot O). Un amical dont la date est passée
 * n'a plus rien à confirmer : il a été joué. On le fait passer VALIDATED d'un balayage,
 * sans confirmation chiffrée (le « validé ligue » en lot ne concerne QUE les championnats,
 * {@see LeagueValidationOutlook}).
 *
 * Un amical se reconnaît de deux façons : un domicile SANS compétition (competitionId null),
 * ou une compétition dont le LIBELLÉ est un amical (« Amical PNF » typé CHAMPIONSHIP côté
 * import) — maison unique {@see FbiDivisionSignature::isFriendlyCode}. Domicile ET extérieur
 * sont balayés ; seul un HOME reçoit l'ancre `MANUAL` (même invariant que le POST « validé
 * ligue » — la grille et le solveur l'exigent), un AWAY garde sa source intacte.
 *
 * Balaie uniquement les amicaux STRICTEMENT passés, non déjà saisis (statut ≠ SUBMITTED/
 * VALIDATED) et sans écart en attente. Idempotent (VALIDATED est exclu, rejouer ne trouve
 * rien) ; ne flushe QUE si quelque chose a changé.
 *
 * ⚠ Effet de bord d'une LECTURE : invoqué par un appelant GESTIONNAIRE (le cockpit
 * {@see EntryDeadlineOutlook} n'appelle que si le membre courant est gestionnaire, le
 * contrôleur « validé ligue » l'est déjà) — un Membre ne fait jamais écrire la base.
 */
final class FriendlyAutoValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FbiDivisionSignature $divisionSignature,
        private readonly ClockInterface $clock,
    ) {}

    /** @return int combien d'amicaux passés ont basculé VALIDATED (0 si rien n'a changé) */
    public function sweep(?string $clubId, string $seasonId): int
    {
        $criteria = ['seasonId' => $seasonId];
        if (null !== $clubId && '' !== $clubId) {
            $criteria['clubId'] = $clubId;
        }
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy($criteria);

        $friendlyCompetitionIds = $this->friendlyCompetitionIds($seasonId);
        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        $swept = 0;
        foreach ($fixtures as $fixture) {
            $competitionId = $fixture->getCompetitionId();
            $isFriendly = null === $competitionId || isset($friendlyCompetitionIds[$competitionId]);
            if (!$isFriendly) {
                continue;
            }
            if ($fixture->getMatchDate()->setTime(0, 0) >= $today) {
                continue; // pas encore joué (jour même inclus dans « pas encore »).
            }
            $status = $fixture->getStatus();
            if (FixtureStatus::SUBMITTED === $status || FixtureStatus::VALIDATED === $status) {
                continue; // déjà saisi / validé.
            }
            if ($fixture->hasPendingDeviations()) {
                continue; // un écart en attente n'est jamais tranché en balayage.
            }
            if (FixtureHomeAway::HOME === $fixture->getHomeAway()) {
                $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
            }
            $fixture->setStatus(FixtureStatus::VALIDATED, $now);
            ++$swept;
        }

        if ($swept > 0) {
            $this->entityManager->flush();
        }

        return $swept;
    }

    /**
     * Les ids des compétitions de la saison dont le LIBELLÉ est un amical.
     *
     * @return array<string, true>
     */
    private function friendlyCompetitionIds(string $seasonId): array
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy(['seasonId' => $seasonId]);

        $ids = [];
        foreach ($competitions as $competition) {
            if ($this->divisionSignature->isFriendlyCode($competition->getName())) {
                $ids[$competition->getId()] = true;
            }
        }

        return $ids;
    }
}
