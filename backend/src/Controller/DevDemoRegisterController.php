<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Exception\DemoTeardownRefusedException;
use App\Repository\ClubRepository;
use App\Repository\EmailVerificationTokenRepository;
use App\Service\AuditTrail;
use App\Service\DemoClubMaterializer;
use App\Service\PasswordPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-4 — le RACCOURCI DÉMO du register (« effet waouw » en rendez-vous). En démo
 * (stack DEV exposée par tunnel), le fondateur remplit le VRAI formulaire
 * d'inscription avec son adresse démo fixe et le code FFBB du prospect ; le front
 * enchaîne le 202 neutre du register avec CETTE route, qui : PROUVE l'identité
 * (mot de passe VÉRIFIÉ, jamais réécrit — revue sécu), REMPLACE le club démo
 * précédent de l'animateur (purge + suppression de sa ligne club, pour libérer le
 * code FFBB) et crée un club is_demo peuplé par la FFBB, puis rend un 2xx SANS cookie —
 * le front montre alors l'écran de démonstration (« ce clic fait les deux ») et invite
 * le prospect à se connecter normalement, comme pour n'importe quel club.
 *
 * Le rail d'inscription de PRODUCTION reste byte-intact : register/verify ne sont
 * pas touchés. Cette route est INOFFENSIVE hors démo — en debug (dev/tunnel) elle se
 * comporte comme avant ; en PROD elle n'agit QUE si la fenêtre d'activation du compte
 * animateur démo est ouverte, et sinon rend le MÊME 422 not_demo_account qu'une adresse
 * quelconque (aucun oracle « fenêtre fermée »). Gardée aussi par l'adresse démo
 * configurée : toute autre adresse → 422 sans effet (ce qui laisse les e2e du register
 * passer par leur fallback silencieux).
 *
 * Elle ne prend JAMAIS le contrôle d'un compte ni ne détruit un club d'autrui :
 * (1) compte existant → mot de passe VÉRIFIÉ, échec = 401 SANS le moindre effet,
 *     hash jamais réécrit, vérification jamais forcée ;
 * (2) mot de passe absent/faible → 400 (politique du register) ;
 * (3) ARA tenu par un club réel, un club démo d'un autre animateur, partagé ou sans
 *     membre → 409 générique, rien détruit ;
 * (4) la destruction du club démo précédent (et sa validation) passe AVANT toute
 *     écriture de compte : un refus 409 laisse le COMPTE intact.
 *
 * ⚠ PRÉ-AUTH par construction (le compte vient d'être créé par le register, aucun
 * JWT encore) → ligne access_control PUBLIC_ACCESS explicite dans security.yaml.
 */
#[AsController]
final class DevDemoRegisterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClubRepository $clubRepository,
        private readonly EmailVerificationTokenRepository $verificationTokens,
        private readonly DemoClubMaterializer $materializer,
        private readonly RateLimiterFactory $authRegisterLimiter,
        private readonly ClockInterface $clock,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AuditTrail $auditTrail,
        private readonly ?LoggerInterface $logger,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
        // MAISON UNIQUE : le compte animateur démo (services.yaml → const
        // DemoCreateCommand::DEFAULT_ANIMATOR_EMAIL). Toute autre adresse est refusée.
        #[Autowire(param: 'app.demo_animator_email')]
        private readonly string $demoAnimatorEmail,
    ) {}

    #[Route('/api/dev/demo-register', name: 'dev_demo_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Borne réelle sur une route atteignable par le tunnel (même limiteur IP que
        // le register), AVANT toute autre garde. En dev il est déjà relâché
        // (rate_limiter.yaml when@dev).
        if (!$this->authRegisterLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many attempts, please try again later'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $email = isset($data['email']) && \is_string($data['email']) ? strtolower(trim($data['email'])) : '';
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : '';
        $ara = isset($data['ara']) && \is_string($data['ara']) ? strtoupper(trim($data['ara'])) : '';
        $clubName = isset($data['clubName']) && \is_string($data['clubName']) ? trim($data['clubName']) : '';

        // Garde MAISON : la route n'agit QUE pour l'adresse démo configurée, et — hors
        // debug (prod) — QUE si la fenêtre d'activation de ce compte est ouverte. Toute
        // autre adresse, OU une fenêtre fermée en prod, → 422 not_demo_account sans le
        // moindre effet ni oracle : la réponse est IDENTIQUE à celle d'une adresse
        // quelconque (on ne dit JAMAIS « fenêtre fermée »). En debug (démo par tunnel)
        // la fenêtre n'est pas requise — les e2e du register en dépendent (leur adresse
        // déclenche le fallback silencieux front). La fenêtre est confrontée à l'horloge
        // RÉELLE, jamais à simulated_today.
        if ('' === $email || $email !== strtolower($this->demoAnimatorEmail)
            || (!$this->debug && !$this->animatorWindowIsOpen($email))) {
            return $this->json(['error' => 'not_demo_account'], 422);
        }
        if (1 !== preg_match('/^[A-Z0-9]{3,20}$/', $ara)) {
            return $this->json(['error' => 'A valid FFBB code (ARA) is required'], 400);
        }

        // F-2 : un mot de passe non vide ET conforme à la politique du register est EXIGÉ,
        // AVANT tout effet — sinon un `new User` sans hash finirait en 500 au flush, et la
        // route serait plus laxiste que /api/register. 400 identique dans les deux cas.
        if ('' === $password || null !== $this->passwordPolicy->validate($password)) {
            return $this->json(['error' => PasswordPolicy::REQUIREMENT_FR], 400);
        }

        // Résout l'animateur en LECTURE : rien n'est écrit tant que TOUT n'est pas prouvé.
        $animator = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $animatorId = $animator instanceof User ? $animator->getId() : null;

        // C-1 : un compte EXISTANT n'est JAMAIS écrasé — on VÉRIFIE le mot de passe (patron
        // du rail d'authentification). Échec → 401, aucune écriture, hash intact. (401 et
        // non 403 : c'est un échec de CREDENTIALS pour un compte connu, comme /api/login.)
        if ($animator instanceof User && !$this->passwordHasher->isPasswordValid($animator, $password)) {
            return $this->json(['error' => 'invalid_credentials'], 401);
        }

        // E-1 : garde ARA élargie. Un club existant sur ce code n'est REMPLAÇABLE que si
        // c'est la PROPRE démo de l'animateur (son unique adhésion). Un club réel, un club
        // démo d'un AUTRE animateur (cas « Démo Basket Club »), un club démo partagé ou
        // sans membre → 409, RIEN détruit — sans quoi le teardown (qui ne regarde QUE les
        // adhésions de l'animateur) laisserait passer le club d'autrui et createClub
        // heurterait l'index unique (500). Message générique (F-3) : aucun nom divulgué.
        $existing = $this->clubRepository->findOneBy(['ffbbClubCode' => $ara]);
        if ($existing instanceof Club && !$this->araIsReplaceableByAnimator($existing, $animatorId)) {
            return $this->json(['error' => 'ara_taken'], 409);
        }

        // E-2 : la destruction du club démo précédent (VALIDATION incluse, qui lève AVANT
        // de rien détruire) passe AVANT toute écriture de compte — un refus 409 laisse donc
        // le COMPTE intact (hash, vérification, jetons). F-3 : le nom du club reste interne
        // (log), la réponse HTTP est un code générique.
        $tornDown = [];
        if ($animator instanceof User) {
            try {
                $tornDown = $this->materializer->teardownPreviousDemo($animator);
            } catch (DemoTeardownRefusedException $refused) {
                $this->logger?->warning('demo_teardown_refused', ['reason' => $refused->getMessage()]);

                return $this->json(['error' => 'teardown_refused'], 409);
            }
        }

        // --- Toutes les gardes passées : ÉCRITURES. ---
        if (!$animator instanceof User) {
            $animator = new User;
            $animator->setEmail($email);
            $animator->setFirstName('Démo');
            $animator->setLastName('Amateo');
            // Création SEULEMENT : le mot de passe soumis (déjà validé) EST celui du compte.
            // Un compte existant, lui, n'est JAMAIS réécrit (C-1) — il vient d'être prouvé.
            $animator->setPasswordHash($this->passwordHasher->hashPassword($animator, $password));
            // SEC-28 — l'animateur créé par le raccourci démo est un compte de
            // DÉMONSTRATION (pérenne, non modifiable, hors règle des comptes orphelins).
            $animator->setIsDemo(true);
            $this->entityManager->persist($animator);
        }
        // emailVerifiedAt : posé à la CRÉATION, ou quand il est nul ET que le mot de passe
        // vient d'être prouvé (le raccourci court-circuite le lien e-mail — verify a du sens
        // ici). Un compte DÉJÀ vérifié n'est pas re-touché (C-1).
        if (!$animator->getEmailVerifiedAt() instanceof DateTimeImmutable) {
            $animator->setEmailVerifiedAt($this->clock->now());
        }
        if (!$animator->getTermsAcceptedAt() instanceof DateTimeImmutable) {
            $animator->setTermsAcceptedAt($this->clock->now());
        }
        $this->entityManager->flush();

        // Le raccourci ne passe pas par verify : purger le token de vérification pendant
        // (créé par le register) et toute demande de création en attente — APRÈS la preuve
        // du mot de passe (E-2 : jamais avant, sinon un 401/409 les aurait déjà effacés).
        $this->verificationTokens->deleteForUser($animator);
        foreach ($this->entityManager->getRepository(ClubCreationRequest::class)->findBy(['userId' => $animator->getId()]) as $pendingRequest) {
            $this->entityManager->remove($pendingRequest);
        }
        $this->entityManager->flush();

        // clubName vide → la fiche FFBB fournira le nom (populate synchrone) ; un repli
        // neutre évite un club sans nom si la FFBB est muette.
        $club = $this->materializer->materialize($ara, '' !== $clubName ? $clubName : 'Club de démonstration', $animator);

        // M-3 : trace GLOBALE (club_id null → lisible même après la suppression des lignes
        // club détruites) — émission de session démo + clubs remplacés. Même rail que
        // AuditTrail::record pour AUTH_REGISTER (best-effort logué).
        $this->auditTrail->record(
            AuditAction::DEMO_SHORTCUT,
            $animator->getId(),
            null,
            'Club',
            $club->getId(),
            ['replacedClubIds' => $tornDown],
        );

        // Le raccourci ne CONNECTE plus : il matérialise le club puis rend un 2xx SANS
        // cookie. Le front affiche alors l'écran de démonstration (« ce clic fait les
        // deux ») et invite le prospect à se connecter normalement — la connexion passe
        // par /api/login, comme pour n'importe quel club.
        return $this->json(['membershipStatus' => 'active', 'clubId' => $club->getId()]);
    }

    /**
     * Un club existant sur l'ARA visé est-il REMPLAÇABLE par l'animateur ? Oui seulement
     * si c'est SA propre démo — un club démo dont l'animateur est l'UNIQUE membre (le
     * teardown la libérera). Tout le reste (club réel, démo d'un autre animateur, démo
     * partagée, démo sans membre) → NON : on protège autrui et on évite la collision
     * d'index unique. Lecture raw DBAL — club_user se lit cross-tenant (SEC-12).
     */
    private function araIsReplaceableByAnimator(Club $existing, ?string $animatorId): bool
    {
        if (!$existing->isDemo() || null === $animatorId) {
            return false;
        }
        $members = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT user_id FROM club_user WHERE club_id = :cid',
            ['cid' => $existing->getId()],
        );

        return [$animatorId] === $members;
    }

    /**
     * La fenêtre d'activation démo du compte animateur (`$email`) est-elle ouverte à
     * l'instant RÉEL ? Un compte absent → fenêtre fermée (false). Horloge réelle
     * (`new DateTimeImmutable('now')`), jamais l'horloge démo simulée : un club démo ne
     * doit pas rouvrir sa propre porte via simulated_today. Lecture seule.
     */
    private function animatorWindowIsOpen(string $email): bool
    {
        $animator = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        return $animator instanceof User && $animator->isDemoWindowOpen(new DateTimeImmutable('now'));
    }
}
