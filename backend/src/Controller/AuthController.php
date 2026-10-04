<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Basketball\FfbbCommittee;
use App\Entity\Basketball\FfbbLeague;
use App\Entity\ClubCreationRequest;
use App\Entity\EmailChangeToken;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\Basketball\FfbbCommitteeRepository;
use App\Repository\Basketball\FfbbLeagueRepository;
use App\Repository\ClubRepository;
use App\Repository\ClubUserRepository;
use App\Security\JwtCookieFactory;
use App\Service\EmailChangeVerifier;
use App\Service\MailFrom;
use App\Service\OrphanAccountNotifier;
use App\Service\PasswordPolicy;
use App\Service\PlanEntitlements;
use App\Service\ProductIdentity;
use App\Service\Registration\EmailVerificationService;
use App\Service\Registration\RegisterService;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AuthController extends AbstractController
{
    // Version des textes acceptés au register — à INCRÉMENTER quand les CGU ou
    // la politique de confidentialité changent substantiellement, ENSEMBLE avec
    // frontend/src/features/legal/terms.ts (la version affichée à l'utilisateur
    // doit être celle qu'on estampille).
    public const TERMS_VERSION = '2026-07-11';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly JwtCookieFactory $jwtCookieFactory,
        private readonly ClubRepository $clubRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly ClockInterface $clock,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly MailerInterface $mailer,
        private readonly EmailChangeVerifier $emailChangeVerifier,
        private readonly RateLimiterFactory $emailChangeLimiter,
        private readonly RateLimiterFactory $emailChangeConfirmLimiter,
        private readonly string $frontendBaseUrl,
        private readonly FfbbLeagueRepository $ffbbLeagues,
        private readonly FfbbCommitteeRepository $ffbbCommittees,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanEntitlements $planEntitlements,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        private readonly LoggerInterface $logger,
        // Inscription démembrée (démembrement VERBATIM) : le register et la
        // vérification d'e-mail vivent désormais dans leurs services dédiés.
        private readonly RegisterService $registerService,
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        return $this->registerService->register($request);
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
    #[Route('/api/register/config', name: 'api_register_config', methods: ['GET'])]
    public function registerConfig(): JsonResponse
    {
        return $this->registerService->registerConfig();
    }

    #[Route('/api/register/verify', name: 'api_register_verify', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        return $this->emailVerificationService->verify($request);
    }

    /**
     * SEC-16 (audit) — la déconnexion DOIT vivre au serveur.
     *
     * Tant que le jeton était en `localStorage`, se déconnecter était un geste
     * client (vider le store). Le cookie étant httpOnly, le JS ne peut plus
     * l'effacer : sans cette route, une session resterait valide jusqu'à son
     * expiration malgré un « Se déconnecter » qui semble avoir marché.
     *
     * PUBLIC_ACCESS assumé : le geste est idempotent et ne révèle rien (il pose
     * un cookie vide). L'exiger authentifié rendrait impossible la déconnexion
     * d'une session déjà expirée — le cas où l'on en a le plus besoin.
     */
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $response = $this->json(['status' => 'logged_out']);
        $response->headers->setCookie($this->jwtCookieFactory->clear());

        return $response;
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // P4-301 / AUD-BCK-10 — ACTIF D'ABORD, ordre explicite : un multi-club avec UNE
        // adhésion active est `active` (sans ORDER BY, PostgreSQL rendait une ligne
        // arbitraire — un désactivé pouvait masquer un actif). À défaut d'active, la plus
        // ancienne adhésion (déterministe) porte l'état `deactivated`/`pending`.
        $clubUser = $this->clubUserRepository->findOneBy(
            ['userId' => $user->getId(), 'isActive' => true],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        ) ?? $this->clubUserRepository->findOneBy(
            ['userId' => $user->getId()],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
        $membershipStatus = 'none';
        $club = null;
        $clubEntity = null;
        $seasonPlan = null;
        $seasons = [];
        $currentSeasonId = null;
        if (null !== $clubUser) {
            // P1-1 (PR B) : `isActive=false` recouvre DEUX états distincts —
            // en attente d'approbation (jamais entré) vs désactivé (sorti). Le
            // `deactivatedAt` les sépare ; sans lui un désactivé se lirait
            // « pending » et retomberait dans une file d'approbation qu'il a déjà quittée.
            $membershipStatus = $clubUser->getIsActive()
                ? 'active'
                : (null !== $clubUser->getDeactivatedAt() ? 'deactivated' : 'pending');
            $clubEntity = $this->clubRepository->find($clubUser->getClubId());
            if (null !== $clubEntity) {
                $club = [
                    'id' => $clubEntity->getId(),
                    'name' => $clubEntity->getName(),
                    'onboardingCompleted' => $clubEntity->getOnboardingCompleted(),
                    // P4-271 — modèle de week-end A/B : aide visuelle de « Semaine type ».
                    'weekendAlternates' => $clubEntity->weekendAlternates(),
                    'logoUrl' => $clubEntity->getLogoUrl(),
                    'accentColor' => $clubEntity->getAccentColor(),
                    'accentColorDark' => $clubEntity->getAccentColorDark(),
                    'accentPalette' => $clubEntity->getAccentPalette(),
                    'schoolZone' => $clubEntity->getSchoolZone(),
                    // P2-21 lot A : vérité serveur — la modale « équipes importées »
                    // du wizard ne s'affiche QUE si l'import FFBB a réellement créé
                    // les équipes (jamais sur une saisie manuelle).
                    'ffbbTeamsImported' => null !== $clubEntity->getFfbbTeamsImportedAt(),
                    // P4-16/P2-4 — l'« aujourd'hui » simulé d'un club : le front
                    // (clock.ts) s'y cale pour que l'écran et le serveur disent la même
                    // date. Null pour tout club à l'heure réelle.
                    'simulatedToday' => $clubEntity->getSimulatedToday()?->format('Y-m-d'),
                    // Le club est-il un club de DÉMONSTRATION ? Le front pose la pastille
                    // « Démo » de l'en-tête dessus (décision fondateur : une démo se dit).
                    'isDemo' => $clubEntity->isDemo(),
                ];

                // FFBB club info: management-only (the /club section is admin-only).
                // ⚠ Les contacts DIRIGEANTS (correspondant/président) et la salle
                // principale ne sont PLUS exposés (décision fondateur 2026-08-04) :
                // l'API FFBB ne les fournit pas, l'app ne les collecte plus, aucun
                // écran ne les rend — les colonnes restent en base, données intactes.
                if ($clubUser->getIsActive() && $this->clubUserRepository->isManagementRole($clubUser->getRole())) {
                    $committee = null !== $clubEntity->getCommitteeCode()
                        ? $this->ffbbCommittees->findByCode($clubEntity->getCommitteeCode())
                        : null;
                    $club += [
                        'league' => $clubEntity->getLeague(),
                        'ffbbClubCode' => $clubEntity->getFfbbClubCode(),
                        'committeeCode' => $clubEntity->getCommitteeCode(),
                        'contactPhone' => $clubEntity->getContactPhone(),
                        'contactEmail' => $clubEntity->getContactEmail(),
                        'address' => $clubEntity->getAddress(),
                        // FFBB autofill (lot C): institutional club data + the
                        // shared league/committee reference blocks ("Contacts FFBB").
                        // league/committee resolved from
                        // the FFBB club-code prefix + committeeCode.
                        'postalCode' => $clubEntity->getPostalCode(),
                        'city' => $clubEntity->getCity(),
                        'website' => $clubEntity->getWebsite(),
                        'latitude' => $clubEntity->getLatitude(),
                        'longitude' => $clubEntity->getLongitude(),
                        'ffbbCommittee' => $this->ffbbOrganisme($committee),
                        // Resolve the league through the committee's authoritative
                        // leagueCode link — not by re-deriving the club-code prefix.
                        'ffbbLeague' => $this->ffbbOrganisme(
                            null !== $committee?->getLeagueCode() ? $this->ffbbLeagues->findByCode($committee->getLeagueCode()) : null,
                        ),
                    ];
                }

                $allSeasons = $this->seasonResolver->seasonsForClub($clubEntity->getId());
                $now = $this->clock->now();
                $current = SeasonResolver::currentAmong($allSeasons, $now);
                $currentSeasonId = $current?->getId();

                // The gates (cockpit/wizard) follow the SELECTED season
                // (X-Season-Id → _season_id, validated by the listener), not
                // blindly the current one — the frontend gate code stays as-is.
                $selected = $current;
                $selectedId = $request->attributes->get('_season_id');
                if (\is_string($selectedId)) {
                    foreach ($allSeasons as $candidate) {
                        if ($candidate->getId() === $selectedId) {
                            $selected = $candidate;
                            break;
                        }
                    }
                }
                // ADR-0002 : LE calendrier de base de la saison, c'est le plan SEASON
                // et sa version choisie. Exposé ici pour que la bascule n'ait plus
                // qu'à déplacer les lecteurs — ADDITIF : les 3 champs legacy
                // ci-dessus restent la vérité tant que la bascule n'a pas eu lieu.
                if ($selected instanceof Season) {
                    $seasonPlan = $this->schedulePlanProvisioner->seasonPlanPayload($selected->getId());
                    // P1-3 — droits de l'offre EFFECTIVE (calculés à la lecture) : caps,
                    // crédits de sortie, bascule de saison. L'enforcement est la PR B.
                    $club['entitlements'] = $this->planEntitlements->forClub($clubEntity, $selected);
                }

                foreach ($allSeasons as $season) {
                    $seasons[] = [
                        'id' => $season->getId(),
                        'name' => $season->getName(),
                        'startDate' => $season->getStartDate()->format('Y-m-d'),
                        'endDate' => $season->getEndDate()->format('Y-m-d'),
                        'isCurrent' => $season->getId() === $currentSeasonId,
                        'isReadonly' => SeasonResolver::isReadonlyAmong($season, $allSeasons, $now),
                    ];
                }
            }
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            // P4-74 : l'adresse en attente de confirmation (le front affiche
            // « en attente : x@y.z » + un geste d'annulation). Null = aucune.
            'pendingEmail' => $user->getPendingEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'membershipStatus' => $membershipStatus,
            'role' => null !== $clubUser ? $clubUser->getRole() : null,
            // P3-4 : sans membership, l'utilisateur peut porter une demande de
            // création en cours — le frontend affiche « demande transmise au club »
            // (et son issue) au lieu d'un état « aucun club » mensonger.
            'clubRequest' => $this->clubRequestState($user->getId(), $clubUser),
            // P4-301 — échéance de suppression d'un compte sans club : la date (Y-m-d) où
            // le compte sera supprimé faute de nouvel accès = préavis + 30 j. Non-null
            // SEULEMENT quand le préavis est posé ET que l'utilisateur n'est pas actif —
            // un actif ne risque rien, un stamp résiduel ne doit pas afficher d'échéance.
            'accountDeletionScheduledFor' => ('active' !== $membershipStatus && $user->getOrphanNoticeSentAt() instanceof DateTimeImmutable)
                ? $user->getOrphanNoticeSentAt()->modify(OrphanAccountNotifier::GRACE_PERIOD)->format('Y-m-d')
                : null,
            'club' => $club,
            'seasonPlan' => $seasonPlan,
            'hasGenerated' => null !== $clubEntity && $clubEntity->getGenerationCountSeason() > 0,
            'seasons' => $seasons,
            'currentSeasonId' => $currentSeasonId,
        ]);
    }

    /** Update the connected user's own profile (self-only by construction — SEC-02). */
    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (isset($data['firstName']) && \is_string($data['firstName'])) {
            $firstName = trim($data['firstName']);
            if ('' === $firstName) {
                return $this->json(['error' => 'Le prénom est requis.'], 400);
            }
            $user->setFirstName($firstName);
        }
        if (isset($data['lastName']) && \is_string($data['lastName'])) {
            $lastName = trim($data['lastName']);
            if ('' === $lastName) {
                return $this->json(['error' => 'Le nom est requis.'], 400);
            }
            $user->setLastName($lastName);
        }
        // P4-74 : le PATCH ne change PLUS l'e-mail en direct — ce serait basculer
        // l'identité (email = identifiant de connexion) sans confirmer la nouvelle
        // adresse, enfermant l'utilisateur dehors à la moindre faute de frappe. Le
        // changement passe par « confirmer d'abord, basculer ensuite »
        // (POST /api/me/email). Un e-mail IDENTIQUE à l'actuel est ignoré (no-op) ;
        // un e-mail DIFFÉRENT → 422 explicite pointant la bonne route.
        if (isset($data['email']) && \is_string($data['email'])) {
            $email = strtolower(trim($data['email']));
            if ($email !== $user->getEmail()) {
                return $this->json([
                    'error' => 'Pour changer votre adresse e-mail, demandez un lien de confirmation (POST /api/me/email).',
                ], 422);
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'pendingEmail' => $user->getPendingEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
        ]);
    }

    /** Change the connected user's password (requires the current one). */
    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $current = \is_string($data['currentPassword'] ?? null) ? $data['currentPassword'] : '';
        $new = \is_string($data['newPassword'] ?? null) ? $data['newPassword'] : '';

        if (!$this->passwordHasher->isPasswordValid($user, $current)) {
            return $this->json(['error' => 'Mot de passe actuel incorrect.'], 400);
        }
        if (null !== ($passwordError = $this->passwordPolicy->validate($new))) {
            return $this->json(['error' => $passwordError], 400);
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $new));
        $this->entityManager->flush();

        return $this->json(['status' => 'ok']);
    }

    /**
     * P4-74 — demander un changement d'e-mail (confirmer d'abord, basculer ensuite).
     *
     * L'adresse actuelle reste ACTIVE et inchangée : on stocke la nouvelle en
     * attente (User::pendingEmail) et on envoie un lien de confirmation À CETTE
     * nouvelle adresse. Rien ne bascule tant que le lien n'est pas suivi
     * (confirmEmailChange) — un login sur l'adresse courante continue de marcher,
     * donc une faute de frappe n'enferme jamais l'utilisateur dehors (le gate
     * UserChecker exige emailVerifiedAt, qu'on ne touche pas).
     */
    #[Route('/api/me/email', name: 'api_me_email_request', methods: ['POST'])]
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // Anti-abus : borne PAR UTILISATEUR — la route envoie un mail vers une
        // adresse tierce (patron des routes sensibles, ici keyé sur le JWT).
        if (!$this->emailChangeLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de demandes, réessayez plus tard.'], 429);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        // ⚠ Revue sécu P4-74 : changer l'e-mail TRANSFÈRE le compte (l'adresse EST
        // l'identifiant de connexion) — c'est plus grave que le supprimer. La règle
        // maison des gestes d'identité s'applique donc ici aussi : le mot de passe
        // courant est exigé, comme pour DELETE /api/me et le changement de mot de
        // passe. Un JWT emprunté ne suffit pas à s'approprier un compte.
        $currentPassword = \is_string($data['currentPassword'] ?? null) ? $data['currentPassword'] : '';
        if ('' === $currentPassword || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json(['error' => 'Mot de passe incorrect.'], 400);
        }

        $email = \is_string($data['email'] ?? null) ? strtolower(trim($data['email'])) : '';
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Adresse e-mail invalide.'], 400);
        }
        if ($email === $user->getEmail()) {
            return $this->json(['error' => 'Cette adresse est déjà la vôtre.'], 400);
        }
        // Déjà prise par un AUTRE compte (adresse active OU en attente ailleurs) →
        // 409, même sémantique qu'au register et qu'à l'ancien PATCH.
        if ($this->emailIsClaimedByAnother($email, $user->getId())) {
            return $this->json(['error' => 'Cet e-mail est déjà utilisé.'], 409);
        }

        $user->setPendingEmail($email);
        $rawToken = $this->emailChangeVerifier->generateToken($user);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Course sur pending_email (unique) : réservée entre-temps par un autre compte.
            return $this->json(['error' => 'Cet e-mail est déjà utilisé.'], 409);
        }

        $this->sendEmailChangeConfirmation($request, $email, $rawToken);
        $this->notifyPreviousAddress((string) $user->getEmail(), $email, switched: false);

        return $this->json(['status' => 'confirmation_sent', 'pendingEmail' => $email]);
    }

    /**
     * P4-74 — confirmer la nouvelle adresse : le clic sur le lien bascule l'e-mail.
     *
     * PUBLIC_ACCESS : le token EST la preuve (envoyé à la nouvelle adresse, que
     * seul son titulaire relève) — comme /api/register/verify et /api/password/reset,
     * et parce que le lien est souvent ouvert dans un onglet non connecté. Le JWT
     * courant porte l'ANCIENNE adresse comme identifiant ; une fois l'e-mail
     * basculé il ne résout plus le compte, donc on repose un cookie frais pour la
     * nouvelle identité (continuité de session, même fabrique que verify).
     */
    #[Route('/api/me/email/confirm', name: 'api_me_email_confirm', methods: ['POST'])]
    public function confirmEmailChange(Request $request): JsonResponse
    {
        if (!$this->emailChangeConfirmLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        $rawToken = \is_array($data) && \is_string($data['token'] ?? null) ? $data['token'] : '';

        $token = $this->emailChangeVerifier->resolve($rawToken);
        if (!$token instanceof EmailChangeToken) {
            return $this->json(['error' => 'Lien de confirmation invalide ou expiré.'], 400);
        }

        $user = $token->getUser();
        // ⚠ Revue sécu P4-74, défense en profondeur : un compte EFFACÉ ne se
        // ressuscite pas par un lien. L'effacement supprime déjà ces tokens et le
        // pending (AccountErasureService) ; ce garde tient même si un token
        // survivait — la route rend un cookie JWT, elle ne doit jamais le rendre
        // pour une identité détruite.
        if ($user->getAnonymizedAt() instanceof DateTimeImmutable) {
            $this->emailChangeVerifier->consume($token);
            $this->entityManager->flush();

            return $this->json(['error' => 'Lien de confirmation invalide ou expiré.'], 400);
        }

        $pending = $user->getPendingEmail();
        if (null === $pending) {
            // Déjà confirmé/annulé : le token n'a plus d'adresse cible.
            $this->emailChangeVerifier->consume($token);
            $this->entityManager->flush();

            return $this->json(['error' => 'Aucun changement d\'adresse en attente.'], 400);
        }

        // Course : l'adresse a pu être prise par un autre compte depuis la demande.
        $claimant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $pending]);
        if ($claimant instanceof User && $claimant->getId() !== $user->getId()) {
            $user->setPendingEmail(null);
            $this->emailChangeVerifier->consume($token);
            $this->entityManager->flush();

            return $this->json(['error' => 'Cet e-mail est désormais utilisé par un autre compte.'], 409);
        }

        // La bascule — et SEULEMENT ici. emailVerifiedAt reste NON NULL : le compte
        // était déjà vérifié, l'utilisateur ne repasse pas par le gate d'activation.
        $previousEmail = (string) $user->getEmail();
        $user->setEmail($pending);
        $user->setPendingEmail(null);
        $this->emailChangeVerifier->consume($token);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Cet e-mail est désormais utilisé par un autre compte.'], 409);
        }

        $this->notifyPreviousAddress($previousEmail, $pending, switched: true);

        $response = $this->json(['status' => 'email_confirmed', 'email' => $user->getEmail()]);
        $response->headers->setCookie($this->jwtCookieFactory->create($this->jwtManager->create($user)));

        return $response;
    }

    /** P4-74 — annuler la demande : efface l'adresse en attente et ses tokens. */
    #[Route('/api/me/email', name: 'api_me_email_cancel', methods: ['DELETE'])]
    public function cancelEmailChange(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $user->setPendingEmail(null);
        $this->emailChangeVerifier->clearForUser($user);
        $this->entityManager->flush();

        return $this->json(['status' => 'cancelled']);
    }

    /** P4-74 — l'adresse est-elle déjà revendiquée (active ou en attente) par un AUTRE compte ? */
    private function emailIsClaimedByAnother(string $email, string $selfId): bool
    {
        $repo = $this->entityManager->getRepository(User::class);
        $byEmail = $repo->findOneBy(['email' => $email]);
        if ($byEmail instanceof User && $byEmail->getId() !== $selfId) {
            return true;
        }
        $byPending = $repo->findOneBy(['pendingEmail' => $email]);

        return $byPending instanceof User && $byPending->getId() !== $selfId;
    }

    private function sendEmailChangeConfirmation(Request $request, string $email, string $rawToken): void
    {
        // FRONTEND_BASE_URL en prod ; repli sur l'hôte de la requête en dev/e2e
        // (origine unique via le proxy Vite) — même patron que la vérification.
        $base = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') : $request->getSchemeAndHttpHost();
        $link = $base . '/confirm-email/' . $rawToken;
        $product = $this->productIdentity->name();

        // mailer->send ne fait plus qu'ENFILER un SendEmailMessage sur le bus ; un échec
        // SMTP surgit chez le worker (le failure transport le retient). Seul un échec de
        // DISPATCH (Redis down) tomberait ici — avalé : un 500 sur cette seule branche
        // serait un oracle d'énumération (comme la vérification / le reset).
        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($email)
                    ->subject(\sprintf('Confirmez votre nouvelle adresse e-mail %s', $product))
                    ->text("Vous avez demandé à changer l'adresse e-mail de votre compte {$product}.\n\nPour confirmer cette nouvelle adresse, ouvrez ce lien :\n{$link}\n\nVotre adresse actuelle reste active tant que vous n'avez pas confirmé.\n\nCe lien expire dans 24 heures. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message."),
            );
        } catch (Throwable $e) {
            // Le mail est avalé (un 500 ici serait un oracle d'énumération) mais l'échec
            // de DISPATCH est tracé — sans quoi une panne du bus (Redis) resterait muette.
            $this->logger->warning('Mailer dispatch failed (transactional email not enqueued)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * ⚠ Revue sécu P4-74 — l'ANCIENNE adresse est prévenue, aux deux moments
     * (demande et bascule). C'est le seul signal qui atteint le titulaire légitime
     * quand quelqu'un d'autre pilote la session, et le seul filet contre la faute
     * de frappe : au moment où il arrive, l'ancienne adresse est encore délivrable.
     */
    private function notifyPreviousAddress(string $previousEmail, string $newEmail, bool $switched): void
    {
        $product = $this->productIdentity->name();
        $subject = $switched
            ? \sprintf('Votre adresse e-mail %s a été modifiée', $product)
            : \sprintf('Demande de changement d’adresse e-mail sur %s', $product);
        $body = $switched
            ? "L'adresse e-mail de votre compte {$product} est désormais {$newEmail}.\n\nSi vous n'êtes pas à l'origine de ce changement, contactez-nous immédiatement : votre compte a pu être compromis."
            : "Une demande de changement d'adresse vers {$newEmail} vient d'être faite sur votre compte {$product}.\n\nVotre adresse actuelle reste active tant que la nouvelle n'est pas confirmée.\n\nSi vous n'êtes pas à l'origine de cette demande, changez votre mot de passe : quelqu'un a accès à votre session.";

        // mailer->send ne fait plus qu'ENFILER un SendEmailMessage sur le bus ; un échec
        // SMTP surgit chez le worker (le failure transport le retient). Seul un échec de
        // DISPATCH (Redis down) tomberait ici — avalé : un 500 sur cette seule branche
        // serait un oracle d'énumération.
        try {
            $this->mailer->send(
                (new Email)
                    ->from($this->mailFrom->address())
                    ->to($previousEmail)
                    ->subject($subject)
                    ->text($body),
            );
        } catch (Throwable $e) {
            // Le mail est avalé (un 500 ici serait un oracle d'énumération) mais l'échec
            // de DISPATCH est tracé — sans quoi une panne du bus (Redis) resterait muette.
            $this->logger->warning('Mailer dispatch failed (transactional email not enqueued)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Shape a league/committee reference row for /api/me (null when not yet
     * populated → the frontend shows an empty state).
     *
     * @return array{name: string, address: ?string, postalCode: ?string, city: ?string, phone: ?string, email: ?string, logoUrl: ?string}|null
     */
    private function ffbbOrganisme(FfbbLeague|FfbbCommittee|null $organisme): ?array
    {
        if (null === $organisme) {
            return null;
        }

        return [
            'name' => $organisme->getName(),
            'address' => $organisme->getAddress(),
            'postalCode' => $organisme->getPostalCode(),
            'city' => $organisme->getCity(),
            'phone' => $organisme->getPhone(),
            'email' => $organisme->getEmail(),
            'logoUrl' => $organisme->getLogoUrl(),
            'website' => $organisme->getWebsite(),
        ];
    }

    /**
     * L'état de la demande de création (P3-4) — la plus récente, pour que le
     * demandeur voie AUSSI un refus/une expiration (pas seulement l'attente).
     * Null dès qu'un membership existe : le club est né, la demande est histoire.
     *
     * @return array{status: string, clubName: string, ara: string, clubEmailKnown: bool}|null
     */
    private function clubRequestState(string $userId, ?object $clubUser): ?array
    {
        if (null !== $clubUser) {
            return null;
        }
        $request = $this->entityManager->getRepository(ClubCreationRequest::class)
            ->findOneBy(['userId' => $userId], ['createdAt' => 'DESC']);

        return $request instanceof ClubCreationRequest ? [
            'status' => $request->getStatus(),
            'clubName' => $request->getClubName(),
            'ara' => $request->getAra(),
            // false = mail FFBB introuvable : c'est le SUPPORT qui validera — l'écran
            // d'attente ne doit pas prétendre qu'un email est parti au club.
            'clubEmailKnown' => null !== $request->getClubEmail(),
        ] : null;
    }
}
