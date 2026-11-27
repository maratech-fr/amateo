<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Fixture;
use App\Enum\FixtureStatus;
use App\Matches\FederalFollowUp;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le décor pose et lit le stade d'un match et son suivi fédéral comme l'application, par la porte (lot 7, PR 6b : le
 * stade, les écarts, le mémo FBI et le libellé de salle gardé ne sont plus des colonnes du match). « Saisi » et
 * « validé » n'existent que pour un match suivi (compétition de source FBI,
 * {@see CreatesCompetitionsTrait::fbiCompetitionFor}), sous son club et sa saison déjà posés. Noyau démarré.
 *
 * ⚠ La porte travaille sur UN gestionnaire d'entités : celui qui a chargé le match et qui le flushera. Un test qui
 * garde son `$this->em` après qu'une requête a redémarré le noyau le passe en `$em`.
 *
 * @phpstan-import-type PendingDeviation from Fixture
 * @phpstan-import-type FbiEcho from Fixture
 */
trait StagesFixturesTrait
{
    private function stageFixture(Fixture $fixture, FixtureStatus $stage, ?DateTimeImmutable $now = null, ?EntityManagerInterface $em = null): Fixture
    {
        $this->followUpOf($em, $fixture)->moveTo($fixture, $stage, $now ?? new DateTimeImmutable);

        return $fixture;
    }

    private function stageOfFixture(?Fixture $fixture, ?EntityManagerInterface $em = null): ?FixtureStatus
    {
        return $fixture instanceof Fixture ? $this->followUpOf($em, $fixture)->stageOf($fixture) : null;
    }

    /** @return list<PendingDeviation> */
    private function pendingDeviationsOf(?Fixture $fixture, ?EntityManagerInterface $em = null): array
    {
        return $fixture instanceof Fixture ? $this->followUpOf($em, $fixture)->deviations($fixture)->all() : [];
    }

    /** @return PendingDeviation|null */
    private function pendingDeviationOf(?Fixture $fixture, string $field, ?EntityManagerInterface $em = null): ?array
    {
        return $fixture instanceof Fixture ? $this->followUpOf($em, $fixture)->deviations($fixture)->get($field) : null;
    }

    /** @param PendingDeviation $entry */
    private function putPendingDeviationOn(Fixture $fixture, array $entry, ?EntityManagerInterface $em = null): void
    {
        $this->followUpOf($em, $fixture)->deviations($fixture)->put($entry);
    }

    /** @return FbiEcho|null */
    private function fbiEchoOfFixture(?Fixture $fixture, ?EntityManagerInterface $em = null): ?array
    {
        return $fixture instanceof Fixture ? $this->followUpOf($em, $fixture)->fbiEchoOf($fixture) : null;
    }

    private function keptVenueLabelOfFixture(?Fixture $fixture, ?EntityManagerInterface $em = null): ?string
    {
        return $fixture instanceof Fixture ? $this->followUpOf($em, $fixture)->keptVenueLabelOf($fixture) : null;
    }

    /**
     * La porte du gestionnaire qui tient le match : celle du conteneur quand c'est le sien (un seul registre des lignes
     * lues), sinon une porte sur `$em`.
     */
    private function followUpOf(?EntityManagerInterface $em, ?Fixture $fixture = null): FederalFollowUp
    {
        $container = static::getContainer();
        $current = $container->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface || $em === $current || ($fixture instanceof Fixture && $current->contains($fixture))) {
            return $container->get(FederalFollowUp::class);
        }

        return new FederalFollowUp($em);
    }
}
