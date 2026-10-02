<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\SubscriptionPlan;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SA4 / P1-3 — attribution d'une offre (A3). `--paid-season` (current|next) pose
 * l'offre ET marque la saison encaissée dans la MÊME transaction, sur l'horloge
 * DÉMO (D6, même patron que MarkNextSeasonPaidCommand) : un club de démonstration
 * épinglé pivote sur `simulated_today`, jamais sur le temps réel. Le marqueur est
 * monotone (GREATEST ne recule jamais). `decouverte` + `--paid-season` est interdit.
 * Sans l'option, l'offre est posée seule (voie CLI directe historique).
 */
#[Group('integration')]
final class SetClubPlanCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CommandTester $tester;

    public function testPaidSeasonCurrentMarksTheSeasonOnTheDemoClock(): void
    {
        // Club démo épinglé au 2028-09-01 (après le pivot du 15 juillet → seasonYear 2028).
        // `current` → 2028 ; l'horloge réelle donnerait une autre année (garde-fou).
        self::assertNotSame(2028, (int) (new DateTimeImmutable)->format('Y'), 'garde-fou : le test perd son sens si le temps réel est déjà 2028');
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'essentiel', '--paid-season' => 'current']), $this->tester->getDisplay());

        $this->em->clear();
        $club = $this->em->find(Club::class, $clubId);
        self::assertSame($this->planId('essentiel'), $club?->getPlanId(), 'l\'offre Essentiel est posée');
        self::assertSame(2028, $club?->getPaidSeasonYear(), 'current sur le pivot 2028 → 2028');
    }

    public function testPaidSeasonNextTargetsTheFollowingSeason(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'club', '--paid-season' => 'next']));

        $this->em->clear();
        self::assertSame(2029, $this->em->find(Club::class, $clubId)?->getPaidSeasonYear(), 'next sur le pivot 2028 → 2029');
    }

    public function testTheMarkerNeverGoesBackwards(): void
    {
        // Déjà réglé jusqu'en 2030 : `current` sur pivot 2028 ne RECULE pas le marqueur.
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01', paidSeasonYear: 2030);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'essentiel', '--paid-season' => 'current']));

        $this->em->clear();
        self::assertSame(2030, $this->em->find(Club::class, $clubId)?->getPaidSeasonYear(), 'GREATEST ne recule jamais');
    }

    public function testDecouvertePlusPaidSeasonIsRejected(): void
    {
        $clubId = $this->club(isDemo: true, simulatedToday: '2028-09-01');

        self::assertSame(Command::FAILURE, $this->tester->execute(['--club' => $clubId, '--plan' => 'decouverte', '--paid-season' => 'current']));
        self::assertStringContainsString('not allowed', $this->tester->getDisplay());
    }

    public function testWithoutPaidSeasonTheOfferIsSetAlone(): void
    {
        // Voie CLI directe historique : l'offre seule, aucun marqueur d'encaissement posé.
        $clubId = $this->club(isDemo: false);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--club' => $clubId, '--plan' => 'club']));

        $this->em->clear();
        $club = $this->em->find(Club::class, $clubId);
        self::assertSame($this->planId('club'), $club?->getPlanId());
        self::assertNull($club?->getPaidSeasonYear(), 'sans --paid-season, aucun encaissement');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:clubs:set-plan'));
    }

    private function planId(string $code): string
    {
        $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(SubscriptionPlan::class, $plan);

        return $plan->getId();
    }

    private function club(bool $isDemo, ?string $simulatedToday = null, ?int $paidSeasonYear = null): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Plan ' . $suffix)->setSlug('plan-cmd-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
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
