<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use App\ApiResource\FixtureResource;
use App\Dto\FixtureInput;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Matches\CompetitionRegistry;
use App\Matches\FriendlyCompetitions;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\ClubPublicHolidays;
use App\Service\ConflictRadarLoader;
use App\Service\FbiCorrectionLedger;
use App\Service\MatchConflictDetector;
use App\Service\PlanVenueClosures;
use App\Service\SocleGuard;
use App\Support\FrenchDate;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProcessor<Fixture, FixtureInput, FixtureResource>
 */
class FixtureStateProcessor extends AbstractStateProcessor
{
    private SocleGuard $socleGuard;

    private CompetitionRegistry $competitionRegistry;

    private FriendlyCompetitions $friendlyCompetitions;

    private ClockInterface $clock;

    private VenueAliasResolver $venueAliasResolver;

    private FbiCorrectionLedger $fbiCorrectionLedger;

    private PlanVenueClosures $planVenueClosures;

    private ClubPublicHolidays $clubPublicHolidays;

    #[Required]
    public function setCompetitionRegistry(CompetitionRegistry $competitionRegistry): void
    {
        $this->competitionRegistry = $competitionRegistry;
    }

    #[Required]
    public function setFriendlyCompetitions(FriendlyCompetitions $friendlyCompetitions): void
    {
        $this->friendlyCompetitions = $friendlyCompetitions;
    }

    #[Required]
    public function setSocleGuard(SocleGuard $socleGuard): void
    {
        $this->socleGuard = $socleGuard;
    }

    #[Required]
    public function setPlanVenueClosures(PlanVenueClosures $planVenueClosures): void
    {
        $this->planVenueClosures = $planVenueClosures;
    }

    #[Required]
    public function setClubPublicHolidays(ClubPublicHolidays $clubPublicHolidays): void
    {
        $this->clubPublicHolidays = $clubPublicHolidays;
    }

    #[Required]
    public function setFbiCorrectionLedger(FbiCorrectionLedger $fbiCorrectionLedger): void
    {
        $this->fbiCorrectionLedger = $fbiCorrectionLedger;
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
            $this->assertTeamInScope($input->teamId);
            $entity->setTeamId($input->teamId);
        }
        // Lot 7, PR 4a (C55) — à la création, une compétition absente, nulle ou vide range le match dans « Amicaux ».
        if (null === $input->competitionId || '' === $input->competitionId) {
            $entity->setCompetitionId($this->defaultFriendlyFor($entity));
        } else {
            $this->assertCompetitionInScope($input->competitionId);
            $entity->setCompetitionId($input->competitionId);
        }
        $this->assertTeamEngaged($entity);
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
        $statusBefore = $entity->getStatus();
        $competitionBefore = $entity->getCompetitionId();
        $homeAwayBefore = $entity->getHomeAway();
        if (null !== $input->teamId) {
            $this->assertTeamInScope($input->teamId);
            $entity->setTeamId($input->teamId);
        }
        // Lot 7, PR 4a (C55) — en modification, une compétition absente ou nulle laisse le match où il est ; seule
        // une chaîne vide explicite le range dans « Amicaux ».
        if ('' === $input->competitionId) {
            $this->assertMayBecomeFriendly($entity, $competitionBefore, $statusBefore, null);
            $entity->setCompetitionId($this->defaultFriendlyFor($entity));
        } elseif (null !== $input->competitionId) {
            $this->assertCompetitionInScope($input->competitionId);
            $this->assertMayBecomeFriendly($entity, $competitionBefore, $statusBefore, $input->competitionId);
            $entity->setCompetitionId($input->competitionId);
        }
        // L'équipe et la compétition RETENUES (renvoyées ou laissées telles quelles) désignent un engagement.
        $this->assertTeamEngaged($entity);
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
        // PR4 « fermetures à la source » (décision fondateur 2026-10-22) — un match à domicile
        // PLACÉ/SOUMIS/VALIDÉ sans gymnase est un état IMPOSSIBLE. Vider la salle d'un tel match
        // SANS le repasser « à placer » dans le même geste est refusé (422, zéro écriture). Le
        // prédicat porte sur le STATUT : le dé-placement (UNPLACED + salle vidée ensemble) reste
        // légitime, et le terrain neutre (HOME + UNPLACED + salle nulle) arrivera plus tard.
        if (
            FixtureHomeAway::HOME === $entity->getHomeAway()
            && null === $entity->getVenueId()
            && \in_array($entity->getStatus(), [FixtureStatus::PLACED, FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED], true)
        ) {
            $this->refuse('Un match à domicile placé doit avoir un gymnase. Pour retirer sa salle, repassez-le « à placer » dans le même geste.');
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
        // L'étape « accès match » ne juge qu'une vraie pose : « Marquer saisi », « Corriger »,
        // « Verrouiller », « Rendre au système » renvoient le match à l'identique et ne posent rien.
        // Indisponibilité et fermeture, elles, restent jugées à chaque PUT.
        $isPlacing = FixtureStatus::UNPLACED === $statusBefore && FixtureStatus::UNPLACED !== $entity->getStatus();
        $moved = $placementBefore !== [
            $entity->getVenueId(),
            $entity->getKickoffTime()?->format('H:i'),
            $entity->getMatchDate()->format('Y-m-d'),
        ];
        $this->assertVenueAccessAllowed(
            $entity,
            $isPlacing || $moved || $competitionBefore !== $entity->getCompetitionId() || $homeAwayBefore !== $entity->getHomeAway(),
        );
    }

    /**
     * Une rencontre supprimée emporte ses entrées « à corriger dans FBI » (aucune FK sur
     * `fixture_id`) : sans ça, `fbiTodo.toCorrect` compterait des orphelins que la liste ne
     * montre jamais (leur rencontre a disparu). Suppression PURE — un match supprimé n'a
     * plus rien à corriger. Appelé AVANT le remove+flush du parent ({@see AbstractStateProcessor}).
     *
     * @param Fixture $entity
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        $this->fbiCorrectionLedger->removeForFixture($entity);
    }

    /**
     * @param Fixture $entity
     */
    protected function mapEntityToOutput(object $entity): FixtureResource
    {
        $competitionId = $entity->getCompetitionId();
        $output = FixtureResource::fromEntity($entity, null === $competitionId ? null : $this->entityManager->find(Competition::class, $competitionId));
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
     *  1bis. couvert par une FERMETURE du calendrier (`venue_closed`) à sa date → refus TOUJOURS
     *     (amical compris, P4-300 D4). Le FAIT brut (config, repli legacy) vient de la maison
     *     unique `PlanVenueClosures`, jamais de la composition `PlanVenue` ;
     *  2. de COMPÉTITION (nature non amicale), quand le club déclare ≥ 1 ligne d'accès
     *     NON datée, et seulement à une vraie pose ($checkAccess) : aucune plage applicable à
     *     cette date (ouvertures datées ∪ férié ou jour de semaine) ou coup d'envoi hors de ces
     *     plages → refus ; un amical reste libre (le solveur ne le pose plus, le radar signale).
     *
     * Aucun refus sur l'enveloppe de ligue (radar seul, décision D2). Les fenêtres et
     * indisponibilités sont lues via les mêmes repos tenant-filtrés que le radar
     * ({@see ConflictRadarLoader}) ; l'appartenance du coup d'envoi est LE
     * MÊME prédicat que le diagnostic ({@see MatchConflictDetector::kickoffInsideAccess},
     * aucune seconde implémentation). Un club sans aucun accès match n'a rien à imposer.
     */
    private function assertVenueAccessAllowed(Fixture $entity, bool $checkAccess = true): void
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

        // (1bis) FERMETURE du calendrier couvrante — TOUS les matchs, amical compris (P4-300 D4).
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');
        if (\is_string($clubId) && '' !== $clubId && \is_string($seasonId) && '' !== $seasonId) {
            foreach ($this->planVenueClosures->closureIntervals($clubId, $seasonId) as $closure) {
                if ($closure['venueId'] !== $venueId || !MatchConflictDetector::dateInsideClosure($date, $closure['startDate'], $closure['endDate'])) {
                    continue;
                }
                $this->refuse(\sprintf(
                    '%s est fermée du %s au %s — %s : le match ne peut pas y être placé. Choisissez un autre gymnase.',
                    $this->venueName($venueId),
                    new DateTimeImmutable($closure['startDate'])->format('j/n'),
                    new DateTimeImmutable($closure['endDate'])->format('j/n'),
                    $closure['title'],
                ));
            }
        }

        // (2) accès match — COMPÉTITION seulement ; un amical est libre hors créneau.
        if (!$checkAccess || $this->friendlyCompetitions->isFriendly($entity)) {
            return;
        }
        /** @var list<VenueMatchWindow> $matchWindows */
        $matchWindows = $this->entityManager->getRepository(VenueMatchWindow::class)->findBy([]);
        $windowArrays = array_map(MatchConflictDetector::windowArray(...), $matchWindows);
        if (!MatchConflictDetector::hasWeeklyAccess($windowArrays)) {
            // le club n'a pas adopté les accès match → rien à imposer (une ouverture datée n'adopte rien).
            return;
        }
        $isHoliday = \is_string($clubId) && '' !== $clubId
            && [] !== $this->clubPublicHolidays->datesInWindow($clubId, $matchDate, $matchDate);
        $applicable = MatchConflictDetector::applicableWindows($venueId, $date, $isHoliday, $windowArrays);
        $dayAndMonth = FrenchDate::weekday($matchDate);
        if ([] === $applicable) {
            $this->refuse(\sprintf(
                '%s n\'a pas d\'accès match le %s%s. Choisissez un autre gymnase, ou ouvrez-le ce jour-là depuis le panneau du match.',
                $this->venueName($venueId),
                $dayAndMonth,
                $isHoliday ? ', jour férié' : '',
            ));
        }
        $kickoffTime = $entity->getKickoffTime();
        if (!$kickoffTime instanceof DateTimeImmutable) {
            return;
        }
        if (!MatchConflictDetector::kickoffInsideAccess($venueId, $date, $isHoliday, $kickoffTime->format('H:i'), $windowArrays)) {
            usort($applicable, static fn (array $a, array $b): int => $a['startTime'] <=> $b['startTime']);
            $this->refuse(\sprintf(
                'Coup d\'envoi hors des accès match de %s le %s (%s). Choisissez une heure dans ces plages, ou ouvrez-le ce jour-là depuis le panneau du match.',
                $this->venueName($venueId),
                $dayAndMonth,
                implode(', ', array_map(static fn (array $window): string => \sprintf('%s – %s', $window['startTime'], $window['endTime']), $applicable)),
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
    private function assertCompetitionInScope(string $competitionId): void
    {
        if (null === $this->entityManager->find(Competition::class, $competitionId)) {
            throw new UnprocessableEntityHttpException('Compétition inconnue pour ce club et cette saison.');
        }
    }

    /**
     * Le match désigne une équipe du club et de la saison (filtres club et saison, RLS) : une équipe inconnue ou d'un
     * autre club est refusée avant toute écriture, match comme engagement.
     */
    private function assertTeamInScope(string $teamId): void
    {
        if (!$this->entityManager->find(Team::class, $teamId) instanceof Team) {
            $this->refuse('Équipe inconnue pour ce club et cette saison.');
        }
    }

    /**
     * « Amicaux » de la saison du match, son équipe engagée au passage (écriture immédiate, C42). Club et saison sont
     * ceux du match (le match existant) ou de la requête (le match neuf) : jamais ceux d'un autre club (RLS).
     */
    private function defaultFriendlyFor(Fixture $fixture): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');
        $clubId = $this->entityManager->contains($fixture) ? $fixture->getClubId() : (\is_string($clubId) ? $clubId : null);
        $seasonId = $this->entityManager->contains($fixture) ? $fixture->getSeasonId() : $this->resolveSeasonId($clubId, \is_string($seasonId) ? $seasonId : null);
        if (null === $clubId || null === $seasonId) {
            $this->refuse('Aucune saison en cours : créez d\'abord la saison.');
        }

        return $this->friendlyCompetitions->engageInDefault($clubId, $seasonId, $fixture->getTeamId())->getId();
    }

    /**
     * Q9 — un match connu de la fédération (numéro FBI, rencontre fédérale) ou déjà saisi ou validé ne CHANGE pas de
     * compétition vers une compétition amicale. On compare la compétition d'avant et celle demandée, jamais la seule
     * valeur reçue : renvoyer la compétition qu'il a déjà n'est jamais refusé. `$target` null = « Amicaux ».
     */
    private function assertMayBecomeFriendly(Fixture $fixture, ?string $competitionBefore, FixtureStatus $statusBefore, ?string $target): void
    {
        $before = null === $competitionBefore ? null : $this->entityManager->find(Competition::class, $competitionBefore);
        if (null === $target) {
            if ($before instanceof Competition && $before->isDefaultFriendly()) {
                return;
            }
        } else {
            $competition = $this->entityManager->find(Competition::class, $target);
            if ($target === $competitionBefore || !$competition instanceof Competition || !$competition->getCompetitionType()->isFriendly()) {
                return;
            }
        }
        $known = null !== $fixture->getExternalRef()
            || null !== $fixture->getFfbbRencontreId()
            || \in_array($statusBefore, [FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED], true);
        if ($known) {
            $this->refuse('Ce match est connu de la fédération : il reste dans sa compétition.');
        }
    }

    /**
     * Un match exige que son équipe soit engagée dans sa compétition (lot 7, PR 3b) : refus 422, jamais le 500
     * de la clé du match vers l'engagement. Une compétition amicale engage l'équipe au passage (PR 4a) : le refus
     * ne vaut que pour une compétition non amicale.
     */
    private function assertTeamEngaged(Fixture $fixture): void
    {
        $competitionId = (string) $fixture->getCompetitionId();
        $competition = $this->entityManager->find(Competition::class, $competitionId);
        if ($competition instanceof Competition && $this->competitionRegistry->isEngaged($competition, $fixture->getTeamId())) {
            return;
        }
        if ($competition instanceof Competition && $competition->getCompetitionType()->isFriendly()) {
            $this->friendlyCompetitions->engageIn($competition, $fixture->getTeamId());

            return;
        }
        $team = $this->entityManager->find(Team::class, $fixture->getTeamId());
        $this->refuse(\sprintf(
            'L\'équipe %s n\'est pas engagée dans « %s ». Choisissez une de ses compétitions, ou « Amicaux ».',
            $team instanceof Team ? $team->getName() : $fixture->getTeamId(),
            $competition instanceof Competition ? $competition->getName() : $competitionId,
        ));
    }
}
