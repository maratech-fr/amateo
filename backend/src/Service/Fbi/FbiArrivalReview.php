<?php

declare(strict_types=1);

namespace App\Service\Fbi;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Service\ClubDay;
use DateTimeImmutable;

/**
 * Arrivée & rattrapage de revue (P4-199) — le foyer UNIQUE qui décide si une
 * rencontre « naît/est rattrapée traitée », partagé par les deux canaux
 * (import xlsx {@see FbiFixtureImporter} + canal API
 * {@see App\Service\Basketball\FfbbRencontreReconciler}) et la commande de
 * rattrapage hors ligne ({@see App\Command\CatchUpFixtureReviewCommand}).
 * Extrait verbatim de {@see FbiFixtureImporter} (lot 5 architecture, BCK-19) —
 * l'importeur délègue, les signatures publiques consommées sont conservées.
 */
final class FbiArrivalReview
{
    public function __construct(
        private readonly ClubDay $clubDay,
    ) {}

    /**
     * Décision fondateur P4-199 — une rencontre naît DÉJÀ traitée (REVIEWED +
     * horodatée) dès sa création dans deux cas : (1) c'est un EXTÉRIEUR (le club ne
     * la place pas, rien à examiner) ; (2) sa date est passée OU tombe dans la
     * semaine ISO en cours (borne = dimanche de la semaine, fuseau du club) — une
     * rencontre déjà jouée ou imminente n'est pas « nouvelle ». Un domicile futur
     * hors de cette fenêtre reste NEW (à examiner et placer). Foyer unique appelé
     * aux deux canaux (import xlsx + canal API).
     */
    public function treatOnArrival(Fixture $fixture, DateTimeImmutable $now, Club $club): void
    {
        if ($this->qualifiesForArrivalTreatment($fixture, $this->currentIsoWeekEnd($club))) {
            $fixture->markReviewed($now);
        }
    }

    /**
     * D2 (rattrapage) — une rencontre EXISTANTE restée « à traiter » (NEW) d'un
     * dépôt antérieur à la naissance-traitée est rattrapée : traitée (REVIEWED +
     * horodatée) dès qu'elle remplit le MÊME prédicat qu'{@see treatOnArrival}
     * (extérieur, ou date ≤ dimanche de la semaine ISO en cours — fenêtre passée
     * par l'appelant, fuseau du club). Ne touche JAMAIS un OUT_OF_SYNC ni un
     * REVIEWED (leur état de traitement est déjà posé) ; idempotent. Le rattrapage
     * n'est PAS une donnée de match : il ne compte pas dans created/updated/
     * unchanged du rapport d'import. Foyer partagé aux deux canaux (re-dépôt xlsx
     * + passage API) et à la commande de rattrapage hors ligne.
     */
    public function catchUpReview(Fixture $existing, DateTimeImmutable $now, DateTimeImmutable $weekEnd): bool
    {
        if (FixtureReviewState::NEW !== $existing->getReviewState()
            || !$this->qualifiesForArrivalTreatment($existing, $weekEnd)) {
            return false;
        }
        $existing->markReviewed($now);

        return true;
    }

    /**
     * Le dimanche (borne haute, date civile) de la semaine ISO EN COURS du club. « Quel
     * jour est-on pour ce club ? » vient du foyer {@see ClubDay} (une génération à
     * 00:30 UTC un lundi est encore dimanche à Paris — c'est lui qui le sait). La
     * semaine ISO commence le lundi (jour 1) : dimanche = aujourd'hui + (7 − jour ISO).
     */
    public function currentIsoWeekEnd(Club $club): DateTimeImmutable
    {
        $today = $this->clubDay->todayFor($club);
        $isoWeekday = (int) $today->format('N');

        return $today->modify(\sprintf('+%d days', 7 - $isoWeekday));
    }

    /**
     * Décision fondateur P4-199 — sur un domicile PLACÉ déphasé, la source « fait
     * foi » (appliquée d'office, sans arbitrage) quand la date Amateo OU la date de
     * la source tombe dans la fenêtre (≤ dimanche de la semaine ISO en cours) :
     * « app OU source ». Un déphasage entièrement futur reste un arbitrage.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     */
    public function sourceIsAuthoritativeForWindow(Fixture $existing, array $row, DateTimeImmutable $weekEnd): bool
    {
        $end = $weekEnd->format('Y-m-d');

        return $existing->getMatchDate()->format('Y-m-d') <= $end
            || $row['matchDate']->format('Y-m-d') <= $end;
    }

    /**
     * Le prédicat « naît/est rattrapée traitée » PARTAGÉ par {@see treatOnArrival}
     * et {@see catchUpReview} (jamais recopié) : un EXTÉRIEUR (le club ne le place
     * pas, rien à examiner), ou une date ≤ dimanche de la semaine ISO en cours
     * (rencontre déjà jouée ou imminente). Un domicile futur hors fenêtre est faux.
     */
    private function qualifiesForArrivalTreatment(Fixture $fixture, DateTimeImmutable $weekEnd): bool
    {
        return FixtureHomeAway::AWAY === $fixture->getHomeAway()
            || $fixture->getMatchDate()->format('Y-m-d') <= $weekEnd->format('Y-m-d');
    }
}
