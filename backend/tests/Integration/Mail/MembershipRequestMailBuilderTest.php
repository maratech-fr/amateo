<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Entity\Club;
use App\Enum\ClubRole;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Mail\MailSafeName;
use App\Service\MembershipRequestMailBuilder;
use App\Service\ProductIdentity;
use App\Tests\AssertsActionMailCta;
use App\Tests\Security\ManagerRoleRequestTest;
use App\Tests\Security\MembershipRequestNotificationTest;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * PROMESSE (chantier Membre, PR 1) : les mails d'adhésion et de fermeture disent MOT POUR MOT ce
 * que le fondateur a validé (plan §2.2) — gestionnaires prévenus (1 ou n demandeurs), synthèse au
 * plafond, demandeur accepté (avec son rôle) ou refusé (avec l'échéance s'il devient orphelin),
 * Membres prévenus de la fermeture (J0, J-7). Chaque mail est un mail MÉTIER du club (boîte aux
 * lettres sous horloge simulée, identité du club sur la carte) ; ceux qui portent une action ont
 * leur bouton, le refus n'en a aucun.
 *
 * PR 3 ajoute les mails d'une demande de RÔLE (gestionnaires prévenus, nommé gestionnaire, refus,
 * demande dépassée, adresse officielle muette 7 jours) et remplace le paragraphe « comment conserver
 * l'espace » du mail de fermeture (le Membre demande depuis le bandeau).
 *
 * Le câblage (qui reçoit quoi, quand) est prouvé par
 * {@see MembershipRequestNotificationTest} et {@see ManagerRoleRequestTest} ; ici, le TEXTE seul.
 */
#[Group('phase1')]
#[Group('integration')]
final class MembershipRequestMailBuilderTest extends KernelTestCase
{
    use AssertsActionMailCta;

    private const string BASE = 'http://localhost:5173';

    public function testNewRequestForOneRequesterNamesHimAndPointsToTheClubScreen(): void
    {
        $mail = $this->builder()->buildNewRequests('marc@club.fr', $this->club(), [['name' => 'Léa Martin', 'email' => 'lea@test.fr']]);
        $p = $this->product();

        self::assertSame('Demande d\'adhésion — Léa Martin souhaite rejoindre BC Testville', $mail->getSubject());
        self::assertSame(
            "Bonjour,\n\nLéa Martin (lea@test.fr) demande à rejoindre l'espace {$p} du club BC Testville.\n\n"
            . "Connectez-vous pour accepter ou refuser cette demande dans l'écran Club, section « Demandes d'adhésion » :\n" . self::BASE . "/club\n\n"
            . "Si vous ne connaissez pas cette personne, refusez la demande. Ce message ne vous demande rien d'autre que d'ouvrir l'application.\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/club', 'Voir les demandes');
        $this->assertClubBusiness($mail);
    }

    public function testNewRequestsForSeveralRequestersListThemInOneMail(): void
    {
        $mail = $this->builder()->buildNewRequests('marc@club.fr', $this->club(), [
            ['name' => 'Anna Un', 'email' => 'anna@test.fr'],
            ['name' => 'Bruno Deux', 'email' => 'bruno@test.fr'],
        ]);

        self::assertSame('2 demandes d\'adhésion — BC Testville', $mail->getSubject());
        $body = (string) $mail->getTextBody();
        self::assertStringContainsString(\sprintf("2 personnes demandent à rejoindre l'espace %s du club BC Testville :\n- Anna Un (anna@test.fr)\n- Bruno Deux (bruno@test.fr)", $this->product()), $body);
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/club', 'Voir les demandes');
        $this->assertClubBusiness($mail);
    }

    public function testDigestSaysNoMoreIndividualMessageUntilTomorrow(): void
    {
        $mail = $this->builder()->buildDigest('marc@club.fr', $this->club());
        $p = $this->product();

        self::assertSame('Plusieurs demandes d\'adhésion aujourd\'hui — BC Testville', $mail->getSubject());
        self::assertSame(
            "Bonjour,\n\nPlusieurs personnes ont demandé à rejoindre l'espace {$p} du club BC Testville aujourd'hui. Vous ne recevrez plus de message individuel avant demain.\n\n"
            . "Retrouvez toutes les demandes dans l'écran Club, section « Demandes d'adhésion » :\n" . self::BASE . "/club\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/club', 'Voir les demandes');
        $this->assertClubBusiness($mail);
    }

    public function testAcceptedSaysTheRoleTheRequesterEntersWith(): void
    {
        $p = $this->product();
        foreach ([[ClubRole::MEMBER, 'Vous y entrez comme membre : vous pouvez tout consulter.'], [ClubRole::MANAGER, 'Vous y entrez comme gestionnaire.']] as [$role, $sentence]) {
            $mail = $this->builder()->buildAccepted('lea@test.fr', $this->club(), 'Léa', $role);

            self::assertSame('BC Testville a accepté votre demande', $mail->getSubject());
            self::assertSame(
                "Bonjour Léa,\n\nLe club BC Testville a accepté votre demande : vous avez maintenant accès à son espace {$p}.\n{$sentence}\n\nConnectez-vous :\n" . self::BASE . "/login\n\n{$p}",
                (string) $mail->getTextBody(),
            );
            $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/login', 'Me connecter');
            $this->assertClubBusiness($mail);
        }
    }

    public function testRefusedCarriesTheAccountDeadlineOnlyWhenTheRequesterBecomesOrphan(): void
    {
        $p = $this->product();
        $plain = $this->builder()->buildRefused('lea@test.fr', $this->club(), 'Léa', null);
        self::assertSame('BC Testville n\'a pas donné suite à votre demande', $plain->getSubject());
        self::assertSame(
            "Bonjour Léa,\n\nLe club BC Testville n'a pas donné suite à votre demande d'accès à son espace {$p}.\n\nSi vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club.\n\n{$p}",
            (string) $plain->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($plain);

        $orphan = $this->builder()->buildRefused('lea@test.fr', $this->club(), 'Léa', new DateTimeImmutable('2026-12-03'));
        self::assertSame(
            "Bonjour Léa,\n\nLe club BC Testville n'a pas donné suite à votre demande d'accès à son espace {$p}.\n\nSi vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club."
            . "\n\nVous n'avez plus accès à aucun club sur {$p}. Sans nouvel accès, votre compte sera supprimé le 03/12/2026. Vous pourrez vous réinscrire ensuite si besoin.\n\n{$p}",
            (string) $orphan->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($orphan);
        $this->assertClubBusiness($orphan);
    }

    public function testClubClosingTellsMembersTheDeadlineAndHowToKeepTheSpace(): void
    {
        $p = $this->product();
        $j0 = $this->builder()->buildClubClosing('paul@test.fr', $this->club(), 'Paul', new DateTimeImmutable('2026-12-03'), false);

        self::assertSame("L'espace {$p} du club BC Testville n'a plus de gestionnaire", $j0->getSubject());
        $body = (string) $j0->getTextBody();
        self::assertStringStartsWith('Bonjour', $body);
        self::assertStringContainsString("Le dernier gestionnaire de l'espace {$p} du club BC Testville a quitté le service. Sans reprise par un gestionnaire, cet espace sera supprimé définitivement le 03/12/2026.", $body);
        // PR 3 : le Membre n'est plus renvoyé à l'inscription — il demande lui-même depuis le bandeau.
        self::assertStringContainsString('Vous gardez un accès en consultation jusque-là. Si vous êtes gestionnaire du club, cliquez « Demander à devenir gestionnaire » dans le bandeau de l\'application : l\'adresse officielle du club validera.', $body);
        self::assertStringNotContainsString('Pour conserver l\'espace, un gestionnaire du club doit s\'inscrire', $body, 'l\'ancien paragraphe (PR 1) est remplacé');
        $this->assertCtaHeadersPointToBodyLink($j0, self::BASE . '/club', 'Ouvrir l\'application');
        $this->assertClubBusiness($j0);

        $j7 = $this->builder()->buildClubClosing('paul@test.fr', $this->club(), 'Paul', new DateTimeImmutable('2026-12-03'), true);
        self::assertStringStartsWith('Rappel — ', (string) $j7->getSubject());
        self::assertStringContainsString('supprimé définitivement le 03/12/2026, dans 7 jours.', (string) $j7->getTextBody());
        self::assertStringContainsString('cliquez « Demander à devenir gestionnaire » dans le bandeau de l\'application', (string) $j7->getTextBody());
        $this->assertCtaHeadersPointToBodyLink($j7, self::BASE . '/club', 'Ouvrir l\'application');
        $this->assertClubBusiness($j7);
    }

    // ---------------------------------------------------------------------------------------
    // PR 3 — un Membre demande à devenir gestionnaire (plan §4.3, mot pour mot). Le câblage
    // (qui reçoit quoi, quand) est prouvé par {@see ManagerRoleRequestTest}.
    // ---------------------------------------------------------------------------------------

    public function testRoleRequestTellsManagersWhoAsksAndWhereToDecide(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildRoleRequest('marc@club.fr', $this->club(), 'Paul Martin', 'paul@test.fr');

        self::assertSame('Paul Martin demande à devenir gestionnaire de BC Testville', $mail->getSubject());
        self::assertSame(
            "Bonjour,\n\nPaul Martin (paul@test.fr), membre de l'espace {$p} du club BC Testville, demande à en devenir gestionnaire.\n\n"
            . "Connectez-vous pour accepter ou refuser dans l'écran Club, section « Membres » :\n" . self::BASE . "/club\n\n"
            . "Un gestionnaire peut tout modifier : n'acceptez que si vous connaissez cette personne. Sans réponse sous 14 jours, la demande sera transmise à l'adresse officielle du club.\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/club', 'Voir les membres');
        $this->assertClubBusiness($mail);
    }

    /** Le nom passé au builder est déjà nettoyé par l'appelant ; un nom hostile n'y est jamais réinterprété en lien. */
    public function testRoleRequestNeverTurnsAHostileNameIntoALink(): void
    {
        $mail = $this->builder()->buildRoleRequest('marc@club.fr', $this->club(), MailSafeName::of('Gagnez', 'evil.com'), 'paul@test.fr');

        self::assertSame('Un demandeur demande à devenir gestionnaire de BC Testville', $mail->getSubject());
        self::assertStringNotContainsString('evil.com', (string) $mail->getTextBody());
    }

    public function testRoleGrantedSaysTheRequesterIsNowManager(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildRoleGranted('paul@test.fr', $this->club(), 'Paul');

        self::assertSame('BC Testville vous a nommé gestionnaire', $mail->getSubject());
        self::assertSame(
            "Bonjour Paul,\n\nLe club BC Testville a accepté votre demande : vous êtes maintenant gestionnaire de son espace {$p}.\n\nConnectez-vous :\n" . self::BASE . "/login\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/login', 'Me connecter');
        $this->assertClubBusiness($mail);
    }

    public function testRoleRefusedKeepsTheConsultationAndHasNothingToClick(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildRoleRefused('paul@test.fr', $this->club(), 'Paul');

        self::assertSame('BC Testville n\'a pas donné suite à votre demande', $mail->getSubject());
        self::assertSame(
            "Bonjour Paul,\n\nLe club BC Testville n'a pas donné suite à votre demande de devenir gestionnaire. Vous gardez votre accès en consultation.\n\n"
            . "Si vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club.\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($mail);
        $this->assertClubBusiness($mail);
    }

    public function testSupersededSaysSomeoneElseTookOverAndHowToAskAgain(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildSuperseded('lea@test.fr', $this->club(), 'Léa');

        self::assertSame('BC Testville a déjà retrouvé un gestionnaire', $mail->getSubject());
        self::assertSame(
            "Bonjour Léa,\n\nUn gestionnaire a repris l'espace {$p} du club BC Testville avant que votre demande ne soit examinée. Vous pouvez lui demander à devenir gestionnaire depuis l'application.\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($mail);
        $this->assertClubBusiness($mail);
    }

    public function testOfficialExpiredNamesTheRequestDateAndTheWayOut(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildOfficialExpired('paul@test.fr', $this->club(), 'Paul', new DateTimeImmutable('2026-11-03'));

        self::assertSame('Le club BC Testville n\'a pas répondu à votre demande', $mail->getSubject());
        self::assertSame(
            "Bonjour Paul,\n\nL'adresse officielle du club BC Testville n'a pas répondu à votre demande du 03/11/2026 dans les 7 jours. Vous pouvez la renvoyer depuis l'application, ou contacter le support.\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($mail);
        $this->assertClubBusiness($mail);
    }

    /** Refus de l'ADRESSE OFFICIELLE : le corps du refus + la date à partir de laquelle redemander (30 jours). */
    public function testOfficialRefusalTellsTheRenewalDate(): void
    {
        $p = $this->product();
        $body = (string) $this->builder()->buildRoleRefused('paul@test.fr', $this->club(), 'Paul', new DateTimeImmutable('2026-12-03'))->getTextBody();

        self::assertStringStartsWith("Bonjour Paul,\n\nLe club BC Testville n'a pas donné suite à votre demande de devenir gestionnaire. Vous gardez votre accès en consultation.", $body);
        self::assertSame(1, substr_count($body, 'Vous pourrez renouveler votre demande à partir du 03/12/2026.'));
        self::assertStringContainsString("Si vous pensez qu'il s'agit d'une erreur, rapprochez-vous de votre club.", $body);
        self::assertStringEndsWith("\n\n{$p}", $body);
    }

    public function testRoleDigestSpeaksOfMembersNotOfNewcomers(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildRoleDigest('marc@club.fr', $this->club());

        self::assertSame('Plusieurs demandes de gestionnaire aujourd\'hui — BC Testville', $mail->getSubject());
        self::assertSame(
            "Bonjour,\n\nPlusieurs membres ont demandé aujourd'hui à devenir gestionnaire de l'espace {$p} du club BC Testville. Vous ne recevrez plus de message individuel avant demain.\n\n"
            . "Retrouvez ces demandes dans l'écran Club, section « Membres » :\n" . self::BASE . "/club\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/club', 'Voir les membres');
        $this->assertClubBusiness($mail);
    }

    public function testEscalationTellsManagersTheRequestWentToTheOfficialAddress(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildRoleEscalatedToManagers('marc@club.fr', $this->club(), 'Léa Martin');

        self::assertSame('La demande de Léa Martin est transmise à l\'adresse officielle de BC Testville', $mail->getSubject());
        self::assertSame(
            "Bonjour,\n\nSans réponse depuis 14 jours, la demande de Léa Martin de devenir gestionnaire de BC Testville a été transmise à l'adresse officielle du club, qui peut l'accepter. Vous pouvez toujours la traiter dans l'écran Club, section « Membres » :\n" . self::BASE . "/club\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($mail, self::BASE . '/club', 'Voir les membres');
        $this->assertClubBusiness($mail);

        $hostile = $this->builder()->buildRoleEscalatedToManagers('marc@club.fr', $this->club(), MailSafeName::of('Gagnez', 'evil.com'));
        self::assertStringNotContainsString('evil.com', (string) $hostile->getSubject() . (string) $hostile->getTextBody(), 'le nom passe par MailSafeName');
    }

    public function testEscalationTellsTheRequesterTheClubWillDecide(): void
    {
        $p = $this->product();
        $mail = $this->builder()->buildRoleEscalatedToRequester('lea@test.fr', $this->club(), 'Léa', new DateTimeImmutable('2026-10-20'));

        self::assertSame('Votre demande est transmise au club BC Testville', $mail->getSubject());
        self::assertSame(
            "Bonjour Léa,\n\nLes gestionnaires de BC Testville n'ont pas répondu à votre demande du 20/10/2026. Elle est transmise à l'adresse officielle du club, qui peut l'accepter sous 7 jours.\n\n{$p}",
            (string) $mail->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($mail);
        $this->assertClubBusiness($mail);
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function assertClubBusiness(Email $mail): void
    {
        self::assertTrue(ClubBusinessMail::isClubBusiness($mail), 'mail MÉTIER du club (capté en boîte sous horloge simulée)');
        self::assertTrue($mail->getHeaders()->has(ClubMailMetadata::CLUB_ID_HEADER), 'identité du club sur la carte d\'e-mail');
    }

    private function club(): Club
    {
        return (new Club)->setName('BC Testville');
    }

    private function builder(): MembershipRequestMailBuilder
    {
        $builder = self::getContainer()->get(MembershipRequestMailBuilder::class);
        \assert($builder instanceof MembershipRequestMailBuilder);

        return $builder;
    }

    private function product(): string
    {
        $product = self::getContainer()->get(ProductIdentity::class);
        \assert($product instanceof ProductIdentity);

        return $product->name();
    }
}
