<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

/*
 * Garde d'hygiène des dépendances backend (composer-unused).
 *
 * composer-unused signale un paquet de `require` dont aucun `use`/`new` n'apparaît dans `src/`.
 * Les 16 filtres ci-dessous sont des FAUX POSITIFS vérifiés : chacun est bien utilisé, mais pas
 * par un symbole PHP importé dans le code — il est câblé par bundle (bundles.php), par
 * configuration (DSN, YAML), par le runtime, ou par un autre paquet via l'injection de
 * dépendances. Chaque ligne porte sa raison. Tout AUTRE paquet devenu inutilisé doit rougir.
 */
return static fn (Configuration $config): Configuration => $config
        // Bundle API Platform (routes /api, providers/processors) — câblé par bundles.php, jamais importé.
        ->addNamedFilter(NamedFilter::fromString('api-platform/symfony'))
        // DoctrineMigrationsBundle — commandes doctrine:migrations:* — bundles.php.
        ->addNamedFilter(NamedFilter::fromString('doctrine/doctrine-migrations-bundle'))
        // NelmioCorsBundle — en-têtes CORS — bundles.php + nelmio_cors.yaml.
        ->addNamedFilter(NamedFilter::fromString('nelmio/cors-bundle'))
        // Extraction de métadonnées de docblock pour le serializer/property-info — service câblé en DI.
        ->addNamedFilter(NamedFilter::fromString('phpdocumentor/reflection-docblock'))
        // Parseur de phpdoc utilisé par l'extraction de types (property-info) — câblé en DI.
        ->addNamedFilter(NamedFilter::fromString('phpstan/phpdoc-parser'))
        // SentryBundle — rapport d'erreurs — bundles.php + sentry.yaml.
        ->addNamedFilter(NamedFilter::fromString('sentry/sentry-symfony'))
        // asset() dans les templates Twig + assets:install — pas de symbole PHP dans src/.
        ->addNamedFilter(NamedFilter::fromString('symfony/asset'))
        // Chargement des fichiers .env — câblé par le runtime/bootstrap, jamais importé.
        ->addNamedFilter(NamedFilter::fromString('symfony/dotenv'))
        // Plugin Composer (recettes Flex) — pas du code d'exécution.
        ->addNamedFilter(NamedFilter::fromString('symfony/flex'))
        // MercureBundle — publication SSE — bundles.php, hub câblé en DI.
        ->addNamedFilter(NamedFilter::fromString('symfony/mercure-bundle'))
        // MonologBundle — configuration du logging — bundles.php + monolog.yaml.
        ->addNamedFilter(NamedFilter::fromString('symfony/monolog-bundle'))
        // Transport Redis de Messenger — sélectionné par le DSN (messenger.yaml), jamais importé.
        ->addNamedFilter(NamedFilter::fromString('symfony/redis-messenger'))
        // Runtime qui amorce l'application (public/index.php via autoload_runtime) — jamais importé.
        ->addNamedFilter(NamedFilter::fromString('symfony/runtime'))
        // Traductions — câblées par config/DI, utilisées par Twig/validateurs, pas de symbole dans src/.
        ->addNamedFilter(NamedFilter::fromString('symfony/translation'))
        // TwigBundle — templating (emails, exports) — bundles.php.
        ->addNamedFilter(NamedFilter::fromString('symfony/twig-bundle'))
        // Analyse YAML des fichiers de configuration (config/*.yaml) — utilisé par le framework, pas dans src/.
        ->addNamedFilter(NamedFilter::fromString('symfony/yaml'));
