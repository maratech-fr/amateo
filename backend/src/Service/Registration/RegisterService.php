<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Controller\AuthController;
use App\Entity\Club;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ClubRepository;
use App\Security\TurnstileVerifier;
use App\Service\AuditTrail;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbClubDirectory;
use App\Service\EmailVerifier;
use App\Service\MailFrom;
use App\Service\PasswordPolicy;
use App\Service\ProductIdentity;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

/**
 * `/api/register` (+ `/api/register/config`) — extrait VERBATIM de
 * AuthController::register. Ne matérialise jamais de tenant : crée un compte
 * UNVERIFIED et envoie un lien de vérification ; le club + seed sont déférés à
 * `/api/register/verify` (EmailVerificationService). La réponse 202 est identique
 * pour un e-mail frais, connu, une adhésion ou une création (anti-énumération A3).
 */
final class RegisterService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClubRepository $clubRepository,
        private readonly RateLimiterFactory $authRegisterLimiter,
        // P4-304 (Q1) — horloge RÉELLE : `termsAcceptedAt` est la preuve RGPD du
        // consentement, un horodatage de SÉCURITÉ. La route publique /api/register
        // peut porter le `_club_id` d'un club démo (JWT présent) ; l'horloge décorée
        // simulerait alors sa date et dater la preuve en 2000/2099. Seul usage de
        // l'horloge ici (termsAcceptedAt) ; `animatorWindowIsOpen` lit déjà l'instant
        // réel en direct.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly MailerInterface $mailer,
        private readonly EmailVerifier $emailVerifier,
        private readonly string $frontendBaseUrl,
        private readonly AuditTrail $auditTrail,
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly string $turnstileSiteKey,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
        // P2-4 (revue sécu) — l'adresse démo, exposée au front SEULEMENT en debug pour
        // qu'il ne tente le raccourci démo QUE sur cette adresse. Maison unique côté
        // controller démo : DevDemoRegisterController::$demoAnimatorEmail (même param).
        #[Autowire(param: 'app.demo_animator_email')]
        private readonly string $demoAnimatorEmail,
        private readonly LoggerInterface $logger,
        private readonly SerializerInterface $serializer,
        private readonly FfbbClubDirectory $ffbbClubDirectory,
        // Vérification de l'existence fédérale du code FFBB à la CRÉATION d'un club :
        // active en prod (garde anti-squatting), inerte en dev/Behat & démos (codes
        // synthétiques). Patron Turnstile (défaut d'environnement).
        #[Autowire(param: 'app.ffbb_register_existence_check')]
        private readonly bool $ffbbRegisterExistenceCheck,
    ) {}

    public function register(Request $request): JsonResponse
    {
        // Rate-limit by client IP (anti-brute-force + anti-ARA-enumeration).
        if (!$this->authRegisterLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $email = isset($data['email']) && \is_string($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : '';
        $firstName = isset($data['firstName']) && \is_string($data['firstName']) ? trim($data['firstName']) : '';
        $lastName = isset($data['lastName']) && \is_string($data['lastName']) ? trim($data['lastName']) : '';
        $ara = isset($data['ara']) && \is_string($data['ara']) ? strtoupper(trim($data['ara'])) : '';
        $clubName = isset($data['club_name']) && \is_string($data['club_name']) ? trim($data['club_name']) : '';
        $consent = true === ($data['consent'] ?? false);

        // Validation below is HOISTED above the email lookup and depends only on the
        // submitted payload (or the ARA — public FFBB data), NEVER on whether the
        // email exists. A differing 400 would otherwise be an account-enumeration
        // oracle (A3). The success path returns an identical 202 for a fresh or an
        // already-registered email — existence is signalled only out-of-band by mail.
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Une adresse e-mail valide est requise.'], 400);
        }
        if (null !== ($passwordError = $this->passwordPolicy->validate($password))) {
            return $this->json(['error' => $passwordError], 400);
        }
        if ('' === $firstName || '' === $lastName) {
            return $this->json(['error' => 'Le prénom et le nom sont requis.'], 400);
        }
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $ara)) {
            return $this->json(['error' => 'L\'ARA doit comporter 3 à 20 caractères alphanumériques en majuscules.'], 400);
        }
        // RGPD : le consentement CGU/politique de confidentialité est requis —
        // validation payload-only, donc toujours enumeration-safe (A3).
        if (!$consent) {
            return $this->json(['error' => 'Vous devez accepter les CGU et la politique de confidentialité.'], 400);
        }

        // P5-3b — Turnstile (preuve d'humanité). INERTE tant qu'aucun secret n'est
        // configuré (dev/test) : le token est alors ignoré et le register reste
        // byte-intact. Placé APRÈS les validations payload-only et AVANT tout lookup
        // (ARA :129, e-mail :143) : le 403 ne dépend JAMAIS de l'existence d'un
        // compte, donc il ne rouvre pas l'oracle d'énumération A3 que le reste de ce
        // contrôleur ferme (message identique email frais vs email connu).
        if ($this->turnstileVerifier->isEnabled()) {
            $turnstileToken = isset($data['turnstileToken']) && \is_string($data['turnstileToken']) ? $data['turnstileToken'] : '';
            if (!$this->turnstileVerifier->verify($turnstileToken, $request->getClientIp())) {
                return $this->json(['error' => 'La vérification anti-robot a échoué. Veuillez réessayer.'], 403);
            }
        }

        $email = strtolower($email);
        $existingClub = $this->clubRepository->findRealByFfbbCode($ara);

        // club_name is required to CREATE a club. Keyed on the ARA (public), not the
        // email → still enumeration-safe: the 400 never depends on account existence.
        if (!$existingClub instanceof Club && '' === $clubName) {
            return $this->json(['error' => 'Le nom du club est requis pour créer un nouveau club.'], 400);
        }

        // Vérification FFBB — chemin CRÉATION uniquement (ARA neuf, aucun club réel en
        // base). Rejoindre un club déjà en base n'appelle JAMAIS la fédération (format
        // hérité toléré). Keyée sur l'ARA (donnée publique FFBB), jamais sur l'e-mail →
        // ne rouvre pas l'oracle d'énumération A3 que ce contrôleur ferme. Gardée par un
        // flag d'environnement : active en prod (anti-squatting d'un code fédéral), inerte
        // en dev/Behat & démos (codes synthétiques, patron Turnstile).
        if (!$existingClub instanceof Club && $this->ffbbRegisterExistenceCheck) {
            if (!FfbbApiClient::isValidClubCode($ara)) {
                return $this->json(['error' => 'Ce code FFBB n\'est pas valide.'], 400);
            }
            // FFBB muette (null) → ne PAS bloquer : la demande s'ouvrira sans mail FFBB
            // (file superadmin). Seul un « inconnu » FRANC (false) refuse l'inscription.
            if (false === $this->ffbbClubDirectory->exists($ara)) {
                return $this->json(['error' => 'Ce code FFBB est inconnu de la fédération.'], 400);
            }
        }

        // Intent captured at register time: a club NAME rides on the token ONLY when this
        // registration creates a club (new ARA). A join (existing ARA) stores null, so
        // verify can never silently promote a would-be pending member to admin if the
        // target club has since vanished.
        $intentClubName = $existingClub instanceof Club ? null : $clubName;

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existingUser) {
            if (null === $existingUser->getEmailVerifiedAt()) {
                // Re-registration of an UNVERIFIED account = recovery: the first email was
                // lost/expired and neither login nor reset can activate it. Refresh the
                // credentials + club intent and resend a fresh verification link. Same 202.
                $existingUser->setPasswordHash($this->passwordHasher->hashPassword($existingUser, $password));
                $existingUser->setFirstName($firstName);
                $existingUser->setLastName($lastName);
                $existingUser->setTermsAcceptedAt($this->clock->now());
                $existingUser->setTermsVersion(AuthController::TERMS_VERSION);
                $rawToken = $this->emailVerifier->generateToken($existingUser, $ara, $intentClubName);
                $this->entityManager->flush();
                $this->sendVerificationEmail($request, $existingUser->getEmail(), $rawToken);
            } else {
                // Verified account: reveal nothing in the response. Spend an equivalent
                // password hash (timing) and send an out-of-band "you already have an
                // account" mail directing to login/reset.
                // Accepted residual: this branch skips the DB writes the create/recover
                // paths perform, so a fine-grained timing probe could still distinguish a
                // *verified* account. Bounded by the per-IP register rate limiter
                // (5/15min in prod) — network jitter dwarfs the sub-ms DB delta; not worth
                // faking writes for. The response body/status stay identical.
                $this->passwordHasher->hashPassword($existingUser, $password);
                $this->sendAccountExistsEmail($existingUser->getEmail());
            }

            return $this->verificationPendingResponse();
        }

        // Fresh email: create the UNVERIFIED account only. User is a global entity (no
        // club_id) so no tenant GUC is needed here — the club + seed are deferred to
        // /api/register/verify, so an unverified (possibly fake) registration never
        // materialises a tenant nor squats an ARA. The pending club intent (ffbb code
        // + name) rides on the verification token until then.
        $rawToken = '';
        $this->entityManager->wrapInTransaction(function () use ($email, $password, $firstName, $lastName, $ara, $intentClubName, &$rawToken): void {
            $user = $this->createUser($email, $password, $firstName, $lastName);
            $rawToken = $this->emailVerifier->generateToken($user, $ara, $intentClubName);
        });

        $this->sendVerificationEmail($request, $email, $rawToken);

        return $this->verificationPendingResponse();
    }

    /**
     * P5-3b — la config publique dont le widget Turnstile de la page d'inscription
     * a besoin : la sitekey (publique par nature). `null` quand Turnstile est
     * inactif (aucune sitekey configurée) → le front rend strictement l'écran
     * actuel, sans widget ni script tiers. Publique par le préfixe ^/api/register
     * de security.yaml.
     *
     * P2-4 — champ ADDITIF `demoShortcut` : vrai en debug (démo) OU quand la fenêtre
     * d'activation du compte animateur démo est ouverte ; le front tente alors le
     * raccourci démo après le 202 du register (DevDemoRegisterController, aligné sur la
     * même condition). Faux sinon → le front garde strictement l'écran « vérifiez votre
     * e-mail ». Exposer `demoEmail` quand la fenêtre est ouverte est un mini-oracle
     * limité à la fenêtre, assumé. Le rail register reste par ailleurs intact.
     */
    public function registerConfig(): JsonResponse
    {
        $demoAvailable = $this->debug || $this->animatorWindowIsOpen();

        return $this->json([
            'turnstileSiteKey' => '' !== $this->turnstileSiteKey ? $this->turnstileSiteKey : null,
            'demoShortcut' => $demoAvailable,
            // Exposée uniquement quand le raccourci est disponible (debug ou fenêtre
            // ouverte) ; sinon nulle, aucun oracle. Le front ne tente le raccourci que si
            // l'adresse saisie EST cette adresse — le mot de passe d'un vrai prospect ne
            // part jamais vers la route démo.
            'demoEmail' => $demoAvailable ? strtolower($this->demoAnimatorEmail) : null,
        ]);
    }

    /**
     * La fenêtre d'activation démo du compte animateur est-elle ouverte à l'instant
     * RÉEL ? Compte absent → fermée. Horloge réelle (`new DateTimeImmutable('now')`),
     * jamais simulated_today. Lecture seule.
     */
    private function animatorWindowIsOpen(): bool
    {
        $animator = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower($this->demoAnimatorEmail)]);

        return $animator instanceof User && $animator->isDemoWindowOpen(new DateTimeImmutable('now'));
    }

    /**
     * The single, identical response for every register outcome (fresh email, taken
     * email, join or create) — byte-for-byte, so nothing distinguishes the branches.
     */
    private function verificationPendingResponse(): JsonResponse
    {
        return $this->json(['status' => 'verification_pending'], 202);
    }

    private function sendVerificationEmail(Request $request, string $email, string $rawToken): void
    {
        // FRONTEND_BASE_URL points at the browser-facing origin; fall back to the
        // request host in dev/e2e (single origin via the Vite proxy). Prod sets it.
        $base = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') : $request->getSchemeAndHttpHost();
        $link = $base . '/verify-email/' . $rawToken;
        $product = $this->productIdentity->name();

        // mailer->send only ENQUEUES a SendEmailMessage on the bus now; an SMTP failure
        // surfaces at the worker, where the failure transport retains it. Only a DISPATCH
        // failure (Redis down) would land here — swallowed: a 500 on this branch alone
        // would itself be an account-enumeration oracle (mirror PasswordController::forgot).
        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($email)
                    ->subject(\sprintf('Confirmez votre adresse e-mail %s', $product))
                    ->text("Bienvenue sur {$product} !\n\nPour activer votre compte, ouvrez ce lien :\n{$link}\n\nCe lien expire dans 24 heures."),
            );
        } catch (Throwable $e) {
            // Le mail est avalé (un 500 ici serait un oracle d'énumération) mais l'échec
            // de DISPATCH est tracé — sans quoi une panne du bus (Redis) resterait muette.
            $this->logger->warning('Mailer dispatch failed (transactional email not enqueued)', ['error' => $e->getMessage()]);
        }
    }

    private function sendAccountExistsEmail(string $email): void
    {
        // mailer->send ne fait plus qu'ENFILER un SendEmailMessage sur le bus ; un échec
        // SMTP surgit chez le worker (le failure transport le retient). Seul un échec de
        // DISPATCH (Redis down) tomberait ici — avalé : un 500 sur cette seule branche
        // serait un oracle d'énumération.
        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($email)
                    ->subject(\sprintf('Tentative d’inscription sur %s', $this->productIdentity->name()))
                    ->text("Une inscription vient d’être tentée avec cette adresse, mais un compte existe déjà.\n\nConnectez-vous, ou réinitialisez votre mot de passe si vous l’avez oublié."),
            );
        } catch (Throwable $e) {
            // Le mail est avalé (un 500 ici serait un oracle d'énumération) mais l'échec
            // de DISPATCH est tracé — sans quoi une panne du bus (Redis) resterait muette.
            $this->logger->warning('Mailer dispatch failed (transactional email not enqueued)', ['error' => $e->getMessage()]);
        }
    }

    private function createUser(string $email, string $password, string $firstName, string $lastName): User
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        // RGPD : preuve de consentement (horodatage + version des textes).
        $user->setTermsAcceptedAt($this->clock->now());
        $user->setTermsVersion(AuthController::TERMS_VERSION);
        $this->entityManager->persist($user);
        // RGPD audit : événement GLOBAL (pas encore de tenant) — l'id seul,
        // jamais l'email (règle no-PII du journal).
        $this->auditTrail->record(AuditAction::AUTH_REGISTER, $user->getId(), null, 'User', $user->getId());

        return $user;
    }

    /**
     * Réplique byte-identique de AbstractController::json (le serializer est
     * toujours présent dans cette application) — démembrement VERBATIM oblige.
     *
     * @param array<string, mixed> $headers
     */
    private function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        $json = $this->serializer->serialize($data, 'json', [
            'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS,
        ]);

        return new JsonResponse($json, $status, $headers, true);
    }
}
