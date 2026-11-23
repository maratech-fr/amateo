<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\EventListener\CacheInvalidationListener;
use App\Plans\PlanComposition;
use App\Service\ScheduleConstraintBuilder;
use App\Service\TeamTagResolver;
use App\Service\TenantDoctrineScope;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — SÉMANTIQUE DE CONTRAINTE (axe structurant §7.1).
 *
 * Une contrainte ciblant une tranche d'âge doit atteindre EXACTEMENT les équipes de cette
 * tranche. Le tag n'est pas un libellé : `ScheduleConstraintBuilder::resolveTagToTeamIds`
 * l'éclate en N contraintes par équipe dans le payload du solveur, donc sa portée EST ce
 * que le solveur applique. Élargir un tag d'une catégorie, c'est imposer en silence une
 * règle à des équipes que le gestionnaire n'a jamais visées ; le rétrécir, c'est la retirer
 * à des équipes qu'il croit couvertes.
 *
 * ⚠ Ce test garde la FRONTIÈRE, là où le doute vit. Avant P4-42, `determineTagNames`
 * taguait EMB toute catégorie d'`ageMax <= 12` — U5 et U7 compris — alors que l'écran
 * annonçait « EMB (U9-U11) » (`tagLabels.ts`). Aucun test ne couvrait cette limite : on
 * pouvait la déplacer d'une année sans qu'une seule assertion rougisse.
 *
 * ⚠ Et « Baby basket » n'a AUCUN âge au catalogue : il ne portait donc aucun tag de
 * tranche et restait invisible à toute contrainte par âge. Sa présence sous BABY se
 * dérive de son NOM — un chemin distinct, qui mérite donc sa propre assertion.
 */
#[Group('phase1')]
#[Group('integration')]
final class TeamTagScopeTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TeamTagResolver $resolver;

    private Club $club;

    private Season $season;

    private Sport $sport;

    private PriorityTier $priorityTier;

    /** @var array<string, Team> nom de catégorie → l'équipe créée dedans */
    private array $teams = [];

    public function testEmbCoversU9AndU11Only(): void
    {
        // LE cas du lot. U5/U7 en sortent, U13 n'y est jamais entré (son `ageMax` vaut 13).
        self::assertSame(
            $this->sortedIds(['U9', 'U11']),
            $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()),
            'EMB doit valoir U9+U11 — ni plus (U5/U7), ni moins',
        );
    }

    public function testBabyCoversU5U7AndTheAgelessBabyCategory(): void
    {
        self::assertSame(
            $this->sortedIds(['U5', 'U7', 'Baby basket']),
            $this->resolver->tagTeamIds('BABY', $this->season->getId(), $this->club->getId()),
            'BABY doit valoir U5+U7 ET « Baby basket », que seul son nom rattache',
        );
    }

    public function testU13StaysYoungAndIsNeverBabyNorEmb(): void
    {
        // La borne EMB n'a pas glissé d'un cran en descendant : U13 reste JEUNE. Sans cette
        // assertion, remplacer `<= 7` par `<= 13` passerait les deux tests ci-dessus.
        $u13 = $this->teams['U13']->getId();
        self::assertContains($u13, $this->resolver->tagTeamIds('JEUNE', $this->season->getId(), $this->club->getId()));
        self::assertNotContains($u13, $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()));
        self::assertNotContains($u13, $this->resolver->tagTeamIds('BABY', $this->season->getId(), $this->club->getId()));
    }

    public function testLoisirHasNoAgeBracketTagAtAll(): void
    {
        // « Loisir » n'a pas d'âge NON PLUS, et son nom ne dit rien : il ne doit tomber dans
        // aucune tranche. C'est ce qui prouve que la règle de nom vise « baby » et n'avale
        // pas toute catégorie sans âge.
        $loisir = $this->teams['Loisir']->getId();
        foreach (['BABY', 'EMB', 'JEUNE', 'ADULTE', 'SENIOR'] as $bracket) {
            self::assertNotContains(
                $loisir,
                $this->resolver->tagTeamIds($bracket, $this->season->getId(), $this->club->getId()),
                \sprintf('« Loisir » ne doit pas tomber dans %s', $bracket),
            );
        }
    }

    /**
     * NR — Volet A « tags » : ADULTE (= +18) et SENIOR (= +22) SE CHEVAUCHENT.
     *
     * U21 (19-21) est ADULTE mais PAS SENIOR ; « Senior » (22-99) et « Vétéran » (35-99) portent
     * les DEUX. La frontière est 22 : la déplacer élargirait ou rétrécirait SENIOR en silence, sur
     * des contraintes qui peuvent être HARD.
     */
    public function testU21IsAdulteButNotSenior(): void
    {
        $u21 = $this->teams['U21']->getId();
        self::assertContains($u21, $this->resolver->tagTeamIds('ADULTE', $this->season->getId(), $this->club->getId()), 'U21 est ADULTE (+ de 18)');
        self::assertNotContains($u21, $this->resolver->tagTeamIds('SENIOR', $this->season->getId(), $this->club->getId()), 'U21 (19-21) n\'atteint pas la borne SENIOR (22)');
    }

    public function testSeniorAndVeteranAreBothAdulteAndSenior(): void
    {
        foreach (['Senior', 'Vétéran'] as $categoryName) {
            $teamId = $this->teams[$categoryName]->getId();
            self::assertContains($teamId, $this->resolver->tagTeamIds('ADULTE', $this->season->getId(), $this->club->getId()), \sprintf('« %s » est ADULTE', $categoryName));
            self::assertContains($teamId, $this->resolver->tagTeamIds('SENIOR', $this->season->getId(), $this->club->getId()), \sprintf('« %s » (ageMin >= 22) est aussi SENIOR', $categoryName));
        }
    }

    /**
     * NR — Volet A « tags » : COMPETITION = niveau NON loisir. Une équipe SANS level n'est PAS en
     * compétition (décision fondateur : ne jamais contraindre par défaut).
     */
    public function testCompetitionTagsOnlyLevelledNonLoisirTeams(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $competing = $this->createTeamInCategory('U15 compet', 14, 15, TeamLevel::REGIONAL);
        $loisir = $this->createTeamInCategory('U15 loisir', 14, 15, TeamLevel::LOISIR_JEUNE);
        // Les équipes du setUp n'ont AUCUN level : elles servent de témoin « sans level ».
        $noLevel = $this->teams['U13']->getId();
        $this->resolver->reset();

        $competition = $this->resolver->tagTeamIds('COMPETITION', $this->season->getId(), $this->club->getId());
        self::assertContains($competing->getId(), $competition, 'un niveau NON loisir (REGIONAL) → COMPETITION');
        self::assertNotContains($loisir->getId(), $competition, 'un niveau loisir → jamais COMPETITION');
        self::assertNotContains($noLevel, $competition, 'une équipe SANS level n\'est PAS en compétition');
    }

    /**
     * NR — P2-29 (axe constraint semantics §7.1). CIBLER PLUSIEURS TAGS = INTERSECTION,
     * EN EXCLURE = UNION SOUSTRAITE. Le foyer `resolveConstraintTeamIds` est ce que le
     * builder ET le gate appliquent : sa portée EST celle du solveur, cibles multiples et
     * exclusions comprises. Le cas réel du terrain est épinglé : « ADULTE sauf LOISIR_ADULTE »
     * doit donner les adultes EN COMPÉTITION (SM/SF/U21), jamais le Basket Santé loisir.
     */
    public function testResolveHonoursMultiTargetIntersectionAndExclusion(): void
    {
        $this->scopeGucToClub($this->club->getId());
        // Trois adultes en compétition + un Basket Santé loisir. ADULTE = ageMin >= 19 ;
        // COMPETITION = un niveau NON loisir ; LOISIR_ADULTE vient du niveau lui-même.
        $u21 = $this->createTeamInCategory('U21 compet', 19, 21, TeamLevel::REGIONAL);
        $sm = $this->createTeamInCategory('Senior M compet', 22, 99, TeamLevel::REGIONAL);
        $sf = $this->createTeamInCategory('Senior F compet', 22, 99, TeamLevel::NATIONAL);
        $sante = $this->createTeamInCategory('Basket santé', 19, 99, TeamLevel::LOISIR_ADULTE);
        $this->resolver->reset();

        $seasonId = $this->season->getId();
        $clubId = $this->club->getId();
        // La base D8 que le builder passerait pour cette scène — mes quatre équipes.
        $base = [$u21->getId(), $sm->getId(), $sf->getId(), $sante->getId()];

        // INTERSECTION : ADULTE ET COMPETITION → les trois en compétition, jamais le loisir.
        $intersection = $this->resolver->resolveConstraintTeamIds(
            ['targetTags' => ['ADULTE', 'COMPETITION']],
            $seasonId,
            $clubId,
            $base,
        );
        self::assertContains($u21->getId(), $intersection);
        self::assertContains($sm->getId(), $intersection);
        self::assertContains($sf->getId(), $intersection);
        self::assertNotContains($sante->getId(), $intersection, 'le Basket Santé n\'est pas en compétition');
        // Les équipes ADULTE SANS niveau du setUp (U21/Senior/Vétéran) ne sont PAS COMPETITION.
        self::assertNotContains($this->teams['Senior']->getId(), $intersection, 'une ADULTE sans niveau n\'est pas dans l\'intersection avec COMPETITION');

        // EXCLUSION — le cas terrain : ADULTE sauf LOISIR_ADULTE.
        $excluded = $this->resolver->resolveConstraintTeamIds(
            ['targetTags' => ['ADULTE'], 'excludeTags' => ['LOISIR_ADULTE']],
            $seasonId,
            $clubId,
            $base,
        );
        self::assertContains($u21->getId(), $excluded);
        self::assertContains($sm->getId(), $excluded);
        self::assertContains($sf->getId(), $excluded);
        self::assertNotContains($sante->getId(), $excluded, 'le loisir adulte est retiré de la cible ADULTE');

        // EXCLUSION SANS CIBLE (D8) : base = mes quatre équipes, moins le loisir.
        $excludeOnly = $this->resolver->resolveConstraintTeamIds(
            ['excludeTags' => ['LOISIR_ADULTE']],
            $seasonId,
            $clubId,
            $base,
        );
        $expected = [$u21->getId(), $sm->getId(), $sf->getId()];
        sort($expected);
        self::assertSame($expected, $excludeOnly, 'D8 : toutes les équipes de la base, moins les exclues — triées');

        // LEGACY : `targetTag` singulier reste équivalent à `targetTags: [x]` (rétro-compat).
        $legacy = $this->resolver->resolveConstraintTeamIds(['targetTag' => 'ADULTE'], $seasonId, $clubId, $base);
        self::assertContains($sante->getId(), $legacy, 'sans exclusion, ADULTE couvre AUSSI le Basket Santé');
    }

    /**
     * NR — P4-50 (b). ÉDITER LA CATÉGORIE DOIT DÉPLACER LES ÉQUIPES, pas les laisser en arrière.
     *
     * Les tags sont dérivés du NOM et des bornes d'âge de la `SportCategory` : corriger une
     * catégorie — geste normal, on ne retouche pas les équipes — doit déplacer ses équipes
     * sans qu'aucune ne soit réécrite (le calcul se fait à la lecture depuis la PR 3 du lot
     * conception 1 ; avant, un écouteur réécrivait les rattachements).
     *
     * Le scénario est celui du terrain : la catégorie a été créée « U11 » par erreur, elle
     * est en réalité U13. Après correction, une contrainte « groupe U13 » DOIT atteindre
     * l'équipe — sans le correctif elle n'atteint personne, pendant qu'une contrainte
     * « U11 » frappe une équipe qui n'est plus U11. Génération `COMPLETED`, zéro
     * diagnostic, planning faux : le pire mode de panne.
     *
     * ⚠ Les quatre assertions comptent, et les NÉGATIVES autant que les positives. Un
     * correctif qui ajouterait les nouveaux tags sans retirer les anciens laisserait l'équipe
     * dans les DEUX groupes — vert sur les positives seules.
     */
    public function testEditingACategoryRetagsItsTeams(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $equipe = $this->teams['U11']->getId();

        // Pré-état : l'équipe est bien U11+EMB. Sans ce témoin, un test vert ne prouverait
        // pas que quelque chose a BOUGÉ — il pourrait l'être depuis toujours.
        self::assertContains($equipe, $this->resolver->tagTeamIds('U11', $this->season->getId(), $this->club->getId()));
        self::assertContains($equipe, $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()));

        $categorie = $this->em->getRepository(SportCategory::class)->find($this->teams['U11']->getSportCategoryId());
        self::assertInstanceOf(SportCategory::class, $categorie);
        $categorie->setName('U13');
        $categorie->setAgeMin(12);
        $categorie->setAgeMax(13);
        // AUCUNE écriture sur la Team : c'est tout l'objet du test.
        $this->em->flush();

        // Le mémo du résolveur est par requête : il a mémorisé l'état d'AVANT.
        $this->resolver->reset();

        self::assertContains(
            $equipe,
            $this->resolver->tagTeamIds('U13', $this->season->getId(), $this->club->getId()),
            'la catégorie corrigée en U13 doit retaguer son équipe — sinon une contrainte « groupe U13 » ne l\'atteint pas',
        );
        self::assertContains(
            $equipe,
            $this->resolver->tagTeamIds('JEUNE', $this->season->getId(), $this->club->getId()),
            'U13 a ageMax 13 : la tranche passe d\'EMB à JEUNE',
        );
        self::assertNotContains(
            $equipe,
            $this->resolver->tagTeamIds('U11', $this->season->getId(), $this->club->getId()),
            'l\'ancien tag doit être RETIRÉ — sinon une contrainte « groupe U11 » frappe une équipe qui n\'en est plus',
        );
        self::assertNotContains(
            $equipe,
            $this->resolver->tagTeamIds('EMB', $this->season->getId(), $this->club->getId()),
            'l\'ancienne tranche doit être retirée elle aussi',
        );
    }

    /**
     * NR (sens des contraintes, décision F0 du lot conception 1) — une catégorie modifiée
     * change les groupes de TOUTES les saisons qui l'emploient, et la saison qui n'est pas celle
     * de la requête voit son empreinte de structure bouger : une version non validée s'y dit
     * périmée (`staleness.ts` ; un planning validé ne porte aucun bandeau, règle d'écran inchangée).
     *
     * La catégorie appartient au club, sans saison ; l'édition arrive par une requête d'UNE saison,
     * que `TenantDoctrineScope::scopeSeason` pose ici comme `TenantFilterListener` le fait. Une
     * étiquette ENREGISTRÉE au fil des écritures ne suivait que la saison de la requête : la saison
     * suivante gardait l'ancien groupe, la règle « U13 » y ratait l'équipe, sans message.
     */
    public function testEditingACategoryRegroupsItsTeamsInEverySeasonAndMovesTheirFingerprint(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $next = new Season;
        $next->setClubId($this->club->getId());
        $next->setName('2026-2027');
        $next->setStartDate(new DateTimeImmutable('2026-09-01'));
        $next->setEndDate(new DateTimeImmutable('2027-06-30'));
        $next->setStatus(SeasonStatus::DRAFT);
        $this->em->persist($next);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $next->getId());

        $nextTeam = new Team;
        $nextTeam->setClubId($this->club->getId());
        $nextTeam->setSeasonId($next->getId());
        $nextTeam->setSportCategoryId($this->teams['U11']->getSportCategoryId());
        $nextTeam->setPriorityTierId($this->priorityTier->getId());
        $nextTeam->setName('U11 saison suivante');
        $nextTeam->setIsActive(true);
        $this->em->persist($nextTeam);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($nextTeam->getSeasonId(), [$nextTeam->getId() => ['sessionsPerWeek' => 1]]);

        $rule = new Constraint;
        $rule->setClubId($this->club->getId());
        $rule->setSeasonId($next->getId());
        $rule->setName('Groupe U13 · pas avant 19:30');
        $rule->setScope(ConstraintScope::CLUB);
        $rule->setFamily(ConstraintFamily::TIME);
        $rule->setRuleType(ConstraintRuleType::HARD);
        $rule->setConfig(['targetTags' => ['U13'], 'minStartTime' => '19:30']);
        $this->em->persist($rule);
        $this->em->flush();

        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $before = $builder->buildForClubSeason($this->club->getId(), $next->getId());
        self::assertNotContains($nextTeam->getId(), $this->ruleTargets($before, $rule->getId()), 'témoin : U11, l\'équipe n\'est pas visée par la règle des U13');

        $scope = self::getContainer()->get(TenantDoctrineScope::class);
        $scope->scopeSeason($this->season->getId());
        $category = $this->em->getRepository(SportCategory::class)->find($this->teams['U11']->getSportCategoryId());
        self::assertInstanceOf(SportCategory::class, $category);
        $category->setName('U13');
        $category->setAgeMin(12);
        $category->setAgeMax(13);
        $this->em->flush();
        $this->em->getFilters()->disable('season_filter');
        // Fin de la requête d'édition : le cache des demandes du club est purgé.
        self::getContainer()->get(CacheInvalidationListener::class)->flushInvalidations();
        $this->resolver->reset();

        self::assertContains($nextTeam->getId(), $this->resolver->tagTeamIds('U13', $next->getId(), $this->club->getId()), 'la saison suivante suit la catégorie modifiée');
        self::assertNotContains($nextTeam->getId(), $this->resolver->tagTeamIds('U11', $next->getId(), $this->club->getId()), 'et quitte l\'ancien groupe');

        $after = $builder->buildForClubSeason($this->club->getId(), $next->getId());
        self::assertContains($nextTeam->getId(), $this->ruleTargets($after, $rule->getId()), 'la règle des U13 vise désormais l\'équipe de la saison suivante');
        self::assertNotSame(
            ScheduleConstraintBuilder::structureHash($before),
            ScheduleConstraintBuilder::structureHash($after),
            'l\'empreinte de la saison suivante bouge : une version non validée s\'y dit périmée',
        );
    }

    /**
     * NR BLOQUANT — ISOLATION ENTRE CLUBS (abus). L'identifiant d'une étiquette est son nom
     * (« U13 »), le même pour tous les clubs (lot conception 1, PR 3) : le groupe « U13 » d'un club
     * ne contient jamais l'équipe d'un autre, ni par le mémo d'un processus qui a servi l'autre
     * club, ni par une saison d'un autre club demandée en son nom.
     */
    public function testTagsNeverCarryAnotherClubsTeam(): void
    {
        $uid = uniqid('', true);
        $other = new Club;
        $other->setName('Autre club');
        $other->setSlug('tag-scope-autre-' . $uid);
        $other->setTimezone('Europe/Paris');
        $other->setLocale('fr');
        $other->setOnboardingCompleted(true);
        $this->em->persist($other);
        $this->em->flush();

        $this->scopeGucToClub($other->getId());
        $otherSeason = new Season;
        $otherSeason->setClubId($other->getId());
        $otherSeason->setName('2025-2026');
        $otherSeason->setStartDate(new DateTimeImmutable('2025-09-01'));
        $otherSeason->setEndDate(new DateTimeImmutable('2026-06-30'));
        $otherSeason->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($otherSeason);
        $otherCategory = new SportCategory;
        $otherCategory->setClubId($other->getId());
        $otherCategory->setSportId($this->sport->getId());
        $otherCategory->setName('U13');
        $otherCategory->setAgeMin(12);
        $otherCategory->setAgeMax(13);
        $otherCategory->setIsCustom(false);
        $otherCategory->setSortOrder(0);
        $this->em->persist($otherCategory);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $otherSeason->getId());
        $otherTeam = new Team;
        $otherTeam->setClubId($other->getId());
        $otherTeam->setSeasonId($otherSeason->getId());
        $otherTeam->setSportCategoryId($otherCategory->getId());
        $otherTeam->setPriorityTierId($this->priorityTier->getId());
        $otherTeam->setName('U13 de l\'autre club');
        $otherTeam->setIsActive(true);
        $this->em->persist($otherTeam);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($otherTeam->getSeasonId(), [$otherTeam->getId() => ['sessionsPerWeek' => 1]]);
        $this->em->flush();

        // Le processus sert d'abord l'autre club (un worker enchaîne les messages).
        $this->resolver->reset();
        self::assertSame([$otherTeam->getId()], $this->resolver->tagTeamIds('U13', $otherSeason->getId(), $other->getId()), 'témoin : l\'autre club a bien son U13');

        $this->scopeGucToClub($this->club->getId());
        self::assertSame(
            [$this->teams['U13']->getId()],
            $this->resolver->tagTeamIds('U13', $this->season->getId(), $this->club->getId()),
            'le U13 de ce club : son équipe, jamais celle de l\'autre club',
        );
        self::assertSame(
            [],
            $this->resolver->tagTeamIds('U13', $otherSeason->getId(), $this->club->getId()),
            'une saison de l\'autre club demandée au nom de ce club ne rend aucune équipe',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->resolver = $container->get(TeamTagResolver::class);

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Tag Scope Club');
        $this->club->setSlug('tag-scope-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->sport = new Sport;
        $this->sport->setName('Basketball');
        $this->sport->setSlug('bball-tag-' . $uid);
        $this->sport->setIsActive(true);
        $this->em->persist($this->sport);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            $this->priorityTier = $existing;
        } else {
            $this->priorityTier = new PriorityTier;
            $this->priorityTier->setId(1);
            $this->priorityTier->setLabel('S');
            $this->priorityTier->setName('Senior');
            $this->priorityTier->setColor('#FF0000');
            $this->priorityTier->setOrToolsWeight(100);
            $this->priorityTier->setDefaultMinSessions(2);
            $this->em->persist($this->priorityTier);
        }
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());

        // Les âges sont ceux du VRAI catalogue (`Service\Basketball\CategoryCatalog`) — un
        // jeu inventé testerait une frontière qui n'existe nulle part. « Baby basket » et
        // « Loisir » y sont bien sans âge, c'est le fait qui compte ici.
        foreach ([
            ['U5', 3, 5],
            ['U7', 6, 7],
            ['U9', 8, 9],
            ['U11', 10, 11],
            ['U13', 12, 13],
            // Volet A — les frontières ADULTE/SENIOR (bornes RÉELLES du catalogue).
            ['U21', 19, 21],
            ['Senior', 22, 99],
            ['Vétéran', 35, 99],
            ['Baby basket', null, null],
            ['Loisir', null, null],
        ] as [$name, $ageMin, $ageMax]) {
            $this->teams[$name] = $this->createTeamInCategory($name, $ageMin, $ageMax);
        }

        // Le mémo de `TeamTagResolver` est par requête/message : il aurait pu figer une
        // réponse d'avant la création des équipes. On repart propre.
        $this->resolver->reset();
    }

    /**
     * Les équipes visées par une règle de groupe dans une demande au moteur : la règle y est
     * éclatée en une ligne par équipe (`<id de règle>:<id d'équipe>`).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function ruleTargets(array $payload, string $ruleId): array
    {
        $targets = [];
        foreach (\is_array($payload['constraints'] ?? null) ? $payload['constraints'] : [] as $row) {
            if (\is_array($row) && str_starts_with((string) ($row['id'] ?? ''), $ruleId . ':')) {
                $targets[] = (string) ($row['scopeTargetId'] ?? '');
            }
        }

        return $targets;
    }

    /**
     * Crée la catégorie et son équipe. Les tags sont DÉRIVÉS de la catégorie, du sexe et du
     * niveau — on ne les écrit jamais à la main, sans quoi le test vérifierait ses propres
     * fixtures au lieu de la règle.
     */
    private function createTeamInCategory(string $categoryName, ?int $ageMin, ?int $ageMax, ?TeamLevel $level = null): Team
    {
        $this->scopeGucToClub($this->club->getId());

        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($this->sport->getId());
        $category->setName($categoryName);
        $category->setAgeMin($ageMin);
        $category->setAgeMax($ageMax);
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId($this->priorityTier->getId());
        $team->setName($categoryName . ' 1');
        if ($level instanceof TeamLevel) {
            $team->setLevel($level);
        }
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 1]]);
        $this->em->flush();

        return $team;
    }

    /**
     * Les ids attendus dans l'ordre du résolveur — `tagTeamIds` trie, et ce tri fait partie
     * de son contrat (il ordonne l'expansion du payload, donc le hash calculé dessus).
     *
     * @param list<string> $categoryNames
     *
     * @return list<string>
     */
    private function sortedIds(array $categoryNames): array
    {
        $ids = array_map(fn (string $name): string => $this->teams[$name]->getId(), $categoryNames);
        sort($ids);

        return $ids;
    }
}
