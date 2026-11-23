<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Plans\SoclePlan;
use App\Service\ClubProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse (lot 4, PR 2, contrat du plan : `SoclePlan::idOf` « lève si absent ») : une saison sans plan de
 * saison est un cas impossible (`CLAUDE.md` §6), jamais un repli. Lire le plan du socle d'une telle saison lève,
 * et n'écrit rien : aucun plan ne naît, aucun verrou n'est pris — sinon une simple lecture (une liste, le Récap,
 * la génération) ferait naître en silence un socle vide.
 *
 * L'autre moitié du contrat : toute saison naît avec son plan. Inscription d'un club ici ; « Préparer la saison
 * suivante » est prouvée par `preparer-la-saison-suivante-reprend-la-structure.feature` (étape « la grille reprise
 * est celle du planning de saison de la saison suivante », qui compte un seul plan de saison).
 */
#[Group('phase1')]
#[Group('integration')]
final class SoclePlanTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testReadingTheSoclePlanOfASeasonWithoutPlanFailsAndWritesNothing(): void
    {
        $season = $this->seasonWithoutPlan();
        $connection = $this->em->getConnection();
        self::assertSame(0, $this->seasonPlansOf($season), 'témoin : la saison n\'a pas de plan de saison');
        $locksBefore = $this->advisoryLocksHeld();

        try {
            self::getContainer()->get(SoclePlan::class)->idOf($season->getId());
            self::fail('lire le plan du socle d\'une saison sans plan doit lever');
        } catch (LogicException) {
        }

        self::assertSame(0, $this->seasonPlansOf($season), 'aucun plan de saison n\'est né d\'une lecture');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE season_id = ?', [$season->getId()]), 'aucun plan, de quelque nature');
        self::assertSame($locksBefore, $this->advisoryLocksHeld(), 'aucun verrou n\'est pris par une lecture');
    }

    public function testReadingTheSoclePlanOfASeasonThatHasOneReturnsIt(): void
    {
        $club = $this->newClub();
        self::getContainer()->get(ClubProvisioner::class)->seedWorkspace($club);
        $this->em->flush();
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        self::assertInstanceOf(Season::class, $season);

        $planId = $this->em->getConnection()->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = \'SEASON\'', [$season->getId()]);

        self::assertIsString($planId, 'l\'inscription d\'un club fait naître sa saison avec son plan de saison');
        self::assertSame(1, $this->seasonPlansOf($season), 'un seul plan de saison');
        self::assertSame($planId, self::getContainer()->get(SoclePlan::class)->idOf($season->getId()), 'la lecture rend ce plan');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function newClub(): Club
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Socle ' . $uid)->setSlug('socle-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club;
    }

    /** Une saison écrite à la main, sans plan : l'état qu'aucun chemin de l'application ne produit. */
    private function seasonWithoutPlan(): Season
    {
        $club = $this->newClub();
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function seasonPlansOf(Season $season): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE season_id = ? AND type = \'SEASON\'', [$season->getId()]);
    }

    /** Les verrous consultatifs que tient cette connexion (ceux de `lockPlanScope` vivent jusqu'à la fin de la transaction). */
    private function advisoryLocksHeld(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pg_locks WHERE locktype = \'advisory\' AND pid = pg_backend_pid()');
    }
}
