<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Mail\EmailTemplateRenderer;
use App\Mail\MailSafeName;
use App\Message\Basketball\PopulateClubFromFfbbMessage;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbClubDirectory;
use App\Service\Registration\ClubWinBackService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * P3-4 — le cycle de vie d'une demande de création de club (anti-squatting).
 *
 * Décisions fondateur (2026-08-05) :
 *  - la preuve du PREMIER gestionnaire = l'approbation du CLUB lui-même, via
 *    son mail institutionnel FFBB (tout gestionnaire y a accès) ;
 *  - mail FFBB introuvable (code inconnu de l'index, API muette) → la demande
 *    reste en attente, VISIBLE du superadmin (PR B) — jamais de création
 *    directe ;
 *  - expiration à 7 jours, relances à 3 jours restants et le jour J (PR B) ;
 *  - le superadmin peut approuver À TOUT MOMENT (gestionnaire parti fâché,
 *    boîte du club illisible) — même service, autre appelant.
 *
 * L'approbation matérialise le club (ClubProvisioner — la même vérité que
 * l'ancien verifyEmail) ; les AUTRES demandes en attente sur le même ARA
 * deviennent des adhésions pending (le club existe désormais : leur intention
 * « créer » est morte, leur intention « gérer ce club » reste).
 */
final class ClubApprovalService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubRepository $clubs,
        private readonly ClubProvisioner $provisioner,
        private readonly ClubWinBackService $clubWinBack,
        private readonly FfbbClubDirectory $ffbbClubDirectory,
        private readonly TenantConnectionContext $tenantContext,
        private readonly MailerInterface $mailer,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
        private readonly MembershipRequestNotifier $membershipRequestNotifier,
    ) {}

    /**
     * Ouvre (ou réutilise) la demande de création de l'utilisateur pour cet ARA.
     * Appelé DANS la transaction de verifyEmail — persiste sans flush. L'envoi
     * du mail d'approbation est différé à sendApprovalEmail() (post-commit).
     */
    public function openRequest(User $user, string $ara, string $clubName): ClubCreationRequest
    {
        $existing = $this->requests->findPendingByUser($user->getId());
        if ($existing instanceof ClubCreationRequest && $existing->getAra() === $ara) {
            return $existing; // re-verify du même mail : une seule demande vivante
        }

        $request = new ClubCreationRequest;
        $request->setUserId($user->getId());
        $request->setAra($ara);
        $request->setClubName($clubName);
        $request->setClubEmail($this->ffbbClubDirectory->lookupClubEmail($ara));
        $this->entityManager->persist($request);

        return $request;
    }

    /**
     * Mail « Approuver / Refuser » au mail institutionnel FFBB du club — appelé
     * APRÈS commit (le lien ne doit jamais référencer une demande non persistée).
     * Sans mail connu : rien à envoyer, la demande attend le superadmin (PR B).
     */
    public function sendApprovalEmail(ClubCreationRequest $request, User $requester, bool $reminder = false): void
    {
        $clubEmail = $request->getClubEmail();
        if (null === $clubEmail) {
            return;
        }
        $link = rtrim($this->frontendBaseUrl, '/') . '/club-approval/' . $request->getToken();
        // Le nom saisi par un inconnu n'injecte jamais de lien ni de domaine (hameçonnage).
        $requesterName = MailSafeName::of($requester->getFirstName(), $requester->getLastName());
        $product = $this->productIdentity->name();
        // Chantier Membre : un club RÉEL existe déjà sous ce code (il n'a plus de gestionnaire —
        // sinon la demande serait une adhésion) → la demande est une REPRISE, pas une création.
        $existing = $this->clubs->findRealByFfbbCode($request->getAra());
        try {
            $email = (new Email)
                ->from($this->mailFrom->address())
                ->to($clubEmail)
                ->subject(($reminder ? 'Rappel — ' : '') . ($existing instanceof Club
                    ? \sprintf('%s demande à reprendre l\'espace %s de %s', $requesterName, $product, $existing->getName())
                    : \sprintf('%s demande à créer l\'espace %s de %s', $requesterName, $product, $request->getClubName())))
                ->text($existing instanceof Club
                    ? \sprintf(
                        "Bonjour,\n\n%s (%s) demande à reprendre la gestion de l'espace {$product} du club %s (code FFBB %s), qui n'a plus de gestionnaire.\n\nSi cette personne est bien un gestionnaire de votre club, approuvez sa demande :\n%s\n\nSinon, refusez-la. Ce lien expire le %s.\n\n{$product}",
                        $requesterName,
                        $requester->getEmail(),
                        $existing->getName(),
                        $request->getAra(),
                        $link,
                        $request->getExpiresAt()->format('d/m/Y'),
                    )
                    : \sprintf(
                        "Bonjour,\n\n%s (%s) demande à créer et gérer l'espace {$product} du club %s (code FFBB %s).\n\nSi cette personne est bien un gestionnaire de votre club, approuvez sa demande :\n%s\n\nSinon, refusez-la depuis la même page. Sans réponse, la demande expire le %s.\n\n{$product}",
                        $requesterName,
                        $requester->getEmail(),
                        $request->getClubName(),
                        $request->getAra(),
                        $link,
                        $request->getExpiresAt()->format('d/m/Y'),
                    ));
            // Bouton CTA du HTML (D1) : l'URL EST le lien d'examen de la demande.
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $link);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Examiner la demande');
            $this->mailer->send($email);
        } catch (Throwable $e) {
            // Best-effort : la demande vit, le superadmin reste la voie de secours.
            $this->logger->warning('Club approval email failed', ['requestId' => $request->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Approbation → le club EXISTE (provisioning complet + populate async), la
     * demande est close, les demandes concurrentes sur le même ARA deviennent
     * des adhésions pending. Transactionnel, GUC posé/relâché ici.
     *
     * Sous le verrou ARA, la demande est RELUE en base (`FOR UPDATE`) : une demande déjà décidée
     * ou annulée entre la lecture de l'appelant et ici (double clic, onglet concurrent) ne fait
     * rien (`NoOp`) ; un demandeur effacé (RGPD) n'est jamais approuvé — sa demande est annulée.
     */
    public function approve(ClubCreationRequest $request): ApprovalOutcome
    {
        $newClubId = null;
        /** @var array{clubId: string, userIds: list<string>}|null $joined les adhésions pending nées ici */
        $joined = null;
        /** @var Club|null $decidedClub */
        $decidedClub = null;
        try {
            $outcome = $this->entityManager->wrapInTransaction(function () use ($request, &$newClubId, &$joined, &$decidedClub): ApprovalOutcome {
                // Sérialise les approbations concurrentes du même ARA (deux onglets,
                // club-mail + superadmin) — même patron que la bascule de saison.
                $this->lockAra($request->getAra());
                if (!$this->isStillLive($request)) {
                    return ApprovalOutcome::NoOp;
                }
                $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
                if (!$requester instanceof User || $requester->getAnonymizedAt() instanceof DateTimeImmutable) {
                    // Compte effacé : jamais d'approbation, la demande meurt (lien 404).
                    $this->close($request, ClubCreationRequest::STATUS_CANCELLED);

                    return ApprovalOutcome::NoOp;
                }

                $existing = $this->clubs->findRealByFfbbCode($request->getAra());
                if ($existing instanceof Club) {
                    $decidedClub = $existing;
                    if ($this->clubWinBack->isManagerless($existing->getId())) {
                        // Reprise : le club RÉEL existe mais n'a PLUS AUCUN gestionnaire
                        // actif (effacement du dernier gestionnaire ; des Membres peuvent
                        // rester). L'approbation du contact officiel fait la preuve → le
                        // demandeur en devient Gestionnaire actif, la fermeture est annulée
                        // et le workspace re-seedé s'il avait été purgé. Jamais un 2e club.
                        $this->clubWinBack->reprise($existing, $request->getUserId());
                        $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                        return ApprovalOutcome::Reprise;
                    }
                    // Le club est né / encore géré : cette demande devient une
                    // adhésion pending — jamais un 2e club.
                    $this->tenantContext->setClubId($existing->getId());
                    // Adhésion sur un club déjà né : PENDING au moindre privilège
                    // (Membre) — un gestionnaire du club l'approuvera.
                    $this->provisioner->createMembership($existing->getId(), $request->getUserId(), false, ClubRole::MEMBER);
                    $this->close($request, ClubCreationRequest::STATUS_APPROVED);
                    $joined = ['clubId' => $existing->getId(), 'userIds' => [$request->getUserId()]];

                    return ApprovalOutcome::Pending;
                }

                // P4-294 — aucun club RÉEL : le code appartient peut-être à un club démo
                // CONSERVÉ (prospect convaincu, détaché de l'animateur par la console). On le
                // REPREND au lieu de créer un club neuf — il redevient un vrai club avec ses
                // données de démo. Le club réel a toujours la priorité (branche ci-dessus).
                $retained = $this->clubs->findRetainedDemoByFfbbCode($request->getAra());
                if ($retained instanceof Club) {
                    $decidedClub = $retained;
                    $this->tenantContext->setClubId($retained->getId());
                    // Membership MANAGER actif + annulation du préavis orphelin (et d'un
                    // effacement éventuel) : patron ClubWinBackService::reprise — il RÉACTIVE
                    // une ligne club_user existante plutôt que d'en insérer une 2e, et ne
                    // re-seede PAS (les saisons de la démo sont déjà là).
                    $this->clubWinBack->reprise($retained, $request->getUserId());
                    // Bascule démo → réel : plus de bride (Découverte classique), crédits remis
                    // à zéro, horloge & conservation effacées. is_demo=false ET simulated_today=NULL
                    // dans la MÊME écriture — la CHECK du lot 2 (simulated_today IS NULL OR is_demo)
                    // l'exige. Le club GARDE son nom (le nom saisi au register est ignoré,
                    // décision fondateur) et n'est PAS re-populé depuis la FFBB.
                    $retained->setIsDemo(false);
                    $retained->setDemoRetainedUntil(null);
                    $retained->setSimulatedToday(null);
                    $retained->setOutputCreditsUsed(0);
                    $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                    // Demandes concurrentes sur le même code → adhésion pending MEMBER (comme
                    // à la création). La demande approuvée est encore `pending` EN BASE : l'exclure.
                    $joined = ['clubId' => $retained->getId(), 'userIds' => $this->siblingsToPending($request, $retained->getId())];

                    return ApprovalOutcome::Created;
                }

                $club = $this->provisioner->createClub($request->getClubName(), $request->getAra());
                $decidedClub = $club;
                $this->tenantContext->setClubId($club->getId());
                // Le créateur du club en est le premier Gestionnaire, actif d'office.
                $this->provisioner->createMembership($club->getId(), $request->getUserId(), true, ClubRole::MANAGER);
                $this->provisioner->seedWorkspace($club);
                $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                // Les demandes concurrentes sur le même ARA : leur « créer » est mort,
                // leur « gérer ce club » reste → adhésion pending, approuvable par le
                // premier gestionnaire (flux existant). ⚠ La demande approuvée est
                // encore `pending` EN BASE (close() non flushé) : l'exclure, sinon
                // son auteur recevrait un second membership (unique violation).
                $joined = ['clubId' => $club->getId(), 'userIds' => $this->siblingsToPending($request, $club->getId())];
                $newClubId = $club->getId();

                return ApprovalOutcome::Created;
            });
        } finally {
            $this->tenantContext->clear();
        }

        if (ApprovalOutcome::NoOp === $outcome) {
            return $outcome;
        }

        // Lot C (même contrat que verifyEmail) : autofill FFBB async, APRÈS commit.
        if (null !== $newClubId) {
            $this->messageBus->dispatch(new PopulateClubFromFfbbMessage($newClubId));
        }

        // Chantier Membre : les adhésions pending nées dans cette transaction préviennent les
        // gestionnaires du club — UN mail groupé, après commit, best-effort.
        if (null !== $joined && [] !== $joined['userIds']) {
            $this->membershipRequestNotifier->notifyManagers($joined['clubId'], $joined['userIds']);
        }

        $this->notifyRequester($request, $outcome, $decidedClub);

        return $outcome;
    }

    /**
     * Refus par le club : la demande est close et le demandeur reçoit UN seul mail — qui porte
     * l'échéance de son compte s'il devient orphelin (le préavis est alors posé). Même relecture
     * sous verrou que {@see approve} : une demande déjà décidée ou annulée ne se refuse pas.
     *
     * @return bool faux si la demande n'était plus vivante (rien n'a été fait)
     */
    public function refuse(ClubCreationRequest $request): bool
    {
        $refused = $this->entityManager->wrapInTransaction(function () use ($request): bool {
            $this->lockAra($request->getAra());
            if (!$this->isStillLive($request)) {
                return false;
            }
            $this->close($request, ClubCreationRequest::STATUS_REFUSED);

            return true;
        });
        if (!$refused) {
            return false;
        }

        // P4-301 (décision fondateur Q1) — un refus de création peut laisser le demandeur
        // sans aucun accès : s'il est orphelin, le mail de refus porte le préavis « compte sans
        // club » et en pose le stamp (best-effort, après le commit). Une demande refusée ne
        // protège plus de la règle.
        $sent = $this->orphanAccountNotifier->notifyAccessRemovedWith(
            $request->getUserId(),
            fn (DateTimeImmutable $deadline): Email => $this->requesterEmail($request, null, null, $deadline) ?? throw new LogicException('requester vanished'),
        );
        if (!$sent) {
            $this->notifyRequester($request, null, null);
        }

        return true;
    }

    private function lockAra(string $ara): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            ['key' => 'club-approval:' . $ara],
        );
    }

    /**
     * Relit le statut EN BASE sous verrou de ligne : la demande que l'appelant tient a pu être
     * décidée ou annulée depuis qu'il l'a lue. Non vivante → l'entité est rafraîchie (jamais une
     * écriture de son état périmé) et rien ne se fait.
     */
    private function isStillLive(ClubCreationRequest $request): bool
    {
        $status = $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM club_creation_request WHERE id = :id FOR UPDATE',
            ['id' => $request->getId()],
        );
        if (\in_array($status, [ClubCreationRequest::STATUS_PENDING, ClubCreationRequest::STATUS_EXPIRED], true)) {
            return true;
        }
        if ($this->entityManager->contains($request)) {
            $this->entityManager->refresh($request);
        }

        return false;
    }

    /**
     * Les demandes concurrentes sur le même ARA deviennent des adhésions pending MEMBER.
     *
     * @return list<string> les demandeurs dont l'adhésion pending vient de naître
     */
    private function siblingsToPending(ClubCreationRequest $request, string $clubId): array
    {
        $userIds = [];
        foreach ($this->requests->findPendingByAra($request->getAra()) as $sibling) {
            if ($sibling->getId() === $request->getId()) {
                continue;
            }
            // Demandes concurrentes sur le même ARA : PENDING au moindre
            // privilège (Membre), approuvables par le premier gestionnaire.
            $this->provisioner->createMembership($clubId, $sibling->getUserId(), false, ClubRole::MEMBER);
            $this->close($sibling, ClubCreationRequest::STATUS_APPROVED);
            $userIds[] = $sibling->getUserId();
        }

        return $userIds;
    }

    private function close(ClubCreationRequest $request, string $status): void
    {
        $request->setStatus($status);
        $request->setDecidedAt(new DateTimeImmutable);
    }

    /** `$outcome` null = refus. */
    private function notifyRequester(ClubCreationRequest $request, ?ApprovalOutcome $outcome, ?Club $club): void
    {
        $email = $this->requesterEmail($request, $outcome, $club, null);
        if (!$email instanceof Email) {
            return;
        }
        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $this->logger->warning('Club approval requester notification failed', ['requestId' => $request->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Le mail au demandeur dit ce que la décision a VRAIMENT fait — un texte par issue. Foyer
     * unique de rendu (créé, repris, transmis aux gestionnaires, refusé avec ou sans échéance).
     * `$outcome` null = refus ; `$deletionDeadline` = échéance du compte d'un refusé orphelin.
     */
    private function requesterEmail(ClubCreationRequest $request, ?ApprovalOutcome $outcome, ?Club $club, ?DateTimeImmutable $deletionDeadline): ?Email
    {
        $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
        if (!$requester instanceof User) {
            return null;
        }
        $product = $this->productIdentity->name();
        $clubName = $club instanceof Club ? $club->getName() : $request->getClubName();
        // Lien de connexion mis SUR SA PROPRE LIGNE (« Connectez-vous :\n<lien> ») : le gabarit HTML
        // (D1) retire la ligne « URL nue » du corps pour la rendre en bouton — sans ligne dédiée, le
        // lien ferait doublon (texte + bouton). Le lien reste cliquable dans un client sans HTML.
        $loginLink = rtrim($this->frontendBaseUrl, '/') . '/login';
        [$subject, $text, $withLogin] = match ($outcome) {
            ApprovalOutcome::Created => [
                \sprintf('Votre espace %s est prêt', $request->getClubName()),
                \sprintf("Bonjour,\n\nVotre demande a été approuvée : l'espace {$product} de %s est créé et vous en êtes gestionnaire.\n\nConnectez-vous :\n%s\n\n{$product}", $request->getClubName(), $loginLink),
                true,
            ],
            ApprovalOutcome::Reprise => [
                \sprintf('Votre espace %s est prêt', $clubName),
                \sprintf("Bonjour,\n\nVotre demande a été approuvée : vous reprenez l'espace {$product} de %s et vous en êtes gestionnaire.\n\nConnectez-vous :\n%s\n\n{$product}", $clubName, $loginLink),
                true,
            ],
            ApprovalOutcome::Pending, ApprovalOutcome::Superseded => [
                \sprintf('Votre demande est transmise au club %s', $clubName),
                \sprintf("Bonjour,\n\nLe club %s existe déjà sur {$product} : votre demande d'adhésion est transmise à ses gestionnaires, qui l'accepteront ou la refuseront.\n\n{$product}", $clubName),
                false,
            ],
            ApprovalOutcome::NoOp, null => [
                \sprintf('Demande refusée — %s', $request->getClubName()),
                \sprintf("Bonjour,\n\nVotre demande de création de l'espace %s a été refusée par le club.\n\nSi vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club.", $request->getClubName())
                    . ($deletionDeadline instanceof DateTimeImmutable
                        ? \sprintf("\n\nVous n'avez plus accès à aucun club sur {$product}. Sans nouvel accès, votre compte sera supprimé le %s. Vous pourrez vous réinscrire ensuite si besoin.", $deletionDeadline->format('d/m/Y'))
                        : '')
                    . "\n\n{$product}",
                false,
            ],
        };
        $email = (new Email)
            ->from($this->mailFrom->address())
            ->to($requester->getEmail())
            ->subject($subject)
            ->text($text);
        // Bouton CTA du HTML (D1) : seulement quand le club est ouvert au demandeur (le refus et la
        // transmission aux gestionnaires n'ont aucune action à offrir).
        if ($withLogin) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $loginLink);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Me connecter');
        }

        return $email;
    }
}
