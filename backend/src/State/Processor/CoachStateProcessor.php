<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachResource;
use App\Dto\CoachInput;
use App\Entity\Coach;
use App\Entity\Person;
use App\Enum\CoachGender;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Structure\PersonDirectory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<Coach, CoachInput, CoachResource>
 */
class CoachStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly CoachWishCampaignTokenSync $tokenSync,
        private readonly PersonDirectory $personDirectory,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Coach::class;
    }

    /** Le coach parti, le suivant répond pour ses équipes dans les collectes ouvertes. */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $coach = $this->entityManager->find(Coach::class, $uriVariables['id'] ?? null);
        $coachClubId = $coach?->getClubId();
        $seasonId = $coach?->getSeasonId();

        parent::processDelete($uriVariables, $clubId);

        if (null !== $coachClubId && null !== $seasonId) {
            $this->tokenSync->syncSeason($coachClubId, $seasonId);
        }
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof Coach) {
            $this->cascadeDeleter?->purgeChildrenOfCoach($entity);
        }
    }

    /**
     * @param CoachInput $input
     */
    protected function createEntityFromInput(object $input): Coach
    {
        // L'identité va sur une fiche de personne neuve, au club de la requête (le coach reçoit le
        // même club dans processPost) ; la fiche est persistée avant le coach qui la pointe.
        $person = new Person;
        $clubId = $this->requestStack->getCurrentRequest()?->attributes->get('_club_id');
        if (\is_string($clubId)) {
            $person->setClubId($clubId);
        }
        // last_name is NOT NULL in the schema; a coach may have only a first name.
        $this->writeIdentity($person, $input);
        $person->setLastName($input->lastName ?? '');
        $this->entityManager->persist($person);

        $entity = new Coach($person);
        if (null !== $input->maxDaysOverride) {
            // P4-51 — 0 = « retirer le plafond ». Le PUT étant partiel (null = inchangé),
            // null ne peut pas porter le retrait : sans ce cas, un champ vidé à l'écran
            // laisserait le plafond en base pour toujours.
            $entity->setMaxDaysOverride(0 === $input->maxDaysOverride ? null : $input->maxDaysOverride);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->isEmployee) {
            $entity->setIsEmployee($input->isEmployee);
        }
        if (null !== $input->isVehicled) {
            $entity->setIsVehicled($input->isVehicled);
        }

        return $entity;
    }

    /**
     * @param Coach      $entity
     * @param CoachInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // L'identité s'écrit sur la fiche de la personne (elle vaut pour toutes ses saisons) ; la
        // ligne du coach est touchée pour que sa version change avec elle (If-Match de l'écran).
        $person = $this->personDirectory->personOf($entity);
        $before = $this->personDirectory->of($entity);
        $this->writeIdentity($person, $input);
        if (null !== $input->lastName) {
            $person->setLastName($input->lastName);
        }
        if (!$before->equals($this->personDirectory->of($entity))) {
            $entity->setUpdatedAt(new DateTimeImmutable);
        }
        if (null !== $input->maxDaysOverride) {
            // P4-51 — 0 = « retirer le plafond ». Le PUT étant partiel (null = inchangé),
            // null ne peut pas porter le retrait : sans ce cas, un champ vidé à l'écran
            // laisserait le plafond en base pour toujours.
            $entity->setMaxDaysOverride(0 === $input->maxDaysOverride ? null : $input->maxDaysOverride);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->isEmployee) {
            $entity->setIsEmployee($input->isEmployee);
        }
        if (null !== $input->isVehicled) {
            $entity->setIsVehicled($input->isVehicled);
        }
    }

    /**
     * @param Coach $entity
     */
    protected function mapEntityToOutput(object $entity): CoachResource
    {
        return CoachResource::fromEntity($entity, $this->personDirectory->of($entity));
    }

    /** Prénom, mail, téléphone, genre : null = inchangé (le nom de famille, l'appelant le décide). */
    private function writeIdentity(Person $person, CoachInput $input): void
    {
        if (null !== $input->firstName) {
            $person->setFirstName($input->firstName);
        }
        if (null !== $input->email) {
            $person->setEmail($input->email);
        }
        if (null !== $input->phone) {
            $person->setPhone($input->phone);
        }
        if (null !== $input->gender) {
            $person->setGender(CoachGender::from($input->gender));
        }
    }
}
