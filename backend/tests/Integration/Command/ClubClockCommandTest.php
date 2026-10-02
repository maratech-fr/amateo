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
 * La commande support qui pose/relâche l'« aujourd'hui » simulé d'un club.
 *
 * L'horloge est une capacité GÉNÉRIQUE : la commande vise n'importe quel club, par id OU
 * par code FFBB. Garde-fou pour un club RÉEL (non démo) : poser l'horloge le coupe de ses
 * e-mails réels, d'où le `--yes` obligatoire — un club démo n'en a jamais besoin. Plus les
 * deux refus qui protègent d'une fausse manip en rendez-vous : date irréelle (2026-02-31
 * « parse » en reportant au 3 mars) et l'ambiguïté --date + --clear.
 */
#[Group('integration')]
final class ClubClockCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CommandTester $tester;

    public function testSetsThenClearsTheSimulatedTodayOnADemoClub(): void
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
        self::assertStringContainsString('not found', $this->tester->getDisplay());
    }

    // Capacité générique : un VRAI club est datable, mais seulement avec --yes — sans lui,
    // refus franc, rien écrit (poser l'horloge coupe ses e-mails réels).
    public function testRealClubIsRefusedWithoutYesAndAcceptedWithYes(): void
    {
        $realClubId = $this->club(isDemo: false);

        self::assertSame(1, $this->tester->execute(['--club' => $realClubId, '--date' => '2026-12-15']));
        self::assertStringContainsString('--yes', $this->tester->getDisplay());
        $this->em->clear();
        self::assertNull($this->em->find(Club::class, $realClubId)?->getSimulatedToday(), 'sans --yes le vrai club reste à l\'heure réelle');

        self::assertSame(0, $this->tester->execute(['--club' => $realClubId, '--date' => '2026-12-15', '--yes' => true]));
        $this->em->clear();
        self::assertSame('2026-12-15', $this->em->find(Club::class, $realClubId)?->getSimulatedToday()?->format('Y-m-d'), 'avec --yes le vrai club est bien daté');
    }

    public function testResolvesByFfbbClubCode(): void
    {
        $code = 'ARA' . str_pad((string) random_int(0, 9_999_999), 7, '0', \STR_PAD_LEFT);
        $clubId = $this->club(ffbbClubCode: $code);

        self::assertSame(0, $this->tester->execute(['--club' => $code, '--date' => '2026-12-15']), $this->tester->getDisplay());
        $this->em->clear();
        self::assertSame('2026-12-15', $this->em->find(Club::class, $clubId)?->getSimulatedToday()?->format('Y-m-d'));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application(self::$kernel);
        // Le nom canonique ; l'alias déprécié `app:demo:clock` résout la MÊME commande.
        $this->tester = new CommandTester($application->find('app:club:clock'));
        self::assertSame($application->find('app:club:clock'), $application->find('app:demo:clock'), 'l\'alias déprécié pointe sur la même commande');
    }

    private function club(bool $isDemo = true, ?string $ffbbClubCode = null): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Demo ' . $suffix)->setSlug('demo-cmd-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo($isDemo);
        if (null !== $ffbbClubCode) {
            $club->setFfbbClubCode($ffbbClubCode);
        }
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club->getId();
    }
}
