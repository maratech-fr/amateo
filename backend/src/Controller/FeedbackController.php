<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Feedback;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\User;
use App\Service\FeedbackContextEnricher;
use App\Service\FeedbackMailBuilder;
use App\Storage\FeedbackScreenshotStorage;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Le canal de signalement côté membre : TOUT membre authentifié peut envoyer un
 * bug, une contrainte manquante ou une idée depuis l'application. Contrôleur
 * custom hors API Platform — le firewall `api` suffit (pas de garde management :
 * n'importe quel membre parle).
 *
 * Deux protections avant tout travail : le firewall (401 sans identité) et un
 * limiteur dédié PAR UTILISATEUR (10/h) — la route persiste ET envoie un mail,
 * elle mérite sa borne en plus du limiteur `api` global.
 *
 * Le contexte lourd (snapshot + diagnostics d'un planning nommé) est recopié
 * CÔTÉ SERVEUR sous le filtre tenant : un client ne peut pas joindre le planning
 * d'un autre club, et la copie fige ce qui sera vrai quand le support la lira —
 * un planning peut disparaître entre-temps.
 */
#[AsController]
final class FeedbackController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /** Valeurs acceptées pour `topic` — validées serveur, pas un enum PHP exposé. */
    private const array TOPICS = ['bug', 'missing_constraint', 'idea'];

    private const int MESSAGE_MAX = 5000;

    // 2 Mo : même borne que la retouche de qualité côté client (modern-screenshot vise ≤ 2 Mo).
    private const int SCREENSHOT_MAX_BYTES = 2_000_000;

    // Garde-fou anti-bombe de décompression : une capture d'écran plausible reste SOUS
    // 8000 px de côté et 40 Mpx (deux écrans 4K ≈ 33 Mpx) — au-delà, refus AVANT toute
    // allocation d'image (dimensions lues dans l'en-tête, jamais via un décodage).
    private const int SCREENSHOT_MAX_DIMENSION = 8000;

    private const int SCREENSHOT_MAX_PIXELS = 40_000_000;

    /** @var array<string, true> types SONDÉS acceptés pour une capture (jamais le type déclaré par le client). */
    private const array SCREENSHOT_ALLOWED = [
        'image/png' => true,
        'image/jpeg' => true,
        'image/webp' => true,
    ];

    /** Champs de contexte léger acceptés du client → longueur maximale conservée. */
    private const array LIGHT_CONTEXT_FIELDS = [
        'screen' => 120,
        'url' => 2048,
        'requestId' => 128,
        'userAgent' => 512,
        // Chantier F PR1 — repères TEXTE que le client joint pour situer l'incident
        // (jamais d'id : le contexte serveur ne lit QUE le club authentifié).
        'screenLabel' => 160,
        'activeFilters' => 300,
        'displayedRange' => 80,
        // P4-355 — version du BUNDLE front déclarée par le client (« App vX · API vY »).
        'appVersion' => 40,
        'lastClick' => 160,
        'incidentStatus' => 40,
        'incidentUrl' => 300,
        'incidentCode' => 80,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly FeedbackMailBuilder $mailBuilder,
        private readonly FeedbackContextEnricher $contextEnricher,
        private readonly RateLimiterFactory $feedbackLimiter,
        private readonly RequestStack $requestStack,
        private readonly FeedbackScreenshotStorage $screenshotStorage,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/feedback', name: 'api_feedback_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Anti-abus : borne PAR UTILISATEUR, AVANT tout travail (patron des routes
        // sensibles keyées sur le JWT).
        if (!$this->feedbackLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de signalements — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Deux portes, un seul corps JSON : en MULTIPART (capture jointe) le JSON arrive dans
        // le champ de formulaire `payload` ; sinon c'est le corps brut. Le chemin JSON est
        // inchangé — aucun client existant n'a à bouger.
        $payloadField = $request->request->get('payload');
        $rawJson = \is_string($payloadField) ? $payloadField : (string) $request->getContent();
        $data = json_decode($rawJson, true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $topic = \is_string($data['topic'] ?? null) ? $data['topic'] : '';
        if (!\in_array($topic, self::TOPICS, true)) {
            return $this->json(['error' => 'Type de signalement invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = \is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if ('' === $message) {
            return $this->json(['error' => 'Le message est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            return $this->json(['error' => 'Le message est trop long.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Capture d'écran (multipart) validée AVANT toute persistance : un envoi invalide ne
        // laisse AUCUNE ligne (atomique). Taille → type sondé → dimensions, dans cet ordre.
        $screenshotBytes = null;
        $file = $request->files->get('screenshot');
        if ($file instanceof UploadedFile) {
            $validated = $this->validateScreenshot($file);
            if ($validated instanceof JsonResponse) {
                return $validated;
            }
            $screenshotBytes = $validated;
        }

        $inputContext = \is_array($data['context'] ?? null) ? $data['context'] : [];
        $context = $this->lightContext($inputContext);

        // Contexte lourd : quand un planning est nommé, on le recopie CÔTÉ SERVEUR
        // sous le filtre tenant. Un scheduleId étranger/inconnu fait échouer le POST
        // ENTIER (404) — le client n'en envoie un que s'il affiche ce planning.
        $scheduleId = \is_string($inputContext['scheduleId'] ?? null) ? $inputContext['scheduleId'] : null;
        if (null !== $scheduleId && '' !== $scheduleId) {
            // Forme validée avant le find() : un id non-UUID ferait lever le driver
            // (500) au lieu du 404 uniforme promis par le contrat (revue sécurité).
            if (!Uuid::isValid($scheduleId)) {
                return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
            }
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
            if (!$schedule instanceof Schedule || $schedule->getClubId() !== $clubId) {
                return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
            }
            $context['scheduleId'] = $scheduleId;
            $context['seasonId'] = $schedule->getSeasonId();
            $context['scheduleStatus'] = $schedule->getStatus()->value;
            // Le signalement porte l'entrée RÉELLE du solve (snapshot + greffe de convergence),
            // pas seulement le snapshot gelé — la clé garde son nom `snapshot`.
            $context['snapshot'] = $schedule->engineInput();
            $context['diagnostics'] = $this->diagnosticsOf($scheduleId);
        }

        // Contexte AUTOMATIQUE large (Chantier F PR1) : résolu CÔTÉ SERVEUR depuis le
        // club authentifié, joint à TOUT signalement (planning nommé ou non). Best-effort :
        // ne fait jamais échouer le POST.
        $context = $this->contextEnricher->enrich($clubId, $user->getId(), $context);

        $feedback = new Feedback($clubId, $user->getId(), $topic, $message);
        $feedback->setContext([] === $context ? null : $context);
        if (null !== $screenshotBytes) {
            $feedback->setHasScreenshot(true);
        }

        // Persister AVANT d'envoyer le mail : l'accusé de réception est un
        // best-effort, un échec de dispatch ne doit pas perdre un signalement
        // déjà en base (patron des helpers d'envoi d'AuthController).
        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        // Blob stocké HORS table APRÈS la persistance (sa clé = l'id du signalement, connu
        // dès le constructeur). Un échec d'écriture RETIRE la ligne : pas de signalement
        // fantôme marqué « avec capture » dont le blob manquerait.
        if (null !== $screenshotBytes) {
            try {
                $this->screenshotStorage->store($feedback->getId(), $screenshotBytes);
            } catch (Throwable $e) {
                $this->entityManager->remove($feedback);
                $this->entityManager->flush();
                $this->logger->error('Feedback screenshot storage failed', ['error' => $e->getMessage()]);

                return $this->json(['error' => 'La capture n\'a pas pu être enregistrée.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        $this->sendAcknowledgement((string) $user->getEmail());

        return $this->json(['id' => $feedback->getId()], Response::HTTP_CREATED);
    }

    /**
     * Valide une capture d'écran jointe : taille d'abord, puis type SONDÉ (jamais le type
     * déclaré par le client), puis dimensions lues dans l'en-tête SANS décoder un pixel.
     * Rend les octets validés, ou un 422 — AVANT toute persistance (envoi atomique).
     */
    private function validateScreenshot(UploadedFile $file): string|JsonResponse
    {
        // Borne de taille EN PREMIER (patron ClubLogoController) : un fichier trop lourd
        // n'est même pas lu en mémoire.
        if ($file->getSize() > self::SCREENSHOT_MAX_BYTES) {
            return $this->json(['error' => 'La capture est trop volumineuse.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bytes = file_get_contents($file->getPathname());
        if (false === $bytes) {
            return $this->json(['error' => 'La capture n\'a pas pu être lue.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Type SONDÉ depuis les octets : un SVG (script embarqué → XSS) et un corps non-image
        // déclaré png (polyglotte) tombent ici, quel que soit le type annoncé par le client.
        $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($bytes);
        if (!\is_string($mime) || !isset(self::SCREENSHOT_ALLOWED[$mime])) {
            return $this->json(['error' => 'Format de capture non pris en charge.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Dimensions lues dans l'en-tête, sans allocation d'image : une bombe de
        // décompression (fichier minuscule déclarant des dimensions énormes) tombe ici.
        $size = getimagesizefromstring($bytes);
        if (false === $size) {
            return $this->json(['error' => 'Capture illisible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        [$width, $height] = $size;
        if ($width > self::SCREENSHOT_MAX_DIMENSION || $height > self::SCREENSHOT_MAX_DIMENSION || $width * $height > self::SCREENSHOT_MAX_PIXELS) {
            return $this->json(['error' => 'La capture a des dimensions trop grandes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $bytes;
    }

    /**
     * Contexte léger : uniquement les champs string attendus, chacun borné en
     * longueur ; tout le reste est ignoré (le client ne dicte pas la forme).
     *
     * @param array<int|string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function lightContext(array $input): array
    {
        $context = [];
        foreach (self::LIGHT_CONTEXT_FIELDS as $key => $max) {
            $value = $input[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                $context[$key] = mb_substr($value, 0, $max);
            }
        }

        return $context;
    }

    /**
     * Sérialisation compacte des diagnostics d'un planning (tenant-scopée par le
     * repository) — ce que le support a besoin de lire, sans les colonnes d'infra.
     *
     * @return list<array<string, mixed>>
     */
    private function diagnosticsOf(string $scheduleId): array
    {
        return array_map(static fn (ScheduleDiagnostic $diagnostic): array => [
            'type' => $diagnostic->getType(),
            'severity' => $diagnostic->getSeverity()->value,
            'message' => $diagnostic->getMessage(),
            'teamId' => $diagnostic->getTeamId(),
            'coachId' => $diagnostic->getCoachId(),
            'venueId' => $diagnostic->getVenueId(),
            'suggestions' => $diagnostic->getSuggestions(),
        ], $this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]));
    }

    private function sendAcknowledgement(string $to): void
    {
        // mailer->send n'enfile qu'un SendEmailMessage sur le bus ; seul un échec de
        // DISPATCH (Redis down) tomberait ici — avalé, le signalement est déjà persisté.
        try {
            $this->mailer->send($this->mailBuilder->build($to));
        } catch (Throwable $e) {
            // Mail avalé (le signalement est déjà persisté) mais l'échec de DISPATCH est
            // tracé — sinon une panne du bus (Redis) resterait muette.
            $this->logger->warning('Feedback acknowledgement mail dispatch failed', ['error' => $e->getMessage()]);
        }
    }
}
