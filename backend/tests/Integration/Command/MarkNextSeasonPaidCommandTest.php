<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SA4 / P1-5 — l'action support « Marquer la saison suivante payée ».
 *
 * D6 (décision fondateur) : un club de DÉMONSTRATION épinglé à une date simulée
 * (`simulated_today`) pivote sur CETTE date, jamais sur l'horloge réelle — sinon la
 * démo de bascule ment. Un vrai club suit l'horloge réelle. Et le marqueur est
 * monotone : GREATEST ne recule jamais (relancer dans l'année est un no-op).
 */
#[Group('integration')]
final class MarkNextSeasonPaidCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CommandTester $tester;

    /**
     * Recette fondateur : BCCL démo épinglé au 2028-05-15 → « saison suivante »
     * = 2028-2029 (l'année-pivot 2028), là où l'horloge réelle (2026) donnerait
     * 2027-2028. Mai est AVANT le pivot du 15 juillet, donc l'année-pivot du pin
     * est 2027 et la suivante 2028 — la preuve que c'est bien `simulated_today` qui
     * décide, pas l'année civile ni le temps réel.
     */
    public function testDemoClubPivotsOnItsSimulatedClock(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-05-15');

        self::assertSame(0, $this->tester->execute(['--club' => $clubId]));

        $realClockPivot = SeasonResolver::seasonYear(new DateTimeImmutable) + 1;
        self::assertNotSame(2028, $realClockPivot, 'garde-fou : le test perd son sens si le temps réel donne déjà 2028');

        $this->em->clear();
        self::assertSame(2028, $this->em->find(Club::class, $clubId)?->getPaidSeasonYear());
        self::assertStringContainsString('Season 2028-2029', $this->tester->getDisplay());
    }

    public function testRealClubUsesTheRealClock(): void
    {
        $clubId = $this->club(isDemo: false);

        self::assertSame(0, $this->tester->execute(['--club' => $clubId]));

        $expected = SeasonResolver::seasonYear(new DateTimeImmutable) + 1;
        $this->em->clear();
        self::assertSame($expected, $this->em->find(Club::class, $clubId)?->getPaidSeasonYear());
    }

    /**
     * Un vrai club SANS pin (`is_demo` mais `simulated_today` NULL, ou tout simplement
     * un vrai club) suit le temps réel — la garde SQL n'ouvre le pin que sur un
     * club démo À DATE posée.
     */
    public function testDemoClubWithoutAPinFallsBackToRealClock(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: null);

        self::assertSame(0, $this->tester->execute(['--club' => $clubId]));

        $expected = SeasonResolver::seasonYear(new DateTimeImmutable) + 1;
        $this->em->clear();
        self::assertSame($expected, $this->em->find(Club::class, $clubId)?->getPaidSeasonYear());
    }

    /** GREATEST monotone : un marqueur déjà en avance ne recule pas à la relance. */
    public function testTheMarkerNeverGoesBackwards(): void
    {
        $clubId = $this->club(isDemo: false, paidSeasonYear: 2030);

        self::assertSame(0, $this->tester->execute(['--club' => $clubId]));

        $this->em->clear();
        self::assertSame(2030, $this->em->find(Club::class, $clubId)?->getPaidSeasonYear(), 'le marqueur ne recule jamais');
    }

    public function testUnknownClubFails(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => '00000000-0000-4000-8000-000000000000']));
        self::assertStringContainsString('not found', $this->tester->getDisplay());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:clubs:mark-next-season-paid'));
    }

    private function club(bool $isDemo, ?string $simulatedToday = null, ?int $paidSeasonYear = null): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Pay ' . $suffix)->setSlug('pay-cmd-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo($isDemo);
        if (null !== $simulatedToday) {
            $club->setSimulatedToday(new DateTimeImmutable($simulatedToday));
        }
        if (null !== $paidSeasonYear) {
            $club->setPaidSeasonYear($paidSeasonYear);
        }
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club->getId();
    }
}
