<?php

declare(strict_types=1);

use App\Tests\Behat\ClosureSegmentationContext;
use App\Tests\Behat\CoachWishesContext;
use App\Tests\Behat\ConflictResolutionContext;
use App\Tests\Behat\ConflictTruthContext;
use App\Tests\Behat\ConstraintHonoredContext;
use App\Tests\Behat\EngagedTeamContext;
use App\Tests\Behat\ExportContext;
use App\Tests\Behat\FbiCorrectionContext;
use App\Tests\Behat\FbiErrorLedgerContext;
use App\Tests\Behat\FixtureReviewContext;
use App\Tests\Behat\HolidayWeekContext;
use App\Tests\Behat\LeagueValidationContext;
use App\Tests\Behat\LockContext;
use App\Tests\Behat\MatchPlacementContext;
use App\Tests\Behat\OnboardingContext;
use App\Tests\Behat\OpponentAutoLocateContext;
use App\Tests\Behat\OpponentSuggestionContext;
use App\Tests\Behat\PeriodOverlayContext;
use App\Tests\Behat\RepriseWeekContext;
use App\Tests\Behat\SeasonGenerationContext;
use App\Tests\Behat\SoclePlansContext;
use App\Tests\Behat\StaleScheduleContext;
use App\Tests\Behat\TenantIsolationContext;
use App\Tests\Behat\TrainingBlockContext;
use App\Tests\Behat\VenueAliasContext;
use App\Tests\Behat\VenueAliasIdentityContext;
use App\Tests\Behat\VenueDeviationContext;
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
                new Suite('traitement')
                    ->withPaths('%paths.base%/features/une-rencontre-importee-dit-si-elle-est-traitee.feature')
                    ->withContexts(FixtureReviewContext::class),
            )
            ->withSuite(
                new Suite('fbi-a-corriger')
                    ->withPaths('%paths.base%/features/ce-que-fbi-doit-refleter.feature')
                    ->withContexts(FbiCorrectionContext::class),
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
            )
            ->withSuite(
                new Suite('regenerer')
                    ->withPaths('%paths.base%/features/le-planning-se-dit-a-regenerer.feature')
                    ->withContexts(StaleScheduleContext::class),
            )
            ->withSuite(
                new Suite('export')
                    ->withPaths('%paths.base%/features/l-export-du-planning.feature')
                    ->withContexts(ExportContext::class),
            )
            ->withSuite(
                new Suite('alias-gymnase')
                    ->withPaths('%paths.base%/features/un-domicile-importe-retrouve-son-gymnase.feature')
                    ->withContexts(VenueAliasContext::class),
            )
            ->withSuite(
                new Suite('ecart-salle-non-place')
                    ->withPaths('%paths.base%/features/un-domicile-non-place-dont-la-ligue-change-la-salle-est-arbitre.feature')
                    ->withContexts(VenueDeviationContext::class),
            )
            ->withSuite(
                new Suite('nom-fbi-gymnase')
                    ->withPaths('%paths.base%/features/le-nom-que-fbi-donne-a-un-gymnase.feature')
                    ->withContexts(VenueAliasIdentityContext::class),
            )
            ->withSuite(
                new Suite('conflits-verite')
                    ->withPaths('%paths.base%/features/les-conflits-d-un-match-disent-la-verite.feature')
                    ->withContexts(ConflictTruthContext::class),
            )
            ->withSuite(
                new Suite('conflits-resolution')
                    ->withPaths('%paths.base%/features/un-conflit-traite-reste-visible-mais-decompte.feature')
                    ->withContexts(ConflictResolutionContext::class),
            )
            ->withSuite(
                new Suite('erreur-fbi-registre')
                    ->withPaths('%paths.base%/features/une-erreur-fbi-alimente-le-registre.feature')
                    ->withContexts(FbiErrorLedgerContext::class),
            )
            ->withSuite(
                new Suite('suggestions-gymnases')
                    ->withPaths('%paths.base%/features/les-gymnases-d-un-adversaire-se-partagent-en-suggestions.feature')
                    ->withContexts(OpponentSuggestionContext::class),
            )
            ->withSuite(
                new Suite('gymnase-du-fichier')
                    ->withPaths('%paths.base%/features/le-gymnase-du-fichier-localise-l-adversaire.feature')
                    ->withContexts(OpponentAutoLocateContext::class),
            )
            ->withSuite(
                new Suite('validation-ligue')
                    ->withPaths('%paths.base%/features/un-club-en-cours-de-saison-valide-ses-matchs-en-lot.feature')
                    ->withContexts(LeagueValidationContext::class),
            ),
    );
