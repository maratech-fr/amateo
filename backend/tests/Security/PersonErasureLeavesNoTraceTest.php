<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Entity\TeamCoach;
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
}
