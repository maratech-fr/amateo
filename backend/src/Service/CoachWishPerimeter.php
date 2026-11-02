<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Source UNIQUE du périmètre d'une campagne de collecte (feature #10) : qui répond pour quelle
 * équipe, et ce que vaut le staff d'une équipe.
 *
 * Deux lectures, à ne pas confondre :
 *  - la DÉSIGNATION, calculée depuis `TeamCoach` : {@see self::recipientsByTeam()} (un seul
 *    principal par équipe, celui que le gestionnaire a marqué, sinon le premier lié) ;
 *  - la VÉRITÉ PERSISTÉE sur le jeton, posée par {@see CoachWishCampaignTokenSync} :
 *    {@see self::coachIdSet()} et {@see self::teamIdsForCoach()}. Le presenter, le digest, la page
 *    publique et l'aperçu lisent celle-là : une campagne à date passée garde ainsi son affichage.
 */
final class CoachWishPerimeter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubDay $clubDay,
    ) {}

    /**
     * Coachs qui répondent pour au moins une équipe, en set indexé pour un test d'appartenance O(1).
     *
     * @return array<string, true>
     */
    public function coachIdSet(CoachWishCampaign $campaign): array
    {
        $set = [];
        foreach ($this->entityManager->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]) as $token) {
            if ([] !== $token->getTeamIds()) {
                $set[$token->getCoachId()] = true;
            }
        }

        return $set;
    }

    /**
     * Les équipes pour lesquelles UN coach répond : celles de son jeton ∩ `teamIds` de la campagne,
     * dans l'ordre de la campagne. Le périmètre d'écriture et d'affichage d'un coach donné (page
     * publique, aperçu gestionnaire).
     *
     * @return list<string>
     */
    public function teamIdsForCoach(CoachWishCampaign $campaign, string $coachId): array
    {
        $token = $this->entityManager->getRepository(CoachWishToken::class)->findOneBy(['campaignId' => $campaign->getId(), 'coachId' => $coachId]);
        if (!$token instanceof CoachWishToken) {
            return [];
        }

        return array_values(array_intersect($campaign->getTeamIds(), $token->getTeamIds()));
    }

    /**
     * Par équipe de la campagne : ses coachs principaux (triés par date de lien) et celui qui
     * répond — le marqué, sinon le premier. Deux marqués : le premier du tri. Un adjoint n'est
     * jamais candidat, même marqué.
     *
     * @return array<string, array{recipientCoachId: string|null, candidateCoachIds: list<string>}>
     */
    public function recipientsByTeam(CoachWishCampaign $campaign): array
    {
        $linksByTeam = array_fill_keys($campaign->getTeamIds(), []);
        foreach ($this->sortedLinks($campaign->getTeamIds()) as $link) {
            if (TeamCoachRole::MAIN === $link->getRole()) {
                $linksByTeam[$link->getTeamId()][] = $link;
            }
        }

        $recipients = [];
        foreach ($linksByTeam as $teamId => $links) {
            $marked = array_values(array_filter($links, static fn (TeamCoach $link): bool => $link->isWishRecipient()));
            $recipient = $marked[0] ?? $links[0] ?? null;
            $recipients[(string) $teamId] = [
                'recipientCoachId' => $recipient?->getCoachId(),
                'candidateCoachIds' => array_map(static fn (TeamCoach $link): string => $link->getCoachId(), $links),
            ];
        }

        return $recipients;
    }

    /**
     * Le staff courant de chaque équipe, principaux puis adjoints, par date de lien. Exposé tel
     * quel sur la page à jeton : ces quatre champs, rien d'autre.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, list<array{coachId: string, firstName: string, lastName: string, role: 'MAIN'|'ASSISTANT'}>>
     */
    public function staffOfTeams(array $teamIds): array
    {
        $staff = array_fill_keys($teamIds, []);
        $links = $this->sortedLinks($teamIds);
        if ([] === $links) {
            return $staff;
        }
        $coachIds = array_values(array_unique(array_map(static fn (TeamCoach $link): string => $link->getCoachId(), $links)));
        $coachById = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy(['id' => $coachIds]) as $coach) {
            $coachById[$coach->getId()] = $coach;
        }

        foreach ([TeamCoachRole::MAIN, TeamCoachRole::ASSISTANT] as $role) {
            foreach ($links as $link) {
                $coach = $coachById[$link->getCoachId()] ?? null;
                if ($role === $link->getRole() && $coach instanceof Coach) {
                    $staff[$link->getTeamId()][] = [
                        'coachId' => $coach->getId(),
                        'firstName' => $coach->getFirstName(),
                        'lastName' => $coach->getLastName(),
                        'role' => TeamCoachRole::MAIN === $role ? 'MAIN' : 'ASSISTANT',
                    ];
                }
            }
        }

        return $staff;
    }

    /** La date limite n'est pas passée, au jour du club : le jour même est encore ouvert. */
    public function isOpen(CoachWishCampaign $campaign): bool
    {
        $club = $this->entityManager->find(Club::class, $campaign->getClubId());

        return $club instanceof Club && $this->clubDay->todayYmdFor($club) <= $campaign->getDeadline()->format('Y-m-d');
    }

    /**
     * @param list<string> $teamIds
     *
     * @return list<TeamCoach>
     */
    private function sortedLinks(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }
        $links = $this->entityManager->getRepository(TeamCoach::class)->findBy(['teamId' => $teamIds]);
        usort($links, static fn (TeamCoach $a, TeamCoach $b): int => [$a->getCreatedAt()->format('Y-m-d H:i:s'), $a->getId()] <=> [$b->getCreatedAt()->format('Y-m-d H:i:s'), $b->getId()]);

        return $links;
    }
}
