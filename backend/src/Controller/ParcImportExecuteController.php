<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Service\ParcImport\ParcImportExecutor;
use App\Service\ParcImport\ParcImportGate;
use App\Service\ParcImport\ParcImportResolutionException;
use App\Service\ParcImport\ParcPayloadValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /api/clubs/{id}/parc-import — l'IMPORT RÉEL du parc (lot correctif « G2 »). Le gestionnaire a
 * analysé son parc (dry-run {@see ParcImportAnalyzeController}), ajusté l'aperçu et clique
 * « Importer » ; le serveur DÉRIVE les sections des cases et bascule les lignes cochées en écriture
 * TOUT-OU-RIEN.
 *
 * MÊME gate ({@see ParcImportGate}) et MÊMES bornes que l'analyse : 404 / 403 / 409 (saison et
 * socle EN VIGUEUR) / 429 / 413 / 422. Le corps est un objet `{payload, decisions}` :
 *  - `payload` : l'objet parc-v2, RE-VALIDÉ en forme ({@see ParcPayloadValidator}) et en bornes ;
 *  - `decisions` : la liste des choix de l'aperçu.
 * L'opération est `deserialize: false` (le contrôleur lit `getContent()` lui-même) et `read: false`.
 *
 * Succès → 200 `{recap: {<section>: {crees, apparies, misAJour, ignores}}}`. Une ligne cochée
 * invalide au moment de l'écriture → 422 `{errors: […]}` nommant la ligne, rien écrit.
 */
#[AsController]
final class ParcImportExecuteController extends AbstractController
{
    /** Bornes d'abus (cadrage G2) — le corps est refusé AVANT le parse au-delà de la taille. */
    private const int MAX_BODY_BYTES = 256 * 1024;

    /** parc-v2 : une ligne par case, au plus 500 cases par import (bombe). */
    private const int MAX_CASES = 500;

    public function __construct(
        private readonly ParcImportGate $gate,
        private readonly ParcPayloadValidator $validator,
        private readonly ParcImportExecutor $executor,
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

        $body = json_decode($raw, true);
        if (!\is_array($body) || !\array_key_exists('payload', $body) || !\is_array($body['payload'])) {
            return $this->json(
                ['errors' => ['Le corps de l\'import doit porter un objet « payload » (le parc) et une liste « decisions ».']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var array<string, mixed> $parc */
        $parc = $body['payload'];
        $decisions = $this->normalizeDecisions($body['decisions'] ?? null);

        // Le payload est re-validé en FORME, sur les mêmes octets que l'analyse.
        $errors = $this->validator->errors((string) json_encode($parc, \JSON_THROW_ON_ERROR));
        if ([] !== $errors) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $countErrors = $this->boundsErrors($parc);
        if ([] !== $countErrors) {
            return $this->json(['errors' => $countErrors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $seasonId = $request->attributes->get('_season_id');

        try {
            $recap = $this->executor->import($club, \is_string($seasonId) ? $seasonId : '', $parc, $decisions);
        } catch (ParcImportResolutionException $exception) {
            // D4 — refus de RÉSOLUTION agrégé : toutes les entités introuvables des lignes cochées,
            // nommées en une passe (rien écrit, la transaction est déjà annulée).
            return $this->json(['errors' => $exception->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnprocessableEntityHttpException $exception) {
            // Une ligne cochée invalide au moment de l'écriture : tout-ou-rien, la transaction est
            // déjà annulée, on nomme la ligne fautive.
            return $this->json(['errors' => [$exception->getMessage()]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($recap, Response::HTTP_OK);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeDecisions(mixed $decisions): array
    {
        if (!\is_array($decisions) || !array_is_list($decisions)) {
            return [];
        }
        $out = [];
        foreach ($decisions as $decision) {
            if (\is_array($decision)) {
                $out[] = $decision;
            }
        }

        return $out;
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
