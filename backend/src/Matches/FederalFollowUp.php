<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Fixture;
use App\Enum\FederalState;
use App\Enum\FixtureStatus;
use DateTimeImmutable;

/**
 * La seule porte vers « où en est ce match » : son stade (à placer, placé, saisi, validé), son état
 * dans FBI, ses écarts avec la source, le mémo « FBI affiche encore … » et le libellé de salle gardé.
 * Tout lecteur et tout écrivain passe par elle ; elle lit et écrit encore les colonnes du match.
 *
 * Toute écriture du stade aboutit à `Fixture::setStatus`, qui reste la maison de ses effets : un
 * stade placé traite la rencontre (sauf écart pendant), « saisi » ou « validé » efface le mémo FBI.
 * L'horloge vient de l'appelant (horloge simulée du mode démo).
 *
 * @phpstan-import-type FbiEcho from Fixture
 */
final readonly class FederalFollowUp
{
    public function stageOf(Fixture $fixture): FixtureStatus
    {
        return $fixture->getStatus();
    }

    /** Aujourd'hui tout match est suivi : un match à placer ou placé est « à saisir ». */
    public function stateOf(Fixture $fixture): FederalState
    {
        return match ($fixture->getStatus()) {
            FixtureStatus::SUBMITTED => FederalState::SUBMITTED,
            FixtureStatus::VALIDATED => FederalState::VALIDATED,
            FixtureStatus::UNPLACED, FixtureStatus::PLACED => FederalState::TO_ENTER,
        };
    }

    /** Saisi ou validé dans FBI. */
    public function isEntered(Fixture $fixture): bool
    {
        $state = $this->stateOf($fixture);

        return FederalState::SUBMITTED === $state || FederalState::VALIDATED === $state;
    }

    public function place(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setStatus(FixtureStatus::PLACED, $now);
    }

    public function unplace(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setStatus(FixtureStatus::UNPLACED, $now);
    }

    public function markSubmitted(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setStatus(FixtureStatus::SUBMITTED, $now);
    }

    public function markValidated(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setStatus(FixtureStatus::VALIDATED, $now);
    }

    /** Un match saisi ou validé redevient « placé », à saisir dans FBI. */
    public function reopen(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setStatus(FixtureStatus::PLACED, $now);
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
        return new FederalDeviations($fixture);
    }

    /** @return FbiEcho|null */
    public function fbiEchoOf(Fixture $fixture): ?array
    {
        return $fixture->getFbiEcho();
    }

    public function clearFbiEcho(Fixture $fixture): void
    {
        $fixture->setFbiEcho(null);
    }

    /** Le libellé de salle gardé par « Garder l'appli » sur un domicile non placé, normalisé. */
    public function keptVenueLabelOf(Fixture $fixture): ?string
    {
        return $fixture->getKeptVenueLabel();
    }

    public function keepVenueLabel(Fixture $fixture, ?string $normalizedLabel): void
    {
        $fixture->setKeptVenueLabel($normalizedLabel);
    }
}
