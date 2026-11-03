<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\ConflictMailing;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Repository\ConflictMailingRepository;
use App\Service\ClubDay;
use App\Service\ConflictCoachMailBuilder;
use App\Service\ConflictCoachMailing;
use App\Service\ConflictFeedAssembler;
use App\Service\ConflictMailingLock;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * « Envoyer aux coachs » (onglet Conflits) — `POST /api/fixtures/conflicts/mail-coaches`,
 * synchrone. Chaque coach concerné reçoit, à titre indicatif, SES conflits de personne sur un
 * match ; un coach qui détenait une liste et n'a plus de conflit reçoit « plus aucun conflit ».
 * Qui reçoit quoi : {@see ConflictCoachMailing} (le même service que les compteurs du GET).
 *
 * Ordre des refus : 409 saison archivée (noyau, {@see SeasonScopedWriteInterface}, AVANT
 * l'action) → 403 gestionnaire → 403 démo → 409 socle non choisi → 409 envoi déjà en cours
 * (verrou club, relâché en `finally` sur tout chemin) → 422 saison introuvable → 422 rien à
 * envoyer → 429 plafond (consommé pour le nombre EXACT de mails à enfiler, lot refusé en bloc) →
 * envois best-effort → mémoire + ligne « Envoyé le … » seulement si au moins un mail est enfilé.
 *
 * Limites déclarées : le verrou ne protège que les envois SIMULTANÉS ; mails enfilés puis échec
 * de l'upsert → 500, mémoire non écrite, un nouveau clic renvoie les listes.
 */
final class ConflictCoachMailController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    private const int LOCK_TTL_SECONDS = 60;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonResolver $seasonResolver,
        private readonly SocleGuard $socleGuard,
        private readonly ConflictMailingLock $lock,
        private readonly ConflictFeedAssembler $feedAssembler,
        private readonly ConflictCoachMailing $coachMailing,
        private readonly ConflictCoachMailBuilder $mailBuilder,
        private readonly ConflictMailingRepository $mailingRepository,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly ClubDay $clubDay,
        private readonly RateLimiterFactory $conflictMailSendLimiter,
    ) {}

    /** L'instant servi à l'écran : ATOM à l'heure murale du club (l'écran découpe la chaîne). */
    public static function wallClock(DateTimeImmutable $at, ?Club $club): string
    {
        $zone = $club instanceof Club && '' !== $club->getTimezone() ? $club->getTimezone() : 'Europe/Paris';

        return $at->setTimezone(new DateTimeZone($zone))->format(DateTimeInterface::ATOM);
    }

    /** Écriture de COLLECTION : la saison du header (ou courante) gouverne. */
    public function writeTargetSeasonId(Request $request): ?string
    {
        return null;
    }

    // priority > 0 : ce chemin statique doit gagner sur l'item API Platform /api/fixtures/{id}.
    #[Route('/api/fixtures/conflicts/mail-coaches', name: 'api_fixture_conflicts_mail_coaches', methods: ['POST'], priority: 10)]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $season = null !== $clubId ? $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId) : null;
        // Saison archivée À LA MAIN (statut gelé) : le garde du noyau ne lit que le calendrier ;
        // même refus, même rang (avant le rôle), patron CoachWishSeasonGuard.
        if ($season instanceof Season && $season->getStatus()->isFrozen()) {
            return $this->json(['detail' => 'Cette saison est archivée — elle est en lecture seule.'], Response::HTTP_CONFLICT);
        }

        $this->managementAccessGuard->assertManager();

        $club = null !== $clubId ? $this->entityManager->getRepository(Club::class)->find($clubId) : null;
        if (!$club instanceof Club) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        // Anti-hameçonnage : un compte de démonstration n'e-maille jamais un tiers.
        if ($club->isDemo()) {
            return $this->json(['error' => 'Envoi d\'e-mails indisponible en démonstration.'], Response::HTTP_FORBIDDEN);
        }

        // 409 du garde du socle, rendu en `{detail}` quel que soit l'Accept (l'écran lit `detail`).
        try {
            $this->socleGuard->assertSeasonPlanChosen($season?->getId());
        } catch (ConflictHttpException $refusal) {
            return $this->json(['detail' => $refusal->getMessage()], Response::HTTP_CONFLICT);
        }

        $token = $this->lock->acquire($club->getId(), self::LOCK_TTL_SECONDS);
        if (null === $token) {
            return $this->json(['error' => 'Un envoi est déjà en cours, réessayez dans un instant.'], Response::HTTP_CONFLICT);
        }

        try {
            if (!$season instanceof Season) {
                return $this->json(['error' => 'Aucune saison sélectionnée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->send($club, $season);
        } finally {
            $this->lock->release($club->getId(), $token);
        }
    }

    private function send(Club $club, Season $season): JsonResponse
    {
        $feed = $this->feedAssembler->assemble($club->getId(), $season);
        $inputs = $this->feedAssembler->mailingInputs();
        $coaches = $inputs['coachesById'];
        $row = $this->mailingRepository->findOneBySeason($season->getId());
        $memory = $row?->getRecipientCoachIds() ?? [];

        $plan = $this->coachMailing->plan($feed['conflicts'], $inputs['mainCoachIdsByTeam'], $feed['groupsBySchedule'], $memory, $coaches);
        if (0 === $plan['personConflictCount'] && 0 === $plan['pendingRecipientCount']) {
            return $this->json(['error' => 'Aucun conflit à envoyer et aucun coach à prévenir.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var list<array{coach: Coach, kind: string}> $toSend */
        $toSend = [];
        $skipped = [];
        foreach (array_keys($plan['byCoach']) as $coachId) {
            $this->route($coaches[$coachId], 'conflicts', $toSend, $skipped);
        }
        foreach ($plan['cleared'] as $coachId) {
            $this->route($coaches[$coachId], 'cleared', $toSend, $skipped);
        }

        if ([] !== $toSend && !$this->consume($club, \count($toSend))) {
            return $this->json(['error' => 'Limite d\'envoi atteinte (200 e-mails / 24 h), réessayez demain.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->getUser();
        $senderFirstName = $user instanceof User ? $user->getFirstName() : '';
        $today = $this->clubDay->todayFor($club);
        [$teams, $venues] = $this->teamsAndVenues();

        $recipients = [];
        $cleared = [];
        $failed = [];
        foreach ($toSend as ['coach' => $coach, 'kind' => $kind]) {
            $to = (string) $coach->getEmail();
            try {
                if ('conflicts' === $kind) {
                    $list = $plan['byCoach'][$coach->getId()];
                    $this->mailer->send($this->mailBuilder->buildConflicts($to, $coach, $club, $season, $senderFirstName, $today, $list, $teams, $venues, $coaches));
                    $recipients[] = $this->person($coach) + ['conflictCount' => \count($list)];
                } else {
                    $this->mailer->send($this->mailBuilder->buildCleared($to, $coach, $club, $season, $senderFirstName, $today));
                    $cleared[] = $this->person($coach);
                }
            } catch (Throwable) {
                // Best-effort : l'échec d'UN envoi ne bloque pas les autres ; il est nommé.
                $failed[] = $this->person($coach) + ['kind' => $kind];
            }
        }

        $sentAt = null;
        if ([] !== $recipients || [] !== $cleared) {
            $now = DateTimeImmutable::createFromInterface($this->clock->now());
            $next = $this->coachMailing->nextMemory($memory, array_column($recipients, 'coachId'), array_column($cleared, 'coachId'), $coaches);
            $this->mailingRepository->upsert(
                (new ConflictMailing)
                    ->setClubId($club->getId())
                    ->setSeasonId($season->getId())
                    ->setSentAt($now)
                    ->setSentByUserId($user instanceof User ? $user->getId() : null)
                    ->setRecipientCoachIds($next)
                    ->setSkippedCoachIds(array_column($skipped, 'coachId'))
                    ->setMailedCount(\count($recipients) + \count($cleared)),
            );
            $sentAt = self::wallClock($now, $club);
        }

        return $this->json([
            'sentAt' => $sentAt,
            'conflictCount' => $plan['personConflictCount'],
            'recipients' => $recipients,
            'cleared' => $cleared,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /**
     * Un coach à adresse valide part dans le lot ; sans adresse, il est sauté et nommé.
     *
     * @param list<array{coach: Coach, kind: string}>                                         $toSend
     * @param list<array{coachId: string, firstName: string, lastName: string, kind: string}> $skipped
     */
    private function route(Coach $coach, string $kind, array &$toSend, array &$skipped): void
    {
        if (ConflictCoachMailing::hasValidAddress($coach)) {
            $toSend[] = ['coach' => $coach, 'kind' => $kind];

            return;
        }
        $skipped[] = $this->person($coach) + ['kind' => $kind];
    }

    /** Plafond anti-hameçonnage PAR CLUB, consommé AVANT l'envoi pour le nombre exact de mails. */
    private function consume(Club $club, int $count): bool
    {
        try {
            return $this->conflictMailSendLimiter->create($club->getId())->consume($count)->isAccepted();
        } catch (InvalidArgumentException) {
            // Un lot plus grand que le plafond lui-même : même refus qu'un budget épuisé.
            return false;
        }
    }

    /** @return array{0: array<string, Team>, 1: array<string, Venue>} */
    private function teamsAndVenues(): array
    {
        $teams = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy([]) as $team) {
            $teams[$team->getId()] = $team;
        }
        $venues = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy([]) as $venue) {
            $venues[$venue->getId()] = $venue;
        }

        return [$teams, $venues];
    }

    /** @return array{coachId: string, firstName: string, lastName: string} */
    private function person(Coach $coach): array
    {
        return ['coachId' => $coach->getId(), 'firstName' => $coach->getFirstName(), 'lastName' => $coach->getLastName()];
    }
}
