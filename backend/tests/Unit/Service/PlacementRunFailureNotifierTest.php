<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\User;
use App\Enum\MatchPlacementRunStatus;
use App\Service\PlacementRunEmailBuilder;
use App\Service\PlacementRunFailureNotifier;
use App\Tests\Double\RecordingLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Promesse : prévenir le demandeur d'un run de placement ÉCHOUÉ est best-effort PAR RUN — un
 * envoi qui casse ne remonte jamais (le rattrapage au démarrage du worker traite plusieurs runs
 * à la suite : un SMTP mort ne doit pas arrêter les suivants), et seul un run de plus de deux
 * minutes prévient (le gestionnaire a eu le temps de quitter l'écran).
 *
 * Angle jumeau, cité : `PlaceMatchesHandlerTest::testALongFailedRunAlsoEmailsTheRequester` et
 * `::testAShortFailedRunDoesNotEmail` (le même seuil, vécu par le handler).
 */
final class PlacementRunFailureNotifierTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];

    public function testAMailerThatThrowsIsSwallowedAndLogged(): void
    {
        $logger = new RecordingLogger;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new RuntimeException('SMTP down'));

        $run = $this->failedRun(secondsLong: 600);
        new PlacementRunFailureNotifier($this->entityManagerFinding($run), $mailer, new PlacementRunEmailBuilder, $logger)->notify($run);

        self::assertSame(MatchPlacementRunStatus::FAILED, $run->getStatus(), 'prévenir ne touche jamais au run');
        $warnings = array_filter($logger->records, static fn (array $r): bool => LogLevel::WARNING === $r['level']);
        self::assertCount(1, $warnings, 'un envoi raté est journalisé en warning, jamais propagé');
    }

    public function testALongFailedRunEmailsTheRequester(): void
    {
        $run = $this->failedRun(secondsLong: 121);
        new PlacementRunFailureNotifier($this->entityManagerFinding($run), $this->spyMailer(), new PlacementRunEmailBuilder)->notify($run);

        self::assertCount(1, $this->sent, 'un échec de plus de deux minutes prévient le demandeur');
        self::assertSame('requester@test.fr', $this->sent[0]->getTo()[0]->getAddress());
    }

    public function testAShortFailedRunDoesNotEmail(): void
    {
        $run = $this->failedRun(secondsLong: 120);
        new PlacementRunFailureNotifier($this->entityManagerFinding($run), $this->spyMailer(), new PlacementRunEmailBuilder)->notify($run);

        self::assertCount(0, $this->sent, 'deux minutes pile : le seuil est STRICT, personne n\'est prévenu');
    }

    private function failedRun(int $secondsLong): MatchPlacementRun
    {
        $createdAt = new DateTimeImmutable('2026-11-11 10:00:00');
        $run = new MatchPlacementRun('00000000-0000-4000-8000-0000000000c1', null, '00000000-0000-4000-8000-0000000000a1', $createdAt);
        $run->setStatus(MatchPlacementRunStatus::FAILED)
            ->setStartedAt($createdAt)
            ->setFinishedAt($createdAt->modify(\sprintf('+%d seconds', $secondsLong)))
            ->setResultData(['error' => 'Le placement automatique a été interrompu. Relancez-le.']);

        return $run;
    }

    private function entityManagerFinding(MatchPlacementRun $run): EntityManagerInterface
    {
        $user = new User;
        $user->setEmail('requester@test.fr');
        $user->setFirstName('Re');
        $user->setLastName('Quester');

        $users = $this->createMock(EntityRepository::class);
        $users->method('find')->willReturn($user);
        $clubs = $this->createMock(EntityRepository::class);
        $clubs->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            User::class => $users,
            Club::class => $clubs,
            default => throw new RuntimeException('dépôt inattendu : ' . $class),
        });

        return $em;
    }

    private function spyMailer(): MailerInterface
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $this->sent[] = $message;
            }
        });

        return $mailer;
    }
}
