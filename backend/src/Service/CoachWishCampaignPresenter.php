<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\CoachWishCampaignResource;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enrichit une campagne en ressource de sortie (feature #10, lot C2) : compteurs du radar
 * + liste des destinataires avec leur token + destinataire par équipe. Partagé entre le provider
 * (GET) et le processor (POST/PUT), pour une seule source de vérité de la présentation.
 *
 * Un ex-destinataire garde son token (jamais supprimé) mais n'apparaît plus ici : son jeton ne
 * porte plus aucune équipe, les compteurs et la liste ne le comptent pas.
 */
final class CoachWishCampaignPresenter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishPerimeter $perimeter,
    ) {}

    public function toResource(CoachWishCampaign $campaign): CoachWishCampaignResource
    {
        $dto = new CoachWishCampaignResource;
        $dto->id = $campaign->getId();
        $dto->calendarEntryId = $campaign->getCalendarEntryId();
        $dto->deadline = $campaign->getDeadline()->format('Y-m-d');
        $dto->weeks = $campaign->getWeeks();
        $dto->teamIds = $campaign->getTeamIds();
        $dto->lastReminderAt = $campaign->getLastReminderAt()?->format(DateTimeInterface::ATOM);

        // Coachs distincts du périmètre courant (source unique partagée avec digest/token-sync).
        $perimeterCoachIds = $this->perimeter->coachIdSet($campaign);

        // Les tokens de cette campagne, indexés par coach (respondedAt).
        $tokenByCoach = [];
        foreach ($this->entityManager->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]) as $token) {
            $tokenByCoach[$token->getCoachId()] = $token;
        }

        // Chargement groupé des coachs du périmètre (une requête) — évite un find() par coach
        // (N+1 amplifié par le provider de collection qui projette toutes les campagnes).
        $coachById = [];
        if ([] !== $perimeterCoachIds) {
            foreach ($this->entityManager->getRepository(Coach::class)->findBy(['id' => array_keys($perimeterCoachIds)]) as $coach) {
                $coachById[$coach->getId()] = $coach;
            }
        }

        $coaches = [];
        $responded = 0;
        foreach (array_keys($perimeterCoachIds) as $coachId) {
            $token = $tokenByCoach[$coachId] ?? null;
            if (null === $token) {
                continue; // token pas encore synchronisé (transitoire) — sans lien, on n'affiche rien
            }
            $coach = $coachById[$coachId] ?? null;
            if (!$coach instanceof Coach) {
                continue;
            }
            $respondedAt = $token->getRespondedAt();
            if (null !== $respondedAt) {
                ++$responded;
            }
            $coaches[] = [
                'coachId' => $coachId,
                'firstName' => $coach->getFirstName(),
                'lastName' => $coach->getLastName(),
                'email' => $coach->getEmail(),
                'token' => $token->getToken(),
                'respondedAt' => $respondedAt?->format(DateTimeInterface::ATOM),
                'sentAt' => $token->getSentAt()?->format(DateTimeInterface::ATOM),
                'teamIds' => $token->getTeamIds(),
            ];
        }

        $dto->coaches = $coaches;
        $dto->teams = $this->recipientTeams($campaign);
        $dto->open = $this->perimeter->isOpen($campaign);
        $dto->totalCoachCount = \count($coaches);
        $dto->respondedCoachCount = $responded;
        // « À traiter » de CETTE campagne : les doléances non traitées de ses ÉQUIPES, pas de
        // toute la période (une saisie « au nom de » sur une équipe hors campagne ne compte pas).
        $dto->openWishCount = [] === $campaign->getTeamIds()
            ? 0
            : (int) $this->entityManager->getRepository(CoachWish::class)
                ->count(['calendarEntryId' => $campaign->getCalendarEntryId(), 'teamId' => $campaign->getTeamIds(), 'done' => false]);

        return $dto;
    }

    /** @return list<array{teamId: string, recipientCoachId: string|null, candidates: list<array{coachId: string, firstName: string, lastName: string}>}> */
    private function recipientTeams(CoachWishCampaign $campaign): array
    {
        $recipients = $this->perimeter->recipientsByTeam($campaign);
        $candidateIds = array_values(array_unique(array_merge(...array_column($recipients, 'candidateCoachIds'))));
        $coachById = [];
        if ([] !== $candidateIds) {
            foreach ($this->entityManager->getRepository(Coach::class)->findBy(['id' => $candidateIds]) as $coach) {
                $coachById[$coach->getId()] = $coach;
            }
        }

        $teams = [];
        foreach ($recipients as $teamId => $entry) {
            $candidates = [];
            foreach ($entry['candidateCoachIds'] as $coachId) {
                $coach = $coachById[$coachId] ?? null;
                if ($coach instanceof Coach) {
                    $candidates[] = ['coachId' => $coachId, 'firstName' => $coach->getFirstName(), 'lastName' => $coach->getLastName()];
                }
            }
            $teams[] = ['teamId' => (string) $teamId, 'recipientCoachId' => $entry['recipientCoachId'], 'candidates' => $candidates];
        }

        return $teams;
    }
}
