<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Service\ParcImport\ParcImportAnalyzer;
use App\Service\ParcImport\ParcImportGate;
use App\Service\ParcImport\ParcPayloadValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * POST /api/clubs/{id}/parc-import/analyze — le DRY-RUN de l'import de parc (lot correctif « G2 »).
 * Le gestionnaire colle le texte `parc-v2` (une ligne par case) rendu par son assistant ; le serveur
 * DÉRIVE les sections et répond, PAR SECTION, le statut d'appariement de chaque ligne (apparié /
 * suggéré / à créer / décoché / alerte) + un récap chiffré, un bloc de fiabilité et les alertes, et
 * N'ÉCRIT RIEN. C'est l'aperçu cochable sur lequel il tranche avant l'import réel.
 * Patron {@see ImportTeamsAnalyzeController}.
 *
 * Le corps EST le texte parc-v2 BRUT (`getContent()`, l'opération est `deserialize: false`) :
 * un JSON illisible produit un 422 nommé par {@see ParcPayloadValidator}, jamais un 400 de
 * désérialisation d'API Platform. Les refus octets (413) et dénombrement (422) suivent la gate.
 */
#[AsController]
final class ParcImportAnalyzeController extends AbstractController
{
    /** Bornes d'abus (cadrage G2) — le corps est refusé AVANT le parse au-delà de la taille. */
    private const int MAX_BODY_BYTES = 256 * 1024;

    /** parc-v2 : une ligne par case, au plus 500 cases par import (bombe). */
    private const int MAX_CASES = 500;

    public function __construct(
        private readonly ParcImportGate $gate,
        private readonly ParcPayloadValidator $validator,
        private readonly ParcImportAnalyzer $analyzer,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $club = $this->gate->gate($request, $id);
        if (!$club instanceof Club) {
            return $club;
        }

        $raw = $request->getContent();
        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->json(
                ['errors' => ['Le parc collé dépasse la taille autorisée — réduisez-le avant de réessayer.']],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $errors = $this->validator->errors($raw);
        if ([] !== $errors) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Le validateur a prouvé que c'est un objet JSON recevable.
        /** @var array<string, mixed> $parc */
        $parc = json_decode($raw, true);

        $countErrors = $this->boundsErrors($parc);
        if ([] !== $countErrors) {
            return $this->json(['errors' => $countErrors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $seasonId = $request->attributes->get('_season_id');

        return $this->json(
            $this->analyzer->analyze($parc, $club, \is_string($seasonId) ? $seasonId : ''),
            Response::HTTP_OK,
        );
    }

    /**
     * @param array<string, mixed> $parc
     *
     * @return list<string>
     */
    private function boundsErrors(array $parc): array
    {
        $errors = [];
        if ($this->count($parc, 'cases') > self::MAX_CASES) {
            $errors[] = \sprintf('Trop de cases : %d au maximum par import.', self::MAX_CASES);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $parc
     */
    private function count(array $parc, string $section): int
    {
        $value = $parc[$section] ?? null;

        return \is_array($value) ? \count($value) : 0;
    }
}
