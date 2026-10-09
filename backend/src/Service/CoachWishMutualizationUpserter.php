<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishMutualization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée, met à jour ou SUPPRIME une demande de mutualisation (feature #10, lot D2). Foyer
 * unique de l'écriture d'un CoachWishMutualization DEPUIS une soumission de coach (page
 * publique). L'unique métier est (calendarEntryId, teamId) ; club/season viennent de la
 * campagne.
 *
 * Une re-soumission ÉCRASE la demande existante et remet `done` à false (« à retraiter » : la
 * parole du coach prime, le gestionnaire doit re-regarder). Une re-soumission SANS AUCUN
 * partenaire SUPPRIME la ligne : le coach a changé d'avis, il ne mutualise plus — pas une
 * ligne « 0 partenaire » fantôme dans la todo-list.
 *
 * N'appelle PAS le processor (couplé JWT/SEC-07, inatteignable depuis un contrôleur public).
 * L'appelant a déjà validé le périmètre et posé le GUC `app.club_id` ; ce service persiste —
 * il ne flush pas (l'appelant commite la transaction).
 */
final class CoachWishMutualizationUpserter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param list<string> $partnerTeamIds
     *
     * @return CoachWishMutualization|null l'entité écrite, ou null si la ligne a été supprimée
     *                                     (0 partenaire)
     */
    public function upsert(
        CoachWishCampaign $campaign,
        string $teamId,
        ?string $coachId,
        array $partnerTeamIds,
        int $sharedSlots,
    ): ?CoachWishMutualization {
        $existing = $this->entityManager->getRepository(CoachWishMutualization::class)->findOneBy([
            'calendarEntryId' => $campaign->getCalendarEntryId(),
            'teamId' => $teamId,
        ]);

        // 0 partenaire = plus de mutualisation : on supprime la ligne plutôt que d'en garder
        // une vide (la todo-list ne liste que les demandes réelles).
        if ([] === $partnerTeamIds) {
            if (null !== $existing) {
                $this->entityManager->remove($existing);
            }

            return null;
        }

        $mutualization = $existing;
        if (null === $mutualization) {
            $mutualization = (new CoachWishMutualization)
                ->setCalendarEntryId($campaign->getCalendarEntryId())
                ->setTeamId($teamId);
            $mutualization->setClubId((string) $campaign->getClubId());
            $mutualization->setSeasonId($campaign->getSeasonId());
            $this->entityManager->persist($mutualization);
        }

        $mutualization->setCoachId($coachId);
        $mutualization->setPartnerTeamIds($partnerTeamIds);
        $mutualization->setSharedSlots($sharedSlots);
        // La parole du coach prime : sa re-soumission remet le drapeau à « à retraiter ».
        $mutualization->setDone(false);
        $mutualization->touch();

        return $mutualization;
    }
}
