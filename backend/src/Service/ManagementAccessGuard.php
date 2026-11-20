<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le rôle de l'appelant dans le club de la session : gestionnaire ou non.
 *
 * Le refus d'une fonction « gestionnaire » à un Membre est posé UNE fois, par le contrôle central
 * (`App\Access\ClubAccessListener`), avant tout contrôleur. Ce service ne refuse plus rien : il
 * répond à la question pour le contrôle central et pour les quelques lectures qui AIGUILLENT
 * selon le rôle (logo adverse non stocké, liens des coachs d'une collecte, caps du club) au lieu
 * de refuser.
 *
 * Gestionnaire = adhésion ACTIVE au club posé par le locataire (`_club_id`) et rôle de gestion
 * (`ClubRole::isManager()`). Sans club posé, sans utilisateur ou sans adhésion active : non.
 */
final class ManagementAccessGuard
{
    /** Le message du refus d'une fonction « gestionnaire ». */
    public const string REFUSAL = 'Cette action est réservée aux gestionnaires.';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly ClubUserRepository $clubUserRepository,
    ) {}

    public function isManager(): bool
    {
        $clubId = $this->requestStack->getCurrentRequest()?->attributes->get('_club_id');

        $user = $this->security->getUser();
        $membership = ($user instanceof User && \is_string($clubId) && '' !== $clubId)
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;

        return $membership instanceof ClubUser && $membership->getRole()->isManager();
    }
}
