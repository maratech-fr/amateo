<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use App\ApiResource\FixtureResource;
use App\Dto\FixtureInput;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\ConflictRadarLoader;
use App\Service\MatchConflictDetector;
use App\Service\SocleGuard;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProcessor<Fixture, FixtureInput, FixtureResource>
 */
class FixtureStateProcessor extends AbstractStateProcessor
{
    /** ISO weekday (1 = Monday … 7 = Sunday) → the French day name, lowercase. */
    private const array DAY_LABELS = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    private SocleGuard $socleGuard;

    private ClockInterface $clock;

    private VenueAliasResolver $venueAliasResolver;

    #[Required]
    public function setSocleGuard(SocleGuard $socleGuard): void
    {
        $this->socleGuard = $socleGuard;
    }

    #[Required]
    public function setVenueAliasResolver(VenueAliasResolver $venueAliasResolver): void
    {
        $this->venueAliasResolver = $venueAliasResolver;
    }

    #[Required]
    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // A match can only be created/edited once the season's main plan is
        // validated (cockpit state 2 → 3). DELETE stays allowed (cleanup).
        $method = $operation instanceof HttpOperation ? $operation->getMethod() : '';
        if (\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $request = $this->requestStack->getCurrentRequest();
            $this->socleGuard->assertSeasonPlanChosen($request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id'));
        }

        return parent::process($data, $operation, $uriVariables, $context);
    }

    protected function getEntityClass(): string
    {
        return Fixture::class;
    }

    /**
     * @param FixtureInput $input
     */
    protected function createEntityFromInput(object $input): Fixture
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $entity = new Fixture;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        // competitionId nullable → friendly; explicit '' clears it.
        $competitionId = '' === $input->competitionId ? null : $input->competitionId;
        $this->assertCompetitionInScope($competitionId);
        $entity->setCompetitionId($competitionId);
        if (null !== $input->matchDate) {
            $entity->setMatchDate(new DateTimeImmutable($input->matchDate));
        }
        if (null !== $input->homeAway) {
            $entity->setHomeAway(FixtureHomeAway::from($input->homeAway));
        }
        if (null !== $input->opponentLabel) {
            $entity->setOpponentLabel($input->opponentLabel);
        }
        if (null !== $input->status) {
            $entity->setStatus(FixtureStatus::from($input->status), $now);
            // P1-4 PR D — every status write through the API is the MANAGER's
            // gesture: a placement becomes a MANUAL anchor (the solver never
            // moves it again), an un-placement clears the marker.
            $entity->setPlacementSource(
                FixtureStatus::UNPLACED === $entity->getStatus() ? null : FixturePlacementSource::MANUAL,
            );
        }
        $entity->setVenueId('' === $input->venueId ? null : $input->venueId);
        $entity->setKickoffTime($this->parseTime($input->kickoffTime));
        if (FixturePlacementSource::SOLVER->value === $input->placementSource) {
            // A match born from a manager's POST is manual by definition.
            throw new UnprocessableEntityHttpException('Un match créé à la main est manuel — le solveur seul pose SOLVER.');
        }
        // Saisie manuelle = geste du gestionnaire → la rencontre est TRAITÉE
        // (PR-3a) : REVIEWED + horodaté, quel que soit le statut de placement.
        $entity->markReviewed($now);
        $this->assertVenueAccessAllowed($entity);

        return $entity;
    }

    /**
     * @param Fixture      $entity
     * @param FixtureInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $placementBefore = [
            $entity->getVenueId(),
            $entity->getKickoffTime()?->format('H:i'),
            $entity->getMatchDate()->format('Y-m-d'),
        ];
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->competitionId) {
            $competitionId = '' === $input->competitionId ? null : $input->competitionId;
            $this->assertCompetitionInScope($competitionId);
            $entity->setCompetitionId($competitionId);
        }
        if (null !== $input->matchDate) {
            $entity->setMatchDate(new DateTimeImmutable($input->matchDate));
        }
        if (null !== $input->homeAway) {
            $entity->setHomeAway(FixtureHomeAway::from($input->homeAway));
        }
        if (null !== $input->opponentLabel) {
            $entity->setOpponentLabel($input->opponentLabel);
        }
        if (null !== $input->status) {
            // D6 — « placer = traiter » : setStatus pose REVIEWED + horodaté quand
            // le match passe placé sans écart pendant (UNPLACED ne change rien).
            $entity->setStatus(FixtureStatus::from($input->status), $now);
            // P1-4 PR D — every status write through the API is the MANAGER's
            // gesture: a placement becomes a MANUAL anchor (the solver never
            // moves it again), an un-placement clears the marker.
            $entity->setPlacementSource(
                FixtureStatus::UNPLACED === $entity->getStatus() ? null : FixturePlacementSource::MANUAL,
            );
        }
        if (null !== $input->venueId) {
            $entity->setVenueId('' === $input->venueId ? null : $input->venueId);
        }
        if (null !== $input->kickoffTime) {
            $entity->setKickoffTime($this->parseTime($input->kickoffTime));
        }
        if (FixturePlacementSource::SOLVER->value === $input->placementSource) {
            $this->assertSolverHandBackAllowed($entity, $placementBefore);
            // "Rendre au solveur" (P1-4 PR E): overrides the MANUAL stamp above —
            // legitimate precisely because nothing else moved in this PUT.
            $entity->setPlacementSource(FixturePlacementSource::SOLVER);
        }
        $this->assertVenueAccessAllowed($entity);
    }

    /**
     * @param Fixture $entity
     */
    protected function mapEntityToOutput(object $entity): FixtureResource
    {
        $output = FixtureResource::fromEntity($entity);
        // D6 — même proposition floue que le provider (second appelant de fromEntity).
        if (FixtureHomeAway::HOME === $entity->getHomeAway() && null === $entity->getVenueId() && null !== $entity->getFbiVenueLabel()) {
            $output->suggestedVenueId = $this->venueAliasResolver->suggest($entity->getFbiVenueLabel());
        }

        return $output;
    }

    /**
     * D2 — refus serveur du placement d'une rencontre (geste gestionnaire, entité
     * FINALE). Une rencontre HOME posée dans un gymnase :
     *  1. couvert par une indisponibilité à sa date → refus TOUJOURS (amical compris) ;
     *  2. de COMPÉTITION (competitionId non null), quand le club déclare ≥ 1 accès
     *     match : aucun accès (gymnase, jour) ou coup d'envoi hors fenêtre → refus ;
     *     un amical reste libre (le solveur ne le pose plus, le radar signale).
     *
     * Aucun refus sur l'enveloppe de ligue (radar seul, décision D2). Les fenêtres et
     * indisponibilités sont lues via les mêmes repos tenant-filtrés que le radar
     * ({@see ConflictRadarLoader}) ; l'appartenance du coup d'envoi est LE
     * MÊME prédicat que le diagnostic ({@see MatchConflictDetector::kickoffInsideWindow},
     * aucune seconde implémentation). Un club sans aucun accès match n'a rien à imposer.
     */
    private function assertVenueAccessAllowed(Fixture $entity): void
    {
        if (FixtureHomeAway::HOME !== $entity->getHomeAway()) {
            return;
        }
        $venueId = $entity->getVenueId();
        if (null === $venueId) {
            return;
        }
        $matchDate = $entity->getMatchDate();
        $date = $matchDate->format('Y-m-d');

        // (1) indisponibilité couvrante — TOUS les matchs, amical compris.
        /** @var list<VenueUnavailability> $unavailabilities */
        $unavailabilities = $this->entityManager->getRepository(VenueUnavailability::class)->findBy([]);
        foreach ($unavailabilities as $unavailability) {
            if ($unavailability->getVenueId() !== $venueId) {
                continue;
            }
            if ($date >= $unavailability->getStartDate()->format('Y-m-d') && $date <= $unavailability->getEndDate()->format('Y-m-d')) {
                $rawLabel = $unavailability->getLabel();
                $label = null !== $rawLabel && '' !== $rawLabel ? ' — ' . $rawLabel : '';
                $this->refuse(\sprintf(
                    '%s est indisponible du %s au %s%s : le match ne peut pas y être placé. Choisissez un autre gymnase.',
                    $this->venueName($venueId),
                    $unavailability->getStartDate()->format('j/n'),
                    $unavailability->getEndDate()->format('j/n'),
                    $label,
                ));
            }
        }

        // (2) accès match — COMPÉTITION seulement ; un amical est libre hors créneau.
        if (null === $entity->getCompetitionId()) {
            return;
        }
        /** @var list<VenueMatchWindow> $matchWindows */
        $matchWindows = $this->entityManager->getRepository(VenueMatchWindow::class)->findBy([]);
        if ([] === $matchWindows) {
            // le club n'a pas adopté les accès match → rien à imposer.
            return;
        }
        $day = (int) $matchDate->format('N');
        $dayWindows = array_values(array_filter(
            $matchWindows,
            static fn (VenueMatchWindow $window): bool => $window->getVenueId() === $venueId && $window->getDayOfWeek() === $day,
        ));
        if ([] === $dayWindows) {
            $this->refuse(\sprintf(
                'Pas d\'accès match le %s à %s : le match ne peut pas y être placé. Choisissez un autre gymnase, ou ajoutez un accès match ce jour-là dans Configuration.',
                self::DAY_LABELS[$day],
                $this->venueName($venueId),
            ));
        }
        $kickoffTime = $entity->getKickoffTime();
        if (!$kickoffTime instanceof DateTimeImmutable) {
            return;
        }
        $windowArrays = array_map(static fn (VenueMatchWindow $window): array => [
            'venueId' => $window->getVenueId(),
            'dayOfWeek' => $window->getDayOfWeek(),
            'startTime' => $window->getStartTime()->format('H:i'),
            'endTime' => $window->getEndTime()->format('H:i'),
        ], $matchWindows);
        if (!MatchConflictDetector::kickoffInsideWindow($venueId, $day, $kickoffTime->format('H:i'), $windowArrays)) {
            $ranges = implode(', ', array_map(
                static fn (VenueMatchWindow $window): string => \sprintf('%s–%s', $window->getStartTime()->format('H:i'), $window->getEndTime()->format('H:i')),
                $dayWindows,
            ));
            $this->refuse(\sprintf(
                'Coup d\'envoi hors fenêtre d\'accès match (%s) le %s à %s : le match ne peut pas y être placé. Choisissez une heure dans la fenêtre, ou ajustez l\'accès match dans Configuration.',
                $ranges,
                self::DAY_LABELS[$day],
                $this->venueName($venueId),
            ));
        }
    }

    /** Le nom HUMAIN du gymnase pour un message 422, ou un repli neutre s'il est introuvable. */
    private function venueName(string $venueId): string
    {
        return $this->entityManager->find(Venue::class, $venueId)?->getName() ?? 'Ce gymnase';
    }

    /**
     * SOLVER can only be handed back on an untouched placement: you cannot label
     * SOLVER a venue/time/date you just chose by hand (the anchor rule would lie
     * to the next solve), nor an unplaced or submitted match (nothing to hand back).
     *
     * @param array{0: string|null, 1: string|null, 2: string} $placementBefore
     */
    private function assertSolverHandBackAllowed(Fixture $entity, array $placementBefore): void
    {
        $untouched = [
            $entity->getVenueId(),
            $entity->getKickoffTime()?->format('H:i'),
            $entity->getMatchDate()->format('Y-m-d'),
        ] === $placementBefore;
        if (!$untouched || FixtureStatus::PLACED !== $entity->getStatus()) {
            throw new UnprocessableEntityHttpException('Rendre au solveur exige un placement inchangé et un match PLACED — modifiez d\'abord (le match restera manuel), ou déverrouillez sans rien changer.');
        }
    }

    private function parseTime(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!H:i', $value);
        // Reject anything createFromFormat rolled over (belt-and-braces; the DTO
        // regex already blocks out-of-range HH:MM before we get here).
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $time || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $time;
    }

    /**
     * A referenced competition must belong to the caller's club+season. em->find
     * is tenant+season-filter aware (enabled per-request), so a foreign, deleted
     * or nonexistent id resolves to null → 422 rather than a dangling reference.
     */
    private function assertCompetitionInScope(?string $competitionId): void
    {
        if (null === $competitionId) {
            return;
        }
        if (null === $this->entityManager->find(Competition::class, $competitionId)) {
            throw new UnprocessableEntityHttpException('Compétition inconnue pour ce club et cette saison.');
        }
    }
}
