<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Entity\CalendarEntry;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Service\PeriodReminderMailBuilder;
use App\Tests\AssertsActionMailCta;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class PeriodReminderEmailTest extends TestCase
{
    use AssertsActionMailCta;

    public function testReminderCarriesTheCockpitCta(): void
    {
        // PROMESSE (D1) : le rappel « période sans plan » porte un bouton « Ouvrir le cockpit »
        // vers le lien du corps. ROUGE tant que le builder ne pose pas l'en-tête CTA.
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('admin@club.fr', 'BCCL', $this->period(), 14);

        $this->assertCtaHeadersPointToBodyLink($email, 'http://localhost:5173/', 'Ouvrir le cockpit');
    }

    public function testSubjectAndBodyAtJ14(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('admin@club.fr', 'BCCL', $this->period(), 14);

        self::assertSame('⏳ Gym Barros fermé dans 14 j — pas de plan de période', $email->getSubject());
        self::assertSame('admin@club.fr', $email->getTo()[0]->getAddress());
        self::assertSame('no-reply@amateo.app', $email->getFrom()[0]->getAddress());
        self::assertSame('Amateo', $email->getFrom()[0]->getName());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('BCCL', $body);
        self::assertStringContainsString('Gym Barros fermé', $body);
        self::assertStringContainsString('Du 04/05/2026 au 10/05/2026', $body);
        self::assertStringContainsString('http://localhost:5173/', $body);
    }

    public function testRedSubjectAtJ3(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('a@b.fr', 'BCCL', $this->period(), 3);
        self::assertStringStartsWith('🔴', (string) $email->getSubject());
    }

    public function testNoLinkWhenBaseUrlEmpty(): void
    {
        $email = new PeriodReminderMailBuilder('')->build('a@b.fr', 'BCCL', $this->period(), 7);
        self::assertStringNotContainsString('http', (string) $email->getTextBody());
    }

    private function period(): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId('club-1');
        $entry->setSeasonId('season-1');
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym Barros fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));

        return $entry;
    }
}
