<?php

declare(strict_types=1);

/*
 * Cliquet de couverture backend (P4-166, décision B3).
 *
 * PHPUnit 11 n'a AUCUN seuil natif (pas de --fail-under) : ce script tient le rôle.
 * Sans dépendance (pas de conteneur, pas d'autoload) : il lit le rapport clover produit
 * par `phpunit --coverage-clover`, en extrait la couverture de LIGNES (les métriques
 * `coveredstatements`/`statements` de l'élément <metrics> agrégé sous <project>),
 * la compare au plancher de la clé `backend` de `coverage-floor.json` (maison UNIQUE,
 * racine du dépôt — le plancher ne descend jamais, remonté dans la même PR quand la
 * mesure s'améliore), imprime le verdict et sort 1 si la mesure est SOUS le plancher.
 *
 * Les fonctions prennent leurs chemins en argument pour rester testables sans conteneur
 * (Unit/Scripts/CoverageGateTest) ; le bloc final ne s'exécute que lancé en direct.
 */

/**
 * Couverture de lignes (%), 0-100, depuis un rapport clover.
 */
function coverage_gate_line_percent(string $cloverPath): float
{
    if (!is_file($cloverPath)) {
        fwrite(\STDERR, "Rapport clover introuvable : {$cloverPath}\n");
        exit(1);
    }

    $xml = simplexml_load_file($cloverPath);
    if (false === $xml) {
        fwrite(\STDERR, "Rapport clover illisible : {$cloverPath}\n");
        exit(1);
    }

    // <coverage><project><metrics .../></project></coverage> : le <metrics> enfant
    // DIRECT de <project> porte les totaux agrégés (les autres vivent sous <file>).
    $metrics = $xml->xpath('/coverage/project/metrics');
    if (null === $metrics || [] === $metrics) {
        fwrite(\STDERR, "Élément <project>/<metrics> absent du clover : {$cloverPath}\n");
        exit(1);
    }

    $statements = (int) $metrics[0]['statements'];
    $covered = (int) $metrics[0]['coveredstatements'];

    if (0 === $statements) {
        return 0.0;
    }

    return $covered / $statements * 100;
}

/**
 * Plancher backend (entier 0-100) depuis coverage-floor.json.
 */
function coverage_gate_floor(string $floorPath): int
{
    if (!is_file($floorPath)) {
        fwrite(\STDERR, "Plancher introuvable : {$floorPath}\n");
        exit(1);
    }

    $floors = json_decode((string) file_get_contents($floorPath), true);
    if (!is_array($floors) || !array_key_exists('backend', $floors)) {
        fwrite(\STDERR, "Clé `backend` absente de {$floorPath}\n");
        exit(1);
    }

    $backend = $floors['backend'];
    if (!is_int($backend)) {
        fwrite(\STDERR, 'Le plancher backend doit être un entier, reçu : ' . var_export($backend, true) . "\n");
        exit(1);
    }

    return $backend;
}

/**
 * Imprime le verdict et renvoie le code de sortie (0 OK, 1 sous le plancher).
 */
function coverage_gate_verdict(float $percent, int $floor): int
{
    printf("Couverture backend : %.2f %% (plancher %d)\n", $percent, $floor);

    if ($percent + 1e-9 < $floor) {
        fwrite(\STDERR, sprintf("Couverture SOUS le plancher : %.2f %% < %d %%\n", $percent, $floor));

        return 1;
    }

    return 0;
}

// Point d'entrée : uniquement quand le script est lancé en direct (pas quand un test
// le `require`). Chemins par défaut ancrés sur __DIR__ (indépendants du cwd) :
// backend/scripts/ → ../coverage/backend.xml et ../../coverage-floor.json (racine).
if (\PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $cloverPath = $argv[1] ?? __DIR__ . '/../coverage/backend.xml';
    $floorPath = $argv[2] ?? __DIR__ . '/../../coverage-floor.json';

    $percent = coverage_gate_line_percent($cloverPath);
    $floor = coverage_gate_floor($floorPath);

    exit(coverage_gate_verdict($percent, $floor));
}
