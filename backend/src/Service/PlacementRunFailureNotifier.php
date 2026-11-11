<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * Prévient par e-mail le gestionnaire qui a lancé un run de placement ÉCHOUÉ, quand ce run a duré
 * plus de deux minutes (il a eu le temps de quitter l'écran et croirait sinon que le moteur
 * travaille encore). Partagé par le handler, le rattrapage des runs figés et le listener d'échec
 * définitif.
 *
 * Best-effort PAR RUN : tout échec (base, SMTP) est journalisé en warning et jamais propagé — le
 * rattrapage traite plusieurs runs à la suite, un envoi raté ne doit pas arrêter les suivants, et
 * le run FAILED déjà persisté n'est jamais altéré.
 */
final class PlacementRunFailureNotifier
{
    /** Seuil (strict) au-delà duquel on prévient le demandeur. */
    public const int EMAIL_THRESHOLD_SECONDS = 120;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly PlacementRunEmailBuilder $emailBuilder,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Le run a-t-il dépassé le seuil d'e-mail (le demandeur a eu le temps de quitter l'écran) ? */
    public static function exceededEmailThreshold(MatchPlacementRun $run): bool
    {
        $finishedAt = $run->getFinishedAt();

        return $finishedAt instanceof DateTimeImmutable
            && $finishedAt->getTimestamp() - $run->getCreatedAt()->getTimestamp() > self::EMAIL_THRESHOLD_SECONDS;
    }

    public function notify(MatchPlacementRun $run): void
    {
        try {
            if (!self::exceededEmailThreshold($run)) {
                return;
            }

            $user = $this->entityManager->getRepository(User::class)->find($run->getRequestedByUserId());
            if (!$user instanceof User) {
                return;
            }

            // Identité du club (logo + nom court) sur la carte d'e-mail, nullable best-effort.
            $club = $this->entityManager->getRepository(Club::class)->find((string) $run->getClubId());

            $this->mailer->send($this->emailBuilder->buildFailure($user->getEmail(), $club instanceof Club ? $club : null));
        } catch (Throwable $exception) {
            $this->logger?->warning('Match placement failure e-mail failed (best-effort)', ['runId' => $run->getId(), 'exception' => $exception]);
        }
    }
}
