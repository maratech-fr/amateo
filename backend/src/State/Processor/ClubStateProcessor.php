<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ClubResource;
use App\Dto\ClubInput;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @extends AbstractStateProcessor<Club, ClubInput, ClubResource>
 */
class ClubStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly Security $security,
        private readonly ClubUserRepository $clubUserRepository,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Club::class;
    }

    /**
     * SEC-01: Club has no club_id column, so the generic getClubId() guard never
     * fires. Require an active admin membership in the target club: unknown club
     * or no membership → 404 (no existence leak); member but not admin → 403.
     *
     * @param ClubInput            $input
     * @param array<string, mixed> $uriVariables
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        $id = $uriVariables['id'] ?? null;
        $user = $this->security->getUser();

        if (!\is_string($id) || !$user instanceof User) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        $membership = $this->clubUserRepository->findActiveMembership($user->getId(), $id);
        if (!$membership instanceof ClubUser) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            throw new AccessDeniedHttpException('Accès refusé.');
        }

        return parent::processPut($input, $uriVariables, $clubId, $seasonId);
    }

    /**
     * @param ClubInput $input
     */
    protected function createEntityFromInput(object $input): Club
    {
        $entity = new Club;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->slug) {
            $entity->setSlug($input->slug);
        }
        if (null !== $input->schoolZone) {
            $entity->setSchoolZone($input->schoolZone);
        }
        if (null !== $input->timezone) {
            $entity->setTimezone($input->timezone);
        }
        if (null !== $input->locale) {
            $entity->setLocale($input->locale);
        }
        if (null !== $input->onboardingCompleted) {
            $entity->setOnboardingCompleted($input->onboardingCompleted);
        }
        if (null !== $input->weekendAlternates) {
            $entity->setWeekendAlternates($input->weekendAlternates);
        }
        if (null !== $input->ffbbClubCode) {
            $entity->setFfbbClubCode($input->ffbbClubCode);
        }
        if (null !== $input->accentColor) {
            $entity->setAccentColor($input->accentColor);
        }
        if (null !== $input->accentPalette) {
            $entity->setAccentPalette($input->accentPalette);
        }

        return $entity;
    }

    /**
     * @param Club      $entity
     * @param ClubInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->slug) {
            $entity->setSlug($input->slug);
        }
        if (null !== $input->schoolZone) {
            $entity->setSchoolZone($input->schoolZone);
        }
        if (null !== $input->timezone) {
            $entity->setTimezone($input->timezone);
        }
        if (null !== $input->locale) {
            $entity->setLocale($input->locale);
        }
        if (null !== $input->onboardingCompleted) {
            $entity->setOnboardingCompleted($input->onboardingCompleted);
        }
        if (null !== $input->weekendAlternates) {
            $entity->setWeekendAlternates($input->weekendAlternates);
        }
        if (null !== $input->ffbbClubCode) {
            $entity->setFfbbClubCode($input->ffbbClubCode);
        }
        if (null !== $input->accentColor) {
            $entity->setAccentColor($input->accentColor);
        }
        if (null !== $input->accentPalette) {
            $entity->setAccentPalette($input->accentPalette);
        }
    }

    /**
     * @param Club $entity
     */
    protected function mapEntityToOutput(object $entity): ClubResource
    {
        return ClubResource::fromEntity($entity);
    }
}
