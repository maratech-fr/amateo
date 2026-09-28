<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueMatchWindow;
use App\Repository\ClubLeagueWindowRepository;
use App\Repository\LeagueMatchWindowRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P4-272 ① — pose la COPIE club de l'enveloppe ligue : recopie les fenêtres de la
 * ligue EFFECTIVE du club (sa ligue si cataloguée, sinon la défaut fédérale AURA
 * — {@see LeagueMatchWindowRepository::effectiveLeague}) dans autant de lignes
 * `ClubLeagueWindow` pour la saison visée. Comportement jour 1 identique à la
 * lecture du catalogue (mêmes bornes/jours).
 *
 * Deux appelants aujourd'hui : la naissance d'un club ({@see ClubProvisioner})
 * et le backfill des clubs existants (commande). La bascule de saison, elle,
 * recopie la copie de la saison SOURCE (les corrections du gestionnaire suivent
 * la saison) et ne retombe ici que si cette source est vide.
 *
 * ⚠ RLS : écriture club-scopée — l'APPELANT pose le GUC tenant (`app.club_id`)
 * et gère la transaction (aucun flush ici, patron du provisioning).
 */
final class ClubLeagueWindowSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeagueMatchWindowRepository $leagueMatchWindowRepository,
        private readonly ClubLeagueWindowRepository $clubLeagueWindowRepository,
    ) {}

    /**
     * Copies the club's effective-league windows into the (club, season) copy.
     * No-op (returns 0) when a copy already exists for that season — never
     * duplicates (the unique key would reject it anyway).
     */
    public function seedForSeason(string $clubId, string $seasonId, ?string $league): int
    {
        if ([] !== $this->clubLeagueWindowRepository->findForClubSeason($clubId, $seasonId)) {
            return 0;
        }

        $effectiveLeague = $this->leagueMatchWindowRepository->effectiveLeague($league);
        $seed = $this->leagueMatchWindowRepository->findEnvelopeForLeague($league);

        $count = 0;
        foreach ($seed as $window) {
            $this->entityManager->persist($this->copyOf($window, $clubId, $seasonId, $effectiveLeague));
            ++$count;
        }

        return $count;
    }

    private function copyOf(LeagueMatchWindow $window, string $clubId, string $seasonId, string $league): ClubLeagueWindow
    {
        $copy = new ClubLeagueWindow;
        $copy->setClubId($clubId);
        $copy->setSeasonId($seasonId);
        $copy->setLeague($league);
        $copy->setCategory($window->getCategory());
        $copy->setLevel($window->getLevel());
        $copy->setGender($window->getGender());
        $copy->setDayOfWeek($window->getDayOfWeek());
        $copy->setKickoffMin($window->getKickoffMin());
        $copy->setKickoffMax($window->getKickoffMax());

        return $copy;
    }
}
