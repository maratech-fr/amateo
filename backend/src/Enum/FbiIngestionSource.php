<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où est venue une ingestion de rencontres (RMM-4). FBI_XLSX est le canal
 * manuel du dépôt (la source qui FAIT FOI) ; FFBB_API est émis par le canal de
 * vérification à la demande (FfbbRencontreReconciler::apply — l'index public ne
 * porte que ce que la fédé publie, amicaux surtout, cf. backend/docs/ffbb-api.md).
 *
 * La hiérarchie (refonte-module-matchs.md §4 fait #1) vit ici : SEUL un dépôt
 * FBI_XLSX tue ou reporte une trace de réconciliation (une ingestion API ne
 * touche jamais une trace née d'un dépôt).
 */
enum FbiIngestionSource: string
{
    use HasValues;

    case FBI_XLSX = 'FBI_XLSX';
    case FFBB_API = 'FFBB_API';
}
