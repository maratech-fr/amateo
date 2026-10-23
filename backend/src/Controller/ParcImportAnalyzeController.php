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
 * POST /api/clubs/{id}/parc-import/analyze — le DRY-RUN de l'import de parc (chantier G).
 * Le gestionnaire colle le texte `parc-v1` rendu par son assistant ; le serveur répond, PAR
 * SECTION, le statut d'appariement de chaque ligne (apparié / suggéré / à créer / décoché /
 * alerte) + un récap chiffré, et N'ÉCRIT RIEN. C'est l'aperçu cochable sur lequel il tranche
 * avant l'import réel (PR suivante). Patron {@see ImportTeamsAnalyzeController}.
 *
 * Le corps EST le texte parc-v1 BRUT (`getContent()`, l'opération est `deserialize: false`) :
 * un JSON illisible produit un 422 nommé par {@see ParcPayloadValidator}, jamais un 400 de
 * désérialisation d'API Platform. Les refus octets (413) et dénombrement (422) suivent la gate.
 */
#[AsController]
final class ParcImportAnalyzeController extends AbstractController
{
    /** Bornes d'abus (cadrage G) — le corps est refusé AVANT le parse au-delà de la taille. */
    private const int MAX_BODY_BYTES = 256 * 1024;

    private const int MAX_TEAMS = 60;

    private const int MAX_VENUES = 20;

    private const int MAX_SLOTS_AND_RESERVATIONS = 500;

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
        if ($this->count($parc, 'equipes') > self::MAX_TEAMS) {
            $errors[] = \sprintf('Trop d\'équipes : %d au maximum par import.', self::MAX_TEAMS);
        }
        if ($this->count($parc, 'gymnases') > self::MAX_VENUES) {
            $errors[] = \sprintf('Trop de gymnases : %d au maximum par import.', self::MAX_VENUES);
        }
        if ($this->count($parc, 'creneaux') + $this->count($parc, 'reservations') > self::MAX_SLOTS_AND_RESERVATIONS) {
            $errors[] = \sprintf('Trop de créneaux et de réservations : %d au maximum par import.', self::MAX_SLOTS_AND_RESERVATIONS);
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
