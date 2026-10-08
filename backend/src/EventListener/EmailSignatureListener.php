<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use App\Service\ProductIdentity;
use App\Storage\LogoStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Pose le gabarit de marque sur TOUS les e-mails sortants (P5-24, habillage commun D1) — les
 * envois club comme superadmin, sans toucher un octet du texte métier existant.
 *
 * UN SEUL foyer, aucune retouche des contrôleurs/builders : on branche `Symfony\Mailer` au niveau
 * du `MessageEvent`. Chaque `Email` dont le corps HTML est encore nul reçoit :
 *  - partie texte = texte d'origine (préfixe byte-identique) + signature texte ;
 *  - partie HTML  = rendue par {@see EmailTemplateRenderer} (carte blanche, fond dérivé du mark,
 *    en-tête club optionnel, pied de signature produit), logo produit en CID.
 *
 * ⚠ Timing (vérifié sur `symfony/mailer` 7.4) — `Mailer::send()` dispatche un premier `MessageEvent`
 * `queued=true` à l'enfilage, mais sur un CLONE : ses mutations de corps sont VOLONTAIREMENT jetées,
 * seul le message ORIGINAL (non signé) part sur le bus Messenger (→ Redis). Le transport
 * re-dispatche le même `MessageEvent` `queued=false` chez le worker, AVANT le SMTP : c'est là que la
 * mutation de ce listener atteint le message réellement livré. On agit donc à CHAQUE phase (on ne
 * filtre pas sur `isQueued()`) : la phase enfilage sert le collecteur de mail des tests (le
 * `MessageLoggerListener`, priorité -255, logge le clone APRÈS nous), la phase worker sert la
 * livraison. Le CID ne « voyage » donc pas sérialisé sur Redis — il est ré-embarqué chez le worker.
 *
 * En-têtes internes & LOGO DU CLUB (D1) : {@see ClubMailMetadata} pose `X-Amateo-Club-Id` +
 * `X-Amateo-Club-Label` à la SOURCE (builders à club connu). On les lit ici — l'id sert à charger
 * les octets du logo du club ({@see LogoStorage}, filesystem, lisible au worker sans GUC) et à
 * l'embarquer en pièce inline (CID), le libellé habille l'en-tête de carte.
 *
 * ⚠ RETRAIT des en-têtes internes : tous les `X-Amateo-*` (dont `X-Amateo-Scope`) sont des
 * marqueurs internes — ils ne doivent JAMAIS fuir sur SMTP. On les RETIRE à la phase WORKER
 * (`!isQueued()`), juste avant la livraison. On NE les retire PAS à l'enfilage : l'intercepteur
 * démo ({@see ClockedClubMailInterceptor}, priorité 100, enfilage SEUL) lit `X-Amateo-Scope` AVANT
 * nous (priorité 0), et le message ORIGINAL conserve ses en-têtes à travers Redis jusqu'au worker.
 *
 * Idempotence : la garde « corps HTML déjà posé » court-circuite un e-mail déjà multipart et
 * empêche une double signature (chaque `Email` n'est signé qu'une fois par phase, phases qui opèrent
 * de toute façon sur des objets distincts).
 */
final readonly class EmailSignatureListener implements EventSubscriberInterface
{
    public function __construct(
        private ProductIdentity $productIdentity,
        private BrandAssets $brandAssets,
        private EmailTemplateRenderer $renderer,
        private LogoStorage $logoStorage,
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

        // Un e-mail qui porte déjà du HTML est laissé intact (aucun aujourd'hui, et la garde
        // évite une double signature si un jour il y en a) — mais on retire quand même les
        // en-têtes internes à la phase worker avant livraison.
        if (null !== $message->getHtmlBody()) {
            $this->stripInternalHeadersAtWorker($event, $message);

            return;
        }

        $originalText = $message->getTextBody();
        if (!\is_string($originalText) || '' === $originalText) {
            // Rien à signer (défensif : tous les envois de l'app posent un texte).
            $this->stripInternalHeadersAtWorker($event, $message);

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

        $message->embed($this->brandAssets->emailLogoPngBytes(), EmailTemplateRenderer::PRODUCT_LOGO_CID, 'image/png');

        // En-tête club : libellé + logo (si le club en a un). L'id vient de l'en-tête interne, les
        // octets du logo de LogoStorage (filesystem, pas de DB → OK au worker sans GUC).
        $clubLabel = $this->headerValue($message, ClubMailMetadata::CLUB_LABEL_HEADER);
        $clubId = $this->headerValue($message, ClubMailMetadata::CLUB_ID_HEADER);
        $clubLogoBytes = null !== $clubId ? $this->logoStorage->read($clubId) : null;
        if (null !== $clubLogoBytes) {
            $message->embed($clubLogoBytes, EmailTemplateRenderer::CLUB_LOGO_CID, $this->detectImageMime($clubLogoBytes));
        }

        $message->html(
            $this->renderer->render($originalText, $name, $tagline, $siteUrl, $clubLabel, null !== $clubLogoBytes),
            $charset,
        );

        $this->stripInternalHeadersAtWorker($event, $message);
    }

    /**
     * Retire TOUS les en-têtes internes `X-Amateo-*` — mais SEULEMENT à la phase worker
     * (`!isQueued()`), juste avant le SMTP. À l'enfilage on les garde (l'intercepteur démo les a
     * déjà lus, et les tests de métadonnées les vérifient sur le clone loggé).
     */
    private function stripInternalHeadersAtWorker(MessageEvent $event, Email $message): void
    {
        if ($event->isQueued()) {
            return;
        }

        $headers = $message->getHeaders();
        $toRemove = [];
        foreach ($headers->all() as $header) {
            if (str_starts_with(strtolower($header->getName()), 'x-amateo-')) {
                $toRemove[] = $header->getName();
            }
        }
        foreach ($toRemove as $headerName) {
            $headers->remove($headerName);
        }
    }

    private function headerValue(Email $message, string $name): ?string
    {
        $headers = $message->getHeaders();
        if (!$headers->has($name)) {
            return null;
        }
        $body = $headers->getHeaderBody($name);

        return \is_string($body) && '' !== $body ? $body : null;
    }

    /** Type MIME de l'image depuis ses octets (png/jpeg/webp), repli `image/png`. */
    private function detectImageMime(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if (false === $info || '' === $info['mime']) {
            return 'image/png';
        }

        return $info['mime'];
    }
}
