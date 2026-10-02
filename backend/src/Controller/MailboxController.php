<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubMailboxMessage;
use App\Repository\ClubMailboxMessageRepository;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La « boîte aux lettres » d'un club à horloge simulée (P4-16), en LECTURE seule. L'animateur
 * de démo — gestionnaire OU simple membre, c'est lui qui montre l'app — y lit les e-mails
 * qu'un club réel aurait reçus (relances de vœux, rappels de période…), interceptés au lieu
 * d'être envoyés.
 *
 * Tenant pur : le club vient du JWT (`_club_id` posé par TenantFilterListener), jamais d'un
 * input ; la RLS + le filtre explicite par clubId bornent la lecture au tenant courant. Aucune
 * garde « gestionnaire » (ManagementAccessGuard) — tout membre connecté du club lit sa boîte.
 * Un club SANS horloge n'a jamais de ligne : l'écran et l'entrée de nav restent vides/masqués
 * côté front, piloté par `me.club.simulatedToday`.
 */
#[AsController]
final class MailboxController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClubMailboxMessageRepository $repository,
    ) {}

    #[Route('/api/mailbox', name: 'api_mailbox_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $clubId = $this->currentClubId();
        $messages = $this->repository->findForClubNewestFirst($clubId);

        return $this->json([
            'messages' => array_map(fn (ClubMailboxMessage $m): array => $this->summary($m), $messages),
            'count' => \count($messages),
        ]);
    }

    #[Route('/api/mailbox/{id}', name: 'api_mailbox_detail', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $message = $this->repository->findOneForClub($id, $this->currentClubId());
        if (!$message instanceof ClubMailboxMessage) {
            throw $this->createNotFoundException();
        }

        return $this->json($this->summary($message) + [
            'bodyText' => $message->getBodyText(),
            'bodyHtml' => $message->getBodyHtml(),
        ]);
    }

    /**
     * @return array{id: string, from: string, to: string, subject: string, simulatedDate: string, createdAt: string}
     */
    private function summary(ClubMailboxMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'from' => $message->getFromAddress(),
            'to' => $message->getToAddress(),
            'subject' => $message->getSubject(),
            'simulatedDate' => $message->getSimulatedDate()->format('Y-m-d'),
            'createdAt' => $message->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    private function currentClubId(): string
    {
        $clubId = $this->requestStack->getCurrentRequest()?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            // Un utilisateur authentifié d'un club porte toujours `_club_id` ; sinon pas de tenant.
            throw $this->createAccessDeniedException();
        }

        return $clubId;
    }
}
