<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * P4-16 / P2-4 — la commande support qui pose/relâche l'« aujourd'hui » simulé.
 * Le geste et son inverse, plus les deux refus qui protègent d'une fausse
 * manipulation en rendez-vous : date irréelle (2026-02-31 « parse » en reportant
 * au 3 mars), et l'ambiguïté --date + --clear.
 */
#[Group('integration')]
final class DemoClockCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CommandTester $tester;

    public function testSetsThenClearsTheSimulatedToday(): void
    {
        $clubId = $this->club();

        self::assertSame(0, $this->tester->execute(['--club' => $clubId, '--date' => '2026-12-15']));
        $this->em->clear();
        self::assertSame('2026-12-15', $this->em->find(Club::class, $clubId)?->getSimulatedToday()?->format('Y-m-d'));

        self::assertSame(0, $this->tester->execute(['--club' => $clubId, '--clear' => true]));
        $this->em->clear();
        self::assertNull($this->em->find(Club::class, $clubId)?->getSimulatedToday(), 'l\'horloge est relâchée — retour au temps réel');
    }

    public function testUnrealDateIsRefused(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => $this->club(), '--date' => '2026-02-31']));
        self::assertStringContainsString('not a real', $this->tester->getDisplay());
    }

    public function testDateAndClearTogetherAreAmbiguousAndRefused(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => $this->club(), '--date' => '2026-12-15', '--clear' => true]));
    }

    public function testUnknownClubFails(): void
    {
        self::assertSame(1, $this->tester->execute(['--club' => '00000000-0000-4000-8000-000000000000', '--date' => '2026-12-15']));
    }

    // P2-4 — l'horloge simulée est RÉSERVÉE aux clubs de démonstration : sur un
    // vrai club, ce serait mentir à son gestionnaire (radar, bascule de saison).
    public function testRealClubIsRefused(): void
    {
        $realClubId = $this->club(isDemo: false);

        self::assertSame(1, $this->tester->execute(['--club' => $realClubId, '--date' => '2026-12-15']));
        self::assertStringContainsString('demo-only', $this->tester->getDisplay());
        $this->em->clear();
        self::assertNull($this->em->find(Club::class, $realClubId)?->getSimulatedToday(), 'le vrai club reste à l\'heure réelle');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:demo:clock'));
    }

    private function club(bool $isDemo = true): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Demo ' . $suffix)->setSlug('demo-cmd-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo($isDemo);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club->getId();
    }
}
