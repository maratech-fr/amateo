<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Competition;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * La seule porte vers « l'équipe d'une compétition, son libellé FBI, sa poule, ses
 * journées attendues ». Une compétition sert encore une seule équipe : la porte lit
 * et écrit les colonnes de `competition`. Aucune écriture n'est flushée ici.
 *
 * Sans gestionnaire d'entités (détecteur de conflits construit à la main), seule la
 * recherche par équipe ({@see competitionsOf}) est indisponible.
 */
final readonly class CompetitionRegistry
{
    public function __construct(
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    public function isEngaged(Competition $competition, string $teamId): bool
    {
        return $competition->getTeamId() === $teamId;
    }

    /**
     * Les compétitions où l'équipe est engagée, dans le périmètre des filtres
     * club et saison actifs.
     *
     * @return list<Competition>
     */
    public function competitionsOf(string $teamId): array
    {
        if (!$this->entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Recherche des compétitions d\'une équipe sans gestionnaire d\'entités.');
        }

        return $this->entityManager->getRepository(Competition::class)->findBy(['teamId' => $teamId]);
    }

    /** @return list<EngagementView> */
    public function engagementsOf(Competition $competition): array
    {
        return [$this->view($competition)];
    }

    public function engagementOf(Competition $competition, string $teamId): ?EngagementView
    {
        return $this->isEngaged($competition, $teamId) ? $this->view($competition) : null;
    }

    public function engage(Competition $competition, string $teamId, ?string $fbiTeamLabel = null): EngagementView
    {
        $competition->setTeamId($teamId);
        $competition->setFbiTeamLabel($fbiTeamLabel);

        return $this->view($competition);
    }

    /**
     * @param list<string>|null $opponents
     */
    public function writePoule(Competition $competition, string $teamId, ?string $pouleId, ?string $pouleName, ?array $opponents, ?int $expectedMatchdays): void
    {
        $this->assertEngaged($competition, $teamId);
        $competition->setFfbbPouleId($pouleId);
        $competition->setFfbbPouleName($pouleName);
        $competition->setFfbbPouleOpponents($opponents);
        $competition->setExpectedMatchdays($expectedMatchdays);
    }

    public function writeFbiTeamLabel(Competition $competition, string $teamId, ?string $label): void
    {
        $this->assertEngaged($competition, $teamId);
        $competition->setFbiTeamLabel($label);
    }

    private function view(Competition $competition): EngagementView
    {
        return new EngagementView(
            $competition->getId(),
            $competition->getTeamId(),
            $competition->getFbiTeamLabel(),
            $competition->getFfbbPouleId(),
            $competition->getFfbbPouleName(),
            $competition->getFfbbPouleOpponents(),
            $competition->getExpectedMatchdays(),
        );
    }

    private function assertEngaged(Competition $competition, string $teamId): void
    {
        if (!$this->isEngaged($competition, $teamId)) {
            throw new LogicException(\sprintf('L\'équipe %s n\'est pas engagée dans la compétition %s.', $teamId, $competition->getId()));
        }
    }
}
