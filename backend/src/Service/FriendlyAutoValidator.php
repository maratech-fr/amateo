<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Matches\FederalFollowUp;
use App\Matches\FriendlyCompetitions;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Auto-validation minimale des AMICAUX passés (lot O). Un amical dont la date est passée
 * n'a plus rien à confirmer : il a été joué. On le fait passer VALIDATED d'un balayage,
 * sans confirmation chiffrée (le « validé ligue » en lot ne concerne QUE les championnats,
 * {@see LeagueValidationOutlook}).
 *
 * Un amical est un match d'une compétition AMICALE (lot 7, PR 4a), « Amicaux » ou celle de la
 * fédération (« Amical PNF ») — porte unique {@see FriendlyCompetitions}. Domicile ET extérieur
 * sont balayés ; seul un HOME reçoit l'ancre `MANUAL` (même invariant que le balayage des
 * championnats — la grille et le solveur l'exigent), un AWAY garde sa source intacte.
 *
 * Balaie uniquement les amicaux STRICTEMENT passés, non déjà saisis (statut ≠ SUBMITTED/
 * VALIDATED) et sans écart en attente. Idempotent (VALIDATED est exclu, rejouer ne trouve
 * rien) ; ne flushe QUE si quelque chose a changé ; un verrou optimiste périmé rend 0
 * (la lecture suivante rattrape).
 *
 * ⚠ Effet de bord d'une LECTURE : invoqué par un appelant GESTIONNAIRE, saison écrivable et
 * socle choisi (l'accueil {@see EntryDeadlineOutlook}, la vue « Déjà dans FBI » et le guichet
 * de placement) — un Membre ne fait jamais écrire la base.
 */
final class FriendlyAutoValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FriendlyCompetitions $friendlyCompetitions,
        private readonly ClockInterface $clock,
        private readonly TenantDoctrineScope $tenantDoctrineScope,
        private readonly LoggerInterface $logger,
        private readonly FederalFollowUp $followUp,
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

        $friendlyCompetitionIds = $this->friendlyCompetitions->friendlyIdsOf($seasonId);
        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        $swept = 0;
        foreach ($fixtures as $fixture) {
            if (!isset($friendlyCompetitionIds[(string) $fixture->getCompetitionId()])) {
                continue;
            }
            if ($fixture->getMatchDate()->setTime(0, 0) >= $today) {
                continue; // pas encore joué (jour même inclus dans « pas encore »).
            }
            if ($this->followUp->isEntered($fixture)) {
                continue; // déjà saisi / validé.
            }
            if ($this->followUp->deviations($fixture)->any()) {
                continue; // un écart en attente n'est jamais tranché en balayage.
            }
            if (FixtureHomeAway::HOME === $fixture->getHomeAway()) {
                $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
            }
            $this->followUp->markValidated($fixture, $now);
            ++$swept;
        }

        if (0 === $swept) {
            return 0;
        }

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            // Même rattrapage que {@see LeagueValidationOutlook::sweepStartedChampionships} :
            // l'EntityManager fermé est réinitialisé, filtres tenant + saison et GUC RLS
            // reposés ; la lecture répond, la suivante rattrape.
            $this->tenantDoctrineScope->resetManager();
            $this->logger->info('Friendly sweep lost a concurrent modification; the next read will retry.', ['seasonId' => $seasonId, 'exception' => $exception]);

            return 0;
        }

        return $swept;
    }
}
