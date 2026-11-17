<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use RuntimeException;

/**
 * La MAISON UNIQUE du geste « poser une occupation » dans les scénarios : une occupation nomme un
 * moment où le gymnase n'est pas au club (« École », mardi 14:00-16:15). Les contextes la posent
 * par ce trait, jamais par une copie de code ni par SQL : le geste passe par l'API, comme le
 * gestionnaire depuis l'écran Gymnases (`POST /api/venue_occupancies`).
 *
 * Chaque occupation posée est mémorisée ; le context qui `use` ce trait appelle
 * `retireOccupationsPosees()` depuis son `#[AfterScenario]`, que le scénario ait réussi ou non
 * (le club de démonstration est partagé entre les features). Les copies de période partent avec
 * leur plan (clé étrangère en cascade), donc avec l'entrée de calendrier que le context retire.
 *
 * @phpstan-require-extends BaseContext
 */
trait PosesVenueOccupancyTrait
{
    /** @var list<string> identifiants des occupations posées par le scénario */
    private array $occupationsPosees = [];

    /**
     * Pose une occupation (saison si `$schedulePlanId` est nul, sinon dans la grille de ce plan de
     * période) et mémorise son identifiant pour le nettoyage.
     *
     * @return array{status: int, json: array<mixed>}
     */
    private function poseOccupation(string $token, string $venueId, int $dayOfWeek, string $startTime, int $durationMinutes, string $label, ?string $schedulePlanId = null): array
    {
        $body = [
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'durationMinutes' => $durationMinutes,
            'label' => $label,
        ];
        if (null !== $schedulePlanId) {
            $body['schedulePlanId'] = $schedulePlanId;
        }

        $posed = $this->apiPost('venue_occupancies', $body, $token);
        $id = $posed['json']['id'] ?? null;
        if (\in_array($posed['status'], [200, 201], true) && \is_string($id) && '' !== $id) {
            $this->occupationsPosees[] = $id;
        }

        return $posed;
    }

    /** Retire les occupations posées par le scénario — tolérant : une occupation déjà partie (404) n'est pas une erreur. */
    private function retireOccupationsPosees(string $token): void
    {
        if ('' === $token) {
            return;
        }
        foreach ($this->occupationsPosees as $id) {
            $this->apiDelete(\sprintf('venue_occupancies/%s', $id), $token);
        }
        $this->occupationsPosees = [];
    }

    /**
     * Les occupations listées par l'API pour un gymnase, dans la couche demandée (saison si
     * `$schedulePlanId` est nul), chacune ramenée à `{id, dayOfWeek, startTime "HH:MM", durationMinutes, label}`.
     *
     * @return list<array{id: string, dayOfWeek: int, startTime: string, durationMinutes: int, label: string}>
     */
    private function occupationsListees(string $token, string $venueId, ?string $schedulePlanId = null): array
    {
        $query = 'venueId=' . rawurlencode($venueId) . (null === $schedulePlanId ? '' : '&schedulePlanId=' . rawurlencode($schedulePlanId));
        $rows = [];
        foreach ($this->toutesLesPages('venue_occupancies?' . $query, $token, 'liste des occupations') as $item) {
            $rows[] = [
                'id' => \is_string($item['id'] ?? null) ? $item['id'] : '',
                'dayOfWeek' => \is_int($item['dayOfWeek'] ?? null) ? $item['dayOfWeek'] : 0,
                'startTime' => $this->heureMurale($item['startTime'] ?? null),
                'durationMinutes' => \is_int($item['durationMinutes'] ?? null) ? $item['durationMinutes'] : 0,
                'label' => \is_string($item['label'] ?? null) ? $item['label'] : '',
            ];
        }

        return $rows;
    }

    /**
     * Une plage libre de `$durationMinutes` minutes dans la grille d'un gymnase (créneaux ET
     * occupations de la couche demandée), cherchée jour par jour dans l'ordre de `$days`, de 08:00
     * à 22:00 par pas de 15 minutes. `$alsoBusy` ajoute des intervalles occupés par ailleurs
     * (séances placées d'une version), sous la forme `[jour, début en minutes, fin en minutes]`.
     *
     * @param list<int>                  $days
     * @param list<array{int, int, int}> $alsoBusy
     *
     * @return array{day: int, start: string}
     */
    private function plageLibre(string $token, string $venueId, int $durationMinutes, array $days, ?string $schedulePlanId = null, array $alsoBusy = []): array
    {
        $busy = $alsoBusy;
        $query = 'venueId=' . rawurlencode($venueId) . (null === $schedulePlanId ? '' : '&schedulePlanId=' . rawurlencode($schedulePlanId));
        foreach ($this->toutesLesPages('venue_training_slots?' . $query, $token, 'liste des créneaux') as $slot) {
            $start = $this->minutesDe($this->heureMurale($slot['startTime'] ?? null));
            $busy[] = [(int) ($slot['dayOfWeek'] ?? 0), $start, $start + (int) ($slot['durationMinutes'] ?? 0)];
        }
        foreach ($this->occupationsListees($token, $venueId, $schedulePlanId) as $occupation) {
            $start = $this->minutesDe($occupation['startTime']);
            $busy[] = [$occupation['dayOfWeek'], $start, $start + $occupation['durationMinutes']];
        }

        foreach ($days as $day) {
            for ($start = 8 * 60; $start + $durationMinutes <= 22 * 60; $start += 15) {
                $free = true;
                foreach ($busy as [$busyDay, $busyStart, $busyEnd]) {
                    if ($busyDay === $day && $start < $busyEnd && $busyStart < $start + $durationMinutes) {
                        $free = false;

                        break;
                    }
                }
                if ($free) {
                    return ['day' => $day, 'start' => \sprintf('%02d:%02d', intdiv($start, 60), $start % 60)];
                }
            }
        }

        throw new RuntimeException(\sprintf('aucune plage libre de %d minutes dans la grille du gymnase %s — décor non tenu', $durationMinutes, $venueId));
    }

    /**
     * Le message d'un refus 422, tel que l'écran le lit (`violations[0].message`, sinon `detail`).
     *
     * @param array<mixed> $json
     */
    private function messageDuRefus(array $json): string
    {
        $violations = $json['violations'] ?? null;
        if (\is_array($violations) && \is_array($violations[0] ?? null) && \is_string($violations[0]['message'] ?? null)) {
            return $violations[0]['message'];
        }

        return \is_string($json['detail'] ?? null) ? $json['detail'] : '';
    }

    /** « 14:00 » depuis « 14:00 », « 14:00:00 » ou un horodatage « 1970-01-01T14:00:00+00:00 ». */
    private function heureMurale(mixed $value): string
    {
        if (!\is_string($value)) {
            return '';
        }
        $time = str_contains($value, 'T') ? substr($value, (int) strpos($value, 'T') + 1) : $value;

        return 1 === preg_match('/^(\d{2}):(\d{2})/', $time, $m) ? $m[1] . ':' . $m[2] : '';
    }

    private function minutesDe(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm . ':0'));

        return $h * 60 + $m;
    }

    /**
     * Toutes les lignes d'une collection paginée de l'API (page par page jusqu'à une page vide ou incomplète).
     *
     * @return list<array<string, mixed>>
     */
    private function toutesLesPages(string $path, string $token, string $what): array
    {
        $rows = [];
        for ($page = 1; $page <= 50; ++$page) {
            $response = $this->apiGet($path . '&page=' . $page, $token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('%s : HTTP %d (200 attendu)', $what, $response['status']));
            }
            $members = $response['json']['member'] ?? $response['json'];
            $members = array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
            foreach ($members as $member) {
                $rows[] = $member;
            }
            $total = $response['json']['totalItems'] ?? null;
            if ([] === $members || (\is_int($total) && \count($rows) >= $total)) {
                break;
            }
        }

        return $rows;
    }
}
