<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishMutualization;
use App\Repository\CalendarEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Q8bis (P2-63) — valider ou rouvrir le planning de SAISON emporte aussi la COLLECTE de
 * doléances et les doléances des vacances ENTIÈREMENT À VENIR.
 *
 * Le socle est la base (ADR-0002) : le déplacer périme les semaines déjà annoncées aux coachs.
 * Une campagne dont les semaines ne correspondent plus ferait remplir un formulaire caduc, et
 * des doléances bâties sur l'ancien découpage n'ont plus de sens. On supprime donc, pour chaque
 * MÈRE de vacances à venir (même pivot que la destruction des plannings de période :
 * `startDate > today`) :
 *  - la campagne (`CoachWishCampaign`) — ses jetons `CoachWishToken` partent par FK cascade ;
 *  - les doléances (`CoachWish`) ;
 *  - les demandes de mutualisation (`CoachWishMutualization`).
 *
 * Jamais une vacance DÉJÀ COMMENCÉE (startDate ≤ today) — elle est annoncée et à moitié jouée.
 * L'horloge est lue via `ClockInterface` (décorée par l'horloge DU club), MÊME source que
 * `OverlayManager::periodPlansInvalidatedBySeasonChange` : les deux portées se correspondent.
 *
 * L'appelant (ValidateScheduleController / ReopenScheduleController) décide SOUS verrou et DANS
 * sa transaction ; ce service ne gère ni verrou ni transaction, il supprime par DQL (le GUC
 * tenant posé par le listener + la RLS bornent déjà l'écriture au club courant, et les ids des
 * mères sont résolus pour ce club+saison).
 */
final class FutureHolidayCollectePurger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Les MÈRES de vacances entièrement à venir (startDate > today) du club+saison.
     *
     * @return list<string>
     */
    public function futureHolidayMotherIds(string $clubId, string $seasonId): array
    {
        return $this->calendarEntryRepository->findFutureHolidayMotherIds($clubId, $seasonId, $this->clock->now());
    }

    /**
     * Doléances déjà reçues sur ces vacances — le CHIFFRE annoncé au gestionnaire avant la
     * destruction (« … et les 25 doléances déjà reçues pour la Toussaint »).
     *
     * @param list<string> $motherIds
     */
    public function countReceivedWishes(array $motherIds): int
    {
        if ([] === $motherIds) {
            return 0;
        }

        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(w.id) FROM ' . CoachWish::class . ' w WHERE w.calendarEntryId IN (:ids)',
        )->setParameter('ids', $motherIds)->getSingleScalarResult();
    }

    /**
     * Supprime campagnes (jetons en cascade), doléances et mutualisations de ces vacances.
     * À appeler DANS la transaction destructive de l'appelant (après confirmation).
     *
     * @param list<string> $motherIds
     */
    public function purge(array $motherIds): void
    {
        if ([] === $motherIds) {
            return;
        }

        // CoachWishCampaign en dernier : le DELETE SQL déclenche la FK `ON DELETE CASCADE` de
        // coach_wish_token (fk_coach_wish_token_campaign) — les jetons partent avec la campagne.
        foreach ([CoachWish::class, CoachWishMutualization::class, CoachWishCampaign::class] as $class) {
            $this->entityManager->createQuery(
                'DELETE FROM ' . $class . ' e WHERE e.calendarEntryId IN (:ids)',
            )->setParameter('ids', $motherIds)->execute();
        }
    }
}
