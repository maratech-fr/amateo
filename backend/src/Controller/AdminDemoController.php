<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SuperAdmin;
use App\Security\AdminSessionCsrf;
use App\Service\DemoResetRunnerInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

/**
 * Console superadmin — pilotage des deux comptes de DÉMONSTRATION (PR B du lot Démos).
 *
 * Deux comptes : la démo BCCL permanente (`app.demo_bccl_email`, club ARA9999999) et la
 * démo prospect du jour (`app.demo_animator_email`). Pour chacun, la console OUVRE/FERME
 * la fenêtre d'activation (`app_user.demo_active_until`, horloge RÉELLE — gardée par
 * UserChecker). La démo BCCL a en plus sa réinitialisation (sous-processus seed via
 * {@see DemoResetRunnerInterface}) et son horloge simulée (`club.demo_today`).
 *
 * Mêmes gardes que {@see AdminClubActionController} : contexte d'audit posé AVANT toute
 * garde, puis CSRF de session, puis identité SuperAdmin. AUCUN club_id posé (surface
 * cross-tenant, contrat SA0) : lectures/écritures par la connexion Doctrine `admin`, qui
 * traverse la RLS. AdminCsrfListener + AdminRequestBoundaryTest couvrent d'office les
 * écritures ajoutées ici.
 */
#[Route('/api/admin')]
final readonly class AdminDemoController
{
    private const int WINDOW_HOURS = 4;

    public function __construct(
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ManagerRegistry $managerRegistry,
        private DemoResetRunnerInterface $resetRunner,
        #[Autowire(param: 'app.demo_bccl_email')]
        private string $bcclEmail,
        #[Autowire(param: 'app.demo_animator_email')]
        private string $prospectEmail,
    ) {}

    /** État des deux comptes démo : fenêtre d'activation, club démo courant, horloge BCCL. */
    #[Route('/demos', methods: ['GET'])]
    public function state(): JsonResponse
    {
        // Le firewall admin gate déjà la lecture ; aucun tenant posé.
        return new JsonResponse([
            'bccl' => $this->accountState('bccl', withClock: true),
            'prospect' => $this->accountState('prospect', withClock: false),
        ]);
    }

    /** Ouvre (ou ré-ouvre) la fenêtre d'activation d'un compte démo pour 4 h, horloge RÉELLE. */
    #[Route('/demos/{target}/activate', methods: ['POST'])]
    public function activate(string $target, Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'activate', 'target' => $target])) instanceof JsonResponse) {
            return $denied;
        }
        $email = $this->emailForTarget($target);
        if (null === $email) {
            return $this->unknownTarget();
        }

        // now + 4 h, JAMAIS une addition : re-cliquer repart de maintenant (le stockage
        // remplace la borne, il ne l'étend pas). Horloge réelle (jamais demo_today).
        $until = new DateTimeImmutable('now')->modify(\sprintf('+%d hours', self::WINDOW_HOURS));
        $updated = $this->connection()->executeStatement(
            'UPDATE app_user SET demo_active_until = :until WHERE email = :email',
            ['until' => $until->format(\DATE_ATOM), 'email' => $email],
        );
        if (0 === $updated) {
            return $this->accountMissing();
        }

        return new JsonResponse(['target' => $target, 'activeUntil' => $until->format(\DATE_ATOM)]);
    }

    /** Ferme la fenêtre d'activation d'un compte démo (demo_active_until → NULL). */
    #[Route('/demos/{target}/deactivate', methods: ['POST'])]
    public function deactivate(string $target, Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'deactivate', 'target' => $target])) instanceof JsonResponse) {
            return $denied;
        }
        $email = $this->emailForTarget($target);
        if (null === $email) {
            return $this->unknownTarget();
        }

        $updated = $this->connection()->executeStatement(
            'UPDATE app_user SET demo_active_until = NULL WHERE email = :email',
            ['email' => $email],
        );
        if (0 === $updated) {
            return $this->accountMissing();
        }

        return new JsonResponse(['target' => $target, 'activeUntil' => null]);
    }

    /** Réinitialise la démo BCCL : re-seed (sous-processus admin) + horloge simulée à null. */
    #[Route('/demos/bccl/reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'reset', 'target' => 'bccl'])) instanceof JsonResponse) {
            return $denied;
        }

        try {
            $this->resetRunner->run();
        } catch (Throwable) {
            return new JsonResponse(['error' => 'La réinitialisation de la démo a échoué.'], 502);
        }

        // Décision fondateur : le reset remet AUSSI la date simulée à aujourd'hui
        // (demo_today → NULL). Le re-seed ne touche pas la fenêtre du compte BCCL.
        $club = $this->resolveDemoClub('bccl');
        if (null !== $club) {
            $this->connection()->executeStatement(
                'UPDATE club SET demo_today = NULL WHERE id = :id AND is_demo = TRUE',
                ['id' => $club['id']],
            );
        }

        return new JsonResponse(['status' => 'reset']);
    }

    /** Pose (ou relâche) l'« aujourd'hui » simulé de la démo BCCL : {date} ou {clear:true}. */
    #[Route('/demos/bccl/clock', methods: ['POST'])]
    public function clock(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'clock', 'target' => 'bccl'])) instanceof JsonResponse) {
            return $denied;
        }

        $body = $this->decodeBody($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Corps JSON invalide.'], 400);
        }
        $clear = true === ($body['clear'] ?? null);
        $rawDate = $body['date'] ?? null;
        // Exactement l'un des deux : une date OU clear. Ni les deux, ni aucun.
        if ($clear === \is_string($rawDate)) {
            return new JsonResponse(['error' => 'Fournir une date (YYYY-MM-DD) ou clear, pas les deux.'], 400);
        }

        $date = null;
        if (\is_string($rawDate)) {
            // La FORME ne suffit pas (même règle que DemoClockCommand / clock.ts) :
            // 2026-02-31 « parse » en 3 mars — la date doit se relire à l'identique.
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            if (false === $parsed || $parsed->format('Y-m-d') !== $rawDate) {
                return new JsonResponse(['error' => 'Date invalide (attendu YYYY-MM-DD).'], 400);
            }
            $date = $rawDate;
        }

        // clubId résolu SERVEUR depuis le compte demo-bccl@ (jamais depuis la requête).
        $club = $this->resolveDemoClub('bccl');
        if (null === $club) {
            return new JsonResponse(['error' => 'Le club de démonstration BCCL est absent.'], 404);
        }
        // Même UPDATE gardé is_demo = TRUE que DemoClockCommand : un vrai club ne peut
        // jamais recevoir d'horloge simulée par ce chemin.
        $this->connection()->executeStatement(
            'UPDATE club SET demo_today = :date WHERE id = :id AND is_demo = TRUE',
            ['date' => $date, 'id' => $club['id']],
        );

        return new JsonResponse(['demoToday' => $date]);
    }

    /**
     * Contexte d'audit posé AVANT toute garde (une tentative REFUSÉE trace la cible),
     * puis CSRF de session, puis identité SuperAdmin. Retourne la réponse d'échec, ou
     * null si la garde passe (l'acteur est alors posé pour l'audit).
     *
     * @param array<string, mixed> $auditContext
     */
    private function guard(Request $request, array $auditContext): ?JsonResponse
    {
        $request->attributes->set('_admin_audit_context', $auditContext);

        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }

        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        return null;
    }

    /**
     * @return array{email: string, activeUntil: string|null, clubName: string|null, demoToday?: string|null}
     */
    private function accountState(string $target, bool $withClock): array
    {
        $email = $this->emailForTarget($target) ?? '';
        $rawUntil = $this->connection()->fetchOne('SELECT demo_active_until FROM app_user WHERE email = :email', ['email' => $email]);
        $club = $this->resolveDemoClub($target);

        $state = [
            'email' => $email,
            'activeUntil' => \is_string($rawUntil) ? new DateTimeImmutable($rawUntil)->format(\DATE_ATOM) : null,
            'clubName' => $club['name'] ?? null,
        ];
        if ($withClock) {
            $state['demoToday'] = $club['demoToday'] ?? null;
        }

        return $state;
    }

    /**
     * Le club démo courant d'un compte (démo BCCL pour bccl, démo prospect pour prospect) :
     * résolu SERVEUR depuis l'adhésion du compte, jamais depuis la requête.
     *
     * @return array{id: string, name: string, demoToday: string|null}|null
     */
    private function resolveDemoClub(string $target): ?array
    {
        $email = $this->emailForTarget($target);
        if (null === $email) {
            return null;
        }

        $row = $this->connection()->fetchAssociative(
            'SELECT c.id, c.name, c.demo_today AS "demoToday"'
            . ' FROM club c'
            . ' JOIN club_user cu ON cu.club_id = c.id'
            . ' JOIN app_user u ON u.id = cu.user_id'
            . ' WHERE u.email = :email AND c.is_demo = TRUE AND cu.deactivated_at IS NULL'
            . ' ORDER BY c.created_at DESC LIMIT 1',
            ['email' => $email],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'demoToday' => \is_string($row['demoToday']) ? $row['demoToday'] : null,
        ];
    }

    private function emailForTarget(string $target): ?string
    {
        return match ($target) {
            'bccl' => strtolower($this->bcclEmail),
            'prospect' => strtolower($this->prospectEmail),
            default => null,
        };
    }

    /** @return array<array-key, mixed>|null null = corps JSON illisible */
    private function decodeBody(Request $request): ?array
    {
        $raw = trim($request->getContent());
        if ('' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private function unknownTarget(): JsonResponse
    {
        return new JsonResponse(['error' => 'Compte de démonstration inconnu.'], 404);
    }

    private function accountMissing(): JsonResponse
    {
        return new JsonResponse(['error' => 'Le compte de démonstration est absent.'], 404);
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
