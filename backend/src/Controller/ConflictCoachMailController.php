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
use App\Repository\ConflictMailReceiptRepository;
use App\Service\ClubDay;
use App\Service\ConflictCoachMailBuilder;
use App\Service\ConflictCoachMailing;
use App\Service\ConflictFeedAssembler;
use App\Service\ConflictMailingLock;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use App\Support\FrenchNameOrder;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use JsonException;
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
 * synchrone. Chaque coach concerné reçoit, à titre indicatif, SA liste complète de conflits de
 * personne sur un match ; par défaut seuls partent les coachs qui ont un conflit nouveau ou modifié
 * depuis le dernier envoi (marqués dans le mail), `{resendAll: true}` renvoie toutes les listes ;
 * un coach qui détenait une liste et n'a plus de conflit reçoit « plus aucun conflit ». Qui reçoit
 * quoi : {@see ConflictCoachMailing} (le même service que les compteurs du GET conflits).
 * `GET …/mail-coaches/preview` ({@see preview}) dit AVANT l'envoi, coach par coach, ce que le POST
 * fera — même calcul ({@see prepare}).
 *
 * Ordre des refus : 409 saison archivée (noyau, {@see SeasonScopedWriteInterface}, AVANT
 * l'action, GET compris) → 409 saison gelée à la main → 403 gestionnaire → 403 démo → 409 socle
 * non choisi → (POST) 409 envoi déjà en cours (verrou club, relâché en `finally` sur tout chemin) →
 * 422 saison introuvable → (POST) 422 option invalide → 422 rien à envoyer → 422 rien de nouveau →
 * 429 plafond (consommé pour le nombre EXACT de mails à enfiler, lot refusé en bloc) → envois
 * best-effort → reçus par conflit + mémoire + ligne « Envoyé le … », en UNE transaction,
 * seulement si au moins un mail est enfilé.
 *
 * Limites déclarées : le verrou ne protège que les envois SIMULTANÉS ; mails enfilés puis échec
 * de l'écriture → 500, ni reçu ni mémoire écrits, un nouveau clic renvoie les listes.
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
        private readonly ConflictMailReceiptRepository $receiptRepository,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly ClubDay $clubDay,
        private readonly RateLimiterFactory $conflictMailSendLimiter,
    ) {}

    /** Écriture de COLLECTION : la saison du header (ou courante) gouverne. */
    public function writeTargetSeasonId(Request $request): ?string
    {
        return null;
    }

    // priority > 0 : ce chemin statique doit gagner sur l'item API Platform /api/fixtures/{id}.
    #[Route('/api/fixtures/conflicts/mail-coaches', name: 'api_fixture_conflicts_mail_coaches', methods: ['POST'], priority: 10)]
    public function __invoke(): JsonResponse
    {
        $guarded = $this->guard();
        if ($guarded instanceof JsonResponse) {
            return $guarded;
        }
        [$club, $season] = $guarded;

        $token = $this->lock->acquire($club->getId(), self::LOCK_TTL_SECONDS);
        if (null === $token) {
            return $this->json(['error' => 'Un envoi est déjà en cours, réessayez dans un instant.'], Response::HTTP_CONFLICT);
        }

        try {
            if (!$season instanceof Season) {
                return $this->json(['error' => 'Aucune saison sélectionnée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $resendAll = $this->resendAllOption();
            if (null === $resendAll) {
                return $this->json(['error' => 'Option « tout renvoyer » invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->send($club, $season, $resendAll);
        } finally {
            $this->lock->release($club->getId(), $token);
        }
    }

    /**
     * L'aperçu avant envoi — `GET …/mail-coaches/preview` : coach par coach, ce que le POST fera
     * (mêmes `plan()`/`classify()`/adresse, via {@see prepare}), par défaut et en « tout renvoyer »,
     * avec le motif quand rien ne partirait. Lecture seule : ni verrou, ni quota, ni écriture.
     * Mêmes refus, même rang que le POST (sans verrou ni quota) ; aucun 422 « rien » : une liste vide.
     */
    #[Route('/api/fixtures/conflicts/mail-coaches/preview', name: 'api_fixture_conflicts_mail_coaches_preview', methods: ['GET'], priority: 10)]
    public function preview(): JsonResponse
    {
        $guarded = $this->guard();
        if ($guarded instanceof JsonResponse) {
            return $guarded;
        }
        [$club, $season] = $guarded;
        if (!$season instanceof Season) {
            return $this->json(['error' => 'Aucune saison sélectionnée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $prepared = $this->prepare($club, $season, false);
        $plan = $prepared['plan'];
        $coaches = $prepared['coaches'];
        $timezone = $this->clubDay->timezoneFor($club);

        $rows = [];
        foreach ($plan['byCoach'] as $coachId => $list) {
            $verdict = $prepared['classified'][$coachId];
            $rows[] = $this->previewRow($coaches[$coachId], 'conflicts', \count($list), $verdict['receiptKnown'], \count($verdict['new']), \count($verdict['changed']), $verdict['receiptKnown'] ? \count($verdict['unchanged']) : 0, $verdict['lastSentAt']?->setTimezone($timezone));
        }
        foreach ($plan['cleared'] as $coachId) {
            $rows[] = $this->previewRow($coaches[$coachId], 'cleared', 0, [] !== ($prepared['receipts'][$coachId] ?? []), 0, 0, 0, null);
        }

        // Ce qui part d'abord, puis les coachs sans adresse valide, puis rien de nouveau ; alpha ensuite.
        $rank = static fn (array $row): int => $row['sendsByDefault'] ? 0 : ($row['hasValidAddress'] ? 2 : 1);
        usort($rows, static fn (array $a, array $b): int => ($rank($a) <=> $rank($b)) ?: FrenchNameOrder::compare($a['firstName'] . ' ' . $a['lastName'], $b['firstName'] . ' ' . $b['lastName']));

        $defaultMailCount = \count(array_filter($rows, static fn (array $row): bool => $row['sendsByDefault']));
        $resendAllMailCount = \count(array_filter($rows, static fn (array $row): bool => $row['sendsOnResendAll']));
        $withAddress = \count(array_filter($rows, static fn (array $row): bool => $row['hasValidAddress']));

        return $this->json([
            'seasonId' => $season->getId(),
            'personConflictCount' => $plan['personConflictCount'],
            'defaultMailCount' => $defaultMailCount,
            'resendAllMailCount' => $resendAllMailCount,
            'blockedByDefault' => 0 !== $defaultMailCount ? null : match (true) {
                [] === $rows => 'nothing',
                0 === $withAddress => 'no_address',
                \count($rows) === $withAddress => 'nothing_new',
                default => 'mixed',
            },
            'blockedOnResendAll' => 0 !== $resendAllMailCount ? null : ([] === $rows ? 'nothing' : 'no_address'),
            'recipients' => $rows,
        ]);
    }

    /**
     * Les refus communs à l'aperçu et à l'envoi, dans leur ordre : 409 saison gelée à la main →
     * 403 gestionnaire → 400 sans club → 403 démo → 409 socle (le 409 du calendrier est passé avant,
     * au noyau).
     *
     * @return JsonResponse|array{0: Club, 1: Season|null}
     */
    private function guard(): JsonResponse|array
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $season = null !== $clubId ? $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId) : null;
        $frozen = $this->frozenRefusal($season);
        if ($frozen instanceof JsonResponse) {
            return $frozen;
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

        return [$club, $season];
    }

    /**
     * Saison archivée À LA MAIN (statut gelé) : le garde du noyau ne lit que le calendrier ; même
     * refus, même rang (avant le rôle), patron CoachWishSeasonGuard — pour l'aperçu comme pour l'envoi.
     */
    private function frozenRefusal(?Season $season): ?JsonResponse
    {
        if ($season instanceof Season && $season->getStatus()->isFrozen()) {
            return $this->json(['detail' => 'Cette saison est archivée — elle est en lecture seule.'], Response::HTTP_CONFLICT);
        }

        return null;
    }

    /** Le corps du POST : vide = false ; `resendAll` absent = false ; non booléen → null (refus). */
    private function resendAllOption(): ?bool
    {
        $raw = trim((string) $this->requestStack->getCurrentRequest()?->getContent());
        if ('' === $raw) {
            return false;
        }
        try {
            $body = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!\is_array($body)) {
            return null;
        }
        if (!\array_key_exists('resendAll', $body)) {
            return false;
        }

        return \is_bool($body['resendAll']) ? $body['resendAll'] : null;
    }

    /**
     * Ce que l'aperçu et l'envoi calculent À L'IDENTIQUE : le plan, les reçus, le verdict par coach,
     * puis le tri — un coach `conflicts` sans nouveauté (reçus connus, rien de nouveau ni de modifié)
     * est écarté AVANT le tri des adresses quand on ne renvoie pas tout (ni servi, ni sauté) ; un
     * coach « plus aucun » et un coach sans reçu connu partent toujours.
     *
     * @return array{
     *     plan: array{byCoach: array<string, list<array<string, mixed>>>, cleared: list<string>, pendingRecipientCount: int, personConflictCount: int, recipientCount: int},
     *     coaches: array<string, Coach>,
     *     memory: list<string>,
     *     receipts: array<string, array<string, array{digest: string, sentAt: DateTimeImmutable}>>,
     *     classified: array<string, array{receiptKnown: bool, new: list<string>, changed: list<string>, unchanged: list<string>, lastSentAt: DateTimeImmutable|null}>,
     *     toSend: list<array{coach: Coach, kind: string}>,
     *     skipped: list<array{coachId: string, firstName: string, lastName: string, kind: string}>,
     *     notNew: list<string>,
     * }
     */
    private function prepare(Club $club, Season $season, bool $resendAll): array
    {
        $feed = $this->feedAssembler->assemble($club->getId(), $season);
        $inputs = $this->feedAssembler->mailingInputs();
        $coaches = $inputs['coachesById'];
        $memory = $this->mailingRepository->findOneBySeason($season->getId())?->getRecipientCoachIds() ?? [];

        $plan = $this->coachMailing->plan($feed['conflicts'], $inputs['mainCoachIdsByTeam'], $feed['groupsBySchedule'], $memory, $coaches);
        $receipts = $this->receiptRepository->mapBySeason($season->getId());
        $classified = $this->coachMailing->classify($plan['byCoach'], $receipts);

        $toSend = [];
        $skipped = [];
        $notNew = [];
        foreach (array_keys($plan['byCoach']) as $coachId) {
            $verdict = $classified[$coachId];
            if (!$resendAll && $verdict['receiptKnown'] && [] === $verdict['new'] && [] === $verdict['changed']) {
                $notNew[] = $coachId;

                continue;
            }
            $this->route($coaches[$coachId], 'conflicts', $toSend, $skipped);
        }
        foreach ($plan['cleared'] as $coachId) {
            $this->route($coaches[$coachId], 'cleared', $toSend, $skipped);
        }

        return ['plan' => $plan, 'coaches' => $coaches, 'memory' => $memory, 'receipts' => $receipts, 'classified' => $classified, 'toSend' => $toSend, 'skipped' => $skipped, 'notNew' => $notNew];
    }

    private function send(Club $club, Season $season, bool $resendAll): JsonResponse
    {
        $prepared = $this->prepare($club, $season, $resendAll);
        $plan = $prepared['plan'];
        $coaches = $prepared['coaches'];
        $memory = $prepared['memory'];
        $toSend = $prepared['toSend'];
        $skipped = $prepared['skipped'];
        if (0 === $plan['personConflictCount'] && 0 === $plan['pendingRecipientCount']) {
            return $this->json(['error' => 'Aucun conflit à envoyer et aucun coach à prévenir.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Rien de nouveau pour personne, et personne à nommer « sans adresse » : refus nommé. Si un
        // coach sans adresse a du nouveau, c'est le 200 habituel (0 mail, il est nommé).
        if ([] === $toSend && [] === $skipped && [] !== $prepared['notNew']) {
            return $this->json(['error' => 'Rien de nouveau depuis le dernier envoi : cochez « Tout renvoyer » pour renvoyer les listes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ([] !== $toSend && !$this->consume($club, \count($toSend))) {
            return $this->json(['error' => 'Limite d\'envoi atteinte (200 e-mails / 24 h), réessayez demain.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->getUser();
        $senderFirstName = $user instanceof User ? $user->getFirstName() : '';
        $today = $this->clubDay->todayFor($club);
        $timezone = $this->clubDay->timezoneFor($club);
        [$teams, $venues] = $this->teamsAndVenues();

        $recipients = [];
        $cleared = [];
        $failed = [];
        /** @var array<string, array<string, string>> $sentLists coach → empreinte → digest de ce qui lui est parti */
        $sentLists = [];
        foreach ($toSend as ['coach' => $coach, 'kind' => $kind]) {
            $to = (string) $coach->getEmail();
            try {
                if ('conflicts' === $kind) {
                    $list = $plan['byCoach'][$coach->getId()];
                    $verdict = $prepared['classified'][$coach->getId()];
                    $marks = array_fill_keys($verdict['new'], 'new') + array_fill_keys($verdict['changed'], 'changed');
                    $previous = $verdict['receiptKnown'] ? $this->lastReceiptAt($prepared['receipts'][$coach->getId()] ?? [])?->setTimezone($timezone) : null;
                    $this->mailer->send($this->mailBuilder->buildConflicts($to, $coach, $club, $season, $senderFirstName, $today, $list, $teams, $venues, $coaches, $marks, $previous));
                    $recipients[] = $this->person($coach) + ['conflictCount' => \count($list), 'newCount' => \count($verdict['new']), 'changedCount' => \count($verdict['changed']), 'receiptKnown' => $verdict['receiptKnown']];
                    $sentLists[$coach->getId()] = ConflictCoachMailing::receiptRows($list);
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
            // Reçus par conflit ET mémoire « qui détient une liste » : UNE transaction, sur LA
            // connexion de l'EntityManager (celle du GUC tenant) — jamais l'un sans l'autre.
            $this->entityManager->getConnection()->transactional(function () use ($club, $season, $sentLists, $cleared, $now, $user, $next, $skipped, $recipients): void {
                foreach ($sentLists as $coachId => $rows) {
                    $this->receiptRepository->replaceForCoach($club->getId(), $season->getId(), (string) $coachId, $rows, $now);
                }
                $this->receiptRepository->deleteForCoaches($season->getId(), array_column($cleared, 'coachId'));
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
            });
            // L'instant servi à l'écran : ATOM à l'heure murale du club (l'écran découpe la chaîne).
            $sentAt = $now->setTimezone($timezone)->format(DateTimeInterface::ATOM);
        }

        return $this->json([
            'sentAt' => $sentAt,
            'resendAll' => $resendAll,
            'conflictCount' => $plan['personConflictCount'],
            'recipients' => $recipients,
            'cleared' => $cleared,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /**
     * Le dernier envoi détaillé à ce coach (tous ses reçus, conflits disparus compris : ils datent
     * le même envoi) — la date de la phrase « Depuis le dernier envoi du … » du mail.
     *
     * @param array<string, array{digest: string, sentAt: DateTimeImmutable}> $receipts
     */
    private function lastReceiptAt(array $receipts): ?DateTimeImmutable
    {
        $last = null;
        foreach ($receipts as $receipt) {
            if (!$last instanceof DateTimeImmutable || $receipt['sentAt'] > $last) {
                $last = $receipt['sentAt'];
            }
        }

        return $last;
    }

    /**
     * Une ligne de l'aperçu. `sendsByDefault` : adresse valide ET (« plus aucun », aucun reçu connu,
     * ou du nouveau / du modifié) ; `sendsOnResendAll` : adresse valide.
     *
     * @return array{coachId: string, firstName: string, lastName: string, email: string|null, hasValidAddress: bool, kind: string, receiptKnown: bool, conflictCount: int, newCount: int, changedCount: int, alreadySentCount: int, lastSentAt: string|null, sendsByDefault: bool, sendsOnResendAll: bool}
     */
    private function previewRow(Coach $coach, string $kind, int $conflictCount, bool $receiptKnown, int $newCount, int $changedCount, int $alreadySentCount, ?DateTimeImmutable $lastSentAt): array
    {
        $valid = ConflictCoachMailing::hasValidAddress($coach);

        return $this->person($coach) + [
            'email' => $coach->getEmail(),
            'hasValidAddress' => $valid,
            'kind' => $kind,
            'receiptKnown' => $receiptKnown,
            'conflictCount' => $conflictCount,
            'newCount' => $newCount,
            'changedCount' => $changedCount,
            'alreadySentCount' => $alreadySentCount,
            'lastSentAt' => $lastSentAt?->format(DateTimeInterface::ATOM),
            'sendsByDefault' => $valid && ('cleared' === $kind || !$receiptKnown || $newCount + $changedCount > 0),
            'sendsOnResendAll' => $valid,
        ];
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
