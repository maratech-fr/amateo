<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Schedule;
use App\Mercure\ClubTopicUpdate;
use App\Mercure\MercureTopic;
use App\Message\ExportPdfMessage;
use App\Service\PdfGenerator;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class ExportPdfHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PdfGenerator $pdfGenerator,
        private HubInterface $hub,
        private TenantConnectionContext $tenantConnectionContext,
    ) {}

    public function __invoke(ExportPdfMessage $message): void
    {
        $clubId = $message->getClubId();
        if (null === $clubId) {
            // Legacy payload queued before clubId existed: without it the
            // schedule is invisible under RLS. Notify failure on a best-effort
            // basis is impossible (no topic without clubId) — drop explicitly.
            return;
        }

        // RLS: no HTTP request in the worker → no GUC set by the listener. Scope
        // the connection to the message's club before any query, clear after.
        $this->tenantConnectionContext->setClubId($clubId);

        try {
            $this->export($message, $clubId);
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    private function export(ExportPdfMessage $message, string $clubId): void
    {
        $schedule = $this->findSchedule($message->getScheduleId());
        if (!$schedule instanceof Schedule) {
            // Under RLS an invisible schedule (deleted, or claimed by another
            // club) must not leave the frontend spinning on pdfExportStatus —
            // publish a failure on the requesting club's topic.
            $this->hub->publish(ClubTopicUpdate::private(
                MercureTopic::for($clubId, $message->getScheduleId()),
                json_encode(['pdfExportStatus' => 'failed', 'pdfExportUrl' => null], \JSON_THROW_ON_ERROR),
            ));

            return;
        }

        $schedule->setPdfExportStatus('generating');
        $this->entityManager->flush();

        try {
            $result = $this->pdfGenerator->generate($schedule, $message->getVenueId(), $message->getShowCoaches());
            $schedule->setPdfExportStatus('completed');
            $schedule->setPdfExportUrl($result['pdf']);
        } catch (Throwable) {
            $schedule->setPdfExportStatus('failed');
            $schedule->setPdfExportUrl(null);
        }

        $this->entityManager->flush();
        $this->publishProgress($schedule);
    }

    private function findSchedule(string $scheduleId): ?Schedule
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);

        return $schedule instanceof Schedule ? $schedule : null;
    }

    private function publishProgress(Schedule $schedule): void
    {
        $topic = MercureTopic::for($schedule->getClubId(), $schedule->getId());
        if (MercureTopic::isEmpty($topic)) {
            throw new LogicException('Schedule Mercure topic cannot be empty.');
        }

        $this->hub->publish(ClubTopicUpdate::private($topic, json_encode([
            'pdfExportStatus' => $schedule->getPdfExportStatus(),
            'pdfExportUrl' => $schedule->getPdfExportUrl(),
        ], \JSON_THROW_ON_ERROR)));
    }
}
