<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\CoachWishCampaign;
use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use App\Service\CoachWishMailBuilder;
use App\Service\ManagementAccessGuard;
use App\Service\ProductIdentity;
use App\Storage\LogoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'APERÇU de l'e-mail du lien coach (D1) — ce que le gestionnaire verra partir à l'envoi
 * INITIAL, rendu dans la fenêtre de campagne avant d'envoyer. Même patron que
 * {@see CoachWishCampaignActionController} : assertManager (SEC-07), club résolu depuis la
 * ligne (jamais du corps), lecture bornée au tenant par la RLS.
 *
 * On construit le mail EXACT via {@see CoachWishMailBuilder::buildCoachLink}, mais avec un
 * jeton FACTICE constant (jamais lu en base) et un prénom de coach d'EXEMPLE : l'aperçu ne doit
 * JAMAIS exposer un vrai lien personnel. Le HTML est rendu par le MÊME
 * {@see EmailTemplateRenderer} que la livraison réelle, à une seule différence : les logos
 * voyagent en `data:` URI (un `cid:` ne résout pas dans l'iframe de l'aperçu) — le mail réel
 * reste en CID.
 *
 * 404 byte-identique pour une campagne inconnue OU d'un autre club (la RLS la rend déjà
 * invisible ; la comparaison explicite est le filet, et renvoie le MÊME 404 — jamais 403 — pour
 * ne pas révéler l'existence d'une campagne d'autrui). Non-gestionnaire → 403 (assertManager).
 */
#[AsController]
final class CoachWishEmailPreviewController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /**
     * Jeton d'aperçu : une CONSTANTE, jamais un vrai jeton lu en base. Le lien reste
     * structurellement valide (le gestionnaire voit la forme du bouton et du lien nu) sans
     * jamais fuir une identité de coach.
     */
    private const string PREVIEW_TOKEN = 'apercu-ceci-nest-pas-un-vrai-jeton';

    /** Prénom d'exemple du coach destinataire — un placeholder neutre, aucune donnée réelle. */
    private const string EXAMPLE_COACH_FIRST_NAME = 'Prénom';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishMailBuilder $mailBuilder,
        private readonly EmailTemplateRenderer $renderer,
        private readonly ProductIdentity $productIdentity,
        private readonly BrandAssets $brandAssets,
        private readonly LogoStorage $logoStorage,
    ) {}

    #[Route('/api/coach_wish_campaigns/{id}/email-preview', name: 'coach_wish_campaign_email_preview', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $campaign = $this->entityManager->getRepository(CoachWishCampaign::class)->find($id);
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        // 404 byte-identique : inconnue, ou d'un autre club (la RLS la masque déjà, ceci est le
        // filet). Jamais 403 ici — on ne distingue pas « existe ailleurs » de « n'existe pas ».
        if (!$campaign instanceof CoachWishCampaign || (null !== $currentClubId && $campaign->getClubId() !== $currentClubId)) {
            throw $this->createNotFoundException();
        }

        $club = $this->entityManager->getRepository(Club::class)->find((string) $campaign->getClubId());
        $clubName = $club?->getName() ?? '';
        $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($campaign->getCalendarEntryId());
        $periodTitle = $entry?->getTitle() ?? 'la période';

        $user = $this->getUser();
        $senderFirstName = $user instanceof User ? $user->getFirstName() : '';

        // L'envoi INITIAL (isReminder: false), avec un jeton FACTICE et un coach d'exemple.
        $email = $this->mailBuilder->buildCoachLink(
            'apercu@exemple.invalid',
            self::EXAMPLE_COACH_FIRST_NAME,
            $clubName,
            $campaign,
            $periodTitle,
            self::PREVIEW_TOKEN,
            $senderFirstName,
            isReminder: false,
            club: $club,
        );

        return $this->json([
            'subject' => $email->getSubject() ?? '',
            'from' => $this->fromLabel($email),
            'html' => $this->renderHtml($email, $club),
        ]);
    }

    /** « Prénom (libellé) via Amateo <no-reply@amateo.app> » — l'expéditeur tel qu'il s'affiche. */
    private function fromLabel(Email $email): string
    {
        $from = $email->getFrom();
        if ([] === $from) {
            return '';
        }
        $address = $from[0];

        return '' !== $address->getName()
            ? \sprintf('%s <%s>', $address->getName(), $address->getAddress())
            : $address->getAddress();
    }

    /**
     * Le HTML de l'aperçu : le MÊME renderer que la livraison, mais logos en `data:` URI (les
     * `cid:` ne résolvent pas dans l'iframe). Le corps = le texte métier du builder (sans la
     * signature texte, posée par le listener au worker — le gabarit porte la sienne).
     */
    private function renderHtml(Email $email, ?Club $club): string
    {
        $bodyText = $this->textBodyOf($email);
        $clubLogoDataUri = $this->clubLogoDataUri($club);
        $ctaUrl = $this->headerValue($email, EmailTemplateRenderer::CTA_URL_HEADER);
        $ctaLabel = $this->headerValue($email, EmailTemplateRenderer::CTA_LABEL_HEADER);

        return $this->renderer->render(
            $bodyText,
            $this->productIdentity->name(),
            $this->productIdentity->tagline(),
            $this->productIdentity->siteUrl(),
            $club?->emailLabel(),
            null !== $clubLogoDataUri,
            $ctaUrl,
            $ctaLabel,
            'data:image/png;base64,' . base64_encode($this->brandAssets->emailLogoPngBytes()),
            $clubLogoDataUri,
        );
    }

    /** Le logo du club en `data:` URI, ou null s'il n'en a pas (filesystem, pas de DB). */
    private function clubLogoDataUri(?Club $club): ?string
    {
        if (!$club instanceof Club) {
            return null;
        }
        $bytes = $this->logoStorage->read($club->getId());
        if (null === $bytes) {
            return null;
        }

        return \sprintf('data:%s;base64,%s', $this->detectImageMime($bytes), base64_encode($bytes));
    }

    private function textBodyOf(Email $email): string
    {
        $body = $email->getTextBody();

        return \is_string($body) ? $body : '';
    }

    private function headerValue(Email $email, string $name): ?string
    {
        $headers = $email->getHeaders();
        if (!$headers->has($name)) {
            return null;
        }
        $body = $headers->getHeaderBody($name);

        return \is_string($body) && '' !== $body ? $body : null;
    }

    private function detectImageMime(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if (false === $info || '' === $info['mime']) {
            return 'image/png';
        }

        return $info['mime'];
    }
}
