<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubInvitation;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Mail\ClubMailMetadata;
use App\Service\ClubInvitationMailer;
use App\Service\CoachWishMailBuilder;
use App\Service\MailFrom;
use App\Service\PeriodReminderMailBuilder;
use App\Service\PlacementRunEmailBuilder;
use App\Service\ProductIdentity;
use App\Service\TransitionReminderMailBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * D1 — l'identité du club ({@see ClubMailMetadata}) est posée par les builders/mailers à club
 * connu : `X-Amateo-Club-Id` + `X-Amateo-Club-Label` (nom court résolu, repli nom long), et RIEN
 * d'autre (aucun contenu métier réécrit).
 */
#[Group('phase1')]
final class ClubMailMetadataTest extends TestCase
{
    public function testMarkPostsIdAndShortNameLabel(): void
    {
        $club = new Club()->setName('BASKET CLUB DE LA VALLÉE')->setShortName('BC Vallée');
        $email = ClubMailMetadata::mark(new Email, $club);

        self::assertSame($club->getId(), $email->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_ID_HEADER));
        self::assertSame('BC Vallée', $email->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_LABEL_HEADER));
    }

    public function testMarkFallsBackToLongNameWhenNoShortName(): void
    {
        $club = new Club()->setName('BASKET CLUB DE LA VALLÉE');
        $email = ClubMailMetadata::mark(new Email, $club);

        self::assertSame('BASKET CLUB DE LA VALLÉE', $email->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_LABEL_HEADER));
    }

    public function testPeriodReminderBuilderPostsClubMetadata(): void
    {
        $club = new Club()->setName('Long')->setShortName('Court');
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('a@b.fr', 'Long', $this->period(), 7, $club);

        $this->assertHasMetadata($email, $club->getId(), 'Court');
    }

    public function testTransitionReminderBuilderPostsClubMetadata(): void
    {
        $club = new Club()->setName('Long')->setShortName('Court');
        $email = new TransitionReminderMailBuilder('http://localhost:5173')
            ->build('a@b.fr', 'Long', '2025-2026', new DateTimeImmutable('2026-05-01'), 30, $club);

        $this->assertHasMetadata($email, $club->getId(), 'Court');
    }

    public function testPlacementRunBuilderPostsClubMetadata(): void
    {
        $club = new Club()->setName('Long')->setShortName('Court');
        $email = new PlacementRunEmailBuilder('http://localhost:5173')->build('a@b.fr', 3, 1, $club);

        $this->assertHasMetadata($email, $club->getId(), 'Court');
    }

    public function testCoachWishDigestBuilderPostsClubMetadata(): void
    {
        $club = new Club()->setName('Long')->setShortName('Court');
        $email = new CoachWishMailBuilder('http://localhost:5173')
            ->buildDigest('a@b.fr', 'Long', 'Période 2', ['Anna'], ['Anna'], [], $club);

        $this->assertHasMetadata($email, $club->getId(), 'Court');
    }

    public function testBuilderPostsNoMetadataWhenClubIsAbsent(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('a@b.fr', 'Long', $this->period(), 7);

        self::assertFalse($email->getHeaders()->has(ClubMailMetadata::CLUB_ID_HEADER));
        self::assertFalse($email->getHeaders()->has(ClubMailMetadata::CLUB_LABEL_HEADER));
    }

    public function testInvitationMailerPostsClubMetadata(): void
    {
        $sent = [];
        $mailer = new class($sent) implements MailerInterface {
            /** @param list<Email> $sent */
            public function __construct(public array &$sent) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->sent[] = $message;
                }
            }
        };

        $club = new Club()->setName('Long')->setShortName('Court');
        $invitation = new ClubInvitation()->setEmail('invite@club.fr')->setExpiresAt(new DateTimeImmutable('2026-12-01'));

        $mailerService = new ClubInvitationMailer($mailer, new MailFrom, new ProductIdentity, 'http://localhost:5173', new NullLogger);
        $mailerService->send($invitation, 'raw-token', 'Alice', 'Long', 'https://host', $club);

        self::assertCount(1, $sent);
        $this->assertHasMetadata($sent[0], $club->getId(), 'Court');
    }

    private function assertHasMetadata(Email $email, string $clubId, string $label): void
    {
        self::assertSame($clubId, $email->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_ID_HEADER));
        self::assertSame($label, $email->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_LABEL_HEADER));
    }

    private function period(): CalendarEntry
    {
        return new CalendarEntry()
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setTitle('Gym fermé')
            ->setStartDate(new DateTimeImmutable('2026-05-04'))
            ->setEndDate(new DateTimeImmutable('2026-05-10'));
    }
}
