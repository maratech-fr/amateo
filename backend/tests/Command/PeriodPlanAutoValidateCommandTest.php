<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\PeriodPlanAutoValidationDecorTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La tâche quotidienne `app:period-plans:auto-validate` (D1, D14) : elle valide les plannings de
 * période dont le 7ᵉ jour après le début est atteint, dit ce qu'elle a fait en une ligne, n'écrit
 * rien en `--dry-run`, se borne à un club avec `--club`, et refuse un `--date` futur hors
 * `--dry-run` (une répétition peut regarder l'avenir, jamais l'écrire).
 *
 * Les promesses de fond (seuil, éligibilité, version retenue, gardes sous verrou, isolation entre
 * clubs dans un même passage) vivent dans le NR `Security/PeriodPlanAutoValidationTest` ; ici, la
 * seule surface de la commande.
 */
#[Group('integration')]
final class PeriodPlanAutoValidateCommandTest extends KernelTestCase
{
    use PeriodPlanAutoValidationDecorTrait;

    /** Une période commencée il y a 10 jours, une version terminée : validée, la sortie le dit. */
    public function testAStartedPeriodPlanIsValidatedAndTheSummarySaysSo(): void
    {
        ['club' => $club, 'planId' => $planId, 'versionId' => $versionId] = $this->startedPlan('ONE');

        $tester = $this->runAutoValidate(['--club' => $club]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 period plan(s) validated', $this->flat($tester));
        self::assertSame($versionId, $this->decorChosenOf($club, $planId));
    }

    /** `--dry-run` : la sortie annonce ce qui serait validé, rien n'est écrit. */
    public function testDryRunWritesNothing(): void
    {
        ['club' => $club, 'planId' => $planId] = $this->startedPlan('DRY');

        $tester = $this->runAutoValidate(['--club' => $club, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 period plan(s) validated', $this->flat($tester));
        self::assertNull($this->decorChosenOf($club, $planId), 'dry-run : aucun pointeur posé');
    }

    /** `--club` borne le passage à ce club : un autre club éligible reste intact. */
    public function testClubOptionLeavesAnotherClubIntact(): void
    {
        ['club' => $club, 'planId' => $planId, 'versionId' => $versionId] = $this->startedPlan('MINE');
        ['club' => $other, 'planId' => $otherPlan] = $this->startedPlan('OTHER');

        $tester = $this->runAutoValidate(['--club' => $club]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($versionId, $this->decorChosenOf($club, $planId));
        self::assertNull($this->decorChosenOf($other, $otherPlan), 'l\'autre club n\'est pas balayé');
    }

    /** C3 — un `--date` postérieur au jour réel est refusé sans `--dry-run` : échec, rien écrit, même si la date rendrait le plan éligible. */
    public function testAFutureDateIsRefusedWithoutDryRun(): void
    {
        ['club' => $club, 'planId' => $planId] = $this->futurePlan('FUT');

        $tester = $this->runAutoValidate(['--club' => $club, '--date' => $this->decorDay(30)], expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--date cannot be in the future unless --dry-run.', $this->flat($tester));
        self::assertNull($this->decorChosenOf($club, $planId), 'date future refusée : rien écrit');
    }

    /** C3 — le même `--date` futur est accepté en `--dry-run` : la répétition annonce, n'écrit rien. */
    public function testAFutureDateIsAcceptedInDryRun(): void
    {
        ['club' => $club, 'planId' => $planId] = $this->futurePlan('FUTDRY');

        $tester = $this->runAutoValidate(['--club' => $club, '--date' => $this->decorDay(30), '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 period plan(s) validated', $this->flat($tester));
        self::assertNull($this->decorChosenOf($club, $planId));
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** @return array{club: string, planId: string, versionId: string} */
    private function startedPlan(string $tag): array
    {
        return $this->planStartingIn($tag, -10);
    }

    /**
     * Une période qui commence dans 20 jours : éligible le jour +27, pas aujourd'hui.
     *
     * @return array{club: string, planId: string, versionId: string}
     */
    private function futurePlan(string $tag): array
    {
        return $this->planStartingIn($tag, 20);
    }

    /** @return array{club: string, planId: string, versionId: string} */
    private function planStartingIn(string $tag, int $days): array
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub($tag, seasons: [[$this->decorDay(-200), $this->decorDay(200)]]);
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, $this->decorDay($days), $this->decorDay($days + 13));
        $versionId = $this->seedDecorVersion($season, $entryId);

        return ['club' => $club->getId(), 'planId' => $planId, 'versionId' => $versionId];
    }

    /** @param array<string, string|bool> $options */
    private function runAutoValidate(array $options, bool $expectSuccess = true): CommandTester
    {
        $this->clearGuc();
        $tester = new CommandTester(new Application(self::$kernel)->find('app:period-plans:auto-validate'));
        $tester->execute($options);
        if ($expectSuccess) {
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        }

        return $tester;
    }

    /** La sortie sur une ligne (SymfonyStyle replie les blocs longs). */
    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
