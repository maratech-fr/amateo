<?php

declare(strict_types=1);

use App\Tests\Behat\OnboardingContext;
use App\Tests\Behat\SeasonGenerationContext;
use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;

// Tests fonctionnels (Gherkin français) — scénarios métier exécutables,
// relus par le fondateur, joués contre la stack réelle (API seule, aucun
// navigateur, aucun noyau Symfony en mémoire). Lancés par `make -C backend behat`
// et par le job CI « Functional Tests (Behat) ».
//
// Une SUITE par feature, chacune reliée à SON fichier et à SON context : les
// définitions de steps d'un context ne peuvent alors pas entrer en collision
// avec celles d'un autre (chaque feature se joue seule et dans n'importe quel
// ordre — c'est la garantie qu'apportaient les smokes qu'elles remplacent).
return (new Config)
    ->withProfile(
        new Profile('default')
            ->withSuite(
                new Suite('generation')
                    ->withPaths('%paths.base%/features/generation-du-planning-de-saison.feature')
                    ->withContexts(SeasonGenerationContext::class),
            )
            ->withSuite(
                new Suite('onboarding')
                    ->withPaths('%paths.base%/features/inscription-et-premier-planning.feature')
                    ->withContexts(OnboardingContext::class),
            ),
    );
