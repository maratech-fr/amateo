<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Entity\Club;
use App\Enum\ClubRole;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Service\MembershipRequestMailBuilder;
use App\Service\ProductIdentity;
use App\Tests\AssertsActionMailCta;
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
 * Le câblage (qui reçoit quoi, quand) est prouvé par
 * {@see MembershipRequestNotificationTest} ; ici, le TEXTE seul.
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
        self::assertStringContainsString("Vous gardez un accès en consultation jusque-là. Pour conserver l'espace, un gestionnaire du club doit s'inscrire sur {$p} avec le code FFBB du club : sa demande sera validée par l'adresse officielle du club.", $body);
        $this->assertCtaHeadersPointToBodyLink($j0, self::BASE . '/register', 'Inscrire un gestionnaire');
        $this->assertClubBusiness($j0);

        $j7 = $this->builder()->buildClubClosing('paul@test.fr', $this->club(), 'Paul', new DateTimeImmutable('2026-12-03'), true);
        self::assertStringStartsWith('Rappel — ', (string) $j7->getSubject());
        self::assertStringContainsString('supprimé définitivement le 03/12/2026, dans 7 jours.', (string) $j7->getTextBody());
        $this->assertClubBusiness($j7);
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
