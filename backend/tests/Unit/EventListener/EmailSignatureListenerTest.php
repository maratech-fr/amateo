<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\EmailSignatureListener;
use App\Service\BrandAssets;
use App\Service\ProductIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * P5-24 — la signature de marque se pose sur TOUS les e-mails via un unique
 * `MessageEvent` listener, sans toucher le texte métier existant.
 *
 * Les valeurs d'identité injectées ici (« TestProd », « ACCROCHE DE TEST »,
 * « https://vitrine.test ») sont VOLONTAIREMENT différentes des littéraux de
 * production : les retrouver dans le rendu prouve que le listener lit
 * `ProductIdentity` et ne code aucune chaîne en dur (et l'absence de « Amateo »
 * le corrobore).
 */
#[Group('phase1')]
final class EmailSignatureListenerTest extends TestCase
{
    private const string ORIGINAL_TEXT = "Bonjour,\nvoici votre lien : https://app.amateo.app/reset/xyz\nÀ bientôt.";

    public function testSignsATextOnlyEmailWithBothPartsAndInlineLogo(): void
    {
        $email = $this->emailWithText(self::ORIGINAL_TEXT);

        $this->listener()->onMessage($this->enqueueEvent($email));

        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();

        // Texte : préfixe byte-identique + signature texte EXACTE.
        self::assertStringStartsWith(self::ORIGINAL_TEXT, $text);
        self::assertStringContainsString(
            "-- \nTestProd\nACCROCHE DE TEST\nhttps://vitrine.test",
            $text,
        );

        // HTML : corps échappé + nl2br, puis nom / accroche / URL vitrine injectés.
        self::assertNotSame('', $html, 'la partie HTML doit être créée');
        self::assertStringContainsString('Bonjour,', $html);
        self::assertStringContainsString('<br', $html, 'le corps texte est passé à nl2br');
        self::assertStringContainsString('TestProd', $html);
        self::assertStringContainsString('ACCROCHE DE TEST', $html);
        self::assertStringContainsString('https://vitrine.test', $html);
        self::assertStringContainsString('cid:product-email-logo', $html);

        // Non-littéral : aucun nom de marque de production ne fuit dans le rendu.
        self::assertStringNotContainsString('Amateo', $text);
        self::assertStringNotContainsString('Amateo', $html);

        // Une partie inline (Content-ID) porte le logo PNG.
        $logo = $this->inlineLogoPart($email);
        self::assertNotNull($logo, 'un logo inline doit être embarqué');
        self::assertSame('image/png', $logo->getContentType());
        self::assertSame('inline', $logo->getPreparedHeaders()->getHeaderBody('Content-Disposition'));
        self::assertStringContainsString('@', $logo->getContentId(), 'la partie inline porte un Content-ID');
    }

    public function testLeavesAnEmailThatAlreadyHasHtmlUntouched(): void
    {
        $email = $this->emailWithText('texte brut')->html('<p>déjà HTML</p>');

        $this->listener()->onMessage($this->enqueueEvent($email));

        self::assertSame('<p>déjà HTML</p>', $email->getHtmlBody());
        self::assertSame('texte brut', $email->getTextBody());
        self::assertSame([], $email->getAttachments(), 'aucun logo ajouté quand le HTML est déjà posé');
    }

    private function listener(): EmailSignatureListener
    {
        return new EmailSignatureListener(
            new ProductIdentity(
                productName: 'TestProd',
                productTagline: 'ACCROCHE DE TEST',
                productSiteUrl: 'https://vitrine.test',
            ),
            // Défaut : résout le vrai asset backend/assets/brand/email-icon.png.
            new BrandAssets,
        );
    }

    private function emailWithText(string $text): Email
    {
        return new Email()
            ->from('no-reply@amateo.app')
            ->to('coach@club.test')
            ->subject('Sujet')
            ->text($text);
    }

    private function enqueueEvent(Email $email): MessageEvent
    {
        // `queued: true` = la phase d'enfilage ; le listener y mute le message qui
        // sera capté par le collecteur de mail des tests.
        return new MessageEvent($email, Envelope::create($email), 'null://null', true);
    }

    private function inlineLogoPart(Email $email): ?DataPart
    {
        foreach ($email->getAttachments() as $part) {
            if ('product-email-logo' === $part->getName()) {
                return $part;
            }
        }

        return null;
    }
}
