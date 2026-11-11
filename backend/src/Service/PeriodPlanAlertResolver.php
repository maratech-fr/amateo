<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SchedulePlanType;
use DateTimeImmutable;

/**
 * L'alerte d'un planning de période et sa mise en sourdine au radar, DÉRIVÉES des faits lus en lot
 * par {@see SchedulePlanProvisioner::periodPlanFacts} — pur, sans requête (le provider sert ces
 * champs pour une collection entière en une lecture). Le front affiche, il ne redérive rien.
 *
 *  - `autoValidated` (la marque « Validé automatiquement ») : pointé, auto-validé, jamais validé
 *    à la main depuis ;
 *  - alerte `auto_validated` : marque ∧ au moins une autre version terminée gardée ∧ période pas
 *    finie ∧ pas encore vue depuis la bascule ;
 *  - alerte `started_without_plan` : plan de période éligible (entrée active, sans enfant, jamais
 *    validé) ∧ jour dans la fenêtre ∧ aucune version terminée ni en vol ∧ pas vue depuis le début ;
 *  - `radarDismissed` : « J'ai vu » sur `started_without_plan` ∧ plan toujours ouvert ∧ jour dans
 *    la fenêtre — la période sort du radar jusqu'à sa fin.
 *
 * Dates comparées en `Y-m-d` CIVIL (celles de l'entrée de calendrier), jamais converties.
 */
final class PeriodPlanAlertResolver
{
    public const string KIND_AUTO_VALIDATED = 'auto_validated';

    public const string KIND_STARTED_WITHOUT_PLAN = 'started_without_plan';

    public const array KINDS = [self::KIND_AUTO_VALIDATED, self::KIND_STARTED_WITHOUT_PLAN];

    /**
     * @param array<string, array<string, mixed>> $facts par plan, forme de {@see SchedulePlanProvisioner::periodPlanFacts}
     *
     * @return array<string, array{autoValidated: bool, alert: array<string, mixed>|null, radarDismissed: bool}>
     */
    public function forPlans(array $facts, DateTimeImmutable $today): array
    {
        $day = $today->format('Y-m-d');
        $out = [];
        foreach ($facts as $planId => $plan) {
            $out[$planId] = $this->resolve($plan, $day);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array{autoValidated: bool, alert: array<string, mixed>|null, radarDismissed: bool}
     */
    private function resolve(array $plan, string $day): array
    {
        $type = $plan['type'] ?? null;
        $start = (string) ($plan['startDate'] ?? '');
        $end = (string) ($plan['endDate'] ?? '');
        $chosen = $plan['chosenScheduleId'] ?? null;
        $firstChosenAt = $plan['firstChosenAt'] ?? null;
        $autoValidatedDate = $plan['autoValidatedDate'] ?? null;
        $seenKind = $plan['alertSeenKind'] ?? null;
        $seenDate = $plan['alertSeenDate'] ?? null;

        $isPeriod = $type instanceof SchedulePlanType && SchedulePlanType::SEASON !== $type;
        $inWindow = $start <= $day && $day <= $end;
        $autoValidated = null !== $chosen && null !== $autoValidatedDate && null === $firstChosenAt;

        $alert = null;
        if ($autoValidated
            && (int) ($plan['keptVersionCount'] ?? 0) >= 1
            && $end >= $day
            && !(self::KIND_AUTO_VALIDATED === $seenKind && \is_string($seenDate) && $seenDate >= (string) $autoValidatedDate)
        ) {
            $alert = [
                'kind' => self::KIND_AUTO_VALIDATED,
                'chosenVersionNumber' => $plan['chosenVersionNumber'] ?? null,
                'keptVersionCount' => (int) $plan['keptVersionCount'],
            ];
        }

        $seenStarted = self::KIND_STARTED_WITHOUT_PLAN === $seenKind && \is_string($seenDate) && $seenDate >= $start;
        $eligible = $isPeriod
            && true === ($plan['entryActive'] ?? false)
            && true !== ($plan['hasChildren'] ?? false)
            && null === $chosen
            && null === $firstChosenAt
            && null === $autoValidatedDate;
        if (null === $alert
            && $eligible
            && $inWindow
            && 0 === (int) ($plan['completedCount'] ?? 0)
            && 0 === (int) ($plan['inFlightCount'] ?? 0)
            && !$seenStarted
        ) {
            $alert = ['kind' => self::KIND_STARTED_WITHOUT_PLAN];
        }

        return [
            'autoValidated' => $autoValidated,
            'alert' => $alert,
            'radarDismissed' => $isPeriod && $seenStarted && null === $chosen && $inWindow,
        ];
    }
}
