<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\LockLevel;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class DevScheduleReportWriter
{
    private const DAYS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Creates the lot directory, writes payload.json and payload-summary.txt.
     * Returns the lot directory path.
     *
     * @param array<string, mixed> $scheduleInput
     */
    public function writePayloadFiles(Schedule $schedule, array $scheduleInput): string
    {
        $base = $this->kernel->getProjectDir() . '/var/generate/schedule-' . $schedule->getId();
        $existingLots = is_dir($base) ? \count((array) glob($base . '/*', \GLOB_ONLYDIR)) : 0;
        $lotNum = str_pad((string) ($existingLots + 1), 3, '0', \STR_PAD_LEFT);
        $datetime = (new DateTimeImmutable)->format('Y_m_d-H_i');
        $lotDir = $base . '/' . $lotNum . '-' . $datetime;
        mkdir($lotDir, 0o755, true);

        // payload.json
        file_put_contents(
            $lotDir . '/payload.json',
            json_encode($scheduleInput, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );

        // payload-summary.txt — version BDD simplifiée
        $clubId = $schedule->getClubId();
        $seasonId = $schedule->getSeasonId();

        // Name maps for readable output
        $teamNames = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $teamNames[$team->getId()] = $team->getName();
        }
        $venueNames = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $venue) {
            $venueNames[$venue->getId()] = $venue->getName();
        }
        $coachNames = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $coach) {
            $coachNames[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
        }

        $summaryLines = [
            \sprintf('Schedule : %s', $schedule->getName()),
            \sprintf('Club     : %s', $clubId),
            \sprintf('Saison   : %s', $seasonId),
            '',
            \sprintf('Équipes          : %d', \count($scheduleInput['teams'] ?? [])),
            \sprintf('Venues           : %d', \count($scheduleInput['venues'] ?? [])),
            \sprintf('Slot templates   : %d', \count($scheduleInput['slotTemplates'] ?? [])),
            \sprintf('Coaches          : %d', \count($scheduleInput['coaches'] ?? [])),
            '',
        ];

        // 1. Contraintes métier (Constraint entity) — plan de base seul, les
        // contraintes datées (CalendarEntry) sont hors périmètre du rapport.
        $constraints = $this->entityManager->getRepository(Constraint::class)->findPermanentByClubSeason($clubId, $seasonId);
        $activeConstraints = array_filter($constraints, static fn (Constraint $c): bool => $c->getIsActive());
        if ([] !== $activeConstraints) {
            // Sort by ruleType (HARD first), then sortOrder
            usort($activeConstraints, static function (Constraint $a, Constraint $b): int {
                $typeOrder = ['HARD' => 0, 'PREFERRED' => 1, 'LOCK' => 2];
                $aType = $typeOrder[$a->getRuleType()->value];
                $bType = $typeOrder[$b->getRuleType()->value];
                if ($aType !== $bType) {
                    return $aType <=> $bType;
                }

                return $a->getSortOrder() <=> $b->getSortOrder();
            });

            $summaryLines[] = \sprintf('Contraintes métier (%d actives) :', \count($activeConstraints));
            foreach ($activeConstraints as $c) {
                $summaryLines[] = \sprintf('  [%s] [%s] %s', $c->getRuleType()->value, $c->getFamily()->value, $c->getName());

                $scopeTargetId = $c->getScopeTargetId();
                $scopeStr = $c->getScope()->value;
                if (null !== $scopeTargetId && '' !== $scopeTargetId && 'CLUB' !== $c->getScope()->value) {
                    $targetName = $scopeTargetId;
                    if ('TEAM' === $c->getScope()->value) {
                        $targetName = $teamNames[$scopeTargetId] ?? $scopeTargetId;
                    } elseif ('FACILITY' === $c->getScope()->value) {
                        $targetName = $venueNames[$scopeTargetId] ?? $scopeTargetId;
                    } elseif ('COACH' === $c->getScope()->value) {
                        $targetName = $coachNames[$scopeTargetId] ?? $scopeTargetId;
                    }
                    $scopeStr .= ': ' . $targetName;
                }

                $configParts = [];
                foreach ($c->getConfig() as $key => $val) {
                    $configParts[] = \is_array($val) ? $key . '=[' . implode(',', $val) . ']' : $key . '=' . $val;
                }
                $detailParts = ['scope: ' . $scopeStr];
                if ([] !== $configParts) {
                    $detailParts[] = 'config: ' . implode(', ', $configParts);
                }
                $summaryLines[] = '      ' . implode(' | ', $detailParts);
            }
            $summaryLines[] = '';
        }

        // 2. TeamCoach
        $teamCoaches = $this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        if ([] !== $teamCoaches) {
            $summaryLines[] = \sprintf('TeamCoach (%d) :', \count($teamCoaches));
            foreach ($teamCoaches as $tc) {
                $teamName = $teamNames[$tc->getTeamId()] ?? $tc->getTeamId();
                $coachName = $coachNames[$tc->getCoachId()] ?? $tc->getCoachId();
                $required = $tc->getIsRequired() ? 'obligatoire' : 'optionnel';
                $summaryLines[] = \sprintf('  %s — %s (%s)', $teamName, $coachName, $required);
            }
            $summaryLines[] = '';
        }

        // 3. CoachPlayerMembership
        $memberships = $this->entityManager->getRepository(CoachPlayerMembership::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        if ([] !== $memberships) {
            $summaryLines[] = \sprintf('CoachPlayerMembership (%d) :', \count($memberships));
            foreach ($memberships as $m) {
                $teamName = $teamNames[$m->getTeamId()] ?? $m->getTeamId();
                $coachName = $coachNames[$m->getCoachId()] ?? $m->getCoachId();
                $pos = $m->getPosition() ? ' (' . $m->getPosition() . ')' : '';
                $summaryLines[] = \sprintf('  %s — %s%s', $coachName, $teamName, $pos);
            }
            $summaryLines[] = '';
        }

        // 4. PriorityTiers
        $priorityTiers = $this->entityManager->getRepository(PriorityTier::class)->findBy([], ['id' => 'ASC']);
        if ([] !== $priorityTiers) {
            $summaryLines[] = \sprintf('PriorityTiers (%d) :', \count($priorityTiers));
            foreach ($priorityTiers as $pt) {
                $summaryLines[] = \sprintf('  %s — poids %d (min %d séances)', $pt->getLabel(), $pt->getOrToolsWeight(), $pt->getDefaultMinSessions());
            }
            $summaryLines[] = '';
        }

        // 5. Créneaux d'entraînement
        $availabilities = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        if ([] !== $availabilities) {
            $summaryLines[] = \sprintf('Disponibilités salles (%d) :', \count($availabilities));
            $byVenue = [];
            foreach ($availabilities as $va) {
                $byVenue[$va->getVenueId()][] = $va;
            }
            foreach ($byVenue as $venueId => $vas) {
                $venueName = $venueNames[$venueId] ?? $venueId;
                usort($vas, static fn (VenueTrainingSlot $a, VenueTrainingSlot $b): int => $a->getDayOfWeek() <=> $b->getDayOfWeek() ?: $a->getStartTime() <=> $b->getStartTime());
                $slots = [];
                foreach ($vas as $va) {
                    $dayName = self::DAYS[$va->getDayOfWeek()] ?? (string) $va->getDayOfWeek();
                    $end = DateTimeImmutable::createFromInterface($va->getStartTime())->modify('+' . $va->getDurationMinutes() . ' minutes');
                    $slots[] = \sprintf('%s %s-%s', $dayName, $va->getStartTime()->format('H:i'), $end->format('H:i'));
                }
                $summaryLines[] = \sprintf('  %s : %s', $venueName, implode(', ', $slots));
            }
            $summaryLines[] = '';
        }

        // 6. Slots templates HARD
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        $hardSlots = array_values(array_filter($slots, static fn (ScheduleSlotTemplate $slot): bool => LockLevel::HARD === $slot->getLockLevel()));
        if ([] !== $hardSlots) {
            usort($hardSlots, static function (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b) use ($teamNames): int {
                $teamA = $teamNames[$a->getTeamId()] ?? $a->getTeamId();
                $teamB = $teamNames[$b->getTeamId()] ?? $b->getTeamId();
                if ($teamA !== $teamB) {
                    return $teamA <=> $teamB;
                }

                if ($a->getDayOfWeek() !== $b->getDayOfWeek()) {
                    return $a->getDayOfWeek() <=> $b->getDayOfWeek();
                }

                return $a->getStartTime()->format('H:i') <=> $b->getStartTime()->format('H:i');
            });

            $summaryLines[] = \sprintf('Slots HARD lockés (%d) :', \count($hardSlots));
            foreach ($hardSlots as $slot) {
                $teamName = $teamNames[$slot->getTeamId()] ?? $slot->getTeamId();
                $venueName = $venueNames[$slot->getVenueId()] ?? $slot->getVenueId();
                $start = $slot->getStartTime()->format('H:i');
                $end = DateTimeImmutable::createFromInterface($slot->getStartTime())->modify('+' . $slot->getDurationMinutes() . ' minutes')->format('H:i');

                $summaryLines[] = \sprintf(
                    '  %-20s  J%d  %s → %s (%d min)   @ %s',
                    $teamName,
                    $slot->getDayOfWeek(),
                    $start,
                    $end,
                    $slot->getDurationMinutes(),
                    $venueName,
                );
            }
            $summaryLines[] = '';
        }

        // 7. Contraintes horaires (TIME family)
        $timeConstraints = array_values(array_filter($activeConstraints, static fn (Constraint $constraint): bool => ConstraintFamily::TIME === $constraint->getFamily()));
        if ([] !== $timeConstraints) {
            usort($timeConstraints, static function (Constraint $a, Constraint $b): int {
                $typeOrder = ['HARD' => 0, 'PREFERRED' => 1];
                $aType = $typeOrder[$a->getRuleType()->value] ?? 99;
                $bType = $typeOrder[$b->getRuleType()->value] ?? 99;
                if ($aType !== $bType) {
                    return $aType <=> $bType;
                }

                return $a->getSortOrder() <=> $b->getSortOrder();
            });

            $summaryLines[] = \sprintf('Contraintes horaires (%d) :', \count($timeConstraints));
            foreach ($timeConstraints as $constraint) {
                $scopeStr = $constraint->getScope()->value;
                $scopeTargetId = $constraint->getScopeTargetId();
                if (null !== $scopeTargetId && '' !== $scopeTargetId && 'CLUB' !== $constraint->getScope()->value) {
                    $targetName = $scopeTargetId;
                    if ('TEAM' === $constraint->getScope()->value) {
                        $targetName = $teamNames[$scopeTargetId] ?? $scopeTargetId;
                    } elseif ('FACILITY' === $constraint->getScope()->value) {
                        $targetName = $venueNames[$scopeTargetId] ?? $scopeTargetId;
                    } elseif ('COACH' === $constraint->getScope()->value) {
                        $targetName = $coachNames[$scopeTargetId] ?? $scopeTargetId;
                    }
                    $scopeStr .= ': ' . $targetName;
                }

                $configParts = [];
                foreach ($constraint->getConfig() as $key => $val) {
                    $configParts[] = \is_array($val) ? $key . '=[' . implode(',', $val) . ']' : $key . '=' . $val;
                }

                $line = \sprintf('  [%s]      scope: %s', $constraint->getRuleType()->value, $scopeStr);
                if ([] !== $configParts) {
                    $line .= '       config: ' . implode(', ', $configParts);
                }

                $summaryLines[] = $line;
            }
            $summaryLines[] = '';
        }

        $summary = implode("\n", $summaryLines);

        file_put_contents($lotDir . '/payload-summary.txt', $summary);

        return $lotDir;
    }

    /**
     * Writes slots-by-team.txt, slots-by-venue.txt, diagnostics.txt (only if non-empty).
     */
    public function writeResultFiles(Schedule $schedule, string $lotDir): void
    {
        $criteria = [
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ];

        // Load slots
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $schedule->getId()],
        );
        $slots = $this->mergeConsecutiveSlots($slots);

        // Build name maps
        $teamNames = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy($criteria) as $team) {
            $teamNames[$team->getId()] = $team->getName();
        }

        $venueNames = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy($criteria) as $venue) {
            $venueNames[$venue->getId()] = $venue->getName();
        }

        $coachNames = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy($criteria) as $coach) {
            $coachNames[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
        }

        // Build team → coaches map from TeamCoach (names for display, IDs for player matching)
        $teamCoaches = [];
        $teamCoachIds = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $schedule->getClubId()]) as $tc) {
            $teamCoaches[$tc->getTeamId()][] = $coachNames[$tc->getCoachId()] ?? $tc->getCoachId();
            $teamCoachIds[$tc->getTeamId()][] = $tc->getCoachId();
        }

        // slots-by-team.txt
        $slotsByTeam = [];
        foreach ($slots as $slot) {
            $slotsByTeam[$slot['teamId']][] = $slot;
        }
        ksort($slotsByTeam);

        $lines = [];
        foreach ($slotsByTeam as $teamId => $teamSlots) {
            $teamName = $teamNames[$teamId] ?? $teamId;
            $coaches = $teamCoaches[$teamId] ?? [];
            $coachStr = [] !== $coaches ? implode(', ', $coaches) : '';
            $lines[] = $teamName . ('' !== $coachStr ? ' — ' . $coachStr : '');

            usort($teamSlots, static fn (array $a, array $b): int => $a['dayOfWeek'] <=> $b['dayOfWeek'] ?: $a['startTime'] <=> $b['startTime']);

            foreach ($teamSlots as $slot) {
                $dayName = self::DAYS[$slot['dayOfWeek']] ?? (string) $slot['dayOfWeek'];
                $start = $slot['startTime']->format('H:i');
                $end = DateTimeImmutable::createFromInterface($slot['startTime'])->modify('+' . $slot['durationMinutes'] . ' minutes')->format('H:i');
                $venueName = $venueNames[$slot['venueId']] ?? $slot['venueId'];
                $lines[] = \sprintf('  %-10s %s → %s (%d min)  @ %s', $dayName, $start, $end, $slot['durationMinutes'], $venueName);
            }
            $lines[] = '';
        }

        file_put_contents($lotDir . '/slots-by-team.txt', implode("\n", $lines));

        // slots-by-venue.txt
        $slotsByVenue = [];
        foreach ($slots as $slot) {
            $slotsByVenue[$slot['venueId']][] = $slot;
        }
        ksort($slotsByVenue);

        $lines = [];
        foreach ($slotsByVenue as $venueId => $venueSlots) {
            $venueName = $venueNames[$venueId] ?? $venueId;
            $lines[] = $venueName;

            usort($venueSlots, static fn (array $a, array $b): int => $a['dayOfWeek'] <=> $b['dayOfWeek'] ?: $a['startTime'] <=> $b['startTime']);

            foreach ($venueSlots as $slot) {
                $dayName = self::DAYS[$slot['dayOfWeek']] ?? (string) $slot['dayOfWeek'];
                $start = $slot['startTime']->format('H:i');
                $end = DateTimeImmutable::createFromInterface($slot['startTime'])->modify('+' . $slot['durationMinutes'] . ' minutes')->format('H:i');
                $teamName = $teamNames[$slot['teamId']] ?? $slot['teamId'];
                $lines[] = \sprintf('  %-10s %s → %s (%d min)   %s', $dayName, $start, $end, $slot['durationMinutes'], $teamName);
            }
            $lines[] = '';
        }

        file_put_contents($lotDir . '/slots-by-venue.txt', implode("\n", $lines));

        // slots-by-coach.txt
        // Load active CoachPlayerMembership so coaches-who-play also appear.
        $playerMemberships = $this->entityManager->getRepository(CoachPlayerMembership::class)
            ->findBy(['clubId' => $schedule->getClubId(), 'seasonId' => $schedule->getSeasonId()]);

        // Build coachId → list of teamIds where coach is an active player.
        $coachPlayerTeams = [];
        foreach ($playerMemberships as $m) {
            if (!$m->isIsActive()) {
                continue;
            }
            $coachPlayerTeams[$m->getCoachId()][] = $m->getTeamId();
        }

        // Initialize coachSlots with every coach in the club/season so that
        // coaches with no coaching and no playing still show "Aucun créneau".
        $coachSlots = [];
        foreach ($coachNames as $coachName) {
            $coachSlots[$coachName] = [];
        }

        // Coaching slots (coach assigned via TeamCoach).
        foreach ($teamCoachIds as $teamId => $coachIds) {
            foreach ($coachIds as $coachId) {
                $coachName = $coachNames[$coachId] ?? $coachId;
                foreach ($slots as $slot) {
                    if ($slot['teamId'] !== $teamId) {
                        continue;
                    }

                    $coachSlots[$coachName][] = ['slot' => $slot, 'isPlayer' => false];
                }
            }
        }

        // Player slots (coach is an active member of the team via CoachPlayerMembership).
        foreach ($coachPlayerTeams as $coachId => $teamIds) {
            $coachName = $coachNames[$coachId] ?? $coachId;
            foreach ($slots as $slot) {
                if (!\in_array($slot['teamId'], $teamIds, true)) {
                    continue;
                }

                $coachSlots[$coachName][] = ['slot' => $slot, 'isPlayer' => true];
            }
        }

        ksort($coachSlots);

        $lines = [];
        foreach ($coachSlots as $coachName => $coachScheduleSlots) {
            $lines[] = $coachName;

            if ([] === $coachScheduleSlots) {
                $lines[] = '  Aucun créneau';
                $lines[] = '';
                continue;
            }

            usort($coachScheduleSlots, static fn (array $a, array $b): int => $a['slot']['dayOfWeek'] <=> $b['slot']['dayOfWeek'] ?: $a['slot']['startTime'] <=> $b['slot']['startTime']);

            foreach ($coachScheduleSlots as $entry) {
                $slot = $entry['slot'];
                $dayName = self::DAYS[$slot['dayOfWeek']] ?? (string) $slot['dayOfWeek'];
                $start = $slot['startTime']->format('H:i');
                $end = DateTimeImmutable::createFromInterface($slot['startTime'])->modify('+' . $slot['durationMinutes'] . ' minutes')->format('H:i');
                $venueName = $venueNames[$slot['venueId']] ?? $slot['venueId'];
                $teamName = $teamNames[$slot['teamId']] ?? $slot['teamId'];
                $suffix = $entry['isPlayer'] ? ' — joueur' : '';
                $lines[] = \sprintf('  %-10s %s → %s (%d min)  @ %s — %s%s', $dayName, $start, $end, $slot['durationMinutes'], $venueName, $teamName, $suffix);
            }
            $lines[] = '';
        }

        file_put_contents($lotDir . '/slots-by-coach.txt', implode("\n", $lines));

        // diagnostics.txt
        $diagnostics = $this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(
            ['scheduleId' => $schedule->getId()],
        );

        $statusValue = $schedule->getStatus()->value;
        $score = $schedule->getScore();
        $wallTime = $schedule->getSolverWallTimeMs();

        $header = \sprintf(
            "Statut solver : %s  |  Score : %s  |  Temps : %s ms\n",
            $statusValue,
            null !== $score ? (string) $score : 'N/A',
            null !== $wallTime ? (string) $wallTime : 'N/A',
        );

        $lines = [$header];
        if ([] !== $diagnostics) {
            $lines[] = \sprintf('Diagnostics (%d) :', \count($diagnostics));
            foreach ($diagnostics as $d) {
                $lines[] = \sprintf('  [%-8s] %-20s — %s', strtoupper($d->getSeverity()->value), $d->getType(), $d->getMessage());
            }
        } else {
            $lines[] = 'Diagnostics (0) : aucun';
        }
        file_put_contents($lotDir . '/diagnostics.txt', implode("\n", $lines) . "\n");
    }

    /**
     * Merges consecutive 15-min CP-SAT slots into single contiguous blocks.
     *
     * Returns array of ['teamId', 'venueId', 'dayOfWeek', 'startTime' (DateTimeInterface), 'durationMinutes' (int)]
     *
     * @param array<int, ScheduleSlotTemplate> $slots
     *
     * @return array<int, array{teamId: string, venueId: string, dayOfWeek: int, startTime: DateTimeInterface, durationMinutes: int}>
     */
    private function mergeConsecutiveSlots(array $slots): array
    {
        $groups = [];
        foreach ($slots as $slot) {
            $key = $slot->getTeamId() . '|' . $slot->getVenueId() . '|' . $slot->getDayOfWeek();
            $groups[$key][] = $slot;
        }

        $merged = [];
        foreach ($groups as $groupSlots) {
            usort($groupSlots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => $a->getStartTime() <=> $b->getStartTime());

            $first = $groupSlots[0];
            $blockStart = clone $first->getStartTime();
            $blockDuration = $first->getDurationMinutes();

            $counter = \count($groupSlots);
            for ($i = 1; $i < $counter; ++$i) {
                $prev = $groupSlots[$i - 1];
                $curr = $groupSlots[$i];
                $prevEnd = (clone $prev->getStartTime())->modify('+' . $prev->getDurationMinutes() . ' minutes');

                if ($prevEnd === $curr->getStartTime()) {
                    $blockDuration += $curr->getDurationMinutes();
                    continue;
                }

                $merged[] = [
                    'teamId' => $first->getTeamId(),
                    'venueId' => $first->getVenueId(),
                    'dayOfWeek' => $first->getDayOfWeek(),
                    'startTime' => $blockStart,
                    'durationMinutes' => $blockDuration,
                ];
                $first = $curr;
                $blockStart = clone $curr->getStartTime();
                $blockDuration = $curr->getDurationMinutes();
            }

            $merged[] = [
                'teamId' => $first->getTeamId(),
                'venueId' => $first->getVenueId(),
                'dayOfWeek' => $first->getDayOfWeek(),
                'startTime' => $blockStart,
                'durationMinutes' => $blockDuration,
            ];
        }

        return $merged;
    }
}
