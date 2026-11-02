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
     *     teams: list<array{id: string, name: string, staff: list<array{coachId: string, firstName: string, lastName: string, role: 'MAIN'|'ASSISTANT'}>}>,
     *     partnerTeams: list<array{id: string, name: string}>,
     *     teamLinks: list<array{teamAId: string, teamBId: string}>,
     *     wishes: list<array{teamId: string, weekStart: string, slotsWanted: int, unavailableDays: list<int>, staffUnavailability: list<array{coachId: string, days: list<int>}>, wishedDays: list<int>, comment: string|null, keepSeasonSlots: bool, byAnotherCoach: bool}>,
     *     absencesElsewhere: list<array{teamId: string, weekStart: string, coachId: string, days: list<int>}>,
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

        $staffByTeam = $this->perimeter->staffOfTeams($coachTeamIds);
        $teams = [];
        foreach ($coachTeamIds as $teamId) {
            if (isset($nameById[$teamId])) {
                $teams[] = ['id' => $teamId, 'name' => $nameById[$teamId], 'staff' => $staffByTeam[$teamId] ?? []];
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
        $absencesElsewhere = [];
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
                    // Le staff COURANT seul : une personne partie n'est plus pré-remplie, donc plus renvoyée.
                    'staffUnavailability' => $wish->staffUnavailabilityAmong(array_fill_keys(array_column($staffByTeam[$wish->getTeamId()] ?? [], 'coachId'), true)),
                    'wishedDays' => $wish->getWishedDays(),
                    'comment' => $wish->getComment(),
                    // Booléen NU (volet B) : jamais les horaires de saison, juste le drapeau.
                    'keepSeasonSlots' => $wish->keepsSeasonSlots(),
                    // Option A (fondateur 2026-10-10) — une doléance par (équipe × semaine) : si CE
                    // couple a été saisi par un AUTRE coach (équipe co-coachée), on PRÉVIENT que la
                    // réponse remplacera la sienne. On n'expose JAMAIS son prénom ni rien de plus que
                    // la saisie déjà pré-remplie (bornée au périmètre du jeton) : le front dira « un
                    // autre coach ». `coachId` null (doléance dé-attribuée) n'est pas « un autre coach ».
                    'byAnotherCoach' => null !== $wish->getCoachId() && $wish->getCoachId() !== $coach->getId(),
                ];
            }

            $absencesElsewhere = $this->absencesElsewhere($campaign, $staffByTeam);

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
            'absencesElsewhere' => $absencesElsewhere,
            'mutualizations' => $mutualizations,
            'respondedAt' => $respondedAt,
        ];
    }

    /**
     * Pour chaque équipe du coach × semaine × personne de son staff : les jours que des doléances
     * d'AUTRES équipes déclarent pour cette personne, chacune filtrée sur le staff courant de SA
     * propre équipe. Des jours et rien d'autre : jamais le nom de l'autre équipe.
     *
     * @param array<string, list<array{coachId: string, firstName: string, lastName: string, role: 'MAIN'|'ASSISTANT'}>> $staffByTeam
     *
     * @return list<array{teamId: string, weekStart: string, coachId: string, days: list<int>}>
     */
    private function absencesElsewhere(CoachWishCampaign $campaign, array $staffByTeam): array
    {
        $wishes = $this->entityManager->getRepository(CoachWish::class)->findBy(['calendarEntryId' => $campaign->getCalendarEntryId()]);
        $teamIds = array_values(array_unique(array_map(static fn (CoachWish $wish): string => $wish->getTeamId(), $wishes)));
        $wishStaff = $this->perimeter->staffOfTeams($teamIds);

        // [semaine][personne][équipe déclarante] = jours
        $declared = [];
        foreach ($wishes as $wish) {
            $current = array_fill_keys(array_column($wishStaff[$wish->getTeamId()] ?? [], 'coachId'), true);
            foreach ($wish->staffUnavailabilityAmong($current) as $line) {
                $declared[$wish->getWeekStart()->format('Y-m-d')][$line['coachId']][$wish->getTeamId()] = $line['days'];
            }
        }

        $absences = [];
        foreach ($staffByTeam as $teamId => $staff) {
            foreach ($campaign->getWeeks() as $week) {
                foreach ($staff as $member) {
                    $elsewhere = $declared[$week][$member['coachId']] ?? [];
                    unset($elsewhere[$teamId]);
                    if ([] === $elsewhere) {
                        continue;
                    }
                    $days = array_values(array_unique(array_merge(...array_values($elsewhere))));
                    sort($days);
                    $absences[] = ['teamId' => (string) $teamId, 'weekStart' => $week, 'coachId' => $member['coachId'], 'days' => $days];
                }
            }
        }

        return $absences;
    }
}
