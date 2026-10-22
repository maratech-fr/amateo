<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FbiFixtureImporter;
use App\Service\FriendlyAutoValidator;
use App\Service\LeagueValidationOutlook;
use App\Service\ManagementAccessGuard;
use App\Service\MatchPlacementPayloadBuilder;
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
 * « Validé ligue » en lot, PILOTÉ PAR LE DÉMARRAGE DU CHAMPIONNAT (lot O). Ce geste est
 * désormais un RATTRAPAGE : au DÉPÔT d'un fichier FBI, l'auto-validation bascule DÉJÀ les
 * domiciles des championnats commencés ({@see FbiFixtureImporter::import}, même maison
 * unique du prédicat et de l'écriture). Ce POST couvre le cas où l'échéance tombe APRÈS le
 * dépôt (le championnat mûrit entre deux passages) — un club qui démarre l'application en
 * cours de saison le rejoue pour basculer d'un coup les domiciles désormais mûrs.
 * N'est concerné QUE un championnat déjà COMMENCÉ : son ÉCHÉANCE DE SAISIE est passée (jour
 * inclus — « les dates peuvent être validées à partir de la date d'échéance », décision
 * fondateur) OU son PREMIER match est déjà joué (une compétition sans échéance mais dont
 * les dates sont tombées n'est plus provisoire).
 *
 * Pourquoi pas « heure + gymnase » seuls : en octobre, une nouvelle vague de matchs jeunes
 * arrive (nouvelle phase, nouvelle poule) avec des DATES PROVISOIRES, échéance non passée et
 * aucun match encore joué. Les verrouiller en ancres fixes au moment où il faut pouvoir les
 * bouger serait le piège. Tant qu'un championnat n'a pas commencé, on ne propose RIEN pour lui.
 *
 * Au DÉPÔT, une rencontre d'un championnat commencé naît AVEC son gymnase (alias confirmé)
 * puis bascule « validé ligue » tout de suite ({@see FbiFixtureImporter::attachConfirmedVenue}
 * pour le gymnase, puis l'auto-validation du même import) : le placement d'office au sens
 * de la grille reste exclu, mais le statut VALIDATED, lui, EST désormais posé au dépôt pour
 * ces domiciles. Ce POST ne fait que RATTRAPER ce que le dépôt n'a pas encore pu valider
 * (championnat mûri entre deux passages).
 *
 * Toute la LECTURE, le prédicat ET l'écriture (maison unique, partagée avec le cockpit et le
 * dépôt FBI) vivent dans {@see LeagueValidationOutlook} ; ce contrôleur ne porte que les
 * gardes, le balayage des amicaux passés ({@see FriendlyAutoValidator}) et le pilotage du
 * flush. Deux routes :
 *   - GET rend la lecture détaillée par championnat commencé + les rencontres à traiter + les
 *     championnats sans échéance PAS ENCORE commencés (à renseigner) + le total à valider ;
 *   - POST applique. Il est SANS CORPS : le serveur RECALCULE les domiciles à basculer au
 *     moment de l'application (l'horloge injectée + les échéances du moment font foi). Aucun
 *     championnat choisi par le client.
 *
 * Application : statut VALIDATED (horloge injectée) + source de placement MANUAL, par
 * {@see LeagueValidationOutlook::markLeagueValidated}. Le MANUAL n'est PAS cosmétique : la
 * formule du cadenas de la grille (`matches/lib/weekendGrid.ts`) et l'ancre FIXED du solveur
 * ({@see MatchPlacementPayloadBuilder::matchRow}) l'exigent. Rejouable : le prédicat exclut
 * VALIDATED. On ne DÉVALIDE jamais.
 *
 * Les amicaux sont HORS du lot : « validé ligue » n'a pas de sens sans championnat, et un
 * libellé amical (« Amical PNF ») en est exclu comme un domicile sans compétition. Ces
 * amicaux passés se valident seuls dans {@see FriendlyAutoValidator} (invoqué par la garde,
 * l'appelant étant gestionnaire).
 *
 * Patron {@see ReviewFixturesController} : management + saison écrivable + socle pointé.
 */
#[AsController]
final class LeagueValidatedFixturesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeagueValidationOutlook $outlook,
        private readonly FriendlyAutoValidator $friendlyAutoValidator,
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

        return $this->json($this->outlook->compute($seasonId));
    }

    #[Route('/api/fixtures/league-validation', name: 'api_fixtures_league_validation_confirm', methods: ['POST'], priority: 10)]
    public function confirm(Request $request): JsonResponse
    {
        $seasonId = $this->guard($request);

        // On RECALCULE les domiciles à basculer MAINTENANT (maison unique du prédicat) :
        // l'horloge et les échéances du moment font foi, jamais ce que l'écran affichait.
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $confirmed = 0;
        foreach ($this->outlook->fixturesToConfirm($seasonId) as $fixture) {
            // Écriture par la maison unique (partagée avec l'auto-validation au dépôt) :
            // ancre MANUAL puis statut VALIDATED (qui TRAITE la rencontre).
            $this->outlook->markLeagueValidated($fixture, $now);
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

        // L'appelant est GESTIONNAIRE (assertManager ci-dessus) : c'est le point où les
        // amicaux passés se valident tout seuls (domicile ET extérieur), le geste chiffré
        // ne concernant QUE les championnats. Un Membre n'atteint jamais cette route.
        $clubId = $request->attributes->get('_club_id');
        $this->friendlyAutoValidator->sweep(\is_string($clubId) ? $clubId : null, $seasonId);

        return $seasonId;
    }
}
