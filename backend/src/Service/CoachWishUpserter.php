<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Crée ou met à jour une doléance (feature #10, lot C2). Foyer unique de l'écriture d'un
 * CoachWish DEPUIS une soumission de coach (page publique) — le point de convergence pour
 * C3 (rappels).
 *
 * Une re-soumission ÉCRASE la doléance existante et remet `done` à false (« à retraiter » :
 * la parole du coach prime, le gestionnaire doit re-regarder). L'unique métier est
 * (calendarEntryId, teamId, weekStart) ; le club/season viennent de la campagne.
 *
 * N'appelle PAS le CoachWishStateProcessor (couplé à AbstractStateProcessor/JWT/SEC-07,
 * inatteignable depuis un contrôleur public). L'appelant a déjà validé le périmètre et posé
 * le GUC `app.club_id` ; ce service se contente de persister — il ne flush pas (l'appelant
 * commite la transaction).
 */
final class CoachWishUpserter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param list<int> $unavailableDays
     * @param list<int> $wishedDays
     */
    public function upsert(
        CoachWishCampaign $campaign,
        string $teamId,
        DateTimeImmutable $weekStart,
        string $coachId,
        int $slotsWanted,
        array $unavailableDays,
        array $wishedDays,
        ?string $comment,
    ): CoachWish {
        // Garde de dernier recours (défense en profondeur) : un jour ne peut pas être à la fois
        // souhaité ET indisponible. Le contrôleur public refuse déjà en amont (422) — ce foyer
        // d'écriture reste gardé par un test unitaire direct (CoachWishUpserterTest).
        if ([] !== array_intersect($wishedDays, $unavailableDays)) {
            throw new InvalidArgumentException('Un jour ne peut pas être à la fois souhaité et indisponible.');
        }

        $wish = $this->entityManager->getRepository(CoachWish::class)->findOneBy([
            'calendarEntryId' => $campaign->getCalendarEntryId(),
            'teamId' => $teamId,
            'weekStart' => $weekStart,
        ]);

        if (null === $wish) {
            $wish = (new CoachWish)
                ->setCalendarEntryId($campaign->getCalendarEntryId())
                ->setTeamId($teamId)
                ->setWeekStart($weekStart);
            $wish->setClubId((string) $campaign->getClubId());
            $wish->setSeasonId($campaign->getSeasonId());
            $this->entityManager->persist($wish);
        }

        $wish->setCoachId($coachId);
        $wish->setSlotsWanted($slotsWanted);
        $wish->setUnavailableDays($unavailableDays);
        $wish->setWishedDays($wishedDays);
        $wish->setComment(null === $comment || '' === trim($comment) ? null : $comment);
        // La parole du coach prime : sa re-soumission remet le drapeau à « à retraiter ».
        $wish->setDone(false);

        return $wish;
    }
}
