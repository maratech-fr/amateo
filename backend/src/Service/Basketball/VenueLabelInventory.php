<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Fixture;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lecture agrégée « libellé de salle FBI/FFBB → gymnase » pour une saison
 * (E1) : les domiciles importés portent un libellé fédéral libre
 * ({@see Fixture::getFbiVenueLabel()}) mais souvent pas de gymnase, et un même
 * gymnase peut être nommé par plusieurs graphies. On regroupe par clé
 * NORMALISÉE ({@see VenueLabelNormalizer}) pour donner au gestionnaire, ligne
 * par ligne, ce qu'il faut pour rattacher (ou ré-affecter) : le gymnase déjà
 * confirmé pour l'alias, une suggestion tirée des placements réels, et les
 * compteurs (combien de domiciles, placés / à placer).
 *
 * Service pur, testable seul : il ne fait que lire (club + saison scopés par
 * les filtres Doctrine + la borne saison explicite, défense en profondeur comme
 * {@see App\Controller\VenueExternalLabelController}). Jamais d'écriture.
 */
final readonly class VenueLabelInventory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * @return list<array{labelKey: string, displayLabel: string, venueId: string|null, suggestedVenueId: string|null, homeCount: int, placedCount: int, unplacedCount: int}>
     */
    public function forSeason(string $seasonId): array
    {
        /** @var list<Fixture> $homes */
        $homes = $this->entityManager->getRepository(Fixture::class)->findBy([
            'homeAway' => FixtureHomeAway::HOME,
            'seasonId' => $seasonId,
        ]);
        /** @var list<Venue> $venues */
        $venues = $this->entityManager->getRepository(Venue::class)->findBy(['seasonId' => $seasonId]);

        // Clé normalisée → gymnase portant cet alias CONFIRMÉ. Les alias sont
        // stockés déjà normalisés ; on re-normalise par sécurité (idempotent) pour
        // qu'une graphie historique non normalisée ne casse pas l'appariement.
        $confirmedByKey = [];
        foreach ($venues as $venue) {
            foreach ($venue->getExternalLabels() as $alias) {
                $confirmedByKey[$this->labelNormalizer->normalize($alias)] = $venue->getId();
            }
        }

        /** @var array<string, array{displayCounts: array<string, int>, homeCount: int, placedCount: int, unplacedCount: int, venueIds: array<string, true>}> $groups */
        $groups = [];
        foreach ($homes as $home) {
            $raw = $home->getFbiVenueLabel();
            if (null === $raw) {
                continue;
            }
            $key = $this->labelNormalizer->normalize($raw);
            if ('' === $key) {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = ['displayCounts' => [], 'homeCount' => 0, 'placedCount' => 0, 'unplacedCount' => 0, 'venueIds' => []];
            }
            $groups[$key]['displayCounts'][$raw] = ($groups[$key]['displayCounts'][$raw] ?? 0) + 1;
            ++$groups[$key]['homeCount'];
            if (FixtureStatus::UNPLACED === $home->getStatus()) {
                ++$groups[$key]['unplacedCount'];
            } else {
                ++$groups[$key]['placedCount'];
            }
            $venueId = $home->getVenueId();
            if (null !== $venueId) {
                $groups[$key]['venueIds'][$venueId] = true;
            }
        }

        $rows = [];
        foreach ($groups as $key => $group) {
            // La graphie brute la PLUS fréquente ; à égalité, la première rencontrée
            // (arsort est stable depuis PHP 8.0, l'ordre d'insertion départage).
            $displayCounts = $group['displayCounts'];
            arsort($displayCounts);
            $displayLabel = (string) array_key_first($displayCounts);

            $venueId = $confirmedByKey[$key] ?? null;

            // Suggestion : le gymnase UNANIME des domiciles qui en portent un, sinon
            // null (aucun, ou plusieurs → jamais un pari) ; null aussi s'il égale le
            // gymnase déjà confirmé (rien à suggérer, c'est déjà posé).
            $distinctVenueIds = array_keys($group['venueIds']);
            $suggestedVenueId = 1 === \count($distinctVenueIds) ? $distinctVenueIds[0] : null;
            if (null !== $suggestedVenueId && $suggestedVenueId === $venueId) {
                $suggestedVenueId = null;
            }

            $rows[] = [
                'labelKey' => $key,
                'displayLabel' => $displayLabel,
                'venueId' => $venueId,
                'suggestedVenueId' => $suggestedVenueId,
                'homeCount' => $group['homeCount'],
                'placedCount' => $group['placedCount'],
                'unplacedCount' => $group['unplacedCount'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['displayLabel'], $b['displayLabel']));

        return $rows;
    }
}
