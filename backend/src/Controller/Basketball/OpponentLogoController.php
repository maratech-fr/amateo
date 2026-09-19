<?php

declare(strict_types=1);

namespace App\Controller\Basketball;

use App\Repository\OpponentDirectoryEntryRepository;
use App\Service\Basketball\FfbbLogoFetcher;
use App\Storage\LogoStorage;
use finfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
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
 */
#[AsController]
final class OpponentLogoController extends AbstractController
{
    public function __construct(
        private readonly LogoStorage $storage,
        private readonly FfbbLogoFetcher $fetcher,
        private readonly OpponentDirectoryEntryRepository $directory,
    ) {}

    #[Route('/api/opponents/{code}/logo', name: 'opponent_logo_serve', requirements: ['code' => '[A-Za-z0-9]{1,24}'], methods: ['GET'])]
    public function serve(string $code): Response
    {
        $key = \sprintf('ffbb-opponent-%s', $code);
        $bytes = $this->storage->read($key);
        if (null === $bytes) {
            // Re-hébergement paresseux : on télécharge une fois depuis le logo_id enregistré.
            $logoId = $this->directory->findOneByFfbbOrganismeCode($code)?->getLogoId();
            if (null !== $logoId) {
                $bytes = $this->fetcher->download($logoId);
                if (null !== $bytes) {
                    $this->storage->store($key, $bytes);
                }
            }
        }
        if (null === $bytes) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
