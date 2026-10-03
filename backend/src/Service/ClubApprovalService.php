<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Message\Basketball\PopulateClubFromFfbbMessage;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbClubDirectory;
use App\Service\Registration\ClubWinBackService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
        $requesterName = trim($requester->getFirstName() . ' ' . $requester->getLastName());
        $product = $this->productIdentity->name();
        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($clubEmail)
                    ->subject(($reminder ? 'Rappel — ' : '') . \sprintf('%s demande à créer l\'espace %s de %s', $requesterName, $product, $request->getClubName()))
                    ->text(\sprintf(
                        "Bonjour,\n\n%s (%s) demande à créer et gérer l'espace {$product} du club %s (code FFBB %s).\n\nSi cette personne est bien un gestionnaire de votre club, approuvez sa demande :\n%s\n\nSinon, refusez-la depuis la même page. Sans réponse, la demande expire le %s.\n\n{$product}",
                        $requesterName,
                        $requester->getEmail(),
                        $request->getClubName(),
                        $request->getAra(),
                        $link,
                        $request->getExpiresAt()->format('d/m/Y'),
                    )),
            );
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
     * @return Club le club créé (ou rejoint, si l'ARA a été créé entre-temps)
     */
    public function approve(ClubCreationRequest $request): Club
    {
        $newClubId = null;
        try {
            $club = $this->entityManager->wrapInTransaction(function () use ($request, &$newClubId): Club {
                // Sérialise les approbations concurrentes du même ARA (deux onglets,
                // club-mail + superadmin) — même patron que la bascule de saison.
                $this->entityManager->getConnection()->executeStatement(
                    'SELECT pg_advisory_xact_lock(hashtext(:key))',
                    ['key' => 'club-approval:' . $request->getAra()],
                );

                $existing = $this->clubs->findRealByFfbbCode($request->getAra());
                if ($existing instanceof Club) {
                    if ($this->clubWinBack->isMemberless($existing->getId())) {
                        // Reprise : le club RÉEL existe mais n'a PLUS AUCUN membre
                        // actif (effacement du dernier gestionnaire). L'approbation du
                        // contact officiel fait la preuve → le demandeur en devient
                        // Gestionnaire actif, l'effacement/rappel programmés sont annulés
                        // et le workspace re-seedé s'il avait été purgé. Jamais un 2e club.
                        $this->clubWinBack->reprise($existing, $request->getUserId());
                        $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                        return $existing;
                    }
                    // Le club est né / encore peuplé : cette demande devient une
                    // adhésion pending — jamais un 2e club.
                    $this->tenantContext->setClubId($existing->getId());
                    // Adhésion sur un club déjà né : PENDING au moindre privilège
                    // (Membre) — un gestionnaire du club l'approuvera.
                    $this->provisioner->createMembership($existing->getId(), $request->getUserId(), false, ClubRole::MEMBER);
                    $this->close($request, ClubCreationRequest::STATUS_APPROVED);

                    return $existing;
                }

                $club = $this->provisioner->createClub($request->getClubName(), $request->getAra());
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
                foreach ($this->requests->findPendingByAra($request->getAra()) as $sibling) {
                    if ($sibling->getId() === $request->getId()) {
                        continue;
                    }
                    // Demandes concurrentes sur le même ARA : PENDING au moindre
                    // privilège (Membre), approuvables par le premier gestionnaire.
                    $this->provisioner->createMembership($club->getId(), $sibling->getUserId(), false, ClubRole::MEMBER);
                    $this->close($sibling, ClubCreationRequest::STATUS_APPROVED);
                }

                $newClubId = $club->getId();

                return $club;
            });
        } finally {
            $this->tenantContext->clear();
        }

        // Lot C (même contrat que verifyEmail) : autofill FFBB async, APRÈS commit.
        if (null !== $newClubId) {
            $this->messageBus->dispatch(new PopulateClubFromFfbbMessage($newClubId));
        }

        $this->notifyRequester($request, approved: true);

        return $club;
    }

    public function refuse(ClubCreationRequest $request): void
    {
        $this->close($request, ClubCreationRequest::STATUS_REFUSED);
        $this->entityManager->flush();
        $this->notifyRequester($request, approved: false);
    }

    private function close(ClubCreationRequest $request, string $status): void
    {
        $request->setStatus($status);
        $request->setDecidedAt(new DateTimeImmutable);
    }

    private function notifyRequester(ClubCreationRequest $request, bool $approved): void
    {
        $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
        if (!$requester instanceof User) {
            return;
        }
        $product = $this->productIdentity->name();
        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($requester->getEmail())
                    ->subject($approved ? \sprintf('Votre espace %s est prêt', $request->getClubName()) : \sprintf('Demande refusée — %s', $request->getClubName()))
                    ->text($approved
                        ? \sprintf("Bonjour,\n\nVotre demande a été approuvée : l'espace {$product} de %s est créé et vous en êtes gestionnaire.\n\nConnectez-vous : %s\n\n{$product}", $request->getClubName(), rtrim($this->frontendBaseUrl, '/') . '/login')
                        : \sprintf("Bonjour,\n\nVotre demande de création de l'espace %s a été refusée par le club.\n\nSi vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club.\n\n{$product}", $request->getClubName())),
            );
        } catch (Throwable $e) {
            $this->logger->warning('Club approval requester notification failed', ['requestId' => $request->getId(), 'error' => $e->getMessage()]);
        }
    }
}
