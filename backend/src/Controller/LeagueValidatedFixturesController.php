<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Validé ligue » en lot (lot L) — un club qui démarre l'application EN COURS de
 * saison importe son fichier FBI : les échéances sont déjà passées, date + heure +
 * gymnase sont déjà enregistrés côté fédération. Plutôt que confirmer chaque
 * placement à la main, un geste CONFIRMÉ bascule d'un coup les rencontres que le
 * fichier atteste.
 *
 * ⚠ EXCEPTION CONSENTIE à « une rencontre naît AVEC son gymnase mais jamais placée
 * d'office » ({@see FbiFixtureImporter::attachConfirmedVenue}) : le statut n'est PAS
 * posé pendant l'import (le compte ne peut être annoncé avant d'écrire, et le
 * fondateur veut une confirmation chiffrée). L'import continue de créer en
 * UNPLACED ; c'est CE geste, explicite et confirmé, qui valide — jamais le chemin de
 * création.
 *
 * Deux routes, MÊME prédicat d'éligibilité (maison unique {@see self::isEligible}) :
 *   - GET  rend le COMPTE des rencontres validables (bandeau de rattrapage + section
 *     du rapport d'import) ;
 *   - POST applique et rend le nombre basculé.
 *
 * Éligible = un domicile UNPLACED qui porte une HEURE ET un venueId (gymnase
 * identifié, jamais le libellé brut) et n'a AUCUN écart en attente. PAS de condition
 * de date : un domicile futur portant heure + gymnase est tout autant enregistré côté
 * fédération. Sans heure ou sans gymnase → rien ne change ; un écart en attente
 * exclut (valider un placement que la source conteste serait mentir).
 *
 * Application : statut VALIDATED (horloge injectée) + source de placement MANUAL. Le
 * MANUAL n'est PAS cosmétique : la formule du cadenas de la grille
 * (`matches/lib/weekendGrid.ts` — `locked` exige `placementSource === "MANUAL"`) et
 * l'ancre FIXED du solveur ({@see MatchPlacementPayloadBuilder::matchRow}) l'exigent,
 * sans quoi les rencontres s'afficheraient déverrouillées alors que le solveur les
 * traite en ancres. Rejouable : le prédicat exclut VALIDATED, une seconde
 * application ne trouve plus rien.
 *
 * Patron {@see ReviewFixturesController} : management + saison écrivable + socle
 * pointé. Scope tenant/saison automatique (filtres Doctrine).
 */
#[AsController]
final class LeagueValidatedFixturesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly ClockInterface $clock,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/league-validation', name: 'api_fixtures_league_validation_count', methods: ['GET'], priority: 10)]
    public function count(Request $request): JsonResponse
    {
        $this->guard($request);

        return $this->json(['count' => \count($this->eligibleFixtures())]);
    }

    #[Route('/api/fixtures/league-validation', name: 'api_fixtures_league_validation_confirm', methods: ['POST'], priority: 10)]
    public function confirm(Request $request): JsonResponse
    {
        $this->guard($request);

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $confirmed = 0;
        foreach ($this->eligibleFixtures() as $fixture) {
            // L'ancre MANUAL d'abord, puis le statut : setStatus(VALIDATED) TRAITE la
            // rencontre (REVIEWED + horodaté, aucun écart pendant par construction).
            $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
            $fixture->setStatus(FixtureStatus::VALIDATED, $now);
            ++$confirmed;
        }
        $this->entityManager->flush();

        return $this->json(['confirmed' => $confirmed]);
    }

    private function guard(Request $request): void
    {
        // SEC : le 403 doit gagner sur les 409 (idiome import).
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));
    }

    /**
     * Les rencontres validables ligue de la saison courante — le scope tenant/saison
     * est déjà borné par les filtres Doctrine (`findBy([])` = club + saison courante).
     *
     * @return list<Fixture>
     */
    private function eligibleFixtures(): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);

        return array_values(array_filter($fixtures, $this->isEligible(...)));
    }

    /**
     * Le prédicat « validable ligue », MAISON UNIQUE partagée par le compte (GET) et
     * l'application (POST) : un domicile UNPLACED portant heure + gymnase identifié,
     * sans écart en attente.
     */
    private function isEligible(Fixture $fixture): bool
    {
        return FixtureHomeAway::HOME === $fixture->getHomeAway()
            && FixtureStatus::UNPLACED === $fixture->getStatus()
            && $fixture->getKickoffTime() instanceof DateTimeImmutable
            && null !== $fixture->getVenueId()
            && !$fixture->hasPendingDeviations();
    }
}
