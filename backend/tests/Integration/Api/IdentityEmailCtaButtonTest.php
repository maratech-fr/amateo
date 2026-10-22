<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Lot bugs Rillieux #2 — PROMESSE : « le destinataire d'un e-mail d'identité a un BOUTON cliquable
 * vers le BON lien, et le texte brut est INCHANGÉ ». Les trois e-mails d'identité (vérification
 * d'inscription, mot de passe oublié, confirmation de changement d'e-mail) doivent désormais poser
 * le bouton d'action via le mécanisme EXISTANT des en-têtes internes {@see EmailTemplateRenderer::CTA_URL_HEADER}
 * / {@see EmailTemplateRenderer::CTA_LABEL_HEADER} (rendus en bouton HTML par {@see EmailTemplateRenderer::ctaButton},
 * retirés avant SMTP par {@see App\EventListener\EmailSignatureListener::stripInternalHeadersAtWorker}),
 * comme le fait déjà {@see App\Service\CoachWishMailBuilder::buildCoachLink}.
 *
 * Chaque test, pour un e-mail, vérifie que :
 *  1. l'en-tête `X-Amateo-Cta-Url` vaut EXACTEMENT le lien présent dans la partie TEXTE (le lien du
 *     reset étant dérivé de FRONTEND_BASE_URL, jamais du Host entrant — angle anti-password-reset-poisoning,
 *     jumeau {@see App\Tests\Security\PasswordResetEnumerationTest::testResetLinkIgnoresTheForgedHostHeader}) ;
 *  2. l'en-tête `X-Amateo-Cta-Label` porte le libellé attendu ;
 *  3. après le pipeline du listener (clone signé à l'enfilage), la partie HTML contient un
 *     `<a href>` vers ce lien, et le lien n'y apparaît qu'UNE fois — jamais dupliqué/auto-lié en clair
 *     dans le corps (anti-hameçonnage, pli de l'abus A1 ; le gabarit n'auto-lie jamais une URL du corps,
 *     prouvé au plus bas par {@see App\Tests\Unit\Mail\EmailTemplateRendererTest::testCtaUrlStaysInBodyWhenNoButton}) ;
 *  4. la partie TEXTE reste byte-identique (le fragment métier avec le lien nu est inchangé — le
 *     bouton s'AJOUTE, il ne réécrit rien ; preuve bout-en-bout du byte-identique en CI :
 *     les features Behat `inscription-et-premier-planning` / `une-invitation-fait-entrer-dans-le-club`
 *     extraient le lien du TEXTE).
 *
 * ROUGE aujourd'hui : les trois producteurs ({@see App\Service\Registration\RegisterService::sendVerificationEmail},
 * {@see App\Controller\PasswordController::forgot}, {@see App\Controller\AuthController::sendEmailChangeConfirmation})
 * ne posent AUCUN en-tête `X-Amateo-Cta-*` → aucun bouton. L'assertion sur l'en-tête et sur le `href`
 * échoue tant que NEO n'a pas câblé le CTA.
 *
 * Abus A2/A3/A4 : gardes de non-régression du MÉCANISME que ces 3 producteurs réutilisent, déjà
 * VERTES au plus bas (pas de doublon ici) — A2 (`javascript:` → pas de bouton)
 * {@see App\Tests\Unit\Mail\EmailTemplateRendererTest::testCtaButtonRefusesNonHttpUrl} ; A3 (label HTML
 * échappé) {@see App\Tests\Unit\Mail\EmailTemplateRendererTest::testCtaButtonEscapesLabelAndUrl} ;
 * A4 (aucun `X-Amateo-*` livré) {@see App\Tests\Unit\EventListener\EmailSignatureListenerTest::testRendersTheCtaButtonFromHeadersAndStripsThemAtWorker}.
 * A5 (panne mailer → réponse inchangée, pas d'oracle d'énumération) : déjà couvert par
 * {@see App\Tests\Security\PasswordResetEnumerationTest} et le 202 identique d'{@see AuthFlowTest::testRegisterReturnsSame202ForFreshAndExistingEmail}.
 */
#[Group('phase1')]
#[Group('integration')]
final class IdentityEmailCtaButtonTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private const string VERIFY_LABEL = 'Confirmer mon adresse';

    private const string RESET_LABEL = 'Réinitialiser mon mot de passe';

    private const string EMAIL_CHANGE_LABEL = 'Confirmer la nouvelle adresse';

    private static int $ipCounter = 0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testVerificationEmailCarriesAClickableCtaToTheVerifyLink(): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->postJson('/api/register', [
            'email' => 'cta-verify@club.fr', 'password' => 'Password123!',
            'firstName' => 'Ve', 'lastName' => 'Rify', 'ara' => 'CTAVER1', 'club_name' => 'CTA Verify Club', 'consent' => true,
        ]);
        self::assertResponseStatusCodeSame(202);

        $email = $this->lastEmail();
        $link = $this->extractLink($email, 'verify-email');

        // Le fragment métier avec le lien nu est inchangé (texte byte-identique, le bouton s'ajoute).
        self::assertStringContainsString(
            "Pour activer votre compte, ouvrez ce lien :\n{$link}\n\nCe lien expire dans 24 heures.",
            (string) $email->getTextBody(),
        );

        $this->assertCtaButton($email, $link, self::VERIFY_LABEL);
    }

    public function testPasswordResetEmailCarriesAClickableCtaToTheResetLink(): void
    {
        $this->registerUnverified('cta-reset@club.fr', 'CTARST1');

        // Host menteur : le lien (donc le CTA) doit rester sur FRONTEND_BASE_URL, jamais le Host entrant.
        $this->client->request('POST', '/api/password/forgot', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(), 'HTTP_HOST' => 'attacker.example',
        ], json_encode(['email' => 'cta-reset@club.fr'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $email = $this->lastEmail();
        $link = $this->extractLink($email, 'reset-password');

        self::assertStringStartsWith('http://localhost:5173/reset-password/', $link, 'le lien du reset vient de FRONTEND_BASE_URL (.env.test)');
        self::assertStringNotContainsString('attacker.example', $link, 'le CTA du reset ne suit jamais le Host entrant');
        self::assertStringContainsString(
            "Pour réinitialiser votre mot de passe, ouvrez ce lien :\n{$link}\n\nCe lien expire dans 1 heure.",
            (string) $email->getTextBody(),
        );

        $this->assertCtaButton($email, $link, self::RESET_LABEL);
    }

    public function testEmailChangeConfirmationCarriesAClickableCtaToTheConfirmLink(): void
    {
        $token = $this->registerAndVerify('cta-change@club.fr', 'CTACHG1');

        $this->client->request('POST', '/api/me/email', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['email' => 'cta-change-new@club.fr', 'currentPassword' => 'Password123!'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $email = $this->lastEmail();
        $link = $this->extractLink($email, 'confirm-email');

        self::assertStringContainsString(
            "Pour confirmer cette nouvelle adresse, ouvrez ce lien :\n{$link}\n\nVotre adresse actuelle reste active",
            (string) $email->getTextBody(),
        );

        $this->assertCtaButton($email, $link, self::EMAIL_CHANGE_LABEL);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Les quatre vérités du bouton d'action, sur le clone SIGNÉ capté à l'enfilage (le HTML y est
     * déjà rendu, les en-têtes internes pas encore retirés — le retrait est prouvé au worker par le
     * jumeau unitaire A4) : l'en-tête CTA porte EXACTEMENT le lien du texte + le libellé attendu, et
     * la partie HTML montre ce lien UNE seule fois, dans un `<a href>`.
     */
    private function assertCtaButton(Email $email, string $link, string $expectedLabel): void
    {
        $headers = $email->getHeaders();
        self::assertTrue(
            $headers->has(EmailTemplateRenderer::CTA_URL_HEADER),
            'l\'e-mail d\'identité doit porter l\'en-tête CTA (bouton cliquable) — ROUGE tant que le producteur ne le pose pas',
        );
        self::assertSame($link, $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER), 'l\'URL du CTA doit être EXACTEMENT le lien du texte');
        self::assertSame($expectedLabel, $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('href="' . $link . '"', $html, 'la partie HTML doit rendre un bouton <a href> vers le lien');
        self::assertSame(1, substr_count($html, $link), 'le lien n\'apparaît qu\'une fois (dans le bouton) — jamais auto-lié/dupliqué dans le corps (A1)');
    }

    /** Le dernier e-mail capté par le collecteur (clone signé par EmailSignatureListener à l'enfilage). */
    private function lastEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email, 'un e-mail doit avoir été émis par ce geste');

        return $email;
    }

    /** Le lien absolu de la partie texte pour le chemin `$pathSegment` (/verify-email/, /reset-password/, /confirm-email/). */
    private function extractLink(Email $email, string $pathSegment): string
    {
        $matched = preg_match('~https?://\S+/' . preg_quote($pathSegment, '~') . '/\S+~', (string) $email->getTextBody(), $m);
        self::assertSame(1, $matched, "le texte doit porter un lien /{$pathSegment}/…");

        return $m[0];
    }

    private function registerUnverified(string $email, string $ara): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->postJson('/api/register', [
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Re', 'lastName' => 'Set', 'ara' => $ara, 'club_name' => "Club {$ara}", 'consent' => true,
        ]);
        self::assertSame(1, $this->em->getRepository(User::class)->count(['email' => $email]), 'le compte doit exister pour que le forgot envoie un lien');
    }

    private function registerAndVerify(string $email, string $ara): string
    {
        $this->registerUnverified($email, $ara);
        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token, 'la vérification doit émettre un JWT');

        return $token;
    }

    /** @param array<string, mixed> $body */
    private function postJson(string $path, array $body): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function nextIp(): string
    {
        $ip = '10.7.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;

        return $ip;
    }
}
