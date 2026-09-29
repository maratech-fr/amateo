<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\BrandAssets;
use App\Service\ProductIdentity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Pose la signature de marque au bas de TOUS les e-mails sortants (P5-24) — les
 * envois club comme superadmin, sans toucher un octet du texte métier existant.
 *
 * UN SEUL foyer, aucune retouche des contrôleurs/builders : on branche
 * `Symfony\Mailer` au niveau du `MessageEvent`. Chaque `Email` dont le corps HTML
 * est encore nul reçoit :
 *  - partie texte = texte d'origine (préfixe byte-identique) + signature texte ;
 *  - partie HTML  = texte d'origine échappé + `nl2br` + bloc signature (trait,
 *    logo 40 px en CID, nom en gras, accroche, lien vitrine).
 *
 * ⚠ Timing (vérifié sur `symfony/mailer` 7.4) — `Mailer::send()` dispatche un
 * premier `MessageEvent` `queued=true` à l'enfilage, mais sur un CLONE : ses
 * mutations de corps sont VOLONTAIREMENT jetées, seul le message ORIGINAL (non
 * signé) part sur le bus Messenger (→ Redis). Le transport re-dispatche le même
 * `MessageEvent` `queued=false` chez le worker, AVANT le SMTP : c'est là que la
 * mutation de ce listener atteint le message réellement livré. On agit donc à
 * CHAQUE phase (on ne filtre pas sur `isQueued()`) : la phase enfilage sert le
 * collecteur de mail des tests (le `MessageLoggerListener`, priorité -255, logge
 * le clone APRÈS nous), la phase worker sert la livraison. Le CID ne « voyage »
 * donc pas sérialisé sur Redis — il est ré-embarqué chez le worker ; l'icône
 * reste néanmoins ≤ 20 Ko par hygiène de taille d'e-mail.
 *
 * Idempotence : la garde « corps HTML déjà posé » court-circuite un e-mail déjà
 * multipart et empêche une double signature (chaque `Email` n'est signé qu'une
 * fois par phase, phases qui opèrent de toute façon sur des objets distincts).
 */
final readonly class EmailSignatureListener implements EventSubscriberInterface
{
    /**
     * Nom technique du logo embarqué, référencé par `cid:` dans le HTML. Opaque
     * (jamais lu par un humain) : `Email::prepareParts()` le remplace par le
     * Content-ID généré au rendu.
     */
    private const string LOGO_CID = 'product-email-logo';

    public function __construct(
        private ProductIdentity $productIdentity,
        private BrandAssets $brandAssets,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => 'onMessage'];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        // Un e-mail qui porte déjà du HTML est laissé intact (aucun aujourd'hui,
        // et la garde évite une double signature si un jour il y en a).
        if (null !== $message->getHtmlBody()) {
            return;
        }

        $originalText = $message->getTextBody();
        if (!\is_string($originalText) || '' === $originalText) {
            // Rien à signer (défensif : tous les envois de l'app posent un texte).
            return;
        }

        $charset = $message->getTextCharset() ?? 'utf-8';
        $name = $this->productIdentity->name();
        $tagline = $this->productIdentity->tagline();
        $siteUrl = $this->productIdentity->siteUrl();

        $message->text(
            $originalText . "\n\n-- \n" . $name . "\n" . $tagline . "\n" . $siteUrl,
            $charset,
        );

        $message->embed($this->brandAssets->emailLogoPngBytes(), self::LOGO_CID, 'image/png');
        $message->html($this->renderHtml($originalText, $name, $tagline, $siteUrl), $charset);
    }

    private function renderHtml(string $originalText, string $name, string $tagline, string $siteUrl): string
    {
        $flags = \ENT_QUOTES | \ENT_SUBSTITUTE;
        $bodyHtml = nl2br(htmlspecialchars($originalText, $flags, 'UTF-8'));
        $nameHtml = htmlspecialchars($name, $flags, 'UTF-8');
        $taglineHtml = htmlspecialchars($tagline, $flags, 'UTF-8');
        $hrefHtml = htmlspecialchars($siteUrl, $flags, 'UTF-8');
        // Libellé du lien = domaine nu de la vitrine (cf. maquette), pas l'URL entière.
        $label = parse_url($siteUrl, \PHP_URL_HOST) ?: $siteUrl;
        $labelHtml = htmlspecialchars($label, $flags, 'UTF-8');
        $cid = self::LOGO_CID;

        return <<<HTML
            <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1a1a1a;">
            <div>{$bodyHtml}</div>
            <hr style="border:none;border-top:1px solid #e5e5e5;margin:24px 0 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="vertical-align:middle;padding-right:12px;"><img src="cid:{$cid}" width="40" height="40" alt="{$nameHtml}" style="display:block;width:40px;height:40px;border-radius:50%;"></td>
            <td style="vertical-align:middle;">
            <div style="font-weight:700;">{$nameHtml}</div>
            <div style="color:#6b7280;">{$taglineHtml}</div>
            <div><a href="{$hrefHtml}" style="color:#2563eb;text-decoration:underline;">{$labelHtml}</a></div>
            </td>
            </tr></table>
            </div>
            HTML;
    }
}
