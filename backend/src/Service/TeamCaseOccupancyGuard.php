<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Exception\TeamAlreadyAtCaseException;
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
 */
final class TeamCaseOccupancyGuard
{
    /** @var array<int, string> ISO-8601 : 1 = lundi (jamais d'identifiant interne dans un texte lu par un humain). */
    private const array DAY_LABELS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

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
