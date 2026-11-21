<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Exception\TeamAlreadyAtCaseException;
use App\Exception\TeamAlreadyTrainsThatDayException;
use App\Support\WeeklySlot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MAISON UNIQUE de la question « cette équipe siège-t-elle DÉJÀ à cette case ? » (schedule + équipe
 * + gymnase + jour + heure de début) et du libellé humain du refus.
 *
 * La même équipe ne doit JAMAIS siéger deux fois à la même case (règle fondateur) : la cohabitation
 * à capacité restante n'est permise qu'entre équipes DIFFÉRENTES. Le moteur fusionnant les séances
 * par (gymnase, jour, heure), le doublon de même équipe lui est invisible — d'où cette garde côté
 * BACKEND, exécutée AVANT tout appel moteur par chaque rail d'écriture (déplacer, placer, déplacer
 * un bloc, mutualiser). Les créneaux déjà engagés dans le geste — la source déplacée, l'occupant
 * évincé, les membres eux-mêmes d'une séance de bloc, la séance désignée d'une mutualisation — sont
 * EXCLUS de la vérification : ils ne constituent pas un doublon, ils SONT le geste.
 *
 * Le filet ultime est l'index UNIQUE en base (`schedule_slot_template`) ; cette garde rend le refus
 * explicite et NOMMÉ avant d'y toucher.
 *
 * Seconde question, posée APRÈS celle de la case : « cette équipe peut-elle encore s'entraîner ce JOUR-LÀ, à
 * cette heure ? » Le plafond de séances par jour de l'équipe (1 par défaut, valeur EFFECTIVE du plan du
 * planning, {@see EffectiveTeamSessions::maxPerDay}) borne le nombre de séances du jour ; à plafond 1, une
 * autre séance ce jour-là suffit à refuser, chevauchement ou non. À plafond ≥ 2, le chevauchement avec une
 * autre de ses séances du jour est refusé d'abord (intervalles demi-ouverts : deux séances collées ne se
 * chevauchent pas), puis le plafond atteint. L'écart entre deux séances n'est jamais contrôlé ici. Une séance
 * de groupe compte pour chacun de ses membres : chaque membre a sa propre ligne, la règle se lit par équipe.
 */
final class TeamCaseOccupancyGuard
{
    /** @var array<int, string> ISO-8601 : 1 = lundi (jamais d'identifiant interne dans un texte lu par un humain). */
    public const array DAY_LABELS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    private readonly EffectiveTeamSessions $effectiveTeamSessions;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?EffectiveTeamSessions $effectiveTeamSessions = null,
    ) {
        $this->effectiveTeamSessions = $effectiveTeamSessions ?? new EffectiveTeamSessions($entityManager);
    }

    /**
     * La phrase humaine « <équipe> a déjà une séance <jour> <heure> à <gymnase> » si l'équipe siège
     * déjà à la case, hors créneaux exclus — null si la case lui est libre. Tenant-scopé par le
     * filtre Doctrine. Noms réels (équipe, gymnase), jour en toutes lettres, aucun identifiant interne.
     *
     * @param list<string> $excludedSlotIds
     */
    public function conflictSentence(string $scheduleId, string $teamId, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, array $excludedSlotIds = []): ?string
    {
        $existing = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $scheduleId,
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
        ]);

        $excluded = array_fill_keys($excludedSlotIds, true);
        foreach ($existing as $slot) {
            if (!isset($excluded[$slot->getId()])) {
                return $this->sentence($teamId, $venueId, $dayOfWeek, $startTime);
            }
        }

        return null;
    }

    /**
     * Refuse (→ 422 `team_already_at_case`) si l'équipe siège déjà à la case, hors créneaux exclus.
     * `$trailer` ferme la phrase : un point pour un refus sec, une consigne pour un rail sans
     * éviction possible (« — déplacez-la ou retirez-la d'abord. »).
     *
     * @param list<string> $excludedSlotIds
     *
     * @throws TeamAlreadyAtCaseException l'équipe siège déjà à la case (→ 422), rien n'est écrit
     */
    public function assertTeamNotAlreadyAtCase(string $scheduleId, string $teamId, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, array $excludedSlotIds = [], string $trailer = '.'): void
    {
        $sentence = $this->conflictSentence($scheduleId, $teamId, $venueId, $dayOfWeek, $startTime, $excludedSlotIds);
        if (null !== $sentence) {
            throw new TeamAlreadyAtCaseException($sentence . $trailer);
        }
    }

    /**
     * Les AUTRES séances de l'équipe ce jour-là, hors créneaux exclus (ceux engagés dans le geste), par heure.
     *
     * @param list<string> $excludedSlotIds
     *
     * @return list<ScheduleSlotTemplate>
     */
    public function otherSessionsOnDay(string $scheduleId, string $teamId, int $dayOfWeek, array $excludedSlotIds = []): array
    {
        $excluded = array_fill_keys($excludedSlotIds, true);

        return array_values(array_filter(
            $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId, 'teamId' => $teamId, 'dayOfWeek' => $dayOfWeek], ['startTime' => 'ASC']),
            static fn (ScheduleSlotTemplate $slot): bool => !isset($excluded[$slot->getId()]),
        ));
    }

    /**
     * Une séance de l'équipe ce jour-là quand elle a DÉJÀ atteint son plafond de séances par jour (hors
     * créneaux exclus) — la première par heure ; null s'il lui reste de la place ce jour-là.
     *
     * @param list<string> $excludedSlotIds
     */
    public function dayCapReachedBy(string $scheduleId, string $teamId, int $dayOfWeek, array $excludedSlotIds = []): ?ScheduleSlotTemplate
    {
        $others = $this->otherSessionsOnDay($scheduleId, $teamId, $dayOfWeek, $excludedSlotIds);

        return \count($others) >= $this->dailyCap($scheduleId, $teamId) ? ($others[0] ?? null) : null;
    }

    /** Le plafond de séances par jour de l'équipe, pour le plan du planning (surcharge de période comprise). */
    public function dailyCap(string $scheduleId, string $teamId): int
    {
        $team = $this->entityManager->getRepository(Team::class)->find($teamId);
        if (!$team instanceof Team) {
            return 1;
        }
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);

        return $this->effectiveTeamSessions->maxPerDay($team, $schedule instanceof Schedule ? $schedule->getSchedulePlanId() : null);
    }

    /**
     * La phrase du plafond atteint : à 1, « U15 s'entraîne déjà le mardi : une seule séance par jour. » ; au-delà,
     * « U15 a déjà 2 séances le mardi : 2 séances par jour au plus pour cette équipe. ».
     */
    public function dayCapSentence(string $teamId, int $dayOfWeek, int $cap): string
    {
        if ($cap <= 1) {
            return $this->oneSessionPerDaySentence($teamId, $dayOfWeek);
        }

        return \sprintf('%s a déjà %d séances le %s : %d séances par jour au plus pour cette équipe.', $this->teamName($teamId), $cap, self::DAY_LABELS[$dayOfWeek] ?? 'jour ' . $dayOfWeek, $cap);
    }

    /**
     * À plafond ≥ 2, « U15 a déjà une séance qui chevauche ce créneau le mardi. » si une autre séance de l'équipe ce
     * jour-là chevauche [début, début + durée[ ; null sinon (et toujours null à plafond 1, où le plafond parle seul).
     *
     * @param list<string> $excludedSlotIds
     */
    public function overlapSentence(string $scheduleId, string $teamId, int $dayOfWeek, DateTimeImmutable $startTime, int $durationMinutes, array $excludedSlotIds = []): ?string
    {
        if ($this->dailyCap($scheduleId, $teamId) < 2) {
            return null;
        }
        $from = WeeklySlot::minutesOf($startTime);
        $to = $from + $durationMinutes;
        foreach ($this->otherSessionsOnDay($scheduleId, $teamId, $dayOfWeek, $excludedSlotIds) as $slot) {
            $slotFrom = WeeklySlot::minutesOf($slot->getStartTime());
            if (WeeklySlot::intervalsOverlap($from, $to, $slotFrom, $slotFrom + $slot->getDurationMinutes())) {
                return \sprintf('%s a déjà une séance qui chevauche ce créneau le %s.', $this->teamName($teamId), self::DAY_LABELS[$dayOfWeek] ?? 'jour ' . $dayOfWeek);
            }
        }

        return null;
    }

    /** « U15 s'entraîne déjà le mardi : une seule séance par jour. » */
    public function oneSessionPerDaySentence(string $teamId, int $dayOfWeek): string
    {
        return \sprintf('%s s\'entraîne déjà le %s : une seule séance par jour.', $this->teamName($teamId), self::DAY_LABELS[$dayOfWeek] ?? 'jour ' . $dayOfWeek);
    }

    /** La consigne de la fiche Mutualiser : quelle séance désigner pour garder une seule séance ce jour-là. */
    public function designateThatSessionHint(ScheduleSlotTemplate $slot): string
    {
        $venueName = $this->entityManager->getRepository(Venue::class)->find($slot->getVenueId())?->getName() ?? 'ce gymnase';

        return \sprintf('Désignez sa séance du %s %s à %s comme séance remplacée.', self::DAY_LABELS[$slot->getDayOfWeek()] ?? 'jour ' . $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'), $venueName);
    }

    /**
     * Refuse (→ 422 `team_already_trains_that_day`) si l'équipe ne peut pas s'entraîner ce jour-là à [début, début
     * + durée[, hors créneaux exclus. Plafond 1 : une autre séance ce jour-là suffit (aucun contrôle de
     * chevauchement). Plafond ≥ 2 : chevauchement d'abord, puis plafond atteint. Aucun contrôle d'écart.
     *
     * @param list<string> $excludedSlotIds
     *
     * @throws TeamAlreadyTrainsThatDayException l'équipe ne peut pas prendre cette séance ce jour-là (→ 422), rien n'est écrit
     */
    public function assertTeamCanTrainThen(string $scheduleId, string $teamId, int $dayOfWeek, DateTimeImmutable $startTime, int $durationMinutes, array $excludedSlotIds = []): void
    {
        $overlap = $this->overlapSentence($scheduleId, $teamId, $dayOfWeek, $startTime, $durationMinutes, $excludedSlotIds);
        if (null !== $overlap) {
            throw new TeamAlreadyTrainsThatDayException($overlap);
        }
        if ($this->dayCapReachedBy($scheduleId, $teamId, $dayOfWeek, $excludedSlotIds) instanceof ScheduleSlotTemplate) {
            throw new TeamAlreadyTrainsThatDayException($this->dayCapSentence($teamId, $dayOfWeek, $this->dailyCap($scheduleId, $teamId)));
        }
    }

    private function teamName(string $teamId): string
    {
        return $this->entityManager->getRepository(Team::class)->find($teamId)?->getName() ?? 'Cette équipe';
    }

    private function sentence(string $teamId, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime): string
    {
        $team = $this->entityManager->getRepository(Team::class)->find($teamId);
        $teamName = $team?->getName() ?? 'Cette équipe';
        $venue = $this->entityManager->getRepository(Venue::class)->find($venueId);
        $venueName = $venue?->getName() ?? 'ce gymnase';
        $dayLabel = self::DAY_LABELS[$dayOfWeek] ?? 'jour ' . $dayOfWeek;

        return \sprintf('%s a déjà une séance %s %s à %s', $teamName, $dayLabel, $startTime->format('H:i'), $venueName);
    }
}
