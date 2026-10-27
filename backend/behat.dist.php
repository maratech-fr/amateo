<?php

declare(strict_types=1);

use App\Tests\Behat\ClosureStraddleContext;
use App\Tests\Behat\CoachLateRelayContext;
use App\Tests\Behat\CoachWishesContext;
use App\Tests\Behat\ConflictResolutionContext;
use App\Tests\Behat\ConflictTruthContext;
use App\Tests\Behat\ConstraintHonoredContext;
use App\Tests\Behat\DemoRetainedContext;
use App\Tests\Behat\ExportContext;
use App\Tests\Behat\FbiCorrectionContext;
use App\Tests\Behat\FbiErrorLedgerContext;
use App\Tests\Behat\FixtureReviewContext;
use App\Tests\Behat\InvitationContext;
use App\Tests\Behat\LeagueValidationContext;
use App\Tests\Behat\LockContext;
use App\Tests\Behat\MatchPlacementContext;
use App\Tests\Behat\OnboardingContext;
use App\Tests\Behat\ParcImportContext;
use App\Tests\Behat\PeriodLockSmallClubContext;
use App\Tests\Behat\PeriodOverlayContext;
use App\Tests\Behat\PlacedPersonConflictContext;
use App\Tests\Behat\SeasonGenerationContext;
use App\Tests\Behat\SeasonWorkLoopContext;
use App\Tests\Behat\SmallClubSmokeContext;
use App\Tests\Behat\SoclePlansContext;
use App\Tests\Behat\TeamsImportContext;
use App\Tests\Behat\TenantIsolationContext;
use App\Tests\Behat\TrainingBlockContext;
use App\Tests\Behat\VenueAliasContext;
use App\Tests\Behat\VenueAliasIdentityContext;
use App\Tests\Behat\VenueClosureSmallClubContext;
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
                new Suite('petit-club')
                    ->withPaths('%paths.base%/features/un-petit-club-se-genere-par-l-api.feature')
                    ->withContexts(SmallClubSmokeContext::class),
            )
            ->withSuite(
                new Suite('boucle-travail')
                    ->withPaths('%paths.base%/features/la-boucle-de-travail-du-gestionnaire.feature')
                    ->withContexts(SeasonWorkLoopContext::class),
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
                new Suite('fermeture-matchs')
                    ->withPaths('%paths.base%/features/une-fermeture-du-calendrier-s-applique-aux-matchs.feature')
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
                new Suite('retrait-seance')
                    ->withPaths('%paths.base%/features/retirer-une-seance-placee.feature')
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
                new Suite('verrou-periode')
                    ->withPaths('%paths.base%/features/un-verrou-de-periode-survit-a-la-regeneration.feature')
                    ->withContexts(PeriodLockSmallClubContext::class),
            )
            ->withSuite(
                new Suite('fermeture-planning')
                    ->withPaths('%paths.base%/features/une-fermeture-adapte-le-planning.feature')
                    ->withContexts(VenueClosureSmallClubContext::class),
            )
            ->withSuite(
                new Suite('fermeture-enjambe-vacances')
                    ->withPaths('%paths.base%/features/une-fermeture-se-prolonge-a-travers-les-vacances.feature')
                    ->withContexts(ClosureStraddleContext::class),
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
                new Suite('validation-ligue')
                    ->withPaths('%paths.base%/features/un-club-en-cours-de-saison-valide-ses-matchs-en-lot.feature')
                    ->withContexts(LeagueValidationContext::class),
            )
            ->withSuite(
                new Suite('import-equipes')
                    ->withPaths('%paths.base%/features/l-import-des-equipes-choisit-ses-lignes.feature')
                    ->withContexts(TeamsImportContext::class),
            )
            ->withSuite(
                new Suite('import-parc')
                    ->withPaths('%paths.base%/features/l-import-du-parc-en-un-geste.feature')
                    ->withContexts(ParcImportContext::class),
            )
            ->withSuite(
                new Suite('personne-deux-endroits')
                    ->withPaths('%paths.base%/features/une-personne-est-signalee-a-deux-endroits.feature')
                    ->withContexts(PlacedPersonConflictContext::class),
            )
            ->withSuite(
                new Suite('relais-adjoint')
                    ->withPaths('%paths.base%/features/le-relais-de-l-adjoint-tolere-un-retard.feature')
                    ->withContexts(CoachLateRelayContext::class),
            )
            ->withSuite(
                new Suite('demo-conservee')
                    ->withPaths('%paths.base%/features/le-club-demo-d-un-prospect-se-conserve.feature')
                    ->withContexts(DemoRetainedContext::class),
            )
            ->withSuite(
                new Suite('invitation')
                    ->withPaths('%paths.base%/features/une-invitation-fait-entrer-dans-le-club.feature')
                    ->withContexts(InvitationContext::class),
            ),
    );
