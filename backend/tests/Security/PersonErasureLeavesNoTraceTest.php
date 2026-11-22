<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\TeamCoach;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Service\StructureRestorer;
use App\Structure\PersonEraser;
use App\Tests\Integration\Structure\PersonEraserTest;
use App\Tests\PersonErasureDecorTrait;
use App\Tests\PersonTraceSweep;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Après l'effacement d'une personne (RGPD art. 17, lot conception 3, PR 3), aucune colonne de la base
 * vue par son club ne contient plus son nom, son mail, son téléphone ni le commentaire de sa doléance,
 * et « Charger » une version d'avant l'effacement ne la ramène pas (abus) ; l'homonyme d'un autre club
 * n'est pas touchée.
 *
 * Le balayage ({@see PersonTraceSweep}) lit toutes les colonnes texte, JSON et tableau du
 * schéma sous le GUC du club ; il est d'abord prouvé sur le décor AVANT l'effacement (il y trouve
 * chaque endroit du relevé), sans quoi un zéro ne prouverait rien. Le décor reprend le relevé du §2 du
 * plan, deux cas compris qu'aucun geste ne joue vite : le signalement né d'une génération en échec
 * (composé par le vrai `FeedbackContextEnricher`) et le récapitulatif de fin de collecte rangé dans la
 * boîte d'un club de démonstration. Il ne prouve que ce relevé : la même promesse par les vrais gestes
 * est `features/effacer-une-personne-efface-tout.feature` (jumelle, citée) ; le détail de chaque étape,
 * {@see PersonEraserTest}.
 */
#[Group('phase1')]
#[Group('integration')]
final class PersonErasureLeavesNoTraceTest extends KernelTestCase
{
    use PersonErasureDecorTrait;

    private EntityManagerInterface $em;

    public function testNoColumnSeenByTheClubStillCarriesHerNameMailPhoneOrCommentAndTheOtherClubKeepsItsHomonym(): void
    {
        $this->seedErasureDecor(demo: true);
        $before = explode(', ', $this->tracesSeenByClub($this->decor['club'], $this->erasedPersonNeedles()));
        foreach (['person.last_name', 'person.email', 'person.phone', 'coach_wish.comment', 'constraint.name', 'schedule.snapshot_data',
            'schedule_structure_snapshot.data', 'schedule_diagnostic.message', 'feedback.context', 'club_mailbox_message.body_text',
            'club_mailbox_message.subject', 'club_mailbox_message.to_address'] as $column) {
            self::assertContains($column, $before, 'témoin : le balayage trouve sa trace dans ' . $column . ' avant l\'effacement');
        }
        $failure = $this->em->find(Feedback::class, $this->decor['feedbackFailure'])?->getContext() ?? [];
        self::assertStringContainsString($this->decor['fullName'], (string) json_encode($failure['auto']['generationFailure'] ?? null, \JSON_UNESCAPED_UNICODE), 'témoin : le signalement né de l\'échec embarque son nom');
        $this->em->clear();

        $this->scopeGucToClub($this->decor['club']);
        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        $this->em->clear();

        self::assertSame('', $this->tracesSeenByClub($this->decor['club'], $this->erasedPersonNeedles()), 'aucune colonne du club ne garde son nom, son mail, son téléphone ni son commentaire');
        $other = explode(', ', $this->tracesSeenByClub($this->decor['clubB'], [$this->decor['fullName'], $this->decor['lastName']]));
        self::assertContains('person.last_name', $other, 'la fiche de l\'homonyme d\'un autre club reste');
        self::assertContains('feedback.context', $other, 'le signalement d\'un autre club qui la cite reste tel quel');
    }

    /** « Charger » une version générée avant l'effacement ne ramène ni sa ligne de coach, ni ses liens, ni ses règles, ni son nom (abus). */
    public function testLoadingAVersionFromBeforeTheErasureBringsBackNeitherHerRoleNorHerName(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        $this->em->clear();

        $this->scopeGucToClub($this->decor['club']);
        $restorer = self::getContainer()->get(StructureRestorer::class);
        $v1 = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertNotNull($v1);
        $restorer->apply($this->decor['club'], $this->decor['current'], $restorer->readSnapshot($v1));
        $this->em->clear();

        $this->scopeGucToClub($this->decor['club']);
        self::assertNull($this->em->find(Coach::class, $this->decor['coach']), 'son rôle ne revient pas');
        self::assertNull($this->em->find(Person::class, $this->decor['person']), 'sa fiche ne revient pas');
        self::assertSame([], $this->em->getRepository(TeamCoach::class)->findBy(['coachId' => $this->decor['coach']]), 'ni ses liens d\'équipe');
        self::assertSame([], $this->em->getRepository(Constraint::class)->findBy(['scopeTargetId' => $this->decor['coach']]), 'ni ses règles');
        self::assertNotNull($this->em->find(Coach::class, $this->decor['dominique']), 'témoin : le reste de la version revient');
        self::assertSame('', $this->tracesSeenByClub($this->decor['club'], $this->erasedPersonNeedles()), 'et son nom ne revient nulle part');
    }

    /**
     * Ses règles sont relevées par IDENTIFIANT (portée coach, l'un de ses rôles, toutes saisons) avant
     * que la cascade ne les supprime : un diagnostic d'une autre équipe qui en cite une dont le nom ne
     * porte pas son nom composé (« Indispo Camille … », prénom seul ; « Indispo C. Durand-… », initiale)
     * n'en garde le nom ni dans ses causes, ni dans son message, ni dans ses suggestions, ni dans le
     * signalement qui l'embarque. Jamais par motif de prénom : la règle d'ÉQUIPE nommée avec son prénom
     * reste (limite 4 du plan), la règle d'une homonyme du même club aussi, et l'autre club n'est pas lu.
     */
    public function testANeighbourDiagnosticNoLongerNamesHerRulesNamedWithoutHerFullName(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        $uid = $this->decor['uid'];
        $firstNameOnly = 'Indispo ' . self::ERASED_FIRST_NAME . ' mardi ' . $uid;
        $initial = 'Indispo C. ' . $this->decor['lastName'];
        $teamRuleName = 'Créneau de ' . self::ERASED_FIRST_NAME . ' pour U13 ' . $uid;
        $twinRuleName = 'Indispo ' . self::ERASED_FIRST_NAME . ' jeudi ' . $uid;
        $twin = $this->newCoach($this->decor['club'], $this->decor['current'], self::ERASED_FIRST_NAME, 'Autre' . ucfirst($uid));
        $this->em->flush();
        $herRule = $this->coachRule($this->decor['club'], $this->decor['current'], $this->decor['coach'], $firstNameOnly);
        $herPastRule = $this->coachRule($this->decor['club'], $this->decor['previous'], $this->decor['coachPast'], $initial);
        $twinRule = $this->coachRule($this->decor['club'], $this->decor['current'], $twin->getId(), $twinRuleName);
        $teamRule = (new Constraint)->setClubId($this->decor['club'])->setSeasonId($this->decor['current'])
            ->setName($teamRuleName)->setScope(ConstraintScope::TEAM)->setScopeTargetId($this->decor['u13'])
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['unavailableDays' => [3]]);
        $this->em->persist($teamRule);
        $this->em->flush();

        $message = 'SM1 : bloquée par ' . $firstNameOnly . ', ' . $initial . ', ' . $teamRuleName . ' et ' . $twinRuleName;
        $suggestions = ['Assouplir « ' . $firstNameOnly . ' »', 'Assouplir « ' . $initial . ' »', 'Assouplir « ' . $teamRuleName . ' »'];
        $causes = [
            ['kind' => 'rule', 'constraintId' => $herRule, 'label' => $firstNameOnly, 'count' => 2],
            ['kind' => 'rule', 'constraintId' => $herPastRule, 'label' => $initial, 'count' => 1],
            ['kind' => 'rule', 'constraintId' => $teamRule->getId(), 'label' => $teamRuleName, 'count' => 1],
            ['kind' => 'rule', 'constraintId' => $twinRule, 'label' => $twinRuleName, 'count' => 1],
        ];
        $neighbour = $this->em->find(ScheduleDiagnostic::class, $this->decor['citedDiagnostic']);
        self::assertInstanceOf(ScheduleDiagnostic::class, $neighbour);
        $neighbour->setMessage($message)->setSuggestions($suggestions)->setCauses($causes);
        $feedback = $this->em->find(Feedback::class, $this->decor['feedbackNamed']);
        self::assertInstanceOf(Feedback::class, $feedback);
        $context = $feedback->getContext() ?? [];
        $context['diagnostics'][] = ['type' => 'rule_conflict', 'teamId' => $this->decor['sm1'], 'message' => $message, 'suggestions' => $suggestions, 'causes' => $causes];
        $feedback->setContext($context);
        $this->em->flush();

        // L'autre club : une règle de son homonyme au même nom, citée par un diagnostic.
        $this->scopeGucToClub($this->decor['clubB']);
        $otherRule = $this->coachRule($this->decor['clubB'], $this->decor['seasonB'], $this->decor['homonymCoach'], $firstNameOnly);
        $other = (new ScheduleDiagnostic)->setClubId($this->decor['clubB'])->setSeasonId($this->decor['seasonB'])->setScheduleId($this->decor['scheduleB'])
            ->setType('rule_conflict')->setSeverity(ScheduleDiagnosticSeverity::ERROR)->setMessage('Bloquée par ' . $firstNameOnly)
            ->setCauses([['kind' => 'rule', 'constraintId' => $otherRule, 'label' => $firstNameOnly, 'count' => 1]]);
        $this->em->persist($other);
        $this->em->flush();
        $otherId = $other->getId();
        $this->em->clear();

        $herNames = [$firstNameOnly, $initial];
        $before = explode(', ', $this->tracesSeenByClub($this->decor['club'], $herNames));
        foreach (['schedule_diagnostic.causes', 'schedule_diagnostic.message', 'schedule_diagnostic.suggestions', 'feedback.context'] as $column) {
            self::assertContains($column, $before, 'témoin : le balayage trouve le nom de ses règles dans ' . $column . ' avant l\'effacement');
        }
        $this->em->clear();

        $this->scopeGucToClub($this->decor['club']);
        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        $this->em->clear();

        self::assertSame('', $this->tracesSeenByClub($this->decor['club'], $herNames), 'aucune colonne du club ne garde le nom de ses règles, même sans son nom composé');
        $kept = explode(', ', $this->tracesSeenByClub($this->decor['club'], [$teamRuleName]));
        foreach (['constraint.name', 'schedule_diagnostic.causes', 'schedule_diagnostic.message', 'schedule_diagnostic.suggestions', 'feedback.context'] as $column) {
            self::assertContains($column, $kept, 'la règle d\'équipe nommée avec son prénom reste, dans ' . $column);
        }
        $twinKept = explode(', ', $this->tracesSeenByClub($this->decor['club'], [$twinRuleName]));
        foreach (['constraint.name', 'schedule_diagnostic.causes', 'schedule_diagnostic.message', 'feedback.context'] as $column) {
            self::assertContains($column, $twinKept, 'la règle de l\'homonyme du même club, au même prénom, reste dans ' . $column);
        }
        $this->scopeGucToClub($this->decor['clubB']);
        $other = $this->em->find(ScheduleDiagnostic::class, $otherId);
        self::assertInstanceOf(ScheduleDiagnostic::class, $other);
        self::assertSame('Bloquée par ' . $firstNameOnly, $other->getMessage(), 'le diagnostic de l\'autre club n\'est pas réécrit');
        self::assertSame($firstNameOnly, $other->getCauses()[0]['label'] ?? null, 'ni sa cause');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeErasureDecorFiles();
        parent::tearDown();
    }

    /** Une indisponibilité de portée coach, visant ce rôle ; rend son identifiant. */
    private function coachRule(string $clubId, string $seasonId, string $coachId, string $name): string
    {
        $rule = (new Constraint)->setClubId($clubId)->setSeasonId($seasonId)
            ->setName($name)->setScope(ConstraintScope::COACH)->setScopeTargetId($coachId)
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['unavailableDays' => [2]]);
        $this->em->persist($rule);
        $this->em->flush();

        return $rule->getId();
    }
}
