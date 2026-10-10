<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #8 (décision fondateur 2026-07-24) — UN ÉPINGLAGE ORPHELIN BLOQUE LA GÉNÉRATION.
 *
 * Une période possède sa grille : refaire cette grille (repartir d'une page blanche,
 * recopier le modèle de saison, désactiver un gymnase) peut laisser un verrou de
 * work-loop ou une réservation qui ne correspond plus à aucun créneau. Le filtrer en
 * silence replacerait la séance ailleurs sans rien dire ; le laisser passer épinglerait
 * une séance sur un créneau inexistant.
 *
 * « On ne fait pas de chose magique, on voit et on informe le gestionnaire. C'est lui qui
 * prend la décision » : on refuse la génération avec un message qui nomme le gymnase et
 * le jour, à charge pour lui de redéfinir ses créneaux ou de retirer l'épinglage.
 *
 * Ne concerne QUE les plannings de période : le socle définit lui-même sa grille.
 */
final class OrphanPinGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanVenueClosures $planVenueClosures,
    ) {}

    /**
     * LE prédicat ÉTROIT « réservation dont le triplet (gymnase, jour, heure) ne retombe
     * sur AUCUN créneau de la grille » — extrait pour la parité MÉCANIQUE avec le front
     * (`wizard/lib/orphanReservations.ts::orphanReservationIds`, cas partagés
     * `orphanReservations.parity.json`, gardé par `OrphanReservationsMirrorParityTest`).
     *
     * ⚠ SOUS-ENSEMBLE ASSUMÉ de `firstOrphanMessage` : le bloqueur complet retire EN PLUS
     * les gymnases désactivés et les jours de fermeture, et couvre les verrous HARD. Le
     * front LISTE (pour supprimer au récap), le backend REFUSE la génération. La parité ne
     * porte QUE sur ce prédicat étroit — les cas « le bloqueur complet refuse en plus »
     * sont documentés dans le fichier de cas comme divergence voulue, gardés par les tests
     * d'`OrphanPinGuard` eux-mêmes.
     *
     * @param list<array{id: string, venueId: string, dayOfWeek: int, startTime: string}> $reservations
     * @param list<array{venueId: string, dayOfWeek: int, startTime: string}>             $slots
     *
     * @return list<string> ids des réservations orphelines-par-triplet, ordre d'entrée
     */
    public static function orphanTripletIds(array $reservations, array $slots): array
    {
        $grid = [];
        foreach ($slots as $slot) {
            $grid[self::tripletKey($slot['venueId'], $slot['dayOfWeek'], $slot['startTime'])] = true;
        }

        $orphans = [];
        foreach ($reservations as $reservation) {
            if (!isset($grid[self::tripletKey($reservation['venueId'], $reservation['dayOfWeek'], $reservation['startTime'])])) {
                $orphans[] = $reservation['id'];
            }
        }

        return $orphans;
    }

    /**
     * P2-37 D5 — LE prédicat LARGE « réservation NON SERVIE » : elle ne sera honorée par
     * aucune génération de la période. Trois causes, UNION :
     *  - son gymnase est ENTIÈREMENT fermé sur la fenêtre (dérivé D1, {@see VenueClosureDays::fullyClosedVenueIds}) ;
     *  - le couple (gymnase, jour) est un JOUR fermé (`closedWeekdaysByVenue`) ;
     *  - son triplet (gymnase, jour, heure) ne retombe sur AUCUN créneau de la grille.
     *
     * ⚠ MIROIR DÉCLARÉ (régime 2) — parité MÉCANIQUE avec le front
     * (`wizard/lib/orphanReservations.ts::unservedReservationIds`, cas partagés
     * `orphanReservations.parity.json`, gardée par `OrphanReservationsMirrorParityTest`). Le
     * front LISTE ces réservations pour ALERTER (décision fondateur : « on ne fait pas de
     * modification passive, on alerte » — rien n'est supprimé) ; le backend, lui, REFUSE la
     * réservation à la source (D3) et l'escamote du payload à la génération.
     *
     * `orphanTripletIds` reste le prédicat ÉTROIT (triplet seul), sous-ensemble de celui-ci.
     *
     * @param list<array{id: string, venueId: string, dayOfWeek: int, startTime: string}> $reservations
     * @param list<array{venueId: string, dayOfWeek: int, startTime: string}>             $slots
     * @param list<string>                                                                $disabledVenueIds      gymnases hors service (désactivés OU fermés-total)
     * @param array<string, list<int>>                                                    $closedWeekdaysByVenue venueId => jours ISO fermés (1..7)
     *
     * @return list<string> ids des réservations non servies, ordre d'entrée
     */
    public static function unservedReservationIds(array $reservations, array $slots, array $disabledVenueIds, array $closedWeekdaysByVenue): array
    {
        $grid = [];
        foreach ($slots as $slot) {
            $grid[self::tripletKey($slot['venueId'], $slot['dayOfWeek'], $slot['startTime'])] = true;
        }
        $disabled = array_fill_keys($disabledVenueIds, true);
        $closed = [];
        foreach ($closedWeekdaysByVenue as $venueId => $weekdays) {
            foreach ($weekdays as $weekday) {
                $closed[$venueId][$weekday] = true;
            }
        }

        $unserved = [];
        foreach ($reservations as $reservation) {
            $isOff = isset($disabled[$reservation['venueId']])
                || isset($closed[$reservation['venueId']][$reservation['dayOfWeek']])
                || !isset($grid[self::tripletKey($reservation['venueId'], $reservation['dayOfWeek'], $reservation['startTime'])]);
            if ($isOff) {
                $unserved[] = $reservation['id'];
            }
        }

        return $unserved;
    }

    /** Heure NORMALISÉE à H:i (comme `hhmm` côté front) — la grille peut porter les secondes. */
    private static function tripletKey(string $venueId, int $dayOfWeek, string $startTime): string
    {
        return $venueId . '|' . $dayOfWeek . '|' . substr($startTime, 0, 5);
    }

    /**
     * Le message à afficher, ou null si tout épinglage retombe sur un créneau existant.
     */
    public function firstOrphanMessage(Schedule $schedule): ?string
    {
        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            return null;
        }
        $schedulePlanId = $schedule->getSchedulePlanId();

        // Les créneaux RÉELLEMENT SERVIS à la période, par (gymnase, jour, heure) — c'est
        // ce qu'un épinglage désigne : ni un verrou ni une réservation ne cite l'id du
        // créneau. On applique le MÊME retrait des jours fermés que buildForOverlay, via
        // l'ÉTAT EFFECTIF de la MAISON UNIQUE (incident × masque manuel) : sans lui, un verrou
        // posé un jour effectivement fermé passerait le garde-fou et la séance serait perdue
        // en silence — ce que ce garde existe pour empêcher (revue #8).
        $state = $this->planVenueClosures->effectiveStateForPlan((string) $schedulePlanId);
        $closedWeekdaysByVenue = $state['effectiveClosedWeekdaysByVenue'];
        // De quoi NOMMER la cause quand un épinglage tombe sur un jour fermé par une
        // INDISPONIBILITÉ déclarée — bornée aux jours EFFECTIVEMENT fermés : un jour rouvert
        // OPEN par le gestionnaire n'est plus une cause (l'incident est désormais informatif).
        $closureByVenueDay = [];
        foreach ($state['summaries'] as $summary) {
            foreach ($summary['weekdays'] as $weekday) {
                if (isset($closedWeekdaysByVenue[$summary['venueId']][$weekday])) {
                    $closureByVenueDay[$summary['venueId']][$weekday] ??= $summary;
                }
            }
        }
        // Un gymnase DÉSACTIVÉ ne sert pas : ses créneaux existent encore en base (le mode
        // conserve la grille) mais buildForOverlay les retire du payload, avec les épinglages
        // qui s'y trouvent — les compter disponibles ici laisserait passer un verrou que le
        // solveur ne verrait jamais (revue #8, round 4).
        //
        // P2-37 D4 — un gymnase EFFECTIVEMENT fermé-total (aucun jour servi après composition)
        // rejoint le même skip : l'épinglage y est inerte (nulle part où aller), NON bloquant —
        // même doctrine que P3-20. La fermeture PARTIELLE (un jour d'un gymnase par ailleurs
        // ouvert), elle, reste bloquante plus bas : là, la séance SERAIT replacée en silence.
        $disabledVenueIds = $state['disabledVenueIds'] + $state['fullyClosedVenueIds'];
        $available = [];
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $slot) {
            if (isset($disabledVenueIds[$slot->getVenueId()]) || isset($closedWeekdaysByVenue[$slot->getVenueId()][$slot->getDayOfWeek()])) {
                continue;
            }
            $available[$this->key($slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = true;
        }
        // P3-20 (décision fondateur 2026-08-06) — un épinglage sur un gymnase DÉSACTIVÉ
        // ne bloque PLUS. Le mode conserve tout (grille, réservations, verrous) pour que
        // réactiver rende la saisie ; l'écran les masque ; et le solveur ne les verra
        // jamais puisque `buildForOverlay` retire le gymnase du payload. Refuser la
        // génération ici enfermait donc le gestionnaire sur un épinglage devenu
        // INVISIBLE, nommant un gymnase que l'écran ne montre plus — le geste de
        // désactivation fabriquait lui-même l'impasse (P3-20, revue #342).
        //
        // ⚠ La doctrine « on ne fait pas de chose magique » n'est pas relâchée : rien
        // n'est déplacé en silence (le gymnase ne sert pas du tout), et le récap
        // continue d'annoncer le surplus de réservations d'une équipe. Les AUTRES causes
        // d'orphelin — grille vidée, jour de fermeture — bloquent toujours : là, une
        // séance SERAIT déplacée ailleurs sans le dire.

        // Verrous HARD du work-loop de CETTE version. Les placements SOFT/NONE sont des
        // RÉSULTATS de solve, pas des épinglages : les refuser bloquerait toute
        // régénération après un simple changement de grille.
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()]) as $lock) {
            if (LockLevel::HARD !== $lock->getLockLevel()) {
                continue;
            }
            if (isset($disabledVenueIds[$lock->getVenueId()])) {
                continue;
            }
            if (!isset($available[$this->key($lock->getVenueId(), $lock->getDayOfWeek(), $lock->getStartTime()->format('H:i'))])) {
                return $this->message($lock->getVenueId(), $lock->getDayOfWeek(), $lock->getTeamId(), $closureByVenueDay[$lock->getVenueId()][$lock->getDayOfWeek()] ?? null);
            }
        }

        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $reservation) {
            if (isset($disabledVenueIds[$reservation->getVenueId()])) {
                continue;
            }
            if (!isset($available[$this->key($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'))])) {
                // Lot 4bis — un créneau LIBRE (teamId null) est épinglé lui aussi : on nomme la cause
                // par son libellé plutôt que par une équipe (le label est du texte club, pas un id).
                $subject = null === $reservation->getTeamId() ? ($reservation->getLabel() ?? 'un créneau réservé') : null;

                return $this->message($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getTeamId(), $closureByVenueDay[$reservation->getVenueId()][$reservation->getDayOfWeek()] ?? null, $subject);
            }
        }

        return null;
    }

    private function key(string $venueId, int $dayOfWeek, string $startTime): string
    {
        return $venueId . '|' . $dayOfWeek . '|' . $startTime;
    }

    /**
     * P2-37 D4 — le message NOMME l'équipe épinglée (le `teamId` est déjà porté par le
     * verrou/la réservation ; un `find(Team)` suffit). Aucun identifiant interne dans un texte
     * lu par un humain (`PublicTextIsFreeOfInternalIdentifiersTest`) : on résout le NOM, ou un
     * repli neutre si l'équipe est introuvable.
     *
     * @param array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}|null $closure la fermeture qui cause l'orphelin, si c'en est une
     */
    private function message(string $venueId, int $dayOfWeek, ?string $teamId, ?array $closure = null, ?string $subjectLabel = null): string
    {
        $venue = $this->entityManager->getRepository(Venue::class)->find($venueId);
        $venueName = $venue?->getName() ?? 'ce gymnase';
        // Lot 4bis — un créneau LIBRE porte un libellé, pas une équipe : on nomme le libellé (jamais
        // `find(null)`). Sinon, le NOM de l'équipe épinglée (jamais son id).
        $team = null !== $teamId ? $this->entityManager->getRepository(Team::class)->find($teamId) : null;
        $teamName = $team?->getName() ?? $subjectLabel ?? 'une équipe';
        $days = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $dayLabel = $days[$dayOfWeek] ?? 'jour ' . $dayOfWeek;

        // Cause FERMETURE : la nommer, avec bornes et titre. Le gestionnaire n'a rien à
        // redéfinir — c'est la fermeture qu'il gère, ou l'épinglage qu'il retire.
        if (null !== $closure) {
            return \sprintf(
                'Le gymnase %s est fermé du %s au %s — %s : une séance de %s le %s y est épinglée sans créneau disponible. Retirez l’épinglage, ou ajustez la fermeture.',
                $venueName,
                $this->humanDate($closure['startDate']),
                $this->humanDate($closure['endDate']),
                $closure['title'],
                $teamName,
                $dayLabel,
            );
        }

        return \sprintf(
            'Les créneaux du %s à %s ne sont plus disponibles en l’état : une séance de %s y est épinglée sans créneau correspondant. Redéfinissez les créneaux de ce gymnase pour la période, ou retirez l’épinglage.',
            $dayLabel,
            $venueName,
            $teamName,
        );
    }

    /** Date `Y-m-d` en `j/n` humain (« 1/5 »), sans zéro de tête. */
    private function humanDate(string $isoDate): string
    {
        return new DateTimeImmutable($isoDate)->format('j/n');
    }
}
