<?php

declare(strict_types=1);

namespace App\Tests\Integration\Structure;

use App\Entity\ClubMailboxMessage;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishToken;
use App\Entity\Constraint;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\TeamCoachRole;
use App\Service\ScheduleConstraintBuilder;
use App\Structure\PersonEraser;
use App\Tests\PersonErasureDecorTrait;
use App\Tests\Security\PersonErasureLeavesNoTraceTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Effacer une personne (lot conception 3, PR 3, `PersonEraser`) retire ce qui vient d'elle de chaque
 * endroit du relevé, une règle à la fois, et laisse lisible ce qui ne vient pas d'elle : ses rôles
 * quittent toutes les saisons (lecture seule comprise, décision 5), ses séances restent sans coach, la
 * collecte ouverte passe au coach suivant, sa doléance perd son commentaire, les demandes figées et
 * les signalements gardent leur contenu avec « Personne supprimée » à la place de son nom (empreinte
 * réalignée), les PDF stockés du club sont supprimés et leur adresse remise à vide.
 *
 * Un cas par étape du plan (§4 PR 3) : ce que dit chaque étape, nommé. Jumeaux cités :
 * {@see PersonErasureLeavesNoTraceTest} (le balayage de toutes les colonnes, la promesse d'ensemble),
 * `features/effacer-une-personne-efface-tout.feature` (la même promesse par les vrais gestes), ici le
 * détail de chaque étape et ce qui doit RESTER.
 */
#[Group('integration')]
final class PersonEraserTest extends KernelTestCase
{
    use PersonErasureDecorTrait;

    private EntityManagerInterface $em;

    /** Étape 7 : les PDF stockés du club partent du disque, leur adresse est remise à vide ; ceux d'un autre club restent. */
    public function testTheStoredPdfsOfTheClubAreDeletedAndTheirAddressesClearedThoseOfAnotherClubStay(): void
    {
        $this->seedErasureDecor();
        [$v1File, $v0File, $bFile] = [$this->decor['pdfV1'], $this->decor['pdfV0'], $this->decor['pdfB']];
        self::assertFileExists($v1File, 'témoin : le PDF de la version en cours est sur le disque');

        $this->erase();

        self::assertFileDoesNotExist($v1File, 'le PDF de la version en cours est supprimé');
        self::assertFileDoesNotExist($v0File, 'le PDF de la saison passée aussi');
        foreach (['v1', 'v0'] as $key) {
            $schedule = $this->em->find(Schedule::class, $this->decor[$key]);
            self::assertInstanceOf(Schedule::class, $schedule);
            self::assertNull($schedule->getPdfExportUrl(), 'l\'adresse du PDF est remise à vide sur la version ' . $key);
            self::assertNotSame('completed', $schedule->getPdfExportStatus(), 'la version ne se dit plus « PDF prêt » : il se refabrique à la demande');
        }
        self::assertNull($this->em->find(Season::class, $this->decor['current'])?->getExportPdfUrl(), 'le PDF épinglé sur la saison est désépinglé');

        $this->scopeGucToClub($this->decor['clubB']);
        self::assertFileExists($bFile, 'le PDF d\'un autre club reste sur le disque');
        self::assertNotNull($this->em->find(Schedule::class, $this->decor['scheduleB'])?->getPdfExportUrl(), 'et son adresse reste');
    }

    /**
     * Étape 4, demandes figées : la fiche du coach et le nom de ses règles sont réécrits PAR IDENTIFIANT,
     * dans chaque version du club (saison passée comprise), et l'empreinte de chaque version réécrite
     * reste celle de sa demande ; la fiche d'un autre coach n'y change pas.
     */
    public function testTheFrozenRequestsNameNoOneAndKeepTheirFingerprintAligned(): void
    {
        $this->seedErasureDecor();

        $result = $this->erase();

        self::assertSame(3, $result['rewrittenSchedules'], 'trois versions du club portaient son nom : la version en cours, celle de la saison passée, l\'échec');
        foreach (['v1' => $this->decor['coach'], 'v0' => $this->decor['coachPast'], 'failed' => $this->decor['coach']] as $key => $coachId) {
            $schedule = $this->em->find(Schedule::class, $this->decor[$key]);
            self::assertInstanceOf(Schedule::class, $schedule);
            $snapshot = $schedule->getSnapshotData();
            $entry = $this->entryById($snapshot['coaches'] ?? [], $coachId);
            self::assertNotNull($entry, 'témoin : la demande figée ' . $key . ' garde la ligne du coach, par son identifiant');
            self::assertSame(PersonEraser::PLACEHOLDER, trim(($entry['firstName'] ?? '') . ' ' . ($entry['lastName'] ?? '')), 'sa fiche dans la demande figée ' . $key . ' dit « Personne supprimée »');
            self::assertSame($schedule->getSnapshotHash(), ScheduleConstraintBuilder::structureHash($snapshot), 'l\'empreinte de la version ' . $key . ' est celle de sa demande réécrite');
            self::assertStringNotContainsString($this->decor['lastName'], (string) json_encode($snapshot), 'plus rien de son nom dans la demande figée ' . $key);
        }
        $v1 = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertInstanceOf(Schedule::class, $v1);
        $rule = $this->entryById($v1->getSnapshotData()['constraints'] ?? [], $this->decor['rule']);
        self::assertNotNull($rule, 'témoin : son indisponibilité reste dans la demande figée, par son identifiant');
        self::assertSame(PersonEraser::PLACEHOLDER . ' · indispo lundi', $rule['name'] ?? null, 'le nom de sa règle dit « Personne supprimée » à la place du sien');
        $dominique = $this->entryById($v1->getSnapshotData()['coaches'] ?? [], $this->decor['dominique']);
        self::assertSame(['Dominique', 'Martin'], [$dominique['firstName'] ?? null, $dominique['lastName'] ?? null], 'la fiche d\'un autre coach ne change pas');
    }

    /**
     * Étape 4, diagnostics : celui qui porte l'identifiant du coach part par la cascade (règle actuelle,
     * `CascadePlan::forCoach`) ; celui d'une autre équipe qui la cite reste lisible, son nom remplacé.
     */
    public function testADiagnosticOfAnotherTeamThatCitesHerStaysReadableWithHerNameReplaced(): void
    {
        $this->seedErasureDecor();

        $this->erase();

        self::assertNull($this->em->find(ScheduleDiagnostic::class, $this->decor['ownDiagnostic']), 'le diagnostic qui porte son identifiant part avec son rôle');
        $cited = $this->em->find(ScheduleDiagnostic::class, $this->decor['citedDiagnostic']);
        self::assertInstanceOf(ScheduleDiagnostic::class, $cited, 'le diagnostic d\'une autre équipe reste');
        self::assertSame('SM1 : créneau partagé avec ' . PersonEraser::PLACEHOLDER . ' (U13M1)', $cited->getMessage(), 'lisible, son nom remplacé');
    }

    /**
     * Étapes 2 et 8, décision 5 (A) : ses rôles quittent TOUTES les saisons, la saison passée en lecture
     * seule comprise, sa fiche est supprimée ; ses séances restent, sans coach. Le plan ne prévoit aucun
     * refus pour un rôle dans une saison archivée (§4 PR 3 : « pour chaque saison du club, lecture seule
     * comprise »).
     */
    public function testSheLeavesEverySeasonArchivedOneIncludedAndHerSessionsStayWithoutCoach(): void
    {
        $this->seedErasureDecor();
        $sessionsBefore = $this->sessionsOf([$this->decor['v1'], $this->decor['v0']]);
        self::assertSame(4, \count($sessionsBefore), 'témoin : quatre séances dans les deux saisons');

        $result = $this->erase();

        $removed = $result['removedCoachIds'];
        sort($removed);
        $expected = [$this->decor['coach'], $this->decor['coachPast']];
        sort($expected);
        self::assertSame($expected, $removed, 'ses deux rôles, saison en cours et saison passée, sont retirés');
        self::assertNull($this->em->find(Coach::class, $this->decor['coach']), 'plus de rôle dans la saison en cours');
        self::assertNull($this->em->find(Coach::class, $this->decor['coachPast']), 'plus de rôle dans la saison passée, archivée');
        self::assertNull($this->em->find(Person::class, $this->decor['person']), 'sa fiche est supprimée');
        self::assertSame([], $this->em->getRepository(TeamCoach::class)->findBy(['coachId' => $expected]), 'plus aucun lien d\'équipe');
        self::assertNull($this->em->find(Constraint::class, $this->decor['rule']), 'son indisponibilité part');

        $sessionsAfter = $this->sessionsOf([$this->decor['v1'], $this->decor['v0']]);
        self::assertSame(array_keys($sessionsBefore), array_keys($sessionsAfter), 'toutes les séances restent, saison passée comprise');
        foreach ($sessionsAfter as $id => $coachId) {
            $expectedCoach = \in_array($sessionsBefore[$id], $expected, true) ? null : $sessionsBefore[$id];
            self::assertSame($expectedCoach, $coachId, 'une séance de la personne effacée reste sans coach, les autres gardent le leur');
        }
        self::assertNotNull($this->em->find(Person::class, $this->decor['dominiquePerson']), 'la fiche d\'un autre coach reste');
    }

    /** Étapes 1 et 3 : la doléance qu'elle a écrite reste, sans son commentaire ; la collecte ouverte désigne le coach suivant. */
    public function testHerWishLosesItsCommentAndTheOpenCollecteNamesTheNextCoach(): void
    {
        $this->seedErasureDecor();
        $tokens = $this->tokensByCoach();
        self::assertSame([$this->decor['coach']], array_keys($tokens), 'témoin : elle est la seule destinataire de la collecte');

        $result = $this->erase();

        self::assertSame(1, $result['blankedWishes'], 'une doléance avait son commentaire');
        $wish = $this->em->find(CoachWish::class, $this->decor['wish']);
        self::assertInstanceOf(CoachWish::class, $wish, 'la doléance reste : son information d\'équipe sert au plan de vacances');
        self::assertNull($wish->getComment(), 'son commentaire est vidé');
        self::assertSame([], $wish->getStaffUnavailability(), 'sa ligne d\'absence est retirée');
        $tokens = $this->tokensByCoach();
        self::assertArrayNotHasKey($this->decor['coach'], $tokens, 'son lien de collecte part');
        self::assertSame([$this->decor['u15']], $tokens[$this->decor['dominique']] ?? null, 'le coach suivant répond pour l\'équipe qu\'elle partageait');
    }

    /**
     * Étape 4, signalements : le signalement d'un planning nommé et celui né d'une génération en échec
     * gardent leur contenu (demande et diagnostics embarqués), avec « Personne supprimée » à la place de son nom.
     */
    public function testTheFeedbacksOfTheClubKeepTheirContentWithHerNameReplaced(): void
    {
        $this->seedErasureDecor();

        $result = $this->erase();

        self::assertSame(2, $result['rewrittenFeedbacks'], 'deux signalements du club la citaient');
        $named = $this->em->find(Feedback::class, $this->decor['feedbackNamed'])?->getContext() ?? [];
        self::assertSame('SM1 : créneau partagé avec ' . PersonEraser::PLACEHOLDER . ' (U13M1)', $named['diagnostics'][1]['message'] ?? null, 'le diagnostic embarqué reste lisible, nom remplacé');
        self::assertSame(PersonEraser::PLACEHOLDER, trim(($this->entryById($named['snapshot']['coaches'] ?? [], $this->decor['coach'])['firstName'] ?? '') . ' ' . ($this->entryById($named['snapshot']['coaches'] ?? [], $this->decor['coach'])['lastName'] ?? '')), 'la demande embarquée dit « Personne supprimée »');
        $failure = $this->em->find(Feedback::class, $this->decor['feedbackFailure'])?->getContext() ?? [];
        self::assertSame('Aucun créneau pour U13M1 : ' . PersonEraser::PLACEHOLDER . ' est indisponible', $failure['auto']['generationFailure']['firstError'] ?? null, 'la raison de l\'échec reste lisible, nom remplacé');
        self::assertStringNotContainsString($this->decor['lastName'], (string) json_encode($failure), 'plus rien de son nom dans le signalement de l\'échec');
    }

    /** Étape 6 : dans la boîte du club de démonstration, les messages qui lui sont adressés partent ; le récapitulatif qui la cite reste, nom remplacé. */
    public function testInTheDemoMailboxMessagesToHerGoAndTheDigestThatCitesHerStaysWithHerNameReplaced(): void
    {
        $this->seedErasureDecor(demo: true);

        $result = $this->erase();

        self::assertSame(1, $result['rewrittenMailboxMessages'], 'un message de la boîte la citait sans lui être adressé');
        self::assertNull($this->em->find(ClubMailboxMessage::class, $this->decor['mailAddressed']), 'le message qui lui est adressé est supprimé');
        $digest = $this->em->find(ClubMailboxMessage::class, $this->decor['mailDigest']);
        self::assertInstanceOf(ClubMailboxMessage::class, $digest, 'le récapitulatif adressé au gestionnaire reste');
        self::assertSame('Collecte : réponse de ' . PersonEraser::PLACEHOLDER, $digest->getSubject(), 'son sujet dit « Personne supprimée »');
        self::assertSame("Nouveaux répondants :\n- " . PersonEraser::PLACEHOLDER . ' (U15M1)', $digest->getBodyText(), 'son corps aussi');
    }

    /**
     * L'effacement agit par IDENTIFIANT (20 coachs de production sur 52 n'ont qu'un prénom) : une AUTRE
     * personne du même club, au même prénom et au même nom, garde sa fiche, son rôle et sa ligne dans
     * les demandes figées.
     */
    public function testAnotherPersonOfTheClubWithTheSameNameKeepsHerRecordAndHerLineInTheFrozenRequests(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        $twin = $this->newCoach($this->decor['club'], $this->decor['current'], self::ERASED_FIRST_NAME, $this->decor['lastName']);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($this->decor['club'])->setSeasonId($this->decor['current'])
            ->setTeamId($this->decor['sm1'])->setCoachId($twin->getId())->setRole(TeamCoachRole::ASSISTANT)->setCreatedAt(new DateTimeImmutable('2025-09-03')));
        $this->em->flush();
        $later = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertInstanceOf(Schedule::class, $later);
        $payload = $this->freshEngineRequest($this->decor['club'], $this->decor['current']);
        $later->setSnapshotData($payload)->setSnapshotHash(ScheduleConstraintBuilder::structureHash($payload));
        $this->em->flush();
        [$twinId, $twinPerson] = [$twin->getId(), $twin->getPersonId()];
        $this->em->clear();
        self::assertNotNull($this->entryById($payload['coaches'] ?? [], $twinId), 'témoin : sa ligne est dans la demande figée');

        $this->erase();

        $person = $this->em->find(Person::class, $twinPerson);
        self::assertInstanceOf(Person::class, $person, 'la fiche de l\'homonyme du même club reste');
        self::assertSame([self::ERASED_FIRST_NAME, $this->decor['lastName']], [$person->getFirstName(), $person->getLastName()], 'avec son nom');
        self::assertNotNull($this->em->find(Coach::class, $twinId), 'et son rôle');
        $entry = $this->entryById($this->em->find(Schedule::class, $this->decor['v1'])?->getSnapshotData()['coaches'] ?? [], $twinId);
        self::assertSame([self::ERASED_FIRST_NAME, $this->decor['lastName']], [$entry['firstName'] ?? null, $entry['lastName'] ?? null], 'sa ligne de la demande figée garde son nom');
    }

    /**
     * Un nom d'un seul mot (« Paul », sans nom de famille) n'est JAMAIS remplacé par correspondance de
     * texte : le diagnostic qui cite une AUTRE personne, « Paul Martin », reste tel quel. Règle du §12 du
     * plan (« l'effacement ne supprime ni ne remplace jamais par texte un nom réduit au prénom »), que la
     * limite 7 du §13 contredit (« remplacé partout […] y compris à l'intérieur d'un autre nom ») : ce cas
     * suit le §12 et la consigne de l'orchestrateur ; à retirer si le fondateur tranche pour la limite 7.
     */
    public function testAOneWordNameIsNeverReplacedInsideTheNameOfAnotherPerson(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        $paul = $this->newCoach($this->decor['club'], $this->decor['current'], 'Paul', '');
        $this->newCoach($this->decor['club'], $this->decor['current'], 'Paul', 'Martin');
        $this->em->flush();
        $v1 = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertInstanceOf(Schedule::class, $v1);
        $diagnostic = (new ScheduleDiagnostic)->setClubId($this->decor['club'])->setSeasonId($this->decor['current'])->setScheduleId($v1->getId())
            ->setType('coach_overload')->setSeverity(ScheduleDiagnosticSeverity::WARNING)->setTeamId($this->decor['sm1'])
            ->setMessage('SM1 : Paul Martin arrive après le début');
        $this->em->persist($diagnostic);
        $this->em->flush();
        [$paulPerson, $diagnosticId] = [$paul->getPersonId(), $diagnostic->getId()];
        $this->em->clear();

        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $paulPerson);
        $this->em->clear();

        self::assertNull($this->em->find(Person::class, $paulPerson), 'témoin : la fiche de « Paul » est effacée');
        self::assertSame('SM1 : Paul Martin arrive après le début', $this->em->find(ScheduleDiagnostic::class, $diagnosticId)?->getMessage(), 'le nom d\'une autre personne qui contient « Paul » n\'est jamais réécrit');
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

    /** @return array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int} */
    private function erase(): array
    {
        $this->scopeGucToClub($this->decor['club']);
        $result = self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        $this->em->clear();
        $this->scopeGucToClub($this->decor['club']);

        return $result;
    }

    /**
     * @param mixed $rows
     *
     * @return array<string, mixed>|null
     */
    private function entryById($rows, string $id): ?array
    {
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && ($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<string> $scheduleIds
     *
     * @return array<string, string|null> id de séance → coach
     */
    private function sessionsOf(array $scheduleIds): array
    {
        $this->scopeGucToClub($this->decor['club']);
        $sessions = [];
        foreach ($this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleIds], ['id' => 'ASC']) as $slot) {
            $sessions[$slot->getId()] = $slot->getCoachId();
        }
        $this->em->clear();

        return $sessions;
    }

    /** @return array<string, list<string>> coach → équipes pour lesquelles il répond, dans la collecte du décor */
    private function tokensByCoach(): array
    {
        $this->scopeGucToClub($this->decor['club']);
        $tokens = [];
        foreach ($this->em->getRepository(CoachWishToken::class)->findBy(['campaignId' => $this->decor['campaign']]) as $token) {
            if ([] !== $token->getTeamIds()) {
                $tokens[$token->getCoachId()] = $token->getTeamIds();
            }
        }
        $this->em->clear();

        return $tokens;
    }
}
