<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Service\MailFrom;
use App\Service\PasswordPolicy;
use App\Service\ProductIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Throwable;

final class PasswordController extends AbstractController
{
    // Mot de passe jeté, dépensé uniquement pour équilibrer le coût CPU du hash
    // sur la branche « compte inexistant » du forgot (anti-énumération par timing).
    // Jamais stocké, jamais comparé — le hasher est keyé par classe, l'instance est jetable.
    private const string TIMING_EQUALISER_PASSWORD = 'timing-equaliser-not-a-real-secret';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactory $authPasswordForgotLimiter,
        private readonly RateLimiterFactory $authPasswordResetLimiter,
        private readonly string $frontendBaseUrl,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
    ) {}

    #[Route('/api/password/forgot', name: 'api_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        if (!$this->authPasswordForgotLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        $email = \is_array($data) && isset($data['email']) && \is_string($data['email']) ? trim($data['email']) : '';

        $user = '' === $email ? null : $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null !== $user) {
            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
                // Le lien se construit depuis FRONTEND_BASE_URL, jamais depuis le
                // header Host entrant : un forgot forgé avec un Host menteur ferait
                // sinon livrer à la victime un lien de reset vers le domaine de
                // l'attaquant (password reset poisoning). Même patron de repli
                // qu'AuthController quand la variable est vide (dev nu).
                $base = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') : $request->getSchemeAndHttpHost();
                $link = $base . '/reset-password/' . $resetToken->getToken();
                $message = (new Email)
                    ->from($this->mailFrom->address())
                    ->to($user->getEmail())
                    ->subject(\sprintf('Réinitialisation de votre mot de passe %s', $this->productIdentity->name()))
                    ->text("Pour réinitialiser votre mot de passe, ouvrez ce lien :\n{$link}\n\nCe lien expire dans 1 heure.");
                // Bouton CTA du HTML : en-têtes internes lus/retirés au worker par
                // EmailSignatureListener. L'URL EST le lien (dérivé de FRONTEND_BASE_URL, jamais
                // du Host entrant — anti password-reset-poisoning) — même mécanisme que
                // CoachWishMailBuilder, posé DANS le try (la panne du bus reste avalée).
                $headers = $message->getHeaders();
                $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $link);
                $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Réinitialiser mon mot de passe');
                $this->mailer->send($message);
            } catch (Throwable) {
                // Élargi de ResetPasswordExceptionInterface à Throwable : outre le throttle
                // du helper, un échec de DISPATCH du mail (Redis down au moment d'enfiler le
                // SendEmailMessage sur le bus) ferait aujourd'hui un 500 — mais seulement sur
                // CETTE branche « compte existant ». Ce 500 serait un oracle d'énumération par
                // code d'erreur. On avale donc tout échec sur ce chemin.
            }
        } else {
            // Compte INEXISTANT : dépenser un hash factice pour approcher le coût CPU que la
            // branche existante paie. Le hasher est keyé par CLASSE, pas par instance — un User
            // jetable convient, le résultat est jeté. Miroir de AuthController::register (branche
            // compte vérifié). Résidu assumé : l'INSERT reset_password_request et le dispatch du
            // bus n'existent que sur la branche existante — un profilage temporel fin reste
            // possible, borné par le limiteur 5/15min ; on ne simule PAS ces écritures.
            $this->passwordHasher->hashPassword(new User, self::TIMING_EQUALISER_PASSWORD);
        }

        // Always 200: never reveal whether an email is registered (no enumeration).
        return $this->json(['status' => 'sent']);
    }

    #[Route('/api/password/reset', name: 'api_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        if (!$this->authPasswordResetLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        $token = \is_array($data) && isset($data['token']) && \is_string($data['token']) ? $data['token'] : '';
        $password = \is_array($data) && isset($data['password']) && \is_string($data['password']) ? $data['password'] : '';

        if ('' === $token) {
            return $this->json(['error' => 'Token is required'], 400);
        }
        if (null !== ($passwordError = $this->passwordPolicy->validate($password))) {
            return $this->json(['error' => $passwordError], 400);
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            return $this->json(['error' => 'Lien de réinitialisation invalide ou expiré.'], 400);
        }

        if (!$user instanceof User) {
            return $this->json(['error' => 'Lien de réinitialisation invalide ou expiré.'], 400);
        }

        $this->resetPasswordHelper->removeResetRequest($token);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        return $this->json(['status' => 'reset']);
    }
}
