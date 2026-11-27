<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Person;
use App\Entity\PlanTeam;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Plans\PlanStates;
use App\Service\ScheduleConstraintBuilder;
use App\Structure\Family;
use App\Structure\Level;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestPlanSettingsDecorTrait;
use App\Tests\EngineRequestRunnerTrait;
use App\Tests\EngineRequestSocleNetDecorTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes *constraint semantics* et *generation pipeline* (lot 5, PR 2, ligne 8 du plan, §3.3) : chaque clé
 * de la demande au moteur a UNE source, libre, froide ou à chaud, et se comporte comme cette source le dit :
 *  - libre (nom, contact, adjoint, case salarié, véhiculé, jours maximum, temps de trajet, libellé) : ni dans
 *    l'empreinte (la changer ne périme rien), ni dans la copie d'une version (son champ n'est pas déclaré froid) ;
 *  - froide (séances, grille, réservations, règles, coach principal, coach-joueur, passerelles, groupes, rang,
 *    gymnase imposé) : dans l'empreinte ET dans la copie (son champ est déclaré froid sur sa famille) ;
 *  - à chaud (âge d'une catégorie, jour de match, faits du calendrier, verrous d'une version, enveloppe) : dans
 *    l'empreinte seule.
 * Une clé sans source fait échouer le test : une clé neuve de la demande se range ici, ou rougit.
 *
 * Les demandes sont les références figées 11, 12, 13 (génération) et 5 (placement) de
 * {@see EngineRequestReferenceTest} : ce test lit leurs fichiers, jamais une demande recalculée, et ne lit jamais la
 * liste des clés libres du code — {@see self::SOURCES} est écrite ici à la main (sinon une clé oubliée côté code le
 * serait aussi ici, et le test resterait vert). L'empreinte est {@see ScheduleConstraintBuilder::structureHash} ; la
 * demande de placement n'a pas d'empreinte, ses clés ne doivent qu'avoir une source.
 *
 * Deux vérités de LIGNE que la table par clé ne peut pas dire : un adjoint (ligne `TEAM_COACH` de rôle `ASSISTANT`) est
 * libre quand un coach principal est froid ; un temps de trajet (absent des quatre décors) est libre.
 *
 * Angle jumeau, cité : `la-boucle-de-travail-du-gestionnaire.feature` (les mêmes gestes, par l'écran « périmé ») ; la
 * copie elle-même : `Integration/Plans/PlanStatesTest`.
 *
 * Cas « non validé » (lot 5, PR 5, ligne 26 du plan) : ce qui lit l'en vigueur ne voit pas le froid modifié sans
 * validation. Sur les décors réels des références 12, 13 (demande d'une période), 5, 6 et 21 (placement des matchs), le
 * socle est validé (copie prise sur l'état du décor), puis un champ froid de chaque famille du socle est modifié en
 * base sans valider ; la demande reste identique à l'octet à sa référence. Jumeau, cité : {@see EngineRequestReferenceTest}
 * (les mêmes décors, sans modification).
 */
#[Group('phase1')]
#[Group('integration')]
final class EngineRequestKeySourcesTest extends KernelTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestPlanSettingsDecorTrait;
    use EngineRequestRunnerTrait;
    use EngineRequestSocleNetDecorTrait;

    private const string FREE = 'libre';

    private const string COLD = 'froide';

    private const string HOT = 'à chaud';

    /** Les demandes de génération (empreinte) et de placement (sources seules). */
    private const array GENERATION_REFERENCES = ['socle-11-socle-complet', 'periode-12-vacances-reglages', 'periode-13-fermeture-reglages'];

    private const array PLACEMENT_REFERENCES = ['placement-5-club-entier'];

    /**
     * Chemin de clé => [source, famille qui la porte (ou null), champ de cette famille (ou null), raison].
     *
     * @var array<string, array{0: string, 1: class-string|null, 2: string|null, 3: string}>
     */
    private const array SOURCES = [
        // L'enveloppe : constantes du rail ou du plan.
        'version' => [self::FREE, null, null, 'numéro de contrat, jamais de la structure'],
        'clubId' => [self::HOT, null, null, 'identité du club, constante du plan'],
        'seasonId' => [self::HOT, null, null, 'identité de la saison, constante du plan'],
        'solverSeed' => [self::HOT, null, null, 'graine du rail'],
        'solverTimeoutSeconds' => [self::HOT, null, null, 'budget du rail'],
        // Gymnases et grille.
        'venues[].id' => [self::COLD, null, null, 'identité du gymnase'],
        'venues[].name' => [self::FREE, Venue::class, 'name', 'un nom'],
        'venues[].trainingSlots[].capacity' => [self::COLD, VenueTrainingSlot::class, 'capacity', 'grille'],
        'venues[].trainingSlots[].dayOfWeek' => [self::COLD, VenueTrainingSlot::class, 'dayOfWeek', 'grille'],
        'venues[].trainingSlots[].durationMinutes' => [self::COLD, VenueTrainingSlot::class, 'durationMinutes', 'grille'],
        'venues[].trainingSlots[].startTime' => [self::COLD, VenueTrainingSlot::class, 'startTime', 'grille'],
        'venues[].matchWindows[].appliesOnPublicHolidays' => [self::HOT, null, null, 'fenêtre de match d\'un gymnase, module matchs, lue en direct'],
        'venues[].matchWindows[].dayOfWeek' => [self::HOT, null, null, 'fenêtre de match d\'un gymnase, module matchs, lue en direct'],
        'venues[].matchWindows[].end' => [self::HOT, null, null, 'fenêtre de match d\'un gymnase, module matchs, lue en direct'],
        'venues[].matchWindows[].start' => [self::HOT, null, null, 'fenêtre de match d\'un gymnase, module matchs, lue en direct'],
        // Équipes.
        'teams[].id' => [self::COLD, null, null, 'identité de l\'équipe'],
        'teams[].name' => [self::FREE, Team::class, 'name', 'un nom'],
        'teams[].ageMin' => [self::HOT, null, null, 'âge d\'une catégorie, lu en direct'],
        'teams[].priorityTierId' => [self::COLD, Team::class, 'priorityTierId', 'rang de l\'équipe'],
        'teams[].sessionsPerWeek' => [self::COLD, PlanTeam::class, 'sessionsPerWeek', 'séances du plan'],
        'teams[].maxSessionsPerDay' => [self::COLD, PlanTeam::class, 'maxSessionsPerDay', 'plafond par jour du plan'],
        'teams[].forcedVenueId' => [self::COLD, Team::class, 'forcedVenueId', 'gymnase imposé, froid du socle (E1)'],
        'teams[].matchDay' => [self::HOT, null, null, 'jour de match tiré des habitudes'],
        'teams[].matchMinutes' => [self::HOT, null, null, 'durée de match de la catégorie, lue en direct'],
        'teams[].habits[].dayOfWeek' => [self::HOT, null, null, 'habitudes de match'],
        'teams[].habits[].kickoff' => [self::HOT, null, null, 'habitudes de match'],
        'teams[].habits[].venueId' => [self::HOT, null, null, 'habitudes de match'],
        'teams[].coaches[].coachId' => [self::COLD, TeamCoach::class, 'coachId', 'coach principal, froid du socle'],
        'teams[].coaches[].role' => [self::COLD, TeamCoach::class, 'role', 'coach principal, froid du socle'],
        'teams[].players[]' => [self::COLD, CoachPlayerMembership::class, 'coachId', 'coach-joueur, froid du socle (décision 7)'],
        'teams[].leagueWindows[]' => [self::HOT, null, null, 'fenêtres de ligue, plateforme et module matchs (vides dans le décor 5)'],
        'venues[].unavailabilities[]' => [self::HOT, null, null, 'indisponibilités de match d\'un gymnase, module matchs (vides dans le décor 5)'],
        'teams[].forbiddenVenueIds[]' => [self::HOT, null, null, 'interdiction de gymnase du module matchs, lue en direct'],
        // Coachs : rien de froid sur la fiche, hors son identité.
        'coaches[].id' => [self::COLD, null, null, 'identité du coach'],
        'coaches[].firstName' => [self::FREE, Person::class, 'firstName', 'un nom'],
        'coaches[].lastName' => [self::FREE, Person::class, 'lastName', 'un nom'],
        'coaches[].isEmployee' => [self::FREE, Coach::class, 'isEmployee', 'case salarié'],
        'coaches[].isVehicled' => [self::FREE, Coach::class, 'isVehicled', 'véhiculé (décision 6)'],
        'coaches[].maxDaysOverride' => [self::FREE, Coach::class, 'maxDaysOverride', 'jours maximum (décision 6)'],
        // Règles : contraintes permanentes, coach principal et coach-joueur en lignes de contrainte.
        'constraints[].id' => [self::COLD, null, null, 'identité de la règle'],
        'constraints[].name' => [self::FREE, Constraint::class, 'name', 'nom d\'une contrainte'],
        'constraints[].family' => [self::COLD, Constraint::class, 'family', 'règle'],
        'constraints[].ruleType' => [self::COLD, Constraint::class, 'ruleType', 'règle'],
        'constraints[].scope' => [self::COLD, Constraint::class, 'scope', 'règle'],
        'constraints[].scopeTargetId' => [self::COLD, Constraint::class, 'scopeTargetId', 'règle'],
        'constraints[].isActive' => [self::COLD, Constraint::class, 'isActive', 'règle'],
        'constraints[].config.endDate' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.forbiddenDays[]' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.minStartTime' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.preferredDays[]' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.preferredVenueId' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.startDate' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.type' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].config.unavailableDays[]' => [self::COLD, Constraint::class, 'config', 'règle'],
        'constraints[].teamId' => [self::COLD, null, null, 'équipe d\'une ligne de coach principal ou de coach-joueur'],
        'constraints[].type' => [self::COLD, null, null, 'nature d\'une ligne de coach principal ou de coach-joueur'],
        'constraints[].value' => [self::COLD, null, null, 'coach d\'une ligne de coach principal ou de coach-joueur'],
        'constraints[].severity' => [self::COLD, CoachPlayerMembership::class, 'isActive', 'coach-joueur actif ou non'],
        'constraints[].metadata.coachId' => [self::COLD, TeamCoach::class, 'coachId', 'coach principal, froid du socle'],
        'constraints[].metadata.role' => [self::COLD, TeamCoach::class, 'role', 'coach principal, froid du socle'],
        'constraints[].metadata.teamId' => [self::COLD, CoachPlayerMembership::class, 'teamId', 'coach-joueur'],
        'constraints[].metadata.position' => [self::COLD, CoachPlayerMembership::class, 'position', 'coach-joueur'],
        'constraints[].metadata.isActive' => [self::COLD, CoachPlayerMembership::class, 'isActive', 'coach-joueur'],
        // Séances épinglées : réservations du plan (froides) et verrous de la version (hors du froid, décision 1).
        'slotTemplates[].id' => [self::FREE, null, null, 'identifiant de ligne : une version régénérée réimporte ses verrous sous des ids neufs'],
        'slotTemplates[].coachId' => [self::FREE, null, null, 'résultat de la tenue, écrit par la régénération'],
        'slotTemplates[].teamId' => [self::COLD, Reservation::class, 'teamId', 'réservation, ou verrou de la version'],
        'slotTemplates[].venueId' => [self::COLD, Reservation::class, 'venueId', 'réservation, ou verrou de la version'],
        'slotTemplates[].dayOfWeek' => [self::COLD, Reservation::class, 'dayOfWeek', 'réservation, ou verrou de la version'],
        'slotTemplates[].startTime' => [self::COLD, Reservation::class, 'startTime', 'réservation, ou verrou de la version'],
        'slotTemplates[].durationMinutes' => [self::COLD, Reservation::class, 'durationMinutes', 'réservation, ou verrou de la version'],
        'slotTemplates[].coachLateToleranceMinutes' => [self::COLD, Reservation::class, 'coachLateToleranceMinutes', 'réservation, ou verrou de la version'],
        'slotTemplates[].lockLevel' => [self::HOT, null, null, 'un verrou appartient à la version (décision 1) : dans l\'empreinte, jamais dans la copie'],
        'slotTemplates[].pendingConstraintSuggestion' => [self::HOT, null, null, 'suggestion portée par la séance de la version'],
        // Règles de bien-être, groupes, passerelles.
        'implicitRules.ageAscending.intensity' => [self::COLD, ImplicitRuleSetting::class, 'intensity', 'règle de bien-être'],
        'implicitRules.coachRestDay.intensity' => [self::COLD, ImplicitRuleSetting::class, 'intensity', 'règle de bien-être'],
        'implicitRules.coachRestDay.minRestDays' => [self::COLD, ImplicitRuleSetting::class, 'params', 'règle de bien-être'],
        'implicitRules.maxConsecutiveDays.intensity' => [self::COLD, ImplicitRuleSetting::class, 'intensity', 'règle de bien-être'],
        'implicitRules.maxConsecutiveDays.maxConsecutiveDays' => [self::COLD, ImplicitRuleSetting::class, 'params', 'règle de bien-être'],
        'implicitRules.maxConsecutiveSessions.intensity' => [self::COLD, ImplicitRuleSetting::class, 'intensity', 'règle de bien-être'],
        'implicitRules.maxConsecutiveSessions.maxConsecutive' => [self::COLD, ImplicitRuleSetting::class, 'params', 'règle de bien-être'],
        'implicitRules.salarieDistribution.intensity' => [self::COLD, ImplicitRuleSetting::class, 'intensity', 'règle de bien-être'],
        'sharedBlocks[].id' => [self::COLD, null, null, 'identité du groupe'],
        'sharedBlocks[].commonSessions' => [self::COLD, SharedTrainingBlock::class, 'commonSessions', 'mutualisation'],
        'sharedBlocks[].teamIds[]' => [self::COLD, SharedTrainingBlockTeam::class, 'teamId', 'membres du groupe'],
        'teamLinks[].id' => [self::COLD, null, null, 'identité de la passerelle'],
        'teamLinks[].intensity' => [self::COLD, TeamLink::class, 'trainingIntensity', 'passerelle, froid du socle (E1)'],
        'teamLinks[].teamAId' => [self::COLD, TeamLink::class, 'teamAId', 'passerelle, froid du socle (E1)'],
        'teamLinks[].teamBId' => [self::COLD, TeamLink::class, 'teamBId', 'passerelle, froid du socle (E1)'],
        'teamLinks[].type' => [self::COLD, TeamLink::class, 'linkType', 'passerelle, froid du socle (E1)'],
        'venueTravelTimes[]' => [self::FREE, null, null, 'temps de trajet (vide dans ces décors ; voir testATravelTimeIsFree)'],
        // Le placement : faits du calendrier et module matchs, lus en direct.
        'matches[].currentKickoff' => [self::HOT, null, null, 'fait du calendrier'],
        'matches[].currentVenueId' => [self::HOT, null, null, 'fait du calendrier'],
        'matches[].date' => [self::HOT, null, null, 'fait du calendrier'],
        'matches[].id' => [self::HOT, null, null, 'fait du calendrier'],
        'matches[].kickoff' => [self::HOT, null, null, 'fait du calendrier'],
        'matches[].kind' => [self::HOT, null, null, 'fait du calendrier'],
        'matches[].teamId' => [self::HOT, null, null, 'fait du calendrier'],
        'trainingOccupancies[]' => [self::HOT, null, null, 'séances du planning en vigueur, calculées'],
        'publicHolidays[]' => [self::HOT, null, null, 'jours fériés de la plateforme'],
        'clubRules[].daysOfWeek[]' => [self::HOT, null, null, 'règle de match du club, module matchs'],
        'clubRules[].kickoffMax' => [self::HOT, null, null, 'règle de match du club, module matchs'],
        'clubRules[].kickoffMin' => [self::HOT, null, null, 'règle de match du club, module matchs'],
        'clubRules[].ruleType' => [self::HOT, null, null, 'règle de match du club, module matchs'],
        'coachUnavailabilities[].coachId' => [self::HOT, null, null, 'indisponibilité de match d\'un coach, module matchs'],
        'coachUnavailabilities[].daysOfWeek[]' => [self::HOT, null, null, 'indisponibilité de match d\'un coach, module matchs'],
        'coachUnavailabilities[].kickoffMax' => [self::HOT, null, null, 'indisponibilité de match d\'un coach, module matchs'],
        'coachUnavailabilities[].kickoffMin' => [self::HOT, null, null, 'indisponibilité de match d\'un coach, module matchs'],
    ];

    /** @return iterable<string, array{string}> */
    public static function inForceReaders(): iterable
    {
        foreach (['periode-12-vacances-reglages', 'periode-13-fermeture-reglages', 'placement-5-club-entier', 'placement-6-fenetre', 'placement-21-equipe-creee-apres-validation'] as $reference) {
            yield $reference => [$reference];
        }
    }

    /** Une clé sans source fait échouer ; une source qu'aucune des quatre demandes ne porte plus aussi. */
    public function testEveryKeyOfTheRequestHasOneSource(): void
    {
        $seen = [];
        foreach ([...self::GENERATION_REFERENCES, ...self::PLACEMENT_REFERENCES] as $reference) {
            foreach (array_keys($this->leaves($this->reference($reference))) as $path) {
                $seen[$path] = true;
            }
        }
        // Une liste vide ici et pleine ailleurs a ses clés, dites par leurs feuilles.
        foreach (array_keys($seen) as $path) {
            foreach (array_keys($seen) as $other) {
                if (str_starts_with($other, $path . '.') || str_starts_with($other, $path . '[]')) {
                    unset($seen[$path]);

                    break;
                }
            }
        }

        $withoutSource = array_values(array_diff(array_keys($seen), array_keys(self::SOURCES)));
        sort($withoutSource);
        self::assertSame([], $withoutSource, "Ces clés de la demande n'ont pas de source (libre, froide, à chaud) :\n  - " . implode("\n  - ", $withoutSource));

        $stale = array_values(array_diff(array_keys(self::SOURCES), array_keys($seen)));
        sort($stale);
        self::assertSame([], $stale, "Ces sources ne désignent plus aucune clé des demandes 11, 12, 13 et 5 :\n  - " . implode("\n  - ", $stale));
    }

    /**
     * L'empreinte suit la source : changer une clé libre, à n'importe quelle occurrence, ne la change pas ; changer une
     * clé froide ou à chaud la change (au moins une occurrence, une séance non verrouillée n'y comptant pas).
     */
    public function testTheFingerprintIgnoresEveryFreeKeyAndSeesEveryOtherOne(): void
    {
        $wrong = [];
        foreach (self::GENERATION_REFERENCES as $reference) {
            $payload = $this->reference($reference);
            $hash = ScheduleConstraintBuilder::structureHash($payload);
            foreach ($this->leaves($payload) as $path => $occurrences) {
                $source = self::SOURCES[$path][0] ?? null;
                if (null === $source || [] === $occurrences) {
                    continue;
                }
                $changed = array_map(
                    fn (array $at): bool => ScheduleConstraintBuilder::structureHash($this->mutated($payload, $at)) !== $hash,
                    \array_slice($occurrences, 0, 6),
                );
                if (self::FREE === $source && \in_array(true, $changed, true)) {
                    $wrong[] = \sprintf('%s (%s) : libre, mais la changer change l\'empreinte', $path, $reference);
                }
                if (self::FREE !== $source && !\in_array(true, $changed, true)) {
                    $wrong[] = \sprintf('%s (%s) : %s, mais la changer laisse l\'empreinte', $path, $reference, $source);
                }
            }
        }

        self::assertSame([], $wrong, "L'empreinte ne suit pas la source de ces clés :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * La copie suit la source : le champ qui porte une clé froide est déclaré froid sur sa famille ; celui qui porte une
     * clé libre ou à chaud ne l'est pas.
     */
    public function testTheCopyHoldsEveryColdKeyAndNoOther(): void
    {
        // Témoin : la lecture d'une déclaration voit un champ froid, un champ libre, une famille toute froide.
        self::assertTrue($this->isCold(new Family(Level::PLAN, cold: ['sessionsPerWeek']), 'sessionsPerWeek'));
        self::assertFalse($this->isCold(new Family(Level::PLAN, cold: ['sessionsPerWeek']), 'label'));
        self::assertTrue($this->isCold(new Family(Level::PLAN, cold: Family::ALL), 'label'));

        $wrong = [];
        foreach (self::SOURCES as $path => [$source, $class, $field]) {
            if (null === $class || null === $field) {
                continue;
            }
            self::assertTrue(new ReflectionClass($class)->hasProperty($field), \sprintf('%s : %s n\'a pas de champ %s', $path, $class, $field));
            $attributes = new ReflectionClass($class)->getAttributes(Family::class);
            self::assertNotSame([], $attributes, $class . ' ne déclare pas sa famille');
            $cold = $this->isCold($attributes[0]->newInstance(), $field);
            if (self::COLD === $source && !$cold) {
                $wrong[] = \sprintf('%s : %s::%s porte une clé froide, mais n\'est pas déclaré froid', $path, $class, $field);
            }
            if (self::COLD !== $source && $cold) {
                $wrong[] = \sprintf('%s : %s::%s porte une clé %s, mais est déclaré froid', $path, $class, $field, $source);
            }
        }

        self::assertSame([], $wrong, "La déclaration des familles ne suit pas la source de ces clés :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * Ligne par ligne : un adjoint de plus (ligne `TEAM_COACH` de rôle `ASSISTANT`) ne change pas l'empreinte, un coach
     * principal de plus la change. Témoin inverse du même décor.
     */
    public function testAnAssistantCoachIsFreeWhereAMainCoachIsCold(): void
    {
        $payload = $this->reference('socle-11-socle-complet');
        $hash = ScheduleConstraintBuilder::structureHash($payload);
        $main = null;
        foreach ($payload['constraints'] as $line) {
            if ('TEAM_COACH' === ($line['type'] ?? null)) {
                $main = $line;

                break;
            }
        }
        self::assertIsArray($main, 'témoin : le décor 11 porte une ligne de coach principal');

        $assistant = $main;
        $assistant['id'] = 'team-coach:0000adj0-0000-4000-8000-000000000001';
        $assistant['metadata']['role'] = 'ASSISTANT';
        $withAssistant = $payload;
        $withAssistant['constraints'][] = $assistant;
        self::assertSame($hash, ScheduleConstraintBuilder::structureHash($withAssistant), 'ajouter un adjoint ne change pas l\'empreinte');

        $secondMain = $main;
        $secondMain['id'] = 'team-coach:0000ma10-0000-4000-8000-000000000001';
        $secondMain['value'] = $secondMain['metadata']['coachId'] = '0000c0ac-0000-4000-8000-000000000099';
        $withMain = $payload;
        $withMain['constraints'][] = $secondMain;
        self::assertNotSame($hash, ScheduleConstraintBuilder::structureHash($withMain), 'un coach principal de plus change l\'empreinte');
    }

    /** Un temps de trajet est libre : en saisir ou en corriger un ne change pas l'empreinte. */
    public function testATravelTimeIsFree(): void
    {
        $payload = $this->reference('socle-11-socle-complet');
        $venues = array_column($payload['venues'], 'id');
        self::assertGreaterThanOrEqual(2, \count($venues), 'témoin : deux gymnases à relier');
        $withTravel = $payload;
        $withTravel['venueTravelTimes'] = [['venueAId' => $venues[0], 'venueBId' => $venues[1], 'drivingMinutes' => 12, 'walkingMinutes' => null]];
        $corrected = $withTravel;
        $corrected['venueTravelTimes'][0]['drivingMinutes'] = 25;

        $hash = ScheduleConstraintBuilder::structureHash($payload);
        self::assertSame($hash, ScheduleConstraintBuilder::structureHash($withTravel), 'saisir un temps de trajet ne change pas l\'empreinte');
        self::assertSame($hash, ScheduleConstraintBuilder::structureHash($corrected), 'le corriger non plus');
    }

    /**
     * Non validé : un champ froid de chaque famille du socle, modifié en base après la validation, laisse la demande
     * d'une période ou du placement identique à sa référence (elle lit l'en vigueur, la copie de la validation). Les
     * familles : équipe (rang, niveau, gymnase imposé), gymnase (« divisible »), coach principal, coach-joueur,
     * passerelle, règle permanente (réglage, activité), ligne d'équipe, grille, réservation, règle de bien-être et
     * groupe mutualisé du plan de saison.
     */
    #[DataProvider('inForceReaders')]
    public function testAColdFieldChangedWithoutValidationLeavesTheRequestOfAnInForceReader(string $reference): void
    {
        self::bootKernel();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
            [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
            [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);

        $run = match ($reference) {
            'periode-12-vacances-reglages' => function (): array {
                $this->seedEngineRequestHolidaySettings();
                $this->erVersion(self::ER_HOLIDAY_V1, self::ER_HOLIDAY_WEEK, ScheduleStatus::DRAFT, []);
                $this->validateSocleAsItStands();
                $this->changeEveryColdFamilyOfTheSocle();

                return $this->runGeneration(self::ER_HOLIDAY_V1)['body'];
            },
            'periode-13-fermeture-reglages' => function (): array {
                $this->seedEngineRequestClosureSettings();
                $this->erVersion(self::ER_CLOSURE_V1, self::ER_CLOSURE_SETTINGS, ScheduleStatus::DRAFT, []);
                $this->validateSocleAsItStands();
                $this->changeEveryColdFamilyOfTheSocle();

                return $this->runGeneration(self::ER_CLOSURE_V1)['body'];
            },
            'placement-5-club-entier', 'placement-6-fenetre' => function () use ($reference): array {
                $this->seedEngineRequestMatches();
                $this->validateSocleAsItStands();
                $this->changeEveryColdFamilyOfTheSocle();

                return $this->runPlacement('placement-6-fenetre' === $reference ? ['from' => '2026-01-12', 'to' => '2026-01-18'] : null);
            },
            'placement-21-equipe-creee-apres-validation' => function (): array {
                $this->seedEngineRequestMatches();
                $this->validateSocleAsItStands();
                $this->seedEngineRequestTeamCreatedAfterValidation();
                $this->changeEveryColdFamilyOfTheSocle();

                return $this->runPlacement(null);
            },
            default => self::fail('référence inconnue ' . $reference),
        };

        $this->assertReference($reference, $run());
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->unpinEngineRequestClock();
        }
        parent::tearDown();
    }

    /** La validation du socle sur l'état du décor : la version en vigueur (V1) reçoit la copie de l'état du jour. */
    private function validateSocleAsItStands(): void
    {
        $this->erEm()->flush();
        $version = $this->erEm()->getRepository(Schedule::class)->find(self::ER_SOCLE_V1);
        self::assertInstanceOf(Schedule::class, $version);
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($version, $states->capture($this->erSeasonPlanId()));
    }

    /** Après la validation, sans valider : un champ froid de chaque famille du socle change en base (identifiants du décor). */
    private function changeEveryColdFamilyOfTheSocle(): void
    {
        $em = $this->erEm();
        $em->flush();
        $connection = $em->getConnection();
        $socle = $this->erSeasonPlanId();
        $witnesses = [
            'rang d\'une équipe' => ['UPDATE team SET priority_tier_id = 1 WHERE id = ?', [self::ER_TEAM_U15]],
            'niveau d\'une équipe' => ['UPDATE team SET level = \'REGIONAL\' WHERE id = ?', [self::ER_TEAM_U13]],
            'gymnase imposé' => ['UPDATE team SET forced_venue_id = ? WHERE id = ?', [self::ER_VENUE_SUD, self::ER_TEAM_SM1]],
            '« divisible » d\'un gymnase' => ['UPDATE venue SET can_split = false WHERE id = ?', [self::ER_VENUE_NORD]],
            'coach principal' => ['UPDATE team_coach SET coach_id = ? WHERE id = ?', [self::ER_COACH_A, self::erId('7c0a', 3)]],
            'coach-joueur' => ['UPDATE coach_player_membership SET is_active = false WHERE id = ?', [self::erId('3e3b', 1)]],
            'passerelle' => ['DELETE FROM team_link WHERE id = ?', [self::erId('11c0', 1)]],
            'réglage d\'une règle permanente' => ['UPDATE "constraint" SET config = \'{"minStartTime": "19:30"}\' WHERE id = ?', [self::erId('c057', 1)]],
            'activité d\'une règle permanente' => ['UPDATE "constraint" SET is_active = false WHERE id = ?', [self::erId('c057', 4)]],
            'séances d\'une équipe au socle' => ['UPDATE plan_team SET sessions_per_week = 3 WHERE schedule_plan_id = ? AND team_id = ?', [$socle, self::ER_TEAM_U13]],
            'grille du socle' => ['UPDATE venue_training_slot SET capacity = 1 WHERE id = ?', [self::erId('a7a1', 1)]],
            'réservation du socle' => ['UPDATE reservation SET start_time = \'19:30:00\' WHERE id = ?', [self::erId('e5e0', 1)]],
        ];
        foreach ($witnesses as $what => [$sql, $parameters]) {
            self::assertSame(1, $connection->executeStatement($sql, $parameters), \sprintf('témoin : %s modifié en base', $what));
        }
        // Présents dans un seul décor : le groupe mutualisé du socle (13), la règle de bien-être du socle (12).
        $connection->executeStatement('UPDATE shared_training_block SET common_sessions = 2 WHERE id = ?', [self::ER_BLOCK_SOCLE]);
        $connection->executeStatement('UPDATE implicit_rule_setting SET params = \'{"maxConsecutiveDays": 4}\' WHERE id = ?', [self::erId('a1e5', 32)]);
        $em->clear();
    }

    /** @return array<string, mixed> */
    private function reference(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(self::REFERENCE_DIR . '/' . $name . '.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $name . ' : référence illisible');

        return $decoded;
    }

    /**
     * Chaque feuille de la demande, par chemin (`a[].b`), avec l'adresse de chacune de ses occurrences ; une liste vide
     * est une feuille sans occurrence (`a[]`).
     *
     * @param array<mixed>                          $node
     * @param list<int|string>                      $at
     * @param array<string, list<list<int|string>>> $out
     *
     * @return array<string, list<list<int|string>>>
     */
    private function leaves(array $node, string $prefix = '', array $at = [], array &$out = []): array
    {
        if (array_is_list($node)) {
            if ([] === $node) {
                $out[$prefix . '[]'] ??= [];
            }
            foreach ($node as $index => $child) {
                if (\is_array($child)) {
                    $this->leaves($child, $prefix . '[]', [...$at, $index], $out);
                } else {
                    $out[$prefix . '[]'][] = [...$at, $index];
                }
            }

            return $out;
        }
        foreach ($node as $key => $child) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if (\is_array($child)) {
                $this->leaves($child, $path, [...$at, $key], $out);
            } else {
                $out[$path][] = [...$at, $key];
            }
        }

        return $out;
    }

    /**
     * La demande avec la feuille à cette adresse changée : un booléen inversé, un nombre augmenté, une chaîne
     * prolongée, un null remplacé.
     *
     * @param array<mixed>     $payload
     * @param list<int|string> $at
     *
     * @return array<mixed>
     */
    private function mutated(array $payload, array $at): array
    {
        $cursor = &$payload;
        foreach ($at as $key) {
            $cursor = &$cursor[$key];
        }
        $cursor = match (true) {
            \is_bool($cursor) => !$cursor,
            \is_int($cursor), \is_float($cursor) => $cursor + 1,
            \is_string($cursor) => $cursor . '≠',
            default => 'changé',
        };
        unset($cursor);

        return $payload;
    }

    private function isCold(Family $family, string $field): bool
    {
        return Family::ALL === $family->cold || (\is_array($family->cold) && \in_array($field, $family->cold, true));
    }
}
