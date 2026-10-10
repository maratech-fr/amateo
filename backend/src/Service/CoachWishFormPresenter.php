<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishMutualization;
use App\Entity\Team;
use App\Entity\TeamLink;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Construit le contexte COACH d'un formulaire de doléances (feature #10) : ce qu'un coach
 * donné voit de SA campagne — son prénom, ses équipes, les équipes partenaires possibles, les
 * passerelles, et (selon le mode) ses doléances/mutualisations déjà saisies.
 *
 * Foyer UNIQUE partagé par deux appelants (D2) :
 *  - la page publique `PublicCoachWishController::show` (`includeExisting: true`, respondedAt du
 *    token) — la vraie page du coach ;
 *  - l'aperçu gestionnaire `CoachWishCampaignPreviewController` (`includeExisting: false`,
 *    respondedAt null) — « ce que voit {Prénom} », en lecture seule, sans ses données.
 *
 * Doit tourner sous un GUC `app.club_id` déjà posé (RLS) : l'appelant l'établit (token côté
 * public, JWT côté aperçu).
 */
final class CoachWishFormPresenter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishPerimeter $perimeter,
    ) {}

    /**
     * @return array{
     *     coachFirstName: string,
     *     periodTitle: string,
     *     periodStart: string|null,
     *     periodEnd: string|null,
     *     deadline: string,
     *     weeks: list<string>,
     *     teams: list<array{id: string, name: string}>,
     *     partnerTeams: list<array{id: string, name: string}>,
     *     teamLinks: list<array{teamAId: string, teamBId: string}>,
     *     wishes: list<array{teamId: string, weekStart: string, slotsWanted: int, unavailableDays: list<int>, wishedDays: list<int>, comment: string|null, keepSeasonSlots: bool}>,
     *     mutualizations: list<array{teamId: string, partnerTeamIds: list<string>, sharedSlots: int}>,
     *     respondedAt: string|null
     * }
     */
    public function build(CoachWishCampaign $campaign, Coach $coach, bool $includeExisting, ?string $respondedAt): array
    {
        $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($campaign->getCalendarEntryId());
        $coachTeamIds = $this->perimeter->teamIdsForCoach($campaign, $coach->getId());

        // Noms des équipes de la campagne (superset : le périmètre du coach en est un
        // sous-ensemble). Une requête groupée, ordre du périmètre préservé par équipe.
        $campaignTeamIds = $campaign->getTeamIds();
        $nameById = [];
        if ([] !== $campaignTeamIds) {
            foreach ($this->entityManager->getRepository(Team::class)->findBy(['id' => $campaignTeamIds]) as $team) {
                $nameById[$team->getId()] = $team->getName();
            }
        }

        $teams = [];
        foreach ($coachTeamIds as $teamId) {
            if (isset($nameById[$teamId])) {
                $teams[] = ['id' => $teamId, 'name' => $nameById[$teamId]];
            }
        }

        // Partenaires proposables = équipes de la campagne (le front retire l'équipe courante
        // par bloc, et met les passerelles en tête). Ordre de `campaign.teamIds`.
        $partnerTeams = [];
        foreach ($campaignTeamIds as $teamId) {
            if (isset($nameById[$teamId])) {
                $partnerTeams[] = ['id' => $teamId, 'name' => $nameById[$teamId]];
            }
        }

        // Passerelles (TeamLink) RESTREINTES aux équipes de la campagne : le couple dont les
        // DEUX extrémités sont dans la collecte. Le front s'en sert pour hisser une passerelle
        // en tête des partenaires proposés.
        $campaignTeamSet = array_flip($campaignTeamIds);
        $teamLinks = [];
        foreach ($this->entityManager->getRepository(TeamLink::class)->findBy(['seasonId' => $campaign->getSeasonId()]) as $link) {
            if (isset($campaignTeamSet[$link->getTeamAId()], $campaignTeamSet[$link->getTeamBId()])) {
                $teamLinks[] = ['teamAId' => $link->getTeamAId(), 'teamBId' => $link->getTeamBId()];
            }
        }

        $wishes = [];
        $mutualizations = [];
        if ($includeExisting && [] !== $coachTeamIds) {
            // Doléances existantes des équipes DU COACH (pré-remplissage), bornées aux semaines
            // de la campagne — jamais le drapeau `done`, jamais des noms tiers.
            foreach ($this->entityManager->getRepository(CoachWish::class)->findBy(['calendarEntryId' => $campaign->getCalendarEntryId(), 'teamId' => $coachTeamIds]) as $wish) {
                if (!\in_array($wish->getWeekStart()->format('Y-m-d'), $campaign->getWeeks(), true)) {
                    continue;
                }
                $wishes[] = [
                    'teamId' => $wish->getTeamId(),
                    'weekStart' => $wish->getWeekStart()->format('Y-m-d'),
                    'slotsWanted' => $wish->getSlotsWanted(),
                    'unavailableDays' => $wish->getUnavailableDays(),
                    'wishedDays' => $wish->getWishedDays(),
                    'comment' => $wish->getComment(),
                    // Booléen NU (volet B) : jamais les horaires de saison, juste le drapeau.
                    'keepSeasonSlots' => $wish->keepsSeasonSlots(),
                ];
            }

            // Mutualisations existantes des équipes DU COACH (pré-remplissage) — jamais `done`
            // ni `coachId` (le coach ne voit pas l'arbitrage du gestionnaire).
            foreach ($this->entityManager->getRepository(CoachWishMutualization::class)->findBy(['calendarEntryId' => $campaign->getCalendarEntryId(), 'teamId' => $coachTeamIds]) as $m) {
                $mutualizations[] = [
                    'teamId' => $m->getTeamId(),
                    'partnerTeamIds' => $m->getPartnerTeamIds(),
                    'sharedSlots' => $m->getSharedSlots(),
                ];
            }
        }

        return [
            'coachFirstName' => $coach->getFirstName(),
            'periodTitle' => $entry?->getTitle() ?? '',
            'periodStart' => $entry?->getStartDate()->format('Y-m-d'),
            'periodEnd' => $entry?->getEndDate()->format('Y-m-d'),
            'deadline' => $campaign->getDeadline()->format('Y-m-d'),
            'weeks' => $campaign->getWeeks(),
            'teams' => $teams,
            'partnerTeams' => $partnerTeams,
            'teamLinks' => $teamLinks,
            'wishes' => $wishes,
            'mutualizations' => $mutualizations,
            'respondedAt' => $respondedAt,
        ];
    }
}
