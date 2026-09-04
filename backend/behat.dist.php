<?php

declare(strict_types=1);

use App\Tests\Behat\SeasonGenerationContext;
use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;

// Tests fonctionnels (Gherkin français) — scénarios métier exécutables,
// relus par le fondateur, joués contre la stack réelle (API seule, aucun
// navigateur, aucun noyau Symfony en mémoire). Lancés par `make -C backend behat`
// et par le job CI « Functional Tests (Behat) ».
return (new Config)
    ->withProfile(
        new Profile('default')->withSuite(
            new Suite('generation')
                ->withPaths('%paths.base%/features')
                ->withContexts(SeasonGenerationContext::class),
        ),
    );
