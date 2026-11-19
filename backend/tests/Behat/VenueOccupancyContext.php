<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Une occupation nomme un moment où le gymnase n'est pas au club : le gestionnaire la pose, la
 * voit listée, ne peut ni poser un créneau dessus ni la poser sur un créneau, pose un créneau dont
 * les bornes touchent les siennes, la déplace, la supprime — de bout en bout sur la stack qui
 * tourne, sur le petit club réaliste bâti par l'API ({@see SmallClubFixtureTrait}), SANS génération
 * (l'occupation n'a aucun effet sur le planning : N1 `OccupancyStaysOutOfTheSolverTest` le prouve).
 *
 * Décor : les créneaux du petit club occupent lun→jeu 18:00-22:30 de chaque gymnase ; le mardi
 * après-midi et le vendredi sont libres. Les cas nombreux (matrice de chevauchement, motif, minuit,
 * couches) vivent dans `Integration/Api/VenueOccupancyApiTest` ; ce scénario prouve la promesse.
 */
final class VenueOccupancyContext extends BaseContext
{
    use PosesVenueOccupancyTrait;
    use SmallClubFixtureTrait;

    private const array DAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];

    private string $venueId = '';

    private string $occupancyId = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $lastResponse = ['status' => 0, 'json' => []];

    #[Given('un petit club réaliste bâti par l\'API, sans génération')]
    public function unPetitClubSansGeneration(): void
    {
        $this->buildSmallClub();
        $this->venueId = $this->smallClubVenueIds['Gymnase Centre'] ?? '';
        if ('' === $this->venueId) {
            throw new RuntimeException('le petit club n\'a pas de « Gymnase Centre » — décor non tenu');
        }
    }

    #[When('/^je pose l\'occupation « (?P<label>[^»]+) » le (?P<jour>\w+) de (?P<debut>\d{2}:\d{2}) à (?P<fin>\d{2}:\d{2}) dans la grille de saison d\'un gymnase$/u')]
    public function jePoseLOccupation(string $label, string $jour, string $debut, string $fin): void
    {
        $this->lastResponse = $this->poseOccupation($this->smallClubToken, $this->venueId, $this->day($jour), $debut, $this->minutesBetween($debut, $fin), $label);
        if (201 !== $this->lastResponse['status']) {
            throw new RuntimeException(\sprintf('la pose de l\'occupation « %s » a répondu %d (201 attendu) — %s', $label, $this->lastResponse['status'], $this->messageDuRefus($this->lastResponse['json'])));
        }
        $id = $this->lastResponse['json']['id'] ?? null;
        $this->occupancyId = \is_string($id) ? $id : '';
    }

    #[Then('/^l\'occupation « (?P<label>[^»]+) » est listée pour ce gymnase le (?P<jour>\w+) de (?P<debut>\d{2}:\d{2}) à (?P<fin>\d{2}:\d{2})$/u')]
    public function lOccupationEstListee(string $label, string $jour, string $debut, string $fin): void
    {
        $listed = $this->occupationsListees($this->smallClubToken, $this->venueId);
        $expected = ['id' => $this->occupancyId, 'dayOfWeek' => $this->day($jour), 'startTime' => $debut, 'durationMinutes' => $this->minutesBetween($debut, $fin), 'label' => $label];
        if ([$expected] !== $listed) {
            throw new RuntimeException(\sprintf('la grille de saison du gymnase liste %s (attendu exactement %s)', json_encode($listed, \JSON_UNESCAPED_UNICODE), json_encode([$expected], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('/^je pose un créneau d\'entraînement le (?P<jour>\w+) à (?P<debut>\d{2}:\d{2}) dans ce gymnase$/u')]
    public function jePoseUnCreneau(string $jour, string $debut): void
    {
        $this->lastResponse = $this->apiPost('venue_training_slots', [
            'venueId' => $this->venueId,
            'dayOfWeek' => $this->day($jour),
            'startTime' => $debut,
            'durationMinutes' => 90,
            'capacity' => 1,
        ], $this->smallClubToken);
    }

    #[When('/^je pose une occupation sur le créneau d\'entraînement du (?P<jour>\w+) à (?P<debut>\d{2}:\d{2}) de ce gymnase$/u')]
    public function jePoseUneOccupationSurUnCreneau(string $jour, string $debut): void
    {
        // 18:30-19:30 : partage une heure avec le créneau 18:00-19:30 du décor, sans en toucher les bornes.
        $start = $this->minutesBetween('00:00', $debut) + 30;
        $this->lastResponse = $this->poseOccupation($this->smallClubToken, $this->venueId, $this->day($jour), \sprintf('%02d:%02d', intdiv($start, 60), $start % 60), 60, 'Mairie');
    }

    #[Then('/^le créneau est refusé : « (?P<message>.+) »$/u')]
    public function leCreneauEstRefuse(string $message): void
    {
        $this->assertRefused('le créneau', $message);
    }

    #[Then('/^l\'occupation est refusée : « (?P<message>.+) »$/u')]
    public function lOccupationEstRefusee(string $message): void
    {
        $this->assertRefused('l\'occupation', $message);
    }

    #[Then('le créneau est posé : ses bornes touchent celles de l\'occupation')]
    public function leCreneauEstPose(): void
    {
        if (201 !== $this->lastResponse['status']) {
            throw new RuntimeException(\sprintf('un créneau dont le début touche la fin de l\'occupation aurait dû être posé (201), obtenu %d — %s', $this->lastResponse['status'], $this->messageDuRefus($this->lastResponse['json'])));
        }
    }

    #[When('/^je déplace l\'occupation « (?P<label>[^»]+) » au (?P<jour>\w+) de (?P<debut>\d{2}:\d{2}) à (?P<fin>\d{2}:\d{2})$/u')]
    public function jeDeplaceLOccupation(string $label, string $jour, string $debut, string $fin): void
    {
        $this->lastResponse = $this->apiPut(\sprintf('venue_occupancies/%s', $this->occupancyId), [
            'venueId' => $this->venueId,
            'dayOfWeek' => $this->day($jour),
            'startTime' => $debut,
            'durationMinutes' => $this->minutesBetween($debut, $fin),
            'label' => $label,
        ], $this->smallClubToken);
        if (200 !== $this->lastResponse['status']) {
            throw new RuntimeException(\sprintf('le déplacement de l\'occupation a répondu %d (200 attendu) — %s', $this->lastResponse['status'], $this->messageDuRefus($this->lastResponse['json'])));
        }
    }

    #[When('/^je supprime l\'occupation « (?P<label>[^»]+) »$/u')]
    public function jeSupprimeLOccupation(string $label): void
    {
        $deleted = $this->apiDelete(\sprintf('venue_occupancies/%s', $this->occupancyId), $this->smallClubToken);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('la suppression de l\'occupation « %s » a répondu %d (204 attendu)', $label, $deleted['status']));
        }
    }

    #[Then('ce gymnase ne liste plus aucune occupation')]
    public function plusAucuneOccupation(): void
    {
        $listed = $this->occupationsListees($this->smallClubToken, $this->venueId);
        if ([] !== $listed) {
            throw new RuntimeException(\sprintf('le gymnase liste encore %s après la suppression', json_encode($listed, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->retireOccupationsPosees($this->smallClubToken);
        $this->teardownSmallClub();
    }

    private function assertRefused(string $what, string $message): void
    {
        if (422 !== $this->lastResponse['status']) {
            throw new RuntimeException(\sprintf('%s aurait dû être refusé (422), obtenu %d', $what, $this->lastResponse['status']));
        }
        $served = $this->messageDuRefus($this->lastResponse['json']);
        if ($served !== $message) {
            throw new RuntimeException(\sprintf('%s est refusé avec « %s » (attendu « %s »)', $what, $served, $message));
        }
    }

    private function day(string $jour): int
    {
        return self::DAYS[$jour] ?? throw new RuntimeException(\sprintf('jour inconnu « %s »', $jour));
    }

    private function minutesBetween(string $from, string $to): int
    {
        return $this->minutesDe($to) - $this->minutesDe($from);
    }
}
