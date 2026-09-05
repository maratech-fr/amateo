<?php

declare(strict_types=1);

use App\Tests\Behat\ClosureSegmentationContext;
use App\Tests\Behat\CoachWishesContext;
use App\Tests\Behat\ConstraintHonoredContext;
use App\Tests\Behat\EngagedTeamContext;
use App\Tests\Behat\HolidayWeekContext;
use App\Tests\Behat\LockContext;
use App\Tests\Behat\MatchPlacementContext;
use App\Tests\Behat\OnboardingContext;
use App\Tests\Behat\PeriodOverlayContext;
use App\Tests\Behat\RepriseWeekContext;
use App\Tests\Behat\SeasonGenerationContext;
use App\Tests\Behat\SoclePlansContext;
use App\Tests\Behat\TenantIsolationContext;
use App\Tests\Behat\TrainingBlockContext;
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
            )
            ->withSuite(
                new Suite('placement')
                    ->withPaths('%paths.base%/features/placement-des-matchs.feature')
                    ->withContexts(MatchPlacementContext::class),
            )
            ->withSuite(
                new Suite('overlay')
                    ->withPaths('%paths.base%/features/plan-de-periode-en-overlay.feature')
                    ->withContexts(PeriodOverlayContext::class),
            )
            ->withSuite(
                new Suite('voeux')
                    ->withPaths('%paths.base%/features/voeux-des-coachs.feature')
                    ->withContexts(CoachWishesContext::class),
            )
            ->withSuite(
                new Suite('socle')
                    ->withPaths('%paths.base%/features/le-socle-commande-les-plans.feature')
                    ->withContexts(SoclePlansContext::class),
            )
            ->withSuite(
                new Suite('bloc')
                    ->withPaths('%paths.base%/features/l-unite-de-placement-est-le-bloc.feature')
                    ->withContexts(TrainingBlockContext::class),
            )
            ->withSuite(
                new Suite('isolation')
                    ->withPaths('%paths.base%/features/un-club-ne-voit-jamais-un-autre-club.feature')
                    ->withContexts(TenantIsolationContext::class),
            )
            ->withSuite(
                new Suite('contrainte')
                    ->withPaths('%paths.base%/features/une-contrainte-saisie-est-honoree.feature')
                    ->withContexts(ConstraintHonoredContext::class),
            )
            ->withSuite(
                new Suite('verrou')
                    ->withPaths('%paths.base%/features/un-verrou-est-souverain.feature')
                    ->withContexts(LockContext::class),
            )
            ->withSuite(
                new Suite('perimetre')
                    ->withPaths('%paths.base%/features/le-perimetre-engage-est-protege.feature')
                    ->withContexts(EngagedTeamContext::class),
            )
            ->withSuite(
                new Suite('decoupage')
                    ->withPaths('%paths.base%/features/une-indisponibilite-se-decoupe-en-debut-milieu-fin.feature')
                    ->withContexts(ClosureSegmentationContext::class),
            )
            ->withSuite(
                new Suite('vacances')
                    ->withPaths('%paths.base%/features/une-semaine-de-vacances-couvre-lundi-vendredi.feature')
                    ->withContexts(HolidayWeekContext::class),
            )
            ->withSuite(
                new Suite('reprise')
                    ->withPaths('%paths.base%/features/la-semaine-de-reprise.feature')
                    ->withContexts(RepriseWeekContext::class),
            ),
    );
