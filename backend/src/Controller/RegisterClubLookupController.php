<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Basketball\FfbbClubDirectory;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-298 — « c'est bien votre club ? » à l'inscription : le NOM (et la ville) du club
 * derrière un code FFBB, pour un retour immédiat pendant la saisie. PUBLIC par le préfixe
 * `^/api/register` de security.yaml (aucune route à ouvrir).
 *
 * AFFICHAGE SEUL : la réponse ne pré-remplit JAMAIS `club_name` (décision fondateur) — le
 * NOM reste celui que la FFBB imposera à la création (FfbbClubPopulator). La forme est
 * construite champ par champ, trois états francs et SANS mail :
 *  - `{status:"found", name, city}` — correspondance EXACTE (city nullable) ;
 *  - `{status:"unknown"}`           — code au bon format mais inconnu, ou format invalide ;
 *  - `{status:"unavailable"}`       — FFBB muette OU vérification désactivée (flag off) :
 *                                     le front n'affiche alors RIEN (non bloquant).
 *
 * Défense : rate-limit PAR IP (surface anonyme énumérable, patron coach_wish_public) ; gating
 * par le flag `app.ffbb_register_existence_check` — off en dev/test/Behat/e2e (codes
 * synthétiques) → `unavailable` SANS aucun appel sortant ; cache court (15 min) des réponses
 * DÉFINITIVES (found/unknown), jamais d'un `unavailable` (panne transitoire).
 */
#[AsController]
final class RegisterClubLookupController extends AbstractController
{
    /** Cache des réponses définitives — 15 min : un club change rarement de nom. */
    private const int CACHE_TTL = 900;

    public function __construct(
        private readonly FfbbClubDirectory $ffbbClubDirectory,
        private readonly RateLimiterFactory $registerClubLookupLimiter,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        // Même flag que la garde d'existence du register : off en dev/test/démos, on en prod.
        #[Autowire(param: 'app.ffbb_register_existence_check')]
        private readonly bool $ffbbRegisterExistenceCheck,
    ) {}

    #[Route('/api/register/club-lookup', name: 'api_register_club_lookup', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Clé PAR IP (repli explicite si null, sinon tout tomberait dans le même compartiment).
        if (!$this->registerClubLookupLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }

        $code = strtoupper(trim((string) $request->query->get('code', '')));

        // Flag off (dev/test/Behat/e2e) → `unavailable` sans jamais sortir vers la FFBB.
        if (!$this->ffbbRegisterExistenceCheck) {
            return $this->json(['status' => 'unavailable']);
        }

        $result = $this->lookup($code);

        // Champ par champ : found porte name+city, les autres n'exposent QUE leur statut.
        if ('found' === $result['status']) {
            return $this->json(['status' => 'found', 'name' => (string) $result['name'], 'city' => $result['city']]);
        }

        return $this->json(['status' => $result['status']]);
    }

    /**
     * Cache court des réponses DÉFINITIVES (found/unknown) ; `unavailable` n'est jamais
     * mis en cache — une panne transitoire ne doit pas figer 15 min de silence.
     *
     * @return array{status: string, name: string|null, city: string|null}
     */
    private function lookup(string $code): array
    {
        $item = $this->cache->getItem('register_club_lookup.' . sha1($code));
        if ($item->isHit()) {
            /** @var array{status: string, name: string|null, city: string|null} $cached */
            $cached = $item->get();

            return $cached;
        }

        $result = $this->ffbbClubDirectory->lookupIdentity($code);
        if ('unavailable' !== $result['status']) {
            $item->set($result)->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        return $result;
    }
}
