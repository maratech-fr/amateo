<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ImplicitRuleSetting;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamTag;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;
use App\Service\ScheduleResultImporter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — LE PLANNING PÉRIMÉ PAR UNE RESSOURCE (axe §7.1 : planning lifecycle).
 *
 * Troisième jumeau de `ConstraintChangeStaleScheduleTest` : une contrainte n'est pas la seule
 * entrée du solveur. Un gymnase, un coach, un créneau/grille, une réservation, un override de
 * période, un tag d'équipe ou une entrée de calendrier qui change APRÈS génération rend le
 * planning PÉRIMÉ — il décrit un état antérieur des DONNÉES, sans que rien ne le dise.
 *
 * L'invariant gardé ici, en base (pas au code retour) :
 *   - une ressource CLUB+SAISON modifiée marque les plannings COMPLETED du club+saison ;
 *   - un tag d'équipe (club-level, sans saison) marque les plannings du club ;
 *   - **le cœur du lot, ADR-0002 : la grille SAISON (créneau sans plan) ne périme QUE le plan
 *     SEASON, jamais les COPIES de période ; un créneau d'un plan de PÉRIODE ne périme QUE ce
 *     plan** — le périmètre se dérive de la colonne `schedule_plan_id`, mécaniquement ;
 *   - un override de période marque SON plan seul, pas le socle ;
 *   - un import de résultat solveur DÉMARQUE ;
 *   - la frontière saison tient ; un planning non COMPLETED n'est pas marqué ;
 *   - la LISTE FERMÉE : une écriture d'entité de RÉSULTAT (schedule_diagnostic, pipeline) ne
 *     marque RIEN — un marquage trop large use la bannière jusqu'à ce qu'on l'ignore ;
 *   - **la ressource écrite HORS API (EntityManager nu) marque quand même** — la preuve que
 *     c'est le listener d'entité qui attrape tout writer, jamais un appelant nommé.
 */
#[Group('phase1')]
#[Group('integration')]
final class ResourceChangeStaleScheduleTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ScheduleResultImporter $importer;

    public function testAResourceWrittenOutsideTheApiMarksCompletedSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        self::assertFalse($this->reload($schedule)->isResourcesChangedSinceGeneration());

        // ⚑ Chemin NON-API : on renomme le gymnase directement par l'EntityManager, comme le
        // ferait un import FFBB ou une commande. Si le marquage n'était branché que sur un
        // processeur d'API, ce cas resterait à false — et le bug reviendrait en silence.
        $this->renameVenueViaEntityManager($club, $season);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une ressource modifiée (même hors API) doit marquer les plannings générés comme périmés.',
        );
    }

    public function testATeamTagMarksTheClubSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        // Un tag d'équipe est club-level (pas de saison) : re-dériver un tag déplace la portée
        // d'une contrainte, donc périme. On marque le club entier (repli assumé, cf. listener).
        $tag = (new TeamTag)->setClubId($club->getId())->setName('Compétition');
        $this->em->persist($tag);
        $this->em->flush();

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un tag d\'équipe modifié périme les plannings du club (sa portée a pu bouger).',
        );
    }

    public function testASeasonGridChangeMarksTheSeasonPlanNotThePeriodPlan(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $this->em->flush();
        // Le montage lui-même écrit des ressources (gymnase, entrée de calendrier) qui marquent
        // légitimement : on repart d'une ardoise propre pour ne mesurer QUE le geste sous test.
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Un créneau de la grille SAISON (schedule_plan_id NULL, ADR-0002 : la grille de la
        // période est une COPIE, jamais une union). Il ne doit périmer QUE le socle.
        $this->trainingSlot($club, $season, $venueId, null);

        self::assertTrue(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Un créneau de la grille SAISON périme le plan SEASON.',
        );
        self::assertFalse(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'ADR-0002 : la grille SAISON ne périme PAS un plan de période (sa grille est une copie).',
        );
    }

    public function testAPeriodGridChangeMarksThatPlanNotTheSeasonPlan(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $periodPlanId = $periodSchedule->getSchedulePlanId();
        $this->em->flush();
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Un créneau PRÊTÉ au plan de période (schedule_plan_id = ce plan). Il ne doit périmer
        // QUE ce plan — inversement du test précédent, c'est la moitié « et inversement » d'ADR-0002.
        $this->trainingSlot($club, $season, $venueId, $periodPlanId);

        self::assertTrue(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'Un créneau du plan de période périme CE plan.',
        );
        self::assertFalse(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'ADR-0002 : un créneau de période ne périme PAS le socle de saison.',
        );
    }

    public function testUpdatingOnlyTheGroupLabelDoesNotMarkStale(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $this->em->flush();
        // Le créneau EXISTE déjà (sa création a légitimement marqué) : on repart d'une ardoise
        // propre pour ne mesurer QUE l'édition du libellé.
        $slotId = $this->persistedSeasonSlot($club, $season, $venueId)->getId();
        $this->resetMarkers($seasonSchedule);

        // Renommer le SEUL libellé de groupe (« CEC3 ») — esthétique : le solveur ne le consomme
        // pas, le planning reste fidèle. Le filtre du changeset ne doit donc pas périmer.
        $slot = $this->em->find(VenueTrainingSlot::class, $slotId);
        self::assertInstanceOf(VenueTrainingSlot::class, $slot);
        $slot->setGroupLabel('CEC3');
        $this->em->flush();

        self::assertFalse(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Renommer le seul libellé de groupe (esthétique) ne doit PAS périmer le planning — un « régénérez » serait un faux signal.',
        );
    }

    public function testUpdatingTheGroupLabelAndTheTimeMarksStale(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $this->em->flush();
        $slotId = $this->persistedSeasonSlot($club, $season, $venueId)->getId();
        $this->resetMarkers($seasonSchedule);

        // Le libellé accompagné d'un VRAI changement (l'heure) : le filtre ne doit pas AVALER le
        // vrai changement. Un créneau déplacé périme comme avant, libellé ou pas.
        $slot = $this->em->find(VenueTrainingSlot::class, $slotId);
        self::assertInstanceOf(VenueTrainingSlot::class, $slot);
        $slot->setGroupLabel('CEC3');
        $slot->setStartTime(new DateTimeImmutable('19:30'));
        $this->em->flush();

        self::assertTrue(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Un changement d\'heure (même accompagné d\'un libellé) périme le planning : le filtre esthétique ne doit avaler que le libellé seul.',
        );
    }

    public function testAPeriodOverrideMarksItsPlanOnly(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $periodPlanId = $periodSchedule->getSchedulePlanId();
        $this->em->flush();
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Un override de période porte TOUJOURS un schedule_plan_id (celui de sa période).
        $override = (new VenuePeriodOverride)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($periodPlanId)
            ->setVenueId($venueId);
        $this->em->persist($override);
        $this->em->flush();

        self::assertTrue(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'Un override de période périme le plan de sa période.',
        );
        self::assertFalse(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Un override de période ne périme pas le socle de saison.',
        );
    }

    public function testAnImportClearsTheStaleMarker(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        $this->renameVenueViaEntityManager($club, $season);
        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());

        // Un résultat solveur a été résolu contre les DONNÉES COURANTES → plus périmé.
        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning (il redevient fidèle aux données).',
        );
    }

    public function testAnotherSeasonPlanIsNotMarked(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        $this->renameVenueViaEntityManager($club, $season);

        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : un gymnase de la saison N ne périme pas un plan de la saison N+1.',
        );
    }

    public function testANonCompletedScheduleIsNotMarked(): void
    {
        [$club, $season] = $this->seed();
        $draft = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Brouillon')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($draft);
        $this->em->flush();

        $this->renameVenueViaEntityManager($club, $season);

        self::assertFalse(
            $this->reload($draft)->isResourcesChangedSinceGeneration(),
            'Un planning non généré (DRAFT) n\'a aucun résultat à périmer — on ne le marque pas.',
        );
    }

    public function testAnImplicitRuleSettingChangeMarksTheClubSeasonSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        // Régler une règle implicite (bien-être) change ce que le solveur applique → les plannings
        // COMPLETED du club+saison sont périmés. Season-scopé : la saison N+1 n'est pas touchée.
        $this->storeImplicitRule($club, $season, ImplicitRuleKey::COACH_REST_DAY, ImplicitRuleIntensity::PREFERRED);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un réglage de règle implicite modifié périme les plannings du club+saison.',
        );
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : un réglage de la saison N ne périme pas la saison N+1.',
        );
    }

    public function testAPeriodScopedImplicitRuleMarksThatPlanOnly(): void
    {
        [$club, $season] = $this->seed();
        $seasonSchedule = $this->seasonSchedule($club, $season);
        $periodSchedule = $this->periodSchedule($club, $season);
        $periodPlanId = $periodSchedule->getSchedulePlanId();
        $this->em->flush();
        // La naissance du plan a matérialisé ses 4 règles (SQL brut, sans listener) : on repart
        // d'une ardoise propre pour ne mesurer QUE la mutation d'une de ces copies.
        $this->resetMarkers($seasonSchedule, $periodSchedule);

        // Régler une règle bien-être de PÉRIODE (sa copie matérialisée) ne vaut que pour SON plan
        // → il ne périme QUE ce plan, jamais le socle (l'« et inversement » d'ADR-0002).
        $this->mutatePlanImplicitRule($periodPlanId, ImplicitRuleKey::COACH_REST_DAY, ImplicitRuleIntensity::PREFERRED);

        self::assertTrue(
            $this->reload($periodSchedule)->isResourcesChangedSinceGeneration(),
            'Un réglage bien-être de période périme le plan de sa période.',
        );
        self::assertFalse(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'ADR-0002 : un réglage bien-être de période ne périme PAS le socle de saison (sa copie lui est propre).',
        );
    }

    /**
     * P4-104 — un réglage bien-être de SAISON (plan NULL) marque le plan SEASON ET tout plan de
     * période LEGACY (sans copie propre : il suit encore la saison via `resolveForPlan`), mais PAS
     * un plan de période qui a matérialisé sa copie. Le resserrement est SÛR : un legacy n'est
     * JAMAIS raté (le rater servirait un planning COMPLETED périmé en silence). Falsification : si
     * le périmètre était le seul plan SEASON, le legacy resterait à false et rougirait ici ; s'il
     * restait le club+saison entier, le plan à copie serait marqué à tort et rougirait aussi.
     */
    public function testASeasonImplicitRuleMarksSeasonAndLegacyPeriodButNotTheCopiedPeriod(): void
    {
        [$club, $season] = $this->seed();
        $seasonSchedule = $this->seasonSchedule($club, $season);
        // Un plan de période né APRÈS la fonctionnalité : sa naissance matérialise ses 4 copies
        // (lignes à son id) → il ne suit plus la saison (ADR-0002).
        $copiedPeriod = $this->periodSchedule($club, $season);
        // Un plan de période LEGACY (né AVANT la copie) : on le simule en retirant ses 4 lignes
        // matérialisées → findByPlanIndexed vide, resolveForPlan retombe sur la portée saison.
        $legacyPeriod = $this->periodSchedule($club, $season, 'Vacances de Noël', '2025-12-20', '2026-01-04');
        $this->em->flush();
        $this->stripPlanImplicitRules($legacyPeriod->getSchedulePlanId());
        $this->resetMarkers($seasonSchedule, $copiedPeriod, $legacyPeriod);

        // Le geste sous test : régler une règle bien-être de SAISON (schedule_plan_id NULL).
        $this->storeImplicitRule($club, $season, ImplicitRuleKey::COACH_REST_DAY, ImplicitRuleIntensity::PREFERRED);

        self::assertTrue(
            $this->reload($seasonSchedule)->isResourcesChangedSinceGeneration(),
            'Un réglage bien-être de saison périme le plan SEASON (il lit les lignes plan-NULL).',
        );
        self::assertTrue(
            $this->reload($legacyPeriod)->isResourcesChangedSinceGeneration(),
            'P4-104 : un plan de période LEGACY (sans copie) suit la saison → périmé. Ne JAMAIS le rater.',
        );
        self::assertFalse(
            $this->reload($copiedPeriod)->isResourcesChangedSinceGeneration(),
            'P4-104 : un plan de période qui a sa copie ne suit plus la saison → PAS périmé.',
        );
    }

    public function testAnImportClearsTheMarkerAfterAnImplicitRuleChange(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        $this->storeImplicitRule($club, $season, ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS, ImplicitRuleIntensity::PREFERRED);
        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());

        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning après un réglage de règle implicite.',
        );
    }

    public function testATeamLinkMarksTheClubSeasonSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        // Une passerelle est STRUCTURE de club+saison (patron Team) : elle nourrit /generate pour
        // tous les plans du club+saison → périme. Season-scopé : la N+1 reste intacte.
        $this->storeTeamLink($club, $season);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une passerelle modifiée périme les plannings COMPLETED du club+saison.',
        );
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : une passerelle de la saison N ne périme pas la N+1.',
        );
    }

    public function testAnImportClearsTheMarkerAfterATeamLinkChange(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        $this->storeTeamLink($club, $season);
        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());

        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning après un changement de passerelle.',
        );
    }

    public function testAVenueTravelTimeMarksTheClubSeasonSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        // La matrice de trajet est STRUCTURE de club+saison (patron passerelle) : elle
        // nourrira /generate en PR-2 → périme dès PR-1. Season-scopé : la N+1 reste intacte.
        $this->storeTravelTime($club, $season);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un barème de trajet modifié périme les plannings COMPLETED du club+saison.',
        );
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : un barème de la saison N ne périme pas la N+1.',
        );
    }

    public function testAnImportClearsTheMarkerAfterATravelTimeChange(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        $this->storeTravelTime($club, $season);
        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());

        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning après un changement de barème de trajet.',
        );
    }

    public function testATeamMatchHabitChangeMarksTheClubSeasonSchedules(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        // RMM-5 PR-3 — le matchDay ÉMIS est dérivé des habitudes (max jour ISO) et entre dans le
        // payload /generate hashé : modifier une habitude périme les COMPLETED du club+saison.
        $this->storeHabit($club, $season, 6);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une habitude modifiée périme les plannings COMPLETED du club+saison (matchDay dérivé).',
        );
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : une habitude de la saison N ne périme pas la N+1.',
        );
    }

    public function testAMatchSlotRotationChangeMarksTheClubSeasonSchedules(): void
    {
        [$club, $season] = $this->seed();
        $venueId = $this->venue($club, $season);
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();
        // La création du gymnase marque légitimement : ardoise propre pour ne mesurer QUE la rotation.
        $this->resetMarkers($schedule);

        // Une rotation (créneau + ses membres) nourrit le matchDay dérivé de chaque membre → périme
        // le club+saison (hors plan de période, patron STRUCTURE).
        $this->storeRotation($club, $season, $venueId);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une rotation A/B (créneau + membres) périme les plannings COMPLETED du club+saison.',
        );
    }

    public function testAnImportClearsTheMarkerAfterAHabitChange(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        $this->storeHabit($club, $season, 6);
        self::assertTrue($this->reload($schedule)->isResourcesChangedSinceGeneration());

        $managed = $this->reload($schedule);
        $this->importer->import($managed, ['slots' => []]);

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un import de résultat solveur démarque le planning après un changement d\'habitude.',
        );
    }

    public function testAResultEntityWriteMarksNothing(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);
        $this->em->flush();

        // Entité de RÉSULTAT du pipeline (liste fermée) : écrire un diagnostic ne périme rien —
        // c'est une SORTIE du solveur, pas une donnée qu'il consomme. Un marquage ici userait
        // la bannière à chaque génération.
        $diagnostic = (new ScheduleDiagnostic)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())
            ->setType('INFEASIBLE')
            ->setSeverity(ScheduleDiagnosticSeverity::WARNING)
            ->setMessage('exemple');
        $this->em->persist($diagnostic);
        $this->em->flush();

        self::assertFalse(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Une écriture d\'entité de résultat (pipeline) ne doit RIEN marquer (liste fermée).',
        );
    }

    public function testASharedTrainingBlockChangeMarksTheSeasonPlan(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->seasonSchedule($club, $season);

        $otherSeason = $this->season($club, '2026-2027', '2026-09-01', '2027-06-30');
        $otherSchedule = $this->seasonSchedule($club, $otherSeason);
        $this->em->flush();

        // Un bloc de mutualisation SOCLE (plan NULL) change ce que le solveur place → le plan
        // SEASON du club+saison est périmé. Portée dérivée du plan (ADR-0002) : la N+1 est intacte.
        $this->storeSharedTrainingBlock($club, $season, null, 1);

        self::assertTrue(
            $this->reload($schedule)->isResourcesChangedSinceGeneration(),
            'Un bloc de mutualisation socle périme le plan SEASON du club+saison.',
        );
        self::assertFalse(
            $this->reload($otherSchedule)->isResourcesChangedSinceGeneration(),
            'La frontière saison tient : un bloc de la saison N ne périme pas la saison N+1.',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(ScheduleResultImporter::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = $this->season($club, '2025-2026', '2025-09-01', '2026-06-30');

        return [$club, $season];
    }

    private function season(Club $club, string $name, string $start, string $end): Season
    {
        $season = (new Season)->setClubId($club->getId())->setName($name)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        // Une vraie saison naît avec son plan SEASON (les 4 chemins de création le posent).
        $this->provisionSeasonPlan($season);

        return $season;
    }

    private function venue(Club $club, Season $season): string
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    private function seasonSchedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    /** Un planning COMPLETED d'un plan de PÉRIODE (holiday), via une CalendarEntry. */
    private function periodSchedule(Club $club, Season $season, string $title = 'Vacances de la Toussaint', string $start = '2025-10-18', string $end = '2025-11-02'): Schedule
    {
        $entry = (new CalendarEntry)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)
            ->setTitle($title)
            ->setStartDate(new DateTimeImmutable($start))
            ->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Sp')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());

        return $schedule;
    }

    private function trainingSlot(Club $club, Season $season, string $venueId, ?string $schedulePlanId): void
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setVenueId($venueId)
            ->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setCapacity(1)
            ->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Un créneau de la grille SAISON (schedule_plan_id NULL) DÉJÀ persisté — capacité 2 (un
     * groupe suppose deux équipes). Retourné pour que l'appelant le retrouve par son id après
     * un clear() et le MUTE (postUpdate), là où trainingSlot() ne teste que la naissance.
     */
    private function persistedSeasonSlot(Club $club, Season $season, string $venueId): VenueTrainingSlot
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setVenueId($venueId)
            ->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setCapacity(2)
            ->setSchedulePlanId(null);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    /**
     * Renomme le gymnase via l'EntityManager NU — le chemin d'une commande ou d'un import, PAS
     * le processeur d'API. C'est la falsification qui prouve la couverture du listener d'entité.
     */
    private function renameVenueViaEntityManager(Club $club, Season $season): void
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase renommé')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->em->clear();
    }

    private function storeImplicitRule(Club $club, Season $season, ImplicitRuleKey $ruleKey, ImplicitRuleIntensity $intensity): void
    {
        $setting = (new ImplicitRuleSetting)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setRuleKey($ruleKey)
            ->setIntensity($intensity);
        $this->em->persist($setting);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Mute une des 4 règles bien-être MATÉRIALISÉES d'un plan de période (postUpdate → markPlan).
     * On ne persiste PAS une nouvelle ligne : le plan porte déjà sa copie (née avec lui), un
     * INSERT de même portée+règle violerait l'unicité. C'est bien la MODIFICATION qu'on mesure.
     */
    private function mutatePlanImplicitRule(string $schedulePlanId, ImplicitRuleKey $ruleKey, ImplicitRuleIntensity $intensity): void
    {
        $row = $this->em->getRepository(ImplicitRuleSetting::class)->findOneBy(['schedulePlanId' => $schedulePlanId, 'ruleKey' => $ruleKey]);
        self::assertInstanceOf(ImplicitRuleSetting::class, $row, 'le plan doit porter sa copie de la règle (matérialisée à la naissance)');
        $row->setIntensity($intensity);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Simule un plan de période LEGACY (né avant la copie) : retire les lignes
     * `implicit_rule_setting` propres au plan (`schedule_plan_id` = ce plan) matérialisées à sa
     * naissance. Après ça, `findByPlanIndexed` est vide → `resolveForPlan` retombe sur la saison.
     */
    private function stripPlanImplicitRules(string $schedulePlanId): void
    {
        foreach ($this->em->getRepository(ImplicitRuleSetting::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function storeSharedTrainingBlock(Club $club, Season $season, ?string $schedulePlanId, int $commonSessions): void
    {
        $block = (new SharedTrainingBlock)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($schedulePlanId)
            ->setCommonSessions($commonSessions);
        $this->em->persist($block);
        $this->em->flush();
        $this->em->clear();
    }

    private function storeTeamLink(Club $club, Season $season): void
    {
        // Les teamAId/teamBId ne sont pas relus par le listener (dénormalisés) : deux uuids
        // suffisent à déclencher le marquage club+saison. Normalisés pour l'invariant du couple.
        $ids = [$this->uuid(), $this->uuid()];
        sort($ids);
        $link = (new TeamLink)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setTeamAId($ids[0])
            ->setTeamBId($ids[1])
            ->setLinkType(TeamLinkType::NOT_SIMULTANEOUS)
            ->setTrainingIntensity(TeamLinkIntensity::PREFERRED);
        $this->em->persist($link);
        $this->em->flush();
        $this->em->clear();
    }

    private function storeTravelTime(Club $club, Season $season): void
    {
        // Les venueAId/venueBId ne sont pas relus par le listener (colonnes club/saison
        // dénormalisées) : deux uuids suffisent à déclencher le marquage club+saison.
        $ids = [$this->uuid(), $this->uuid()];
        sort($ids);
        $travel = (new VenueTravelTime)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setVenueAId($ids[0])
            ->setVenueBId($ids[1])
            ->setDrivingMinutes(12);
        $this->em->persist($travel);
        $this->em->flush();
        $this->em->clear();
    }

    private function storeHabit(Club $club, Season $season, int $dayOfWeek): void
    {
        // Le teamId n'est pas relu par le listener (colonnes club/saison dénormalisées) : un uuid
        // suffit à déclencher le marquage club+saison.
        $habit = (new TeamMatchHabit)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setTeamId($this->uuid())
            ->setDayOfWeek($dayOfWeek)
            ->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->persist($habit);
        $this->em->flush();
        $this->em->clear();
    }

    private function storeRotation(Club $club, Season $season, string $venueId): void
    {
        $rotation = (new MatchSlotRotation)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setVenueId($venueId)
            ->setDayOfWeek(6)
            ->setKickoffTime(new DateTimeImmutable('20:30'));
        $this->em->persist($rotation);

        $position = 0;
        foreach ([$this->uuid(), $this->uuid()] as $teamId) {
            $member = (new MatchSlotRotationTeam)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setRotationId($rotation->getId())
                ->setTeamId($teamId)
                ->setPosition($position++);
            $this->em->persist($member);
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Ardoise propre : remet les marqueurs à false pour isoler le seul geste sous test. */
    private function resetMarkers(Schedule ...$schedules): void
    {
        // clear() AVANT de relire : un bulk UPDATE (le listener) ne synchronise pas l'identity
        // map — sans ça on relirait l'instance périmée (rc encore false en mémoire) et le
        // setter serait un no-op qui laisserait le true en base.
        $this->em->clear();
        foreach ($schedules as $schedule) {
            $managed = $this->em->find(Schedule::class, $schedule->getId());
            self::assertInstanceOf(Schedule::class, $managed);
            $managed->setResourcesChangedSinceGeneration(false);
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function reload(Schedule $schedule): Schedule
    {
        $this->em->clear();
        $found = $this->em->find(Schedule::class, $schedule->getId());
        self::assertInstanceOf(Schedule::class, $found);

        return $found;
    }
}
