<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Coach;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Tests\Integration\Structure\PersonEraserTest;
use App\Tests\PersonErasureDecorTrait;
use App\Tests\Security\PersonErasureLeavesNoTraceTest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La commande de support `app:people:erase --club=<uuid> --coach=<uuid> [--dry-run]` (décision 4, A) :
 * le support désigne la personne par l'identifiant de l'un de ses rôles coach, dans un club donné ; la
 * commande l'efface (code 0) ou répond « introuvable » (code 1), et ne parle qu'en identifiants et en
 * comptes. Elle laisse une trace d'audit `PERSON_ERASED` sans nom ni adresse ; un coach d'un autre club
 * est introuvable, et l'effacement ne touche ni le signalement ni l'homonyme d'un autre club (abus).
 *
 * Jumeaux cités : {@see PersonEraserTest} (chaque étape de l'effacement, appelé sans la commande),
 * {@see PersonErasureLeavesNoTraceTest} (le balayage de toutes les colonnes) ; ici la porte du support.
 */
#[Group('integration')]
final class ErasePersonCommandTest extends KernelTestCase
{
    use PersonErasureDecorTrait;

    private EntityManagerInterface $em;

    /** Désignée par son rôle de la saison PASSÉE, la personne est effacée de toutes les saisons ; l'audit ne porte que des identifiants. */
    public function testTheSupportErasesThePersonOfACoachAndTracesIdentifiersOnly(): void
    {
        $this->seedErasureDecor();

        $tester = $this->runErase(['--club' => $this->decor['club'], '--coach' => $this->decor['coachPast']]);

        self::assertSame(0, $tester->getStatusCode(), 'effacée : code 0');
        $this->scopeGucToClub($this->decor['club']);
        self::assertNull($this->em->find(Person::class, $this->decor['person']), 'sa fiche est effacée');
        self::assertNull($this->em->find(Coach::class, $this->decor['coach']), 'son rôle de la saison en cours aussi, désigné par celui de la saison passée');
        $output = $tester->getDisplay();
        foreach ($this->erasedPersonNeedles() as $needle) {
            self::assertStringNotContainsString($needle, $output, 'la sortie de la commande ne cite rien de la personne');
        }

        $rows = $this->decorConnection()->fetchAllAssociative('SELECT entity_id, details FROM audit_log WHERE club_id = ? AND action = ?', [$this->decor['club'], 'person.erased']);
        self::assertCount(1, $rows, 'une trace d\'audit de l\'effacement');
        $details = json_decode((string) $rows[0]['details'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($this->decor['person'], $details['personId'] ?? null, 'la trace nomme la fiche par son identifiant');
        $removed = $details['removedCoachIds'] ?? [];
        sort($removed);
        $expected = [$this->decor['coach'], $this->decor['coachPast']];
        sort($expected);
        self::assertSame($expected, $removed, 'et les rôles retirés par leurs identifiants');
        foreach ($this->erasedPersonNeedles() as $needle) {
            self::assertStringNotContainsString($needle, json_encode($rows[0], \JSON_UNESCAPED_UNICODE) ?: '', 'la trace d\'audit ne porte ni nom, ni mail, ni téléphone, ni commentaire');
        }
    }

    /** Le coach d'un autre club est « introuvable » : code 1, rien ne bouge, dans aucun des deux clubs (abus). */
    public function testACoachOfAnotherClubIsNotFoundAndNothingMoves(): void
    {
        $this->seedErasureDecor();

        $tester = $this->runErase(['--club' => $this->decor['club'], '--coach' => $this->decor['homonymCoach']]);

        self::assertSame(1, $tester->getStatusCode(), 'un coach d\'un autre club est introuvable : code 1');
        $this->scopeGucToClub($this->decor['clubB']);
        self::assertNotNull($this->em->find(Person::class, $this->decor['homonymPerson']), 'la fiche de l\'autre club reste');
        self::assertNotNull($this->em->find(Coach::class, $this->decor['homonymCoach']), 'et son rôle');
        $this->scopeGucToClub($this->decor['club']);
        self::assertNotNull($this->em->find(Person::class, $this->decor['person']), 'la personne du club désigné n\'est pas effacée à sa place');
        self::assertSame(0, (int) $this->decorConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND club_id IN (?, ?)', ['person.erased', $this->decor['club'], $this->decor['clubB']]), 'aucune trace d\'effacement');
    }

    public function testAnUnknownCoachIsNotFound(): void
    {
        $this->seedErasureDecor();

        $tester = $this->runErase(['--club' => $this->decor['club'], '--coach' => '00000000-0000-4000-8000-000000000000']);

        self::assertSame(1, $tester->getStatusCode(), 'un coach inconnu est introuvable : code 1');
        $this->scopeGucToClub($this->decor['club']);
        self::assertNotNull($this->em->find(Person::class, $this->decor['person']), 'rien n\'est effacé');
    }

    /** Effacer la personne d'un club ne touche ni l'homonyme, ni le signalement, ni le PDF d'un autre club (abus). */
    public function testErasingInOneClubLeavesTheHomonymTheFeedbackAndThePdfOfAnotherClubUntouched(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['clubB']);
        $contextBefore = $this->em->find(Feedback::class, $this->decor['feedbackB'])?->getContext();
        $this->em->clear();

        $tester = $this->runErase(['--club' => $this->decor['club'], '--coach' => $this->decor['coach']]);

        self::assertSame(0, $tester->getStatusCode(), 'témoin : la personne du club A est effacée');
        $this->scopeGucToClub($this->decor['clubB']);
        $homonym = $this->em->find(Person::class, $this->decor['homonymPerson']);
        self::assertInstanceOf(Person::class, $homonym, 'la fiche de l\'homonyme de l\'autre club reste');
        self::assertSame([self::ERASED_FIRST_NAME, $this->decor['lastName']], [$homonym->getFirstName(), $homonym->getLastName()], 'avec son nom');
        self::assertSame($contextBefore, $this->em->find(Feedback::class, $this->decor['feedbackB'])?->getContext(), 'le signalement de l\'autre club qui la cite n\'est pas réécrit');
        self::assertFileExists($this->decor['pdfB'], 'le PDF de l\'autre club reste sur le disque');
        self::assertNotNull($this->em->find(Schedule::class, $this->decor['scheduleB'])?->getPdfExportUrl(), 'et son adresse');
    }

    /** `--dry-run` compte sans rien effacer ni tracer. */
    public function testADryRunErasesNothing(): void
    {
        $this->seedErasureDecor();

        $tester = $this->runErase(['--club' => $this->decor['club'], '--coach' => $this->decor['coach'], '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode(), 'la personne est trouvée : code 0');
        $this->scopeGucToClub($this->decor['club']);
        self::assertNotNull($this->em->find(Person::class, $this->decor['person']), 'rien n\'est effacé à blanc');
        self::assertNotNull($this->em->find(Coach::class, $this->decor['coach']), 'ses rôles restent');
        self::assertFileExists($this->decor['pdfV1'], 'les PDF restent');
        self::assertSame(0, (int) $this->decorConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND club_id = ?', ['person.erased', $this->decor['club']]), 'aucune trace d\'effacement');
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

    /** @param array<string, mixed> $input */
    private function runErase(array $input): CommandTester
    {
        $this->clearGuc();
        $tester = new CommandTester(new Application(self::$kernel)->find('app:people:erase'));
        $tester->execute($input);
        $this->em->clear();

        return $tester;
    }
}
