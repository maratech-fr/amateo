<?php

declare(strict_types=1);

namespace App\Tests\Integration\Structure;

use App\Entity\Club;
use App\Entity\ClubMailboxMessage;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachWish;
use App\Entity\CoachWishToken;
use App\Entity\Constraint;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\TeamCoachRole;
use App\Plans\SoclePlan;
use App\Service\ClubGenerationLock;
use App\Service\MatchPlacementLock;
use App\Service\ScheduleConstraintBuilder;
use App\Service\StructureSnapshotter;
use App\Structure\PersonEraser;
use App\Tests\Integration\Command\ErasePersonCommandTest;
use App\Tests\PersonErasureDecorTrait;
use App\Tests\Security\PersonErasureLeavesNoTraceTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

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

    /**
     * Étape 7 : les PDF stockés du club partent du disque, leur adresse est remise à vide avec
     * l'identifiant de la demande livrée (sans quoi la demande relue serait tenue pour déjà rendue et
     * le PDF jamais refabriqué) ; ceux d'un autre club restent.
     */
    public function testTheStoredPdfsOfTheClubAreDeletedAndTheirAddressesClearedThoseOfAnotherClubStay(): void
    {
        $this->seedErasureDecor();
        [$v1File, $v0File, $bFile] = [$this->decor['pdfV1'], $this->decor['pdfV0'], $this->decor['pdfB']];
        self::assertFileExists($v1File, 'témoin : le PDF de la version en cours est sur le disque');
        $this->scopeGucToClub($this->decor['club']);
        self::assertNotNull($this->em->find(Schedule::class, $this->decor['v1'])?->getPdfExportRequestId(), 'témoin : la version porte l\'identifiant de la demande livrée');
        $this->em->clear();

        $this->erase();

        self::assertFileDoesNotExist($v1File, 'le PDF de la version en cours est supprimé');
        self::assertFileDoesNotExist($v0File, 'le PDF de la saison passée aussi');
        foreach (['v1', 'v0'] as $key) {
            $schedule = $this->em->find(Schedule::class, $this->decor[$key]);
            self::assertInstanceOf(Schedule::class, $schedule);
            self::assertNull($schedule->getPdfExportUrl(), 'l\'adresse du PDF est remise à vide sur la version ' . $key);
            self::assertNull($schedule->getPdfExportRequestId(), 'l\'identifiant de la demande livrée aussi, sur la version ' . $key);
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

    /**
     * Étape 4, sa règle désignée par IDENTIFIANT (portée coach, cible = son rôle) : un nom de règle qui
     * ne contient pas son nom composé (« Indispo de Camille mardi », prénom seul) est remplacé EN ENTIER
     * dans la demande figée, puisque la correspondance de texte ne l'y trouverait pas.
     */
    public function testHerRuleNamedWithoutHerFullNameIsRenamedWhollyInTheFrozenRequest(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        $rule = (new Constraint)->setClubId($this->decor['club'])->setSeasonId($this->decor['current'])
            ->setName('Indispo de ' . self::ERASED_FIRST_NAME . ' mardi')->setScope(ConstraintScope::COACH)->setScopeTargetId($this->decor['coach'])
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['unavailableDays' => [2]]);
        $this->em->persist($rule);
        $this->em->flush();
        $this->refreezeV1();
        $ruleId = $rule->getId();
        $this->em->clear();

        $this->erase();

        $entry = $this->entryById($this->em->find(Schedule::class, $this->decor['v1'])?->getSnapshotData()['constraints'] ?? [], $ruleId);
        self::assertNotNull($entry, 'témoin : sa règle reste dans la demande figée, par son identifiant');
        self::assertSame(PersonEraser::PLACEHOLDER, $entry['name'] ?? null, 'le nom de sa règle, qui ne porte que son prénom, devient « Personne supprimée » en entier');
    }

    /**
     * Étape 4, diagnostics qui restent : leurs suggestions (textes) et leurs causes mesurées (le nom de
     * la règle en cause, `label`) perdent son nom composé comme leur message.
     */
    public function testTheSuggestionsAndCausesOfARemainingDiagnosticLoseHerName(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        $cited = $this->em->find(ScheduleDiagnostic::class, $this->decor['citedDiagnostic']);
        self::assertInstanceOf(ScheduleDiagnostic::class, $cited);
        $cited->setSuggestions(['Libérer un créneau de ' . $this->decor['fullName']])
            ->setCauses([['kind' => 'rule', 'constraintId' => $this->decor['rule'], 'label' => $this->decor['fullName'] . ' · indispo lundi', 'count' => 1]]);
        $this->em->flush();
        $this->em->clear();

        $this->erase();

        $cited = $this->em->find(ScheduleDiagnostic::class, $this->decor['citedDiagnostic']);
        self::assertInstanceOf(ScheduleDiagnostic::class, $cited);
        self::assertSame(['Libérer un créneau de ' . PersonEraser::PLACEHOLDER], $cited->getSuggestions(), 'la suggestion reste lisible, nom remplacé');
        self::assertSame(PersonEraser::PLACEHOLDER . ' · indispo lundi', $cited->getCauses()[0]['label'] ?? null, 'la cause reste lisible, nom remplacé');
    }

    /**
     * Étape 5 : la photo d'une version (ce que « Charger » réinsère) perd sa ligne de coach, ses liens
     * d'équipe, ses liens de joueur et ses règles ; les lignes d'un autre coach restent. Vu sur la
     * photo elle-même : « Charger » filtre aussi les personnes disparues, le balayage et
     * {@see PersonErasureLeavesNoTraceTest} ne voient donc pas une ligne d'identifiants oubliée.
     */
    public function testThePhotoOfAVersionNoLongerHoldsHerRowHerLinksOrHerRules(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        foreach ([$this->decor['coach'], $this->decor['dominique']] as $coachId) {
            $this->em->persist((new CoachPlayerMembership)->setClubId($this->decor['club'])->setSeasonId($this->decor['current'])
                ->setCoachId($coachId)->setTeamId($this->decor['sm1'])->setPosition('meneur'));
        }
        $this->em->flush();
        $snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $v1 = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertInstanceOf(Schedule::class, $v1);
        $snapshotter->store($v1, $snapshotter->serialize($this->decor['club'], $this->decor['current']));
        $this->em->clear();
        $before = $this->photoRowsOf($this->decor['coach']);
        self::assertSame(['Coach' => 1, 'TeamCoach' => 2, 'CoachPlayerMembership' => 1, 'Constraint' => 1], $before, 'témoin : la photo porte sa ligne, ses deux liens d\'équipe, son lien de joueur et sa règle');

        $this->erase();

        self::assertSame(['Coach' => 0, 'TeamCoach' => 0, 'CoachPlayerMembership' => 0, 'Constraint' => 0], $this->photoRowsOf($this->decor['coach']), 'plus rien d\'elle dans la photo');
        self::assertSame(['Coach' => 1, 'TeamCoach' => 2, 'CoachPlayerMembership' => 1, 'Constraint' => 0], $this->photoRowsOf($this->decor['dominique']), 'les lignes d\'un autre coach restent');
    }

    /**
     * Étape 6, boîte d'un club de démonstration : un message adressé à elle parmi d'autres
     * destinataires, son adresse écrite dans une autre casse, part ; le corps HTML d'un message qui la
     * cite perd son nom, écrit tel quel ET échappé (« O'Neil » rendu « O&#039;Neil » par le gabarit).
     */
    public function testInTheDemoMailboxTheHtmlBodyLosesHerNameRawAndEscapedAndAMessageToHerAmongOthersGoes(): void
    {
        $this->seedErasureDecor(demo: true);
        $this->scopeGucToClub($this->decor['club']);
        $anne = $this->newCoach($this->decor['club'], $this->decor['current'], 'Anne', 'O\'Neil');
        $this->personOfDecorCoach($anne)->setEmail('anne.oneil.' . $this->decor['uid'] . '@exemple.fr');
        $this->em->flush();
        $toHer = (new ClubMailboxMessage)->setClubId($this->decor['club'])->setSimulatedDate(new DateTimeImmutable('today'))
            ->setFromAddress('collecte@exemple.fr')->setToAddress($this->decor['managerEmail'] . ', ANNE.ONEIL.' . strtoupper($this->decor['uid']) . '@EXEMPLE.FR')
            ->setSubject('Rappel')->setBodyText('Merci de répondre.');
        $digest = (new ClubMailboxMessage)->setClubId($this->decor['club'])->setSimulatedDate(new DateTimeImmutable('today'))
            ->setFromAddress('collecte@exemple.fr')->setToAddress($this->decor['managerEmail'])
            ->setSubject('Récapitulatif')->setBodyHtml('<li>Anne O&#039;Neil (U15M1)</li><p>Anne O\'Neil</p>');
        $this->em->persist($toHer);
        $this->em->persist($digest);
        $this->em->flush();
        [$person, $toHerId, $digestId] = [$anne->getPersonId(), $toHer->getId(), $digest->getId()];
        $this->em->clear();

        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $person);
        $this->em->clear();
        $this->scopeGucToClub($this->decor['club']);

        self::assertNull($this->em->find(ClubMailboxMessage::class, $toHerId), 'le message qui lui est adressé, parmi d\'autres destinataires et dans une autre casse, part');
        self::assertSame('<li>' . PersonEraser::PLACEHOLDER . ' (U15M1)</li><p>' . PersonEraser::PLACEHOLDER . '</p>', $this->em->find(ClubMailboxMessage::class, $digestId)?->getBodyHtml(), 'le corps HTML perd son nom, échappé comme tel quel');
    }

    /**
     * Étape 7 : la demande au moteur que le club a en cache ne la sert plus. Une commande de support ne
     * passe pas par la fin de requête qui vide ce cache après une écriture : sans le vidage de
     * l'effacement, la génération suivante partirait avec son nom.
     */
    public function testTheCachedEngineRequestOfTheClubNoLongerServesHer(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        self::assertNotNull($this->entryById($this->freshEngineRequest($this->decor['club'], $this->decor['current'])['coaches'] ?? [], $this->decor['coach']), 'témoin : la demande mise en cache la porte');
        $this->em->clear();

        $this->erase();

        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForPlan(self::getContainer()->get(SoclePlan::class)->idOf($this->decor['current']));
        self::assertNull($this->entryById($payload['coaches'] ?? [], $this->decor['coach']), 'la demande suivante ne la porte plus');
        self::assertStringNotContainsString($this->decor['lastName'], (string) json_encode($payload, \JSON_UNESCAPED_UNICODE), 'ni son nom');
    }

    /**
     * Le nom composé est remplacé en MOT ENTIER, casse ignorée : effacer « Paul Martin » réécrit
     * « PAUL MARTIN » mais ne touche ni « Paul Martinez » ni « Jean-Paul Martin », deux autres personnes.
     */
    public function testHerFullNameIsReplacedAsAWholeWordCaseIgnoredNeverInsideAnotherName(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['club']);
        $paul = $this->newCoach($this->decor['club'], $this->decor['current'], 'Paul', 'Martin');
        $this->em->flush();
        $messages = [
            'SM1 : Paul Martinez arrive après le début' => 'SM1 : Paul Martinez arrive après le début',
            'SM1 : Jean-Paul Martin arrive après le début' => 'SM1 : Jean-Paul Martin arrive après le début',
            'SM1 : PAUL MARTIN arrive après le début' => 'SM1 : ' . PersonEraser::PLACEHOLDER . ' arrive après le début',
        ];
        $v1 = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertInstanceOf(Schedule::class, $v1);
        $ids = [];
        foreach (array_keys($messages) as $message) {
            $ids[$message] = $this->erasureDiagnostic($this->em->find(Club::class, $this->decor['club']) ?? throw new LogicException('club'), $this->em->find(Season::class, $this->decor['current']) ?? throw new LogicException('saison'), $v1, $message, teamId: $this->decor['sm1'])->getId();
        }
        $person = $paul->getPersonId();
        $this->em->clear();

        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $person);
        $this->em->clear();
        $this->scopeGucToClub($this->decor['club']);

        foreach ($messages as $before => $after) {
            self::assertSame($after, $this->em->find(ScheduleDiagnostic::class, $ids[$before])?->getMessage(), 'mot entier, casse ignorée : « ' . $before . ' »');
        }
    }

    /**
     * Isolation entre clubs : appelé depuis le contexte d'un AUTRE club (GUC de B), l'effacement agit
     * dans le club nommé (il pose lui-même le contexte de A), et rend ensuite au GUC sa valeur d'avant ;
     * appelé sans contexte, il le rend vide. Jumeau à la porte du support :
     * {@see ErasePersonCommandTest::testACoachOfAnotherClubIsNotFoundAndNothingMoves}.
     */
    public function testCalledFromAnotherClubContextItErasesInTheNamedClubAndGivesTheContextBack(): void
    {
        $this->seedErasureDecor();

        $this->scopeGucToClub($this->decor['clubB']);
        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        self::assertSame($this->decor['clubB'], $this->currentGuc(), 'le contexte du club B est rendu après l\'effacement');
        $this->em->clear();
        $this->scopeGucToClub($this->decor['club']);
        self::assertNull($this->em->find(Person::class, $this->decor['person']), 'la personne du club A est effacée');
        $this->scopeGucToClub($this->decor['clubB']);
        self::assertNotNull($this->em->find(Person::class, $this->decor['homonymPerson']), 'l\'homonyme du club B reste');

        $this->scopeGucToClub($this->decor['club']);
        $other = $this->newCoach($this->decor['club'], $this->decor['current'], 'Lou', 'Bernard');
        $this->em->flush();
        $otherPerson = $other->getPersonId();
        $this->em->clear();
        $this->clearGuc();
        self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $otherPerson);
        self::assertSame('', $this->currentGuc(), 'appelé sans contexte, il le rend vide');
    }

    /**
     * Isolation entre clubs (abus) : la fiche d'un autre club ne s'efface pas au nom de ce club, même
     * appelé depuis le contexte de cet autre club ; rien ne bouge. Deux couches tiennent chacune seule :
     * le contexte du club nommé (RLS) et la clause de club sur la fiche.
     */
    public function testAPersonOfAnotherClubCannotBeErasedThroughThisClub(): void
    {
        $this->seedErasureDecor();
        $this->scopeGucToClub($this->decor['clubB']);

        try {
            self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['homonymPerson']);
            self::fail('la fiche d\'un autre club est introuvable');
        } catch (InvalidArgumentException) {
        }
        $this->em->clear();

        $this->scopeGucToClub($this->decor['clubB']);
        self::assertNotNull($this->em->find(Person::class, $this->decor['homonymPerson']), 'la fiche du club B reste');
        self::assertNotNull($this->em->find(Coach::class, $this->decor['homonymCoach']), 'et son rôle');
    }

    /**
     * Un échec au milieu (ici à l'écriture des signalements, après le retrait des rôles et de la fiche)
     * n'efface rien à moitié : la transaction est annulée, les PDF restent sur le disque (supprimés
     * seulement après la validation) et le GUC revient à sa valeur d'avant.
     */
    public function testAFailureMidwayErasesNothingAndGivesTheContextBack(): void
    {
        $this->seedErasureDecor();
        $failOnFeedbackWrite = new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Feedback) {
                        throw new RuntimeException('panne simulée à l\'écriture des signalements');
                    }
                }
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener([Events::onFlush], $failOnFeedbackWrite);
        $this->scopeGucToClub($this->decor['clubB']);

        try {
            self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
            self::fail('la panne simulée remonte');
        } catch (RuntimeException $failure) {
            self::assertSame('panne simulée à l\'écriture des signalements', $failure->getMessage());
        } finally {
            $events->removeEventListener([Events::onFlush], $failOnFeedbackWrite);
        }

        self::assertSame($this->decor['clubB'], $this->currentGuc(), 'le GUC revient à sa valeur d\'avant');
        $this->scopeGucToClub($this->decor['club']);
        $connection = $this->em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM person WHERE id = ?', [$this->decor['person']]), 'sa fiche reste');
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM coach WHERE person_id = ?', [$this->decor['person']]), 'ses deux rôles restent');
        self::assertSame($this->decor['comment'], $connection->fetchOne('SELECT comment FROM coach_wish WHERE id = ?', [$this->decor['wish']]), 'le commentaire de sa doléance reste');
        self::assertFileExists($this->decor['pdfV1'], 'le PDF reste sur le disque');
    }

    /**
     * Une erreur du moteur d'expressions pendant le remplacement du nom (ici la limite de retour
     * arrière épuisée, forcée après le retrait de sa fiche) ne vide pas le texte en silence : elle
     * remonte et la transaction est annulée, le diagnostic qui la cite garde son texte.
     */
    public function testAPatternFailureWhileReplacingHerNameAbortsTheErasureInsteadOfBlankingTheText(): void
    {
        $this->seedErasureDecor();
        $failPatternsOnceHerRecordIsGone = new class {
            private bool $personRemoved = false;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
                    $this->personRemoved = $this->personRemoved || $entity instanceof Person;
                }
            }

            public function postFlush(): void
            {
                if ($this->personRemoved) {
                    ini_set('pcre.jit', '0');
                    ini_set('pcre.backtrack_limit', '1');
                }
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener([Events::onFlush, Events::postFlush], $failPatternsOnceHerRecordIsGone);
        $this->scopeGucToClub($this->decor['club']);
        $thrown = null;

        try {
            self::getContainer()->get(PersonEraser::class)->erase($this->decor['club'], $this->decor['person']);
        } catch (Throwable $failure) {
            $thrown = $failure;
        } finally {
            ini_restore('pcre.backtrack_limit');
            ini_restore('pcre.jit');
            $events->removeEventListener([Events::onFlush, Events::postFlush], $failPatternsOnceHerRecordIsGone);
        }

        self::assertNotNull($thrown, 'l\'erreur du remplacement remonte au lieu de rendre un texte vide');
        $this->em->clear();
        $this->scopeGucToClub($this->decor['club']);
        $connection = $this->em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM person WHERE id = ?', [$this->decor['person']]), 'la transaction est annulée : sa fiche reste');
        self::assertSame('SM1 : créneau partagé avec ' . $this->decor['fullName'] . ' (U13M1)', $connection->fetchOne('SELECT message FROM schedule_diagnostic WHERE id = ?', [$this->decor['citedDiagnostic']]), 'le diagnostic qui la cite garde son texte, jamais vidé');
        self::assertFileExists($this->decor['pdfV1'], 'le PDF reste sur le disque');
    }

    /**
     * L'effacement tient les verrous de génération et de placement du club pendant qu'il écrit (une
     * génération lancée en même temps réécrirait son nom après la validation), et les rend après un
     * succès comme après un échec. Jumeau à la porte du support, verrou déjà tenu :
     * {@see ErasePersonCommandTest::testAGenerationOrPlacementInProgressRefusesTheErasureAndNothingMoves}.
     */
    public function testTheErasureHoldsTheClubLocksWhileWritingAndGivesThemBackAfterSuccessAndFailure(): void
    {
        $this->seedErasureDecor();
        $generationLock = self::getContainer()->get(ClubGenerationLock::class);
        $placementLock = self::getContainer()->get(MatchPlacementLock::class);
        $clubId = $this->decor['club'];
        $probe = new class($generationLock, $placementLock, $clubId) {
            /** @var list<array{bool, bool}> */
            public array $seen = [];

            public bool $fail = false;

            public function __construct(private readonly ClubGenerationLock $generation, private readonly MatchPlacementLock $placement, private readonly string $clubId) {}

            public function onFlush(OnFlushEventArgs $args): void
            {
                $this->seen[] = [$this->generation->isGenerating($this->clubId), $this->placement->isHeld($this->clubId)];
                if ($this->fail) {
                    throw new RuntimeException('panne simulée pendant l\'effacement');
                }
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener([Events::onFlush], $probe);

        try {
            $probe->fail = true;
            $this->scopeGucToClub($clubId);

            try {
                self::getContainer()->get(PersonEraser::class)->erase($clubId, $this->decor['person']);
                self::fail('la panne simulée remonte');
            } catch (RuntimeException $failure) {
                self::assertSame('panne simulée pendant l\'effacement', $failure->getMessage());
            }
            self::assertSame([[true, true]], $probe->seen, 'pendant l\'écriture, les deux verrous du club sont tenus');
            self::assertFalse($generationLock->isGenerating($clubId), 'après un échec, le verrou de génération est rendu');
            self::assertFalse($placementLock->isHeld($clubId), 'après un échec, le verrou de placement est rendu');

            $probe->fail = false;
            $probe->seen = [];
            $this->em->clear();
            $this->erase();
            self::assertNotSame([], $probe->seen, 'témoin : l\'effacement a écrit');
            self::assertSame(array_fill(0, \count($probe->seen), [true, true]), $probe->seen, 'à chaque écriture, les deux verrous du club sont tenus');
            self::assertNull($this->em->find(Person::class, $this->decor['person']), 'témoin : sa fiche est effacée');
            self::assertFalse($generationLock->isGenerating($clubId), 'après un succès, le verrou de génération est rendu');
            self::assertFalse($placementLock->isHeld($clubId), 'après un succès, le verrou de placement est rendu');
        } finally {
            $events->removeEventListener([Events::onFlush], $probe);
        }
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

    /** Refige la demande de la version en cours sur l'état présent du club (empreinte comprise). */
    private function refreezeV1(): void
    {
        $v1 = $this->em->find(Schedule::class, $this->decor['v1']);
        self::assertInstanceOf(Schedule::class, $v1);
        $payload = $this->freshEngineRequest($this->decor['club'], $this->decor['current']);
        $v1->setSnapshotData($payload)->setSnapshotHash(ScheduleConstraintBuilder::structureHash($payload));
        $this->em->flush();
    }

    /** @return array{Coach: int, TeamCoach: int, CoachPlayerMembership: int, Constraint: int} lignes de la photo de la version en cours qui désignent ce coach */
    private function photoRowsOf(string $coachId): array
    {
        $this->scopeGucToClub($this->decor['club']);
        $photo = $this->em->getRepository(ScheduleStructureSnapshot::class)->findOneBy(['scheduleId' => $this->decor['v1']]);
        self::assertInstanceOf(ScheduleStructureSnapshot::class, $photo);
        $data = $photo->getData();
        $this->em->clear();
        $count = static fn (string $family, callable $match): int => \count(array_filter($data[$family] ?? [], $match));

        return [
            'Coach' => $count('Coach', static fn (array $row): bool => ($row['id'] ?? null) === $coachId),
            'TeamCoach' => $count('TeamCoach', static fn (array $row): bool => ($row['coachId'] ?? null) === $coachId),
            'CoachPlayerMembership' => $count('CoachPlayerMembership', static fn (array $row): bool => ($row['coachId'] ?? null) === $coachId),
            'Constraint' => $count('Constraint', static fn (array $row): bool => ($row['scopeTargetId'] ?? null) === $coachId),
        ];
    }

    private function currentGuc(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
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
