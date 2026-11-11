<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\EntryDeadlineOutlook;
use App\Service\FbiFixtureImporter;
use App\Service\FriendlyAutoValidator;
use App\Service\LeagueValidationOutlook;
use App\Service\ManagementAccessGuard;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La vue « Déjà dans FBI » (« validé ligue » en lot), PILOTÉE PAR LE DÉMARRAGE DU
 * CHAMPIONNAT (lot O). Un championnat COMMENCÉ n'a plus rien à confirmer : sa lecture par un
 * GESTIONNAIRE fait passer seuls ses domiciles datés « Déjà dans FBI », sans geste — comme le
 * dépôt d'un fichier FBI ({@see FbiFixtureImporter::import}) et la lecture de l'accueil
 * ({@see EntryDeadlineOutlook}), même maison unique du prédicat et de l'écriture
 * ({@see LeagueValidationOutlook::sweepStartedChampionships}). La réponse dit combien CETTE
 * lecture en a fait passer (`swept`) pour que le front rafraîchisse les rencontres.
 * Commencé = son ÉCHÉANCE DE SAISIE est passée (jour inclus) OU son PREMIER match est déjà
 * joué (une compétition sans échéance mais dont les dates sont tombées n'est plus provisoire).
 *
 * Pourquoi pas « heure + gymnase » seuls : en octobre, une nouvelle vague de matchs jeunes
 * arrive (nouvelle phase, nouvelle poule) avec des DATES PROVISOIRES, échéance non passée et
 * aucun match encore joué. Les verrouiller en ancres fixes au moment où il faut pouvoir les
 * bouger serait le piège. Tant qu'un championnat n'a pas commencé, on ne propose RIEN pour lui.
 *
 * Écriture : statut VALIDATED (horloge injectée) + source de placement MANUAL, par
 * {@see LeagueValidationOutlook::markLeagueValidated}. Le MANUAL n'est PAS cosmétique : la
 * formule du cadenas de la grille (`matches/lib/weekendGrid.ts`) et l'ancre FIXED du solveur
 * ({@see MatchPlacementPayloadBuilder::matchRow}) l'exigent. Rejouable : le prédicat exclut
 * VALIDATED. On ne DÉVALIDE jamais.
 *
 * Les amicaux sont HORS du lot : « validé ligue » n'a pas de sens sans championnat, et un
 * libellé amical (« Amical PNF ») en est exclu comme un domicile sans compétition. Ces
 * amicaux passés se valident seuls dans {@see FriendlyAutoValidator}, balayé AVANT les
 * championnats et compté dans `swept`.
 *
 * Gardes AVANT tout balayage, patron {@see ReviewFixturesController} : management + saison
 * écrivable + saison résolue + socle pointé.
 */
#[AsController]
final class LeagueValidatedFixturesController extends AbstractController
{
    public function __construct(
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
        [$seasonId, $swept] = $this->guard($request);

        return $this->json([...$this->outlook->compute($seasonId), 'swept' => $swept]);
    }

    /**
     * Les gardes, puis les balayages (amicaux passés PUIS championnats commencés).
     *
     * @return array{0: string, 1: int} la saison résolue (validée par le listener) et le nombre de rencontres balayées
     */
    private function guard(Request $request): array
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

        // L'appelant est GESTIONNAIRE (assertManager ci-dessus), la saison écrivable et le
        // socle en vigueur : les balayages peuvent écrire. Un Membre n'atteint jamais ce point.
        $clubId = $request->attributes->get('_club_id');
        $swept = $this->friendlyAutoValidator->sweep(\is_string($clubId) ? $clubId : null, $seasonId);
        $swept += $this->outlook->sweepStartedChampionships($seasonId, DateTimeImmutable::createFromInterface($this->clock->now()));

        return [$seasonId, $swept];
    }
}
