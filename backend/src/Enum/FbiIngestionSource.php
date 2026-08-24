<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où est venue une ingestion de rencontres (RMM-4). FBI est le canal manuel du
 * xlsx (le seul chemin réel aujourd'hui — l'index `rencontres` de l'API FFBB est
 * vide pour les vrais clubs, cf. backend/docs/ffbb-api.md) ; FFBB_API est préparé
 * pour le jour où l'API portera un calendrier, jamais émis aujourd'hui.
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
