<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\EmailSignatureListener;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use App\Service\ProductIdentity;
use App\Storage\LogoStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * P5-24 / D1 — le gabarit de marque se pose sur TOUS les e-mails via un unique `MessageEvent`
 * listener, sans toucher le texte métier existant. Le HTML est délégué à {@see
 * EmailTemplateRenderer} ; l'en-tête club et le retrait des en-têtes internes sont pilotés ici.
 *
 * Les valeurs d'identité injectées ici (« TestProd », « ACCROCHE DE TEST », « https://vitrine.test »)
 * sont VOLONTAIREMENT différentes des littéraux de production : les retrouver dans le rendu prouve
 * que le listener lit `ProductIdentity` et ne code aucune chaîne en dur.
 */
#[Group('phase1')]
final class EmailSignatureListenerTest extends TestCase
{
    private const string ORIGINAL_TEXT = "Bonjour,\nvoici votre lien : https://app.amateo.app/reset/xyz\nÀ bientôt.";

    // 1×1 PNG transparent valide (getimagesizefromstring le reconnaît → image/png).
    private const string PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

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
        $logo = $this->inlineLogoPart($email, 'product-email-logo');
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

    public function testEmbedsTheClubLogoAndRendersItsLabelWhenClubIsKnown(): void
    {
        $email = $this->emailWithText('Corps métier.');
        $email->getHeaders()->addTextHeader(ClubMailMetadata::CLUB_ID_HEADER, 'club-123');
        $email->getHeaders()->addTextHeader(ClubMailMetadata::CLUB_LABEL_HEADER, 'BC Lyon');

        $this->listener(['club-123' => base64_decode(self::PNG_1X1, true)])->onMessage($this->enqueueEvent($email));

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('cid:club-email-logo', $html, 'le logo du club est référencé en CID');
        self::assertStringContainsString('BC Lyon', $html, 'le libellé du club habille l\'en-tête de carte');

        $clubLogo = $this->inlineLogoPart($email, 'club-email-logo');
        self::assertNotNull($clubLogo, 'le logo du club est embarqué en pièce inline');
        self::assertSame('image/png', $clubLogo->getContentType());
    }

    public function testInternalHeadersAreStrippedAtWorkerPhase(): void
    {
        $email = $this->emailWithText('Corps.');
        $email->getHeaders()->addTextHeader('X-Amateo-Scope', 'club-business');
        $email->getHeaders()->addTextHeader(ClubMailMetadata::CLUB_ID_HEADER, 'club-123');
        $email->getHeaders()->addTextHeader(ClubMailMetadata::CLUB_LABEL_HEADER, 'BC Lyon');

        // Phase WORKER (queued:false) : les en-têtes internes sont retirés avant SMTP.
        $this->listener()->onMessage($this->workerEvent($email));

        $headers = $email->getHeaders();
        self::assertFalse($headers->has('X-Amateo-Scope'), 'X-Amateo-Scope ne doit jamais fuir sur SMTP');
        self::assertFalse($headers->has(ClubMailMetadata::CLUB_ID_HEADER));
        self::assertFalse($headers->has(ClubMailMetadata::CLUB_LABEL_HEADER));
    }

    public function testInternalHeadersSurviveTheEnqueuePhase(): void
    {
        // À l'enfilage, les en-têtes doivent RESTER : l'intercepteur démo (priorité 100) les a lus,
        // et le message original les porte à travers Redis jusqu'au worker.
        $email = $this->emailWithText('Corps.');
        $email->getHeaders()->addTextHeader('X-Amateo-Scope', 'club-business');
        $email->getHeaders()->addTextHeader(ClubMailMetadata::CLUB_ID_HEADER, 'club-123');

        $this->listener()->onMessage($this->enqueueEvent($email));

        self::assertTrue($email->getHeaders()->has('X-Amateo-Scope'), 'les en-têtes internes restent à l\'enfilage');
        self::assertTrue($email->getHeaders()->has(ClubMailMetadata::CLUB_ID_HEADER));
    }

    /** @param array<string, string> $logos clubId => octets du logo */
    private function listener(array $logos = []): EmailSignatureListener
    {
        return new EmailSignatureListener(
            new ProductIdentity(
                productName: 'TestProd',
                productTagline: 'ACCROCHE DE TEST',
                productSiteUrl: 'https://vitrine.test',
            ),
            // Défaut : résout le vrai asset backend/assets/brand/email-icon.png.
            new BrandAssets,
            new EmailTemplateRenderer,
            new class($logos) implements LogoStorage {
                /** @param array<string, string> $logos */
                public function __construct(private array $logos) {}

                public function store(string $clubId, string $bytes): void {}

                public function read(string $clubId): ?string
                {
                    return $this->logos[$clubId] ?? null;
                }

                public function delete(string $clubId): void {}
            },
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
        // `queued: true` = la phase d'enfilage ; le listener y mute le message qui sera capté par
        // le collecteur de mail des tests, et GARDE les en-têtes internes.
        return new MessageEvent($email, Envelope::create($email), 'null://null', true);
    }

    private function workerEvent(Email $email): MessageEvent
    {
        // `queued: false` = la phase worker (livraison) : les en-têtes internes sont retirés.
        return new MessageEvent($email, Envelope::create($email), 'null://null', false);
    }

    private function inlineLogoPart(Email $email, string $name): ?DataPart
    {
        foreach ($email->getAttachments() as $part) {
            if ($name === $part->getName()) {
                return $part;
            }
        }

        return null;
    }
}
