<?php

declare(strict_types=1);

namespace App\Access;

/**
 * La sortie payante qu'une fonction de club produit, déclarée sur la fonction
 * (`#[ClubFunction(output: …)]`). En Découverte, chaque sortie OBTENUE prend un crédit du pool du
 * club ; demander ne coûte rien. Le refus « crédits épuisés » se juge avant le contrôleur, au
 * contrôle central ; le décompte, lui, se fait quand la sortie existe :
 *  - à la réponse (2xx) pour une sortie rendue dans la requête (`settledAtResponse()`) ;
 *  - dans le worker, dans la transaction qui livre le résultat, pour les autres.
 */
enum Output
{
    /** Vrai pour une sortie rendue dans la requête : son crédit se prend à la réponse 2xx. */
    public function settledAtResponse(): bool
    {
        return match ($this) {
            self::PLANNING_XLSX, self::MATCHES_PDF => true,
            self::SOLVE, self::MATCH_PLACEMENT, self::PLANNING_PDF => false,
        };
    }

    /** Une version calculée par le solveur : générer, régénérer, combler une période. */
    case SOLVE;

    /** Un placement automatique des matchs terminé. */
    case MATCH_PLACEMENT;

    /** Le PDF du planning, livré par le worker de rendu. */
    case PLANNING_PDF;

    /** Le tableur du planning, rendu dans la requête. */
    case PLANNING_XLSX;

    /** Le PDF de la liste des matchs, rendu dans la requête. */
    case MATCHES_PDF;
}
