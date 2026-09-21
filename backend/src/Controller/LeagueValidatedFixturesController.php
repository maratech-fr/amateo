<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Repository\SharedCompetitionDeadlineRepository;
use App\Service\CompetitionDeadlineResolver;
use App\Service\FbiFixtureImporter;
use App\Service\ManagementAccessGuard;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use App\State\Processor\FixtureStateProcessor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Validé ligue » en lot, PILOTÉ PAR L'ÉCHÉANCE DU CHAMPIONNAT (lot O). Un club qui
 * démarre l'application EN COURS de saison importe son fichier FBI : la ligue a fixé une
 * ÉCHÉANCE DE SAISIE par championnat, et « la date d'échéance est de validation des dates
 * pour un championnat entier — toutes les dates du championnat peuvent être validées à
 * PARTIR de la date d'échéance » (décision fondateur). Le geste bascule d'un coup les
 * domiciles que le fichier atteste, MAIS seulement pour les championnats DONT L'ÉCHÉANCE
 * EST PASSÉE (jour de l'échéance INCLUS).
 *
 * Pourquoi l'échéance et pas « heure + gymnase » seuls : en octobre, une nouvelle vague de
 * matchs jeunes arrive (nouvelle phase, nouvelle poule, parfois un changement de niveau)
 * avec des DATES PROVISOIRES et une échéance NON ENCORE PASSÉE. Les verrouiller en ancres
 * fixes au moment précis où il faut pouvoir les bouger serait le piège. Tant que l'échéance
 * d'un championnat n'est pas passée, on ne propose RIEN pour lui.
 *
 * ⚠ EXCEPTION CONSENTIE à « une rencontre naît AVEC son gymnase mais jamais placée
 * d'office » ({@see FbiFixtureImporter::attachConfirmedVenue}) : le statut
 * n'est PAS posé pendant l'import ; c'est CE geste, explicite et confirmé, qui valide.
 *
 * Deux routes :
 *   - GET rend la LECTURE détaillée par championnat échu (nom, échéance, provenance, compte
 *     de validables) + la liste NOMMÉE des rencontres à traiter (ni heure ni gymnase, ou
 *     écart en attente) + les championnats SANS échéance ayant pourtant des rencontres
 *     prêtes (à renseigner) + le total à valider ;
 *   - POST applique. Il est SANS CORPS : le serveur RECALCULE les championnats échus au
 *     moment de l'application (l'horloge injectée + les échéances du moment font foi, jamais
 *     ce que l'écran croyait savoir). Aucun championnat choisi par le client.
 *
 * Application : statut VALIDATED (horloge injectée) + source de placement MANUAL. Le MANUAL
 * n'est PAS cosmétique : la formule du cadenas de la grille (`matches/lib/weekendGrid.ts`)
 * et l'ancre FIXED du solveur ({@see MatchPlacementPayloadBuilder::matchRow})
 * l'exigent. Rejouable : le prédicat exclut VALIDATED. On ne DÉVALIDE jamais — une rencontre
 * validée par l'ancienne règle reste validée ; la sortie reste le geste manuel unitaire.
 *
 * Les amicaux sont HORS du lot : « validé ligue » n'a pas de sens sans championnat, et un
 * domicile sans compétition ne mûrit sous aucune échéance (`isCandidate` l'exclut).
 *
 * Patron {@see ReviewFixturesController} : management + saison écrivable + socle pointé.
 */
#[AsController]
final class LeagueValidatedFixturesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedCompetitionDeadlineRepository $sharedDeadlineRepository,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly ClockInterface $clock,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/league-validation', name: 'api_fixtures_league_validation_count', methods: ['GET'], priority: 10)]
    public function outlook(Request $request): JsonResponse
    {
        $seasonId = $this->guard($request);

        return $this->json($this->buildOutlook($seasonId));
    }

    #[Route('/api/fixtures/league-validation', name: 'api_fixtures_league_validation_confirm', methods: ['POST'], priority: 10)]
    public function confirm(Request $request): JsonResponse
    {
        $seasonId = $this->guard($request);

        // On RECALCULE les championnats échus MAINTENANT : l'horloge et les échéances du
        // moment font foi, jamais ce que l'écran affichait.
        $maturedIds = $this->maturedCompetitionIds($seasonId);

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $confirmed = 0;
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy(['seasonId' => $seasonId]);
        foreach ($fixtures as $fixture) {
            $competitionId = $fixture->getCompetitionId();
            if (null === $competitionId || !isset($maturedIds[$competitionId]) || !$this->passesPredicate($fixture)) {
                continue;
            }
            // L'ancre MANUAL d'abord, puis le statut : setStatus(VALIDATED) TRAITE la
            // rencontre (REVIEWED + horodaté, aucun écart pendant par construction).
            $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
            $fixture->setStatus(FixtureStatus::VALIDATED, $now);
            ++$confirmed;
        }
        try {
            // Verrou optimiste `#[ORM\Version]` de Fixture : deux onglets ou un double-clic
            // font écrire le second sur une version périmée → 409 lisible plutôt qu'une 500.
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new ConflictHttpException('Ces rencontres viennent d\'être modifiées ailleurs (autre onglet ou double-clic). Rechargez la page avant de valider en lot.');
        }

        return $this->json(['confirmed' => $confirmed]);
    }

    /**
     * @return string la saison résolue (validée par le listener) — non-null au retour
     */
    private function guard(Request $request): string
    {
        // SEC : le 403 doit gagner sur les 409 (idiome import).
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        // Défense en profondeur (revue lot L) : l'isolation de saison ne doit PAS reposer
        // sur la seule activation conditionnelle du filtre Doctrine. Si aucune saison ne se
        // résout, on refuse franchement, et on scope la lecture sur cette saison.
        $seasonId = $request->attributes->get('_season_id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            throw new ConflictHttpException('Aucune saison active : impossible de valider les rencontres en lot.');
        }
        $this->socleGuard->assertSeasonPlanChosen($seasonId);

        return $seasonId;
    }

    /**
     * La lecture « validé ligue » : par championnat ÉCHU (échéance passée, jour inclus), son
     * nom, son échéance, sa provenance et son compte de validables ; la liste NOMMÉE des
     * rencontres à traiter d'un championnat échu ; les championnats SANS échéance qui ont
     * pourtant des rencontres prêtes ; et le total à valider.
     *
     * @return array{
     *     matured: list<array{competitionId: string, name: string, deadline: string, deadlineSource: string, validatableCount: int}>,
     *     toTreat: list<array{fixtureId: string, teamId: string, competitionName: string, matchDate: string, opponentLabel: string, reason: string}>,
     *     missingDeadline: list<array{competitionId: string, name: string, validatableCount: int}>,
     *     totalValidatable: int,
     * }
     */
    private function buildOutlook(string $seasonId): array
    {
        $meta = $this->competitionMeta($seasonId);

        $validatableByComp = [];
        $missingByComp = [];
        $toTreat = [];
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy(['seasonId' => $seasonId]);
        foreach ($fixtures as $fixture) {
            if (!$this->isCandidate($fixture)) {
                continue;
            }
            $competitionId = (string) $fixture->getCompetitionId();
            $entry = $meta[$competitionId] ?? null;
            if (null === $entry) {
                continue; // compétition hors saison résolue (défensif)
            }
            $passes = $this->passesPredicate($fixture);
            if (!$entry['deadline'] instanceof DateTimeImmutable) {
                // Sans échéance : on ne propose rien, mais on SIGNALE le championnat s'il a
                // des rencontres prêtes (à renseigner via l'écran des échéances).
                if ($passes) {
                    $missingByComp[$competitionId] = ($missingByComp[$competitionId] ?? 0) + 1;
                }

                continue;
            }
            if (!$entry['matured']) {
                continue; // échéance FUTURE → on ne propose rien pour ce championnat.
            }
            if ($passes) {
                $validatableByComp[$competitionId] = ($validatableByComp[$competitionId] ?? 0) + 1;
            } else {
                $toTreat[] = [
                    'fixtureId' => $fixture->getId(),
                    'teamId' => $fixture->getTeamId(),
                    'competitionName' => $entry['name'],
                    'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
                    'opponentLabel' => $fixture->getOpponentLabel(),
                    'reason' => $this->reasonToTreat($fixture),
                ];
            }
        }

        $matured = [];
        foreach ($validatableByComp as $competitionId => $count) {
            $entry = $meta[$competitionId];
            \assert($entry['deadline'] instanceof DateTimeImmutable);
            $matured[] = [
                'competitionId' => $competitionId,
                'name' => $entry['name'],
                'deadline' => $entry['deadline']->format('Y-m-d'),
                'deadlineSource' => $entry['source'],
                'validatableCount' => $count,
            ];
        }
        usort($matured, static fn (array $a, array $b): int => [$a['deadline'], $a['name']] <=> [$b['deadline'], $b['name']]);

        $missingDeadline = [];
        foreach ($missingByComp as $competitionId => $count) {
            $missingDeadline[] = [
                'competitionId' => $competitionId,
                'name' => $meta[$competitionId]['name'],
                'validatableCount' => $count,
            ];
        }
        usort($missingDeadline, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        usort($toTreat, static fn (array $a, array $b): int => [$a['matchDate'], $a['competitionName']] <=> [$b['matchDate'], $b['competitionName']]);

        return [
            'matured' => $matured,
            'toTreat' => $toTreat,
            'missingDeadline' => $missingDeadline,
            'totalValidatable' => array_sum($validatableByComp),
        ];
    }

    /**
     * Les ids des championnats de la saison DONT L'ÉCHÉANCE EFFECTIVE EST PASSÉE (jour de
     * l'échéance inclus). Recalculé à chaque appel (POST comme GET) — jamais mémorisé.
     *
     * @return array<string, true>
     */
    private function maturedCompetitionIds(string $seasonId): array
    {
        $matured = [];
        foreach ($this->competitionMeta($seasonId) as $competitionId => $entry) {
            if ($entry['matured']) {
                $matured[$competitionId] = true;
            }
        }

        return $matured;
    }

    /**
     * Les métadonnées d'échéance des championnats de la saison, par id : nom, échéance
     * effective (règle « club sinon communautaire », maison unique
     * {@see CompetitionDeadlineResolver}), provenance, et si l'échéance est passée.
     *
     * @return array<string, array{name: string, deadline: DateTimeImmutable|null, source: string, matured: bool}>
     */
    private function competitionMeta(string $seasonId): array
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy(['seasonId' => $seasonId]);

        $sharedByFfbbId = $this->sharedDeadlineRepository->mapByFfbbCompetitionIds(
            array_values(array_filter(array_map(
                static fn (Competition $c): ?string => $c->getFfbbCompetitionId(),
                $competitions,
            ))),
        );

        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);

        $meta = [];
        foreach ($competitions as $competition) {
            $ffbbCompetitionId = $competition->getFfbbCompetitionId();
            $shared = null !== $ffbbCompetitionId ? ($sharedByFfbbId[$ffbbCompetitionId] ?? null) : null;
            [$effective, $source] = CompetitionDeadlineResolver::resolve($competition, $shared);
            $meta[$competition->getId()] = [
                'name' => $competition->getName(),
                'deadline' => $effective,
                'source' => $source ?? '',
                // Le jour de l'échéance est INCLUS : « à partir de la date d'échéance ».
                'matured' => $effective instanceof DateTimeImmutable && $effective <= $today,
            ];
        }

        return $meta;
    }

    /** Un candidat « validé ligue » : un domicile UNPLACED rattaché à un championnat (jamais un amical). */
    private function isCandidate(Fixture $fixture): bool
    {
        return FixtureHomeAway::HOME === $fixture->getHomeAway()
            && FixtureStatus::UNPLACED === $fixture->getStatus()
            && null !== $fixture->getCompetitionId();
    }

    /**
     * Le prédicat « validable » d'un candidat : heure + gymnase identifié, sans écart en
     * attente. La MATURITÉ du championnat (échéance passée) est vérifiée à part.
     *
     * ⚠ DIVERGENCE ASSUMÉE avec le contrôle d'accès du geste UNITAIRE
     * ({@see FixtureStateProcessor::assertVenueAccessAllowed}) : ce
     * prédicat ne contrôle PAS les créneaux d'accès match. La fédération a enregistré cette
     * réalité ; l'application la reflète, le radar signale l'incohérence (`ACCESS_WINDOW_LOST`).
     */
    private function passesPredicate(Fixture $fixture): bool
    {
        return $this->isCandidate($fixture)
            && $fixture->getKickoffTime() instanceof DateTimeImmutable
            && null !== $fixture->getVenueId()
            && !$fixture->hasPendingDeviations();
    }

    /** Pourquoi un candidat d'un championnat échu n'est pas validable — nommé, jamais tu. */
    private function reasonToTreat(Fixture $fixture): string
    {
        if ($fixture->hasPendingDeviations()) {
            return 'PENDING_DEVIATION';
        }
        if (!$fixture->getKickoffTime() instanceof DateTimeImmutable) {
            return 'NO_KICKOFF';
        }

        return 'NO_VENUE';
    }
}
