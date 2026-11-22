<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Coach;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Service\ClubGenerationLock;
use App\Service\MatchPlacementLock;
use App\Service\PdfGenerator;
use App\Structure\PersonEraser;
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

    /**
     * Une génération ou un placement de matchs en cours dans le club (son verrou tenu) refuse
     * l'effacement : code 1, rien ne bouge, sinon le run en cours réécrirait son nom après la
     * validation. Jumeau, les verrous tenus pendant l'écriture et rendus :
     * {@see PersonEraserTest::testTheErasureHoldsTheClubLocksWhileWritingAndGivesThemBackAfterSuccessAndFailure}.
     */
    public function testAGenerationOrPlacementInProgressRefusesTheErasureAndNothingMoves(): void
    {
        $this->seedErasureDecor();
        $locks = [
            'génération' => self::getContainer()->get(ClubGenerationLock::class),
            'placement de matchs' => self::getContainer()->get(MatchPlacementLock::class),
        ];

        foreach ($locks as $run => $lock) {
            $token = $lock->acquire($this->decor['club'], 60);
            self::assertNotNull($token, 'témoin : le verrou de ' . $run . ' est pris');

            try {
                $tester = $this->runErase(['--club' => $this->decor['club'], '--coach' => $this->decor['coach']]);
            } finally {
                $lock->release($this->decor['club'], $token);
            }

            self::assertSame(1, $tester->getStatusCode(), 'un(e) ' . $run . ' en cours refuse l\'effacement : code 1');
            $this->scopeGucToClub($this->decor['club']);
            self::assertNotNull($this->em->find(Person::class, $this->decor['person']), 'sa fiche reste (' . $run . ')');
            self::assertNotNull($this->em->find(Coach::class, $this->decor['coach']), 'son rôle reste (' . $run . ')');
            self::assertFileExists($this->decor['pdfV1'], 'les PDF restent (' . $run . ')');
            self::assertSame(0, (int) $this->decorConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND club_id = ?', ['person.erased', $this->decor['club']]), 'aucune trace d\'effacement (' . $run . ')');
            $this->em->clear();
        }
    }

    /**
     * Reprise après un arrêt entre la validation et la suppression des fichiers : la personne est déjà
     * effacée (« introuvable » par son rôle), ses PDF sont restés sur le disque. `--sweep-files` balaie
     * les rendus de chaque planning du club, vue club et vues par gymnase, et ne touche à aucun fichier
     * d'un autre club.
     */
    public function testTheFileSweepResumesAfterAStopBetweenCommitAndFileDeletionAndTouchesOnlyTheClub(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        $this->em->clear();
        $left = [];
        foreach ([$this->decor['v1'] => ['all', 'venue-nord'], $this->decor['v0'] => ['all']] as $scheduleId => $views) {
            foreach ($views as $view) {
                $left[] = $this->leftBehindRender((string) $scheduleId, $view);
            }
        }
        self::assertSame(1, $this->runErase(['--club' => $this->decor['club'], '--coach' => $this->decor['coach']])->getStatusCode(), 'témoin : rejouée par son rôle, la commande ne la trouve plus');
        foreach ($left as $path) {
            self::assertFileExists($path, 'témoin : le rendu est resté sur le disque');
        }

        $tester = $this->runErase(['--club' => $this->decor['club'], '--sweep-files' => true]);

        self::assertSame(0, $tester->getStatusCode(), 'la reprise du balayage réussit : code 0');
        foreach ($left as $path) {
            self::assertFileDoesNotExist($path, 'le rendu resté sur le disque est supprimé');
        }
        self::assertFileExists($this->decor['pdfB'], 'le PDF d\'un autre club reste sur le disque');
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

    /** Un rendu laissé sur le disque (`schedule-<id>-<vue>.pdf`), retiré en fin de test s'il reste. */
    private function leftBehindRender(string $scheduleId, string $view): string
    {
        $path = PdfGenerator::outputDir() . '/schedule-' . $scheduleId . '-' . $view . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->decorFiles[] = $path;

        return $path;
    }
}
