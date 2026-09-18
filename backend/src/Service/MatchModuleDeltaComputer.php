<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le calcul du DELTA « depuis ta dernière visite » du module matchs (RMM-3), tenu
 * SÉPARÉ de la rotation de la référence. Le gardien (RMM-3) rotationne (le
 * contrôleur stampe une nouvelle référence quand la visite est neuve) ; l'escalade
 * future (RMM-6) devra LIRE le delta SANS stamper — d'où la coupure : ce service
 * ne fait que comparer un état courant à une référence donnée, il n'écrit rien.
 *
 * Trois signaux, tous falsifiables dans les deux sens (MatchVisitDeltaParityTest) :
 *  - newFixturesCount : les Fixture nées APRÈS la référence (createdAt > takenAt) —
 *    filtres club+saison automatiques ;
 *  - newConflictFingerprints : les empreintes COURANTES absentes du snapshot (un
 *    conflit DISPARU ne produit rien — on ne signale que du neuf) ;
 *  - planningChanged : la version choisie OU la dernière COMPLETED du plan SEASON
 *    diffère du snapshot — comparaison d'IDS, jamais d'updatedAt.
 *
 * Le radar courant est chargé par la MAISON UNIQUE {@see ConflictRadarLoader}, la
 * même que {@see FixtureConflictsController} — mêmes repositories sous les mêmes
 * filtres, même enveloppe ligue, MÊMES profils de durée + trajets aller-retour
 * (BCK-23 : ces deux dernières entrées manquaient ici, un conflit né du seul trajet
 * adverse échappait donc au delta). L'empreinte d'un conflit est identique des deux
 * côtés, garanti par la maison unique {@see ConflictFingerprinter}.
 */
final class MatchModuleDeltaComputer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConflictFingerprinter $fingerprinter,
        private readonly ConflictRadarLoader $radarLoader,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * L'état COURANT, figé en instantané de référence (première visite, ou rotation
     * d'une visite neuve). Pas de comparaison ici — juste la photo.
     *
     * @return array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null}
     */
    public function currentSnapshot(string $clubId, ?string $seasonId): array
    {
        return [
            'fingerprints' => $this->currentFingerprints($clubId, $seasonId),
            'chosenScheduleId' => $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId),
            'latestCompletedSeasonScheduleId' => $this->schedulePlanProvisioner->latestCompletedScheduleIdOfSeasonPlan($seasonId),
        ];
    }

    /**
     * Le delta de l'état courant CONTRE une référence — sans jamais la toucher.
     *
     * @param array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null} $reference
     *
     * @return array{newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool, currentSnapshot: array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null}}
     */
    public function computeDelta(string $clubId, ?string $seasonId, array $reference, DateTimeImmutable $referenceTakenAt): array
    {
        $current = $this->currentSnapshot($clubId, $seasonId);

        $known = array_flip($reference['fingerprints']);
        $newFingerprints = array_values(array_filter(
            $current['fingerprints'],
            static fn (string $fp): bool => !isset($known[$fp]),
        ));

        $planningChanged = $current['chosenScheduleId'] !== $reference['chosenScheduleId']
            || $current['latestCompletedSeasonScheduleId'] !== $reference['latestCompletedSeasonScheduleId'];

        return [
            'newFixturesCount' => $this->newFixturesCount($referenceTakenAt),
            'newConflictFingerprints' => $newFingerprints,
            'planningChanged' => $planningChanged,
            'currentSnapshot' => $current,
        ];
    }

    /**
     * Les empreintes DÉDUPLIQUÉES des conflits courants. Un même litige ne compte
     * qu'une fois même si le détecteur l'émet deux fois (rare, mais l'ensemble est
     * une comparaison d'appartenance, pas un multiset).
     *
     * @return list<string>
     */
    private function currentFingerprints(string $clubId, ?string $seasonId): array
    {
        $seen = [];
        foreach ($this->currentConflicts($clubId, $seasonId) as $conflict) {
            $seen[$this->fingerprinter->fingerprint($conflict)] = true;
        }

        return array_keys($seen);
    }

    /**
     * Le radar courant, chargé par la MAISON UNIQUE partagée avec le contrôleur
     * ({@see ConflictRadarLoader}) — donc profils de durée et trajets aller-retour
     * INCLUS, à l'identique du feed servi (BCK-23).
     *
     * @return list<array<string, mixed>>
     */
    private function currentConflicts(string $clubId, ?string $seasonId): array
    {
        return $this->radarLoader->conflicts($clubId, $seasonId)['conflicts'];
    }

    /** Les Fixture nées après la référence — filtres club+saison automatiques. */
    private function newFixturesCount(DateTimeImmutable $referenceTakenAt): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(Fixture::class, 'f')
            ->where('f.createdAt > :ref')
            ->setParameter('ref', $referenceTakenAt)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
