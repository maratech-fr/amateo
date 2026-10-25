<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Service\TransitionReminderMailBuilder;
use App\Tests\AssertsActionMailCta;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class TransitionReminderEmailTest extends TestCase
{
    use AssertsActionMailCta;

    private const PIVOT = '2026-07-15';

    public function testReminderCarriesTheOpenAppCta(): void
    {
        // PROMESSE (D1) : le rappel « préparer la saison suivante » porte un bouton « Ouvrir
        // l'application » vers le lien du corps. ROUGE tant que le builder ne pose pas l'en-tête.
        $email = new TransitionReminderMailBuilder('http://localhost:5173')->build('admin@club.fr', 'BCCL', '2025-2026', new DateTimeImmutable(self::PIVOT), 56);

        $this->assertCtaHeadersPointToBodyLink($email, 'http://localhost:5173/', 'Ouvrir l\'application');
    }

    public function testSubjectAndBodyAtJ56(): void
    {
        $email = new TransitionReminderMailBuilder('http://localhost:5173')
            ->build('admin@club.fr', 'BCCL', '2025-2026', new DateTimeImmutable(self::PIVOT), 56);

        self::assertSame('⏳ Préparez la saison suivante — bascule dans 56 j', $email->getSubject());
        self::assertSame('admin@club.fr', $email->getTo()[0]->getAddress());
        self::assertSame('no-reply@amateo.app', $email->getFrom()[0]->getAddress());
        self::assertSame('Amateo', $email->getFrom()[0]->getName());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('BCCL', $body);
        self::assertStringContainsString('2025-2026', $body);
        self::assertStringContainsString('15/07/2026', $body);
        self::assertStringContainsString('Préparer la saison suivante', $body);
        self::assertStringContainsString('http://localhost:5173/', $body);
    }

    public function testRedSubjectAtJ14(): void
    {
        $email = new TransitionReminderMailBuilder('http://localhost:5173')
            ->build('a@b.fr', 'BCCL', '2025-2026', new DateTimeImmutable(self::PIVOT), 10);
        self::assertStringStartsWith('🔴', (string) $email->getSubject());
    }

    public function testNoLinkWhenBaseUrlEmpty(): void
    {
        $email = new TransitionReminderMailBuilder('')
            ->build('a@b.fr', 'BCCL', '2025-2026', new DateTimeImmutable(self::PIVOT), 30);
        self::assertStringNotContainsString('http', (string) $email->getTextBody());
    }
}
