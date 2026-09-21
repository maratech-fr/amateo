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
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        $seasonId = $this->guard($request);

        return $this->json(['count' => \count($this->eligibleFixtures($seasonId))]);
    }

    #[Route('/api/fixtures/league-validation', name: 'api_fixtures_league_validation_confirm', methods: ['POST'], priority: 10)]
    public function confirm(Request $request): JsonResponse
    {
        $seasonId = $this->guard($request);

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $confirmed = 0;
        foreach ($this->eligibleFixtures($seasonId) as $fixture) {
            // L'ancre MANUAL d'abord, puis le statut : setStatus(VALIDATED) TRAITE la
            // rencontre (REVIEWED + horodaté, aucun écart pendant par construction).
            $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
            $fixture->setStatus(FixtureStatus::VALIDATED, $now);
            ++$confirmed;
        }
        try {
            // Verrou optimiste `#[ORM\Version]` de Fixture : deux onglets ou un double-clic
            // font écrire le second sur une version périmée → 409 lisible plutôt qu'une 500.
            // Aucun double effet possible (transaction unique, le prédicat exclut déjà
            // VALIDATED) — c'est SEULEMENT le message qui doit rester actionnable.
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

        // Défense en profondeur (revue lot L) : l'isolation de saison ne doit PAS
        // reposer sur la seule activation conditionnelle du filtre Doctrine. Si aucune
        // saison ne se résout, `_season_id` est absent ET le filtre est éteint — un
        // `findBy([])` porterait alors sur TOUTES les saisons du club (archivées
        // comprises). On refuse franchement, et on scope la lecture sur cette saison.
        $seasonId = $request->attributes->get('_season_id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            throw new ConflictHttpException('Aucune saison active : impossible de valider les rencontres en lot.');
        }
        $this->socleGuard->assertSeasonPlanChosen($seasonId);

        return $seasonId;
    }

    /**
     * Les rencontres validables ligue de la saison résolue. Le scope tenant/saison est
     * déjà borné par les filtres Doctrine, mais on RÉPÈTE explicitement la saison dans
     * la requête (défense en profondeur, revue lot L) : l'écriture reste bornée à cette
     * saison même si le filtre venait à ne pas s'activer.
     *
     * @return list<Fixture>
     */
    private function eligibleFixtures(string $seasonId): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy(['seasonId' => $seasonId]);

        return array_values(array_filter($fixtures, $this->isEligible(...)));
    }

    /**
     * Le prédicat « validable ligue », MAISON UNIQUE partagée par le compte (GET) et
     * l'application (POST) : un domicile UNPLACED portant heure + gymnase identifié,
     * sans écart en attente.
     *
     * ⚠ DIVERGENCE ASSUMÉE avec le contrôle d'accès du geste UNITAIRE
     * ({@see FixtureStateProcessor::assertVenueAccessAllowed}, qui REFUSE une pose hors
     * des créneaux d'accès match déclarés du gymnase) : ce prédicat ne fait PAS ce
     * contrôle. C'est VOULU — la fédération a enregistré cette réalité (date, heure,
     * gymnase déjà joués/programmés côté FBI), l'application doit la refléter, pas la
     * nier. L'incohérence n'est pas tue : le radar de conflits la signale
     * (`ACCESS_WINDOW_LOST`, {@see MatchConflictDetector}). On valide, le radar alerte —
     * jamais on ne bloque une réalité fédérale. Figé par
     * {@see LeagueValidatedFixturesControllerTest::testAnOutOfAccessWindowHomeFixtureStaysEligibleAndTheRadarSignalsIt}.
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
