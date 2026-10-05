<?php

declare(strict_types=1);

namespace App\Controller\Basketball;

use App\Entity\User;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Service\Basketball\FfbbLogoFetcherInterface;
use App\Storage\LogoStorage;
use finfo;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C7 — sert le logo FÉDÉRAL d'un adversaire, re-hébergé PARESSEUSEMENT au premier GET.
 *
 * ⚠ MEMBRE authentifié (≠ la route PUBLIQUE des logos club/ligue) : le logo est déduit du
 * code organisme d'un ADVERSAIRE de club, une donnée du module matchs — on ne l'expose donc
 * qu'à un membre connecté (catch-all `^/api` = IS_AUTHENTICATED_FULLY), jamais en anonyme.
 *
 * Octets stockés sous la clé namespacée `ffbb-opponent-{code}`. Absents : on télécharge une
 * fois depuis l'uuid `logo_id` que le résolveur a enregistré (jamais de hotlink, jamais de
 * fetch à la résolution), on stocke, on sert. Sans logo connu ou téléchargement en échec → 404.
 *
 * BCK-37 (lot robustesse) — deux garde-fous sur le chemin de TÉLÉCHARGEMENT, qui relançait
 * jusqu'ici `fetcher->download()` (timeout 8 s) à CHAQUE GET d'un code sans logo servable :
 *  - un **marqueur d'échec** (cache, TTL 24 h) : un téléchargement qui échoue pose le marqueur,
 *    et tant qu'il court on répond 404 SANS re-télécharger — plus de martèlement de l'hôte FFBB ;
 *  - un **limiteur `opponent_logo`** PAR UTILISATEUR, consommé UNIQUEMENT quand un vrai
 *    téléchargement va partir (jamais un logo déjà stocké, ni un code sans `logo_id`, ni un code
 *    sous marqueur) → une rafale de premiers affichages est bornée, le reste passe librement.
 */
#[AsController]
final class OpponentLogoController extends AbstractController
{
    /** TTL du marqueur d'échec (BCK-37) : un code dont le téléchargement a échoué n'est pas re-tenté pendant ce délai. */
    private const int MISS_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly LogoStorage $storage,
        private readonly FfbbLogoFetcherInterface $fetcher,
        private readonly OpponentDirectoryEntryRepository $directory,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly RateLimiterFactory $opponentLogoLimiter,
    ) {}

    #[Route('/api/opponents/{code}/logo', name: 'opponent_logo_serve', requirements: ['code' => '[A-Za-z0-9]{1,24}'], methods: ['GET'])]
    public function serve(string $code): Response
    {
        $key = \sprintf('ffbb-opponent-%s', $code);
        $bytes = $this->storage->read($key);
        if (null === $bytes) {
            $bytes = $this->rehost($code);
            if ($bytes instanceof Response) {
                return $bytes; // 404 (rien à servir) ou 429 (téléchargements trop fréquents)
            }
        }

        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Re-hébergement paresseux : octets téléchargés (et stockés), ou une Response terminale
     * (404 rien à servir / 429 trop de téléchargements). Pas de `logo_id` → 404 franc (lookup
     * DB bon marché, un logo résolu plus tard s'affiche aussitôt — le marqueur d'échec ne
     * couvre QUE les téléchargements RÉELS qui ont raté).
     */
    private function rehost(string $code): string|Response
    {
        $logoId = $this->directory->findOneByFfbbOrganismeCode($code)?->getLogoId();
        if (null === $logoId) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // Marqueur d'échec encore valide : on NE re-télécharge PAS (404 sans toucher FFBB).
        $missItem = $this->cache->getItem($this->missKey($code));
        if ($missItem->isHit()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // Limiteur consommé ICI SEULEMENT : un vrai téléchargement va partir.
        $user = $this->getUser();
        if ($user instanceof User && !$this->opponentLogoLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $bytes = $this->fetcher->download($logoId);
        if (null === $bytes) {
            // Échec : on pose le marqueur pour ne pas re-télécharger pendant le TTL.
            $missItem->set(true)->expiresAfter(self::MISS_TTL_SECONDS);
            $this->cache->save($missItem);

            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->storage->store(\sprintf('ffbb-opponent-%s', $code), $bytes);

        return $bytes;
    }

    private function missKey(string $code): string
    {
        // Le code est validé `[A-Za-z0-9]{1,24}` par la route — aucun caractère réservé PSR-6.
        return 'ffbb_logo_miss_' . $code;
    }
}
