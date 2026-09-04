<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Message\ExportPdfMessage;
use App\MessageHandler\ExportPdfHandler;
use App\Service\PdfGenerator;
use App\Service\TenantConnectionContext;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * SEC-03 non-regression: a messenger handler runs with NO HTTP request, so no
 * tenant GUC is set by the listener. Under RLS the handler MUST scope the
 * connection itself from the message's clubId — otherwise its SELECT on
 * schedule returns nothing and the export silently no-ops.
 *
 * The GUC is cleared before invoking the handler: if the handler stops setting
 * it, findSchedule() comes back empty and the status assertion fails.
 * (GenerateScheduleHandler uses the identical pattern and is exercised
 * end-to-end under RLS by the Behat feature `backend/features/generation-du-planning-de-saison.feature`.)
 */
#[Group('phase1')]
#[Group('integration')]
final class ExportPdfHandlerRlsTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    public function testHandlerScopesItsOwnGuc(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Seed a club + schedule (schedule is club-scoped → GUC needed).
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('RLS Worker Club');
        $club->setSlug('rls-worker-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $em->persist($club);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $em->persist($season);
        $em->flush();

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('RLS export');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        // lot D : la version doit porter son plan SEASON avant le flush (schedule_plan_id NOT NULL).
        // linkSeededSchedule résout le plan, le pose, persiste et numérote — comme la prod au POST.
        $this->linkSeededSchedule($schedule);
        $em->flush();
        $em->clear();

        // Simulate the worker context: NO GUC when the handler starts.
        $this->clearGuc();

        $pdfGenerator = $this->createMock(PdfGenerator::class);
        $pdfGenerator->method('generate')->willReturn(['pdf' => '/exports/x.pdf']);
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static fn (Update $u): string => 'id');

        $handler = new ExportPdfHandler(
            $em,
            $pdfGenerator,
            $hub,
            $container->get(TenantConnectionContext::class),
        );

        $handler(new ExportPdfMessage(scheduleId: $schedule->getId(), clubId: $club->getId()));

        // Read back under the club's GUC: the handler must have found the
        // schedule (thanks to its own GUC) and completed the export.
        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($schedule->getId());
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame('completed', $reloaded->getPdfExportStatus(), 'handler must scope its own GUC to see and update the schedule');
        self::assertSame('/exports/x.pdf', $reloaded->getPdfExportUrl());
    }
}
