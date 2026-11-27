<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Competition;
use App\Entity\FederalTracking;
use App\Entity\Fixture;
use App\Enum\CompetitionSource;
use App\Enum\FederalState;
use App\Enum\FixtureStatus;
use App\Enum\PlacementStatus;
use App\EventListener\KeptVenueLabelListener;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Contracts\Service\ResetInterface;

/**
 * La seule porte vers « où en est ce match » : son stade (à placer, placé, saisi, validé), son état dans FBI, ses
 * écarts avec la source, le mémo « FBI affiche encore … » et le libellé de salle gardé. Tout lecteur et tout
 * écrivain passe par elle. Le placement vit sur le match ({@see Fixture::getPlacement}), le suivi fédéral dans
 * `fixture_federal_tracking` ({@see FederalTracking}).
 *
 * Un match est suivi si sa compétition est de source FBI ({@see isTracked}). Le stade se calcule : validé si son
 * suivi est « validé », saisi si son suivi est « saisi », sinon son placement. Un match suivi sans ligne de suivi
 * est « à saisir » : la ligne naît à la première écriture qui en a besoin. Un match non suivi s'arrête à « Placé » :
 * « saisi » et « validé » ne s'y écrivent pas ({@see markSubmitted}, {@see markValidated} n'y font rien).
 *
 * Effets d'une écriture de stade, ici seulement : un stade placé (placé, saisi, validé) traite la rencontre — sauf
 * écart pendant —, « saisi » ou « validé » efface le mémo FBI, placer ou dé-placer remet le suivi à « à saisir ».
 * L'horloge vient de l'appelant (horloge simulée du mode démo).
 *
 * Les lignes absentes déjà cherchées sont retenues pour la requête (ou le message) en cours, et oubliées quand le
 * gestionnaire d'entités est vidé ; `prime` les charge en une lecture pour une liste de matchs.
 *
 * @phpstan-import-type FbiEcho from Fixture
 */
#[AsDoctrineListener(event: Events::onClear)]
final class FederalFollowUp implements ResetInterface
{
    /** @var array<string, true> matchs sans ligne de suivi, déjà cherchés */
    private array $absent = [];

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function reset(): void
    {
        $this->absent = [];
    }

    /** Le gestionnaire d'entités est vidé : ce qui a été lu peut avoir changé en base. */
    public function onClear(): void
    {
        $this->reset();
    }

    /**
     * Charge en une lecture les lignes de suivi de ces matchs (lecteurs de listes : API, demande au moteur).
     *
     * @param iterable<Fixture> $fixtures
     */
    public function prime(iterable $fixtures): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $ids = [];
        foreach ($fixtures as $fixture) {
            $id = $fixture->getId();
            if (!isset($this->absent[$id]) && false === $unitOfWork->tryGetById($id, FederalTracking::class)) {
                $ids[$id] = true;
            }
        }
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            foreach ($this->entityManager->getRepository(FederalTracking::class)->findBy(['fixtureId' => $chunk]) as $tracking) {
                unset($ids[$tracking->getFixtureId()]);
            }
        }
        $this->absent += $ids;
    }

    /** {@see prime} pour tous les matchs déjà chargés par le gestionnaire d'entités (une page de l'API, une liste). */
    public function primeLoaded(): void
    {
        /** @var array<string, Fixture> $loaded */
        $loaded = $this->entityManager->getUnitOfWork()->getIdentityMap()[Fixture::class] ?? [];
        $this->prime($loaded);
    }

    /** Suivi par la fédération : la compétition du match est de source FBI. */
    public function isTracked(Fixture $fixture): bool
    {
        $competitionId = $fixture->getCompetitionId();
        if (null === $competitionId) {
            return false;
        }
        $competition = $this->entityManager->find(Competition::class, $competitionId);

        return $competition instanceof Competition && CompetitionSource::FBI === $competition->getSource();
    }

    public function stageOf(Fixture $fixture): FixtureStatus
    {
        return match ($this->stateOf($fixture)) {
            FederalState::SUBMITTED => FixtureStatus::SUBMITTED,
            FederalState::VALIDATED => FixtureStatus::VALIDATED,
            FederalState::TO_ENTER, null => PlacementStatus::PLACED === $fixture->getPlacement() ? FixtureStatus::PLACED : FixtureStatus::UNPLACED,
        };
    }

    /** L'état du match dans FBI ; null pour un match que la fédération ne suit pas. */
    public function stateOf(Fixture $fixture): ?FederalState
    {
        if (!$this->isTracked($fixture)) {
            return null;
        }

        return $this->trackingOf($fixture)?->getState() ?? FederalState::TO_ENTER;
    }

    /** Saisi ou validé dans FBI. */
    public function isEntered(Fixture $fixture): bool
    {
        $state = $this->stateOf($fixture);

        return FederalState::SUBMITTED === $state || FederalState::VALIDATED === $state;
    }

    /** Placé et suivi, pas encore saisi : à copier dans FBI. */
    public function isToEnter(Fixture $fixture): bool
    {
        return FixtureStatus::PLACED === $this->stageOf($fixture) && $this->isTracked($fixture);
    }

    public function place(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setPlacement(PlacementStatus::PLACED);
        $this->trackingOf($fixture)?->setState(FederalState::TO_ENTER);
        $this->reviewUnlessPending($fixture, $now);
    }

    /** Dé-placer remet le suivi à « à saisir ». */
    public function unplace(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setPlacement(PlacementStatus::UNPLACED);
        $this->trackingOf($fixture)?->setState(FederalState::TO_ENTER);
    }

    /** Sans effet sur un match non suivi : il s'arrête à « Placé ». */
    public function markSubmitted(Fixture $fixture, DateTimeImmutable $now): void
    {
        $this->enter($fixture, FederalState::SUBMITTED, $now);
    }

    /** Sans effet sur un match non suivi : il s'arrête à « Placé ». */
    public function markValidated(Fixture $fixture, DateTimeImmutable $now): void
    {
        $this->enter($fixture, FederalState::VALIDATED, $now);
    }

    /** Un match saisi ou validé redevient « placé », à saisir dans FBI. */
    public function reopen(Fixture $fixture, DateTimeImmutable $now): void
    {
        $this->place($fixture, $now);
    }

    /** Le stade demandé par un geste du gestionnaire (formulaire de match). */
    public function moveTo(Fixture $fixture, FixtureStatus $stage, DateTimeImmutable $now): void
    {
        match ($stage) {
            FixtureStatus::UNPLACED => $this->unplace($fixture, $now),
            FixtureStatus::PLACED => $this->isEntered($fixture) ? $this->reopen($fixture, $now) : $this->place($fixture, $now),
            FixtureStatus::SUBMITTED => $this->markSubmitted($fixture, $now),
            FixtureStatus::VALIDATED => $this->markValidated($fixture, $now),
        };
    }

    public function deviations(Fixture $fixture): FederalDeviations
    {
        return new FederalDeviations($this, $fixture);
    }

    /** @return FbiEcho|null */
    public function fbiEchoOf(Fixture $fixture): ?array
    {
        return $this->trackingOf($fixture)?->getFbiEcho();
    }

    public function clearFbiEcho(Fixture $fixture): void
    {
        $this->trackingOf($fixture)?->setFbiEcho(null);
    }

    /** Le libellé de salle gardé par « Garder l'appli » sur un domicile non placé, normalisé. */
    public function keptVenueLabelOf(Fixture $fixture): ?string
    {
        return $this->trackingOf($fixture)?->getKeptVenueLabel();
    }

    public function keepVenueLabel(Fixture $fixture, ?string $normalizedLabel): void
    {
        if (null === $normalizedLabel) {
            $this->trackingOf($fixture)?->setKeptVenueLabel(null);

            return;
        }
        $this->trackingFor($fixture)->setKeptVenueLabel($normalizedLabel);
    }

    /**
     * La ligne de suivi du match, si elle existe (écritures en attente comprises). Réservé à la porte et à
     * {@see FederalDeviations}, {@see KeptVenueLabelListener}.
     *
     * @internal
     */
    public function trackingOf(Fixture $fixture): ?FederalTracking
    {
        $id = $fixture->getId();
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $managed = $unitOfWork->tryGetById($id, FederalTracking::class);
        if ($managed instanceof FederalTracking) {
            return $unitOfWork->isScheduledForDelete($managed) ? null : $managed;
        }
        if (isset($this->absent[$id]) || UnitOfWork::STATE_NEW === $unitOfWork->getEntityState($fixture)) {
            return null;
        }
        $found = $this->entityManager->find(FederalTracking::class, $id);
        if (!$found instanceof FederalTracking) {
            $this->absent[$id] = true;
        }

        return $found;
    }

    /**
     * La ligne de suivi du match, créée (persistée, pas flushée) si elle manque. Le match est persisté avant elle :
     * la clé `(fixture_id, club_id)` exige qu'il soit écrit d'abord.
     *
     * @internal
     */
    public function trackingFor(Fixture $fixture): FederalTracking
    {
        $tracking = $this->trackingOf($fixture);
        if ($tracking instanceof FederalTracking) {
            return $tracking;
        }
        if (!$this->entityManager->contains($fixture)) {
            $this->entityManager->persist($fixture);
        }
        $tracking = new FederalTracking($fixture->getId(), $fixture->getClubId(), $fixture->getSeasonId());
        $this->entityManager->persist($tracking);
        unset($this->absent[$fixture->getId()]);

        return $tracking;
    }

    private function enter(Fixture $fixture, FederalState $state, DateTimeImmutable $now): void
    {
        if (!$this->isTracked($fixture)) {
            return;
        }
        $this->trackingFor($fixture)->setState($state);
        $this->reviewUnlessPending($fixture, $now);
    }

    /** « Placer = traiter » (D6) : REVIEWED + horodaté, sauf écart pendant (la rencontre reste déphasée). */
    private function reviewUnlessPending(Fixture $fixture, DateTimeImmutable $now): void
    {
        if (!$this->deviations($fixture)->any()) {
            $fixture->markReviewed($now);
        }
    }
}
