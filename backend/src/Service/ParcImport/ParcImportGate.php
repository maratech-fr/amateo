<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use App\Service\TeamImportGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * La gate de l'import de parc (dry-run d'analyse, puis l'import réel). Patron
 * {@see TeamImportGate} : refus BYTE-IDENTIQUES, keyés sur le `{id}` du chemin.
 *
 * La séquence, dans l'ordre :
 *   1. pas d'adhésion active → 404 `{"error":"Club not found."}` (jamais 403, qui fuiterait
 *      l'existence du club) ;
 *   2. membre non-gestionnaire → 403 `{"error":"Forbidden."}` ;
 *   3. saison archivée → 409 ({@see SeasonAccessGuard::assertWritable}) ;
 *   4. socle EN VIGUEUR → 409 ({@see SocleGuard::assertSeasonPlanNotChosen}) — l'import de parc
 *      est un geste d'ONBOARDING, AVANT qu'un plan de saison soit pointé. C'est l'inverse de
 *      {@see TeamImportGate}, qui ne lance PAS le SocleGuard ;
 *   5. limiteur `parc_import` PAR UTILISATEUR → 429 (consommé APRÈS l'auth pour que 403/404/409
 *      gagnent d'abord) ;
 *   6/7. les bornes OCTETS (413) et DÉNOMBREMENT (422) vivent dans le contrôleur, après la gate :
 *      le corps n'est lu qu'une fois l'appelant autorisé et non throttlé.
 *
 * Rend le Club du tenant en succès, ou la JsonResponse de refus à relayer (404/403/429) ; les
 * 409 (saison/socle) sont levés en ConflictHttpException, comme dans TeamImportGate.
 */
final class ParcImportGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly Security $security,
        private readonly RateLimiterFactory $parcImportLimiter,
    ) {}

    public function gate(Request $request, string $id): Club|JsonResponse
    {
        // SEC-04 (patron TeamImportGate) : on garde sur l'adhésion active de l'appelant AVANT
        // toute vérification d'existence — sinon un 403 (club existant) vs 404 (uuid au hasard)
        // fuiterait quels clubs existent.
        $user = $this->security->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $id)
            : null;
        if (!$membership instanceof ClubUser) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        // Saison archivée (409) — APRÈS l'auth pour que 403 gagne d'abord.
        $this->seasonAccessGuard->assertWritable($request);

        // Socle EN VIGUEUR (409) : importer un parc est un geste d'onboarding, interdit tant que
        // le plan de saison pointe une version (rouvrir d'abord).
        $seasonId = $request->attributes->get('_season_id');
        $this->socleGuard->assertSeasonPlanNotChosen(\is_string($seasonId) ? $seasonId : null);

        $club = $this->entityManager->getRepository(Club::class)->find($id);
        if (!$club instanceof Club) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Anti-abus : borne PAR UTILISATEUR (JWT), consommée APRÈS l'auth. $user est ici
        // forcément un User (la garde de membership l'a établi).
        if (!$this->parcImportLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Trop d\'analyses de parc — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $club;
    }
}
