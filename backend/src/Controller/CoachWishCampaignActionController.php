<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\User;
use App\Repository\CoachWishTokenRepository;
use App\Service\CoachWishCampaignPresenter;
use App\Service\CoachWishMailBuilder;
use App\Service\CoachWishSeasonGuard;
use App\Service\ManagementAccessGuard;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

/**
 * Les deux ACTIONS d'envoi d'une campagne de collecte (feature #10, lot C3), même patron
 * que VenuePeriodGridActionController (SEC-07, club depuis la ligne jamais du corps) :
 *
 *  - `send-links` : envoie son lien personnel à chaque coach du périmètre AYANT un email et
 *    PAS ENCORE servi (`sentAt` null) — ou aux seuls `coachIds` du corps (ajout tardif d'un
 *    email, décision D1/D2 : le bouton global n'est pas un renvoi général). Stampe
 *    `token.sentAt` par coach. Best-effort : un échec SMTP n'empêche pas les autres envois
 *    (le coach en échec garde `sentAt` null — sa ligne reste « à envoyer »).
 *  - `remind` : relance les SILENCIEUX (`respondedAt` null) à email. UNE relance par jour
 *    Europe/Paris (anti-harcèlement, décision fondateur D3) → 422 sinon.
 *
 * Les adresses passent un dernier filtre de forme (D8) — la validation de saisie
 * (Assert\Email sur CoachInput) fait foi, ceci n'est que le filet.
 */
#[AsController]
final class CoachWishCampaignActionController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachWishTokenRepository $tokenRepository,
        private readonly CoachWishMailBuilder $mailBuilder,
        private readonly CoachWishCampaignPresenter $presenter,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly CoachWishSeasonGuard $seasonGuard,
        private readonly RateLimiterFactory $coachWishSendLimiter,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $campaign = $this->entityManager->getRepository(CoachWishCampaign::class)->find($id);
        if (!$campaign instanceof CoachWishCampaign) {
            return $this->json(['error' => 'Collecte introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Le club vient de la LIGNE, jamais du corps — un id d'un autre club est déjà
        // invisible ici (RLS + filtre tenant), la comparaison est le filet app-layer.
        $request = $this->requestStack->getCurrentRequest();
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $campaign->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Une saison gelée est en lecture seule (invariant SeasonReadonly) : ces actions
        // écrivent (sentAt/lastReminderAt), donc elles doivent 409 comme le chemin processor.
        // Dérivation calendaire (isReadonlyAmong) + archivage manuel — même règle que la page
        // publique (le read-only n'est PAS posé sur le statut au roulement du 15-juillet).
        if ($this->seasonGuard->isReadonly($campaign)) {
            return $this->json(['error' => 'Cette saison est archivée — elle est en lecture seule.'], Response::HTTP_CONFLICT);
        }

        // Anti-hameçonnage (P4-316) : un compte de DÉMONSTRATION ne doit jamais e-mailer un tiers
        // (geste de compte interdit aux démos, patron ClubInvitationController). L'envoi réel de
        // liens/relances est refusé — aucune adresse de coach n'est démarchée sous un club démo.
        $club = $this->entityManager->getRepository(Club::class)->find((string) $campaign->getClubId());
        if ($club instanceof Club && $club->isDemo()) {
            return $this->json(['error' => 'Envoi d\'e-mails indisponible en démonstration.'], Response::HTTP_FORBIDDEN);
        }

        if ('remind_coach_wish_campaign' === $request?->attributes->get('_route')) {
            return $this->remind($campaign);
        }

        return $this->sendLinks($campaign, $request);
    }

    private function sendLinks(CoachWishCampaign $campaign, ?Request $request): JsonResponse
    {
        $payload = json_decode((string) $request?->getContent(), true);
        $onlyCoachIds = null;
        if (\is_array($payload) && isset($payload['coachIds']) && \is_array($payload['coachIds'])) {
            $onlyCoachIds = array_values(array_filter($payload['coachIds'], is_string(...)));
        }

        $outcome = $this->dispatchToCoaches($campaign, isReminder: false, onlyCoachIds: $onlyCoachIds);
        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }
        $this->entityManager->flush();

        return $this->json(['sent' => $outcome, 'campaign' => $this->presenter->toResource($campaign)], Response::HTTP_OK);
    }

    private function remind(CoachWishCampaign $campaign): JsonResponse
    {
        // Une relance par jour CALENDAIRE Europe/Paris — « pas 2 fois dans la même
        // journée, c'est du harcèlement » (fondateur). Pas 24 h glissantes (D3).
        $paris = new DateTimeZone('Europe/Paris');
        $today = $this->clock->now()->setTimezone($paris)->format('Y-m-d');
        $last = $campaign->getLastReminderAt()?->setTimezone($paris)->format('Y-m-d');
        if ($today === $last) {
            return $this->json(['error' => 'Les coachs ont déjà été relancés aujourd\'hui.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $outcome = $this->dispatchToCoaches($campaign, isReminder: true, onlyCoachIds: null);
        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }
        if ($outcome > 0) {
            $campaign->markReminderSent($this->clock->now());
        }
        $this->entityManager->flush();

        return $this->json(['sent' => $outcome, 'campaign' => $this->presenter->toResource($campaign)], Response::HTTP_OK);
    }

    /**
     * Résout les destinataires éligibles, applique le PLAFOND anti-hameçonnage PAR CLUB, puis
     * envoie. Retourne le nombre d'envois réussis, ou une réponse 429 si le budget du club est
     * épuisé (dans ce cas RIEN n'est envoyé). Plafond partagé send-links/relance/renvoi ciblé.
     *
     * @param list<string>|null $onlyCoachIds
     */
    private function dispatchToCoaches(CoachWishCampaign $campaign, bool $isReminder, ?array $onlyCoachIds): int|JsonResponse
    {
        $recipients = $this->eligibleRecipients($campaign, $isReminder, $onlyCoachIds);
        if ([] === $recipients) {
            return 0; // rien à envoyer → on ne consomme AUCUN budget (N==0).
        }

        // Plafond anti-hameçonnage (P4-316), PAR CLUB : on consomme AVANT l'envoi le nombre EXACT
        // de destinataires de CETTE requête. Refusé → 429 et rien n'est enfilé (consume ne réserve
        // rien quand il refuse). Clé = clubId → send-links, relance et renvoi ciblé partagent le
        // même budget de 24 h.
        $limiter = $this->coachWishSendLimiter->create((string) $campaign->getClubId());
        try {
            $accepted = $limiter->consume(\count($recipients))->isAccepted();
        } catch (InvalidArgumentException) {
            // Un lot de destinataires PLUS GRAND que le plafond lui-même : le limiteur refuse de
            // réserver plus de jetons que sa taille (il lève). C'est le MÊME dépassement métier
            // qu'un budget épuisé → même refus 429, rien d'enfilé (jamais un 500 opaque).
            $accepted = false;
        }
        if (!$accepted) {
            return $this->json(['error' => 'Limite d\'envoi atteinte (200 e-mails / 24 h), réessayez demain.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->sendToRecipients($campaign, $recipients, $isReminder);
    }

    /**
     * Les destinataires éligibles de CETTE requête (après filtres d'éligibilité), chacun avec son
     * token et son email de forme valide — résolus AVANT l'envoi pour compter le budget à consommer.
     *
     * Éligible : coach du périmètre courant, porteur d'un token, avec un email de forme
     * valide, ET — envoi initial : `sentAt` null (ou listé dans `$onlyCoachIds`, renvoi
     * ciblé explicite) — relance : `respondedAt` null (les répondants ne sont pas harcelés).
     *
     * @param list<string>|null $onlyCoachIds
     *
     * @return list<array{token: CoachWishToken, coach: Coach, email: string}>
     */
    private function eligibleRecipients(CoachWishCampaign $campaign, bool $isReminder, ?array $onlyCoachIds): array
    {
        $tokens = $this->tokenRepository->findByCampaign($campaign->getId());
        $wanted = null === $onlyCoachIds ? null : array_flip($onlyCoachIds);
        $recipients = [];
        foreach ($tokens as $token) {
            if (null !== $wanted && !isset($wanted[$token->getCoachId()])) {
                continue;
            }
            if ($isReminder) {
                if (null !== $token->getRespondedAt()) {
                    continue; // a répondu — on ne relance pas.
                }
            } elseif (null === $wanted && null !== $token->getSentAt()) {
                continue; // déjà servi — le bouton global n'est pas un renvoi général (D2).
            }

            $coach = $this->entityManagerCoach($token->getCoachId());
            $email = $coach?->getEmail();
            if (!$coach instanceof Coach || null === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                continue; // pas d'email exploitable → badge « pas d'email » côté écran.
            }

            $recipients[] = ['token' => $token, 'coach' => $coach, 'email' => $email];
        }

        return $recipients;
    }

    /**
     * Envoie le lien à chaque destinataire éligible et stampe `sentAt`. Retourne le nombre
     * d'envois réussis. Best-effort : un échec SMTP n'empêche pas les autres (le coach en échec
     * garde `sentAt` null — sa ligne reste « à envoyer »).
     *
     * @param list<array{token: CoachWishToken, coach: Coach, email: string}> $recipients
     */
    private function sendToRecipients(CoachWishCampaign $campaign, array $recipients, bool $isReminder): int
    {
        $club = $this->entityManager->getRepository(Club::class)->find((string) $campaign->getClubId());
        $clubName = $club?->getName() ?? '';
        $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($campaign->getCalendarEntryId());
        $periodTitle = $entry?->getTitle() ?? 'la période';

        // Formule A (D1) : le corps et l'expéditeur portent le PRÉNOM du gestionnaire qui
        // déclenche CET envoi/relance — le `getUser()` courant, après assertManager (SEC-07).
        $user = $this->getUser();
        $senderFirstName = $user instanceof User ? $user->getFirstName() : '';

        $sent = 0;
        foreach ($recipients as $recipient) {
            $token = $recipient['token'];
            $coach = $recipient['coach'];
            try {
                $this->mailer->send($this->mailBuilder->buildCoachLink($recipient['email'], $coach->getFirstName(), $clubName, $campaign, $periodTitle, $token->getToken(), $senderFirstName, $isReminder, $club));
                $token->markSent($this->clock->now());
                ++$sent;
            } catch (Throwable) {
                // Best-effort : l'échec d'UN envoi ne bloque pas les autres ; sentAt reste
                // null pour ce coach — sa ligne reste visible « à envoyer ».
            }
        }

        return $sent;
    }

    private function entityManagerCoach(string $coachId): ?Coach
    {
        $coach = $this->entityManager->getRepository(Coach::class)->find($coachId);

        return $coach instanceof Coach ? $coach : null;
    }
}
