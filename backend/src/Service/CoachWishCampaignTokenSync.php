<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La RÈGLE du destinataire, foyer unique : elle écrit sur chaque jeton les équipes pour lesquelles
 * son coach répond. Tous les chemins qui changent la réponse l'appellent : création et modification
 * de collecte, lien coach-équipe posé ou retiré, coach supprimé, « Charger », choix du gestionnaire.
 *
 * Un jeton naît quand un coach devient destinataire ; on n'en SUPPRIME jamais, un lien déjà collé
 * en WhatsApp ne doit pas devenir un 404 (ex-destinataire : liste vide, « aucune équipe concernée »).
 * Une équipe GAGNÉE remet « à envoyer » un jeton déjà servi ou déjà répondu : il lui manque les
 * réponses de cette équipe. Une équipe perdue ne remet rien à zéro. Un coach supprimé → FK CASCADE
 * emporte le jeton.
 *
 * Idempotente : la rejouer sans changement n'écrit rien de neuf.
 */
final class CoachWishCampaignTokenSync
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishPerimeter $perimeter,
    ) {}

    public function sync(CoachWishCampaign $campaign): void
    {
        $teamsByCoach = [];
        foreach ($this->perimeter->recipientsByTeam($campaign) as $teamId => $entry) {
            if (null !== $entry['recipientCoachId']) {
                $teamsByCoach[$entry['recipientCoachId']][] = $teamId;
            }
        }

        foreach ($this->entityManager->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]) as $token) {
            $teamIds = $teamsByCoach[$token->getCoachId()] ?? [];
            unset($teamsByCoach[$token->getCoachId()]);
            if ([] !== array_diff($teamIds, $token->getTeamIds()) && (null !== $token->getSentAt() || null !== $token->getRespondedAt())) {
                $token->backToSend();
            }
            $token->setTeamIds($teamIds);
        }

        foreach ($teamsByCoach as $coachId => $teamIds) {
            $this->entityManager->persist((new CoachWishToken)
                ->setCampaignId($campaign->getId())
                ->setCoachId((string) $coachId)
                ->setClubId((string) $campaign->getClubId())
                ->setTeamIds($teamIds));
        }

        $this->entityManager->flush();
    }

    /** La règle sur chaque campagne OUVERTE de la saison ; une campagne à date passée n'est pas touchée. */
    public function syncSeason(string $clubId, string $seasonId): void
    {
        foreach ($this->entityManager->getRepository(CoachWishCampaign::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $campaign) {
            if ($this->perimeter->isOpen($campaign)) {
                $this->sync($campaign);
            }
        }
    }
}
