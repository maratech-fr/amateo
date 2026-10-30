<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Controller\ParcImportExecuteController;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * PR A (D4) — le refus de RÉSOLUTION agrégé de l'import du parc. Contrairement aux gardes d'écriture
 * (chevauchement, capacité, Σ séances, cap), fail-fast chacune via {@see UnprocessableEntityHttpException},
 * cette exception agrège TOUTES les équipes/gymnases introuvables des lignes cochées (toutes sections)
 * en UNE passe, chaque message nommant l'équipe/le gymnase ET sa case. Levée dans la transaction
 * d'import (donc rien n'est écrit), elle est catchée par {@see ParcImportExecuteController}
 * qui la rend en `{errors: […]}` (422).
 */
final class ParcImportResolutionException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Des lignes cochées citent des entités introuvables.');
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
