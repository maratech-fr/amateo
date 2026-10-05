<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Entity\SuperAdmin;
use App\Message\ResetDemoBcclMessage;
use App\Security\AdminSessionCsrf;
use App\Service\ClubMailboxPurgerInterface;
use App\Service\DemoResetTracker;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
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
 * {@see DemoResetRunnerInterface}) et son horloge simulée (`club.simulated_today`).
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

    /** P4-294 — durée FIXE de conservation d'un club démo prospect (décision fondateur : 14 j, pas de prolongation). */
    private const int RETENTION_DAYS = 14;

    public function __construct(
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ManagerRegistry $managerRegistry,
        private DemoResetTracker $resetTracker,
        private MessageBusInterface $messageBus,
        private ClubMailboxPurgerInterface $mailboxPurger,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.demo_bccl_email')]
        private string $bcclEmail,
        #[Autowire(param: 'app.demo_animator_email')]
        private string $prospectEmail,
    ) {}

    /** État des deux comptes démo : fenêtre d'activation, club démo courant, horloge BCCL. */
    #[Route('/demos', methods: ['GET'])]
    public function state(): JsonResponse
    {
        // Le firewall admin gate déjà la lecture ; aucun tenant posé. L'horloge simulée est
        // désormais une capacité GÉNÉRIQUE : les deux comptes démo la portent (la carte horloge
        // de la console est factorisée entre BCCL et prospect).
        return new JsonResponse([
            'bccl' => $this->accountState('bccl', withClock: true),
            'prospect' => $this->accountState('prospect', withClock: true),
            // P4-294 — les clubs démo CONSERVÉS, lus par la TABLE (demo_retained_until non null),
            // jamais par une adhésion : un club conservé est DÉTACHÉ de l'animateur, il sortirait
            // sinon de tout radar. Nom + échéance, pas de bouton de prolongation (14 j fixes).
            'retained' => $this->retainedClubs(),
            // BCK-35 — l'état du reset ASYNCHRONE de la démo BCCL : `running` tant qu'un
            // re-seed tourne (console bloque le bouton), puis `succeeded`/`failed` ; null si
            // aucun reset récent. Lu sur le verrou Redis + son statut, jamais sur une table.
            'reset' => $this->resetTracker->snapshot(),
        ]);
    }

    /**
     * Conserve 14 jours le club démo PROSPECT (décision fondateur 2026-10-03, option B) :
     * l'horloge revient à aujourd'hui + boîte vidée, l'animateur est DÉTACHÉ du club (sinon le
     * raccourci démo suivant refuse en 409 et la purge nocturne — qui itère ses adhésions —
     * le détruirait), et le club reçoit une échéance à J+14 (Europe/Paris, horloge RÉELLE).
     *
     * La fenêtre démo et la conservation ne se chevauchent JAMAIS (décision fondateur) : tant
     * que l'accès prospect est OUVERT (horloge réelle), le geste est refusé (409).
     */
    #[Route('/demos/prospect/retain', methods: ['POST'])]
    public function retain(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'retain', 'target' => 'prospect'])) instanceof JsonResponse) {
            return $denied;
        }
        $email = $this->emailForTarget('prospect');
        if (null === $email) {
            return $this->unknownTarget();
        }

        // Conservation et fenêtre d'accès ne se chevauchent jamais : fenêtre encore ouverte
        // (horloge RÉELLE) → 409, on ne conserve pas un club encore en démonstration.
        $rawUntil = $this->connection()->fetchOne('SELECT demo_active_until FROM app_user WHERE email = :email', ['email' => $email]);
        if (\is_string($rawUntil) && new DateTimeImmutable($rawUntil) > new DateTimeImmutable('now')) {
            return new JsonResponse(['error' => 'Fermez d’abord l’accès de la démonstration avant de conserver le club.'], 409);
        }

        $club = $this->resolveDemoClub('prospect');
        if (null === $club) {
            return new JsonResponse(['error' => 'Le club de démonstration est absent.'], 404);
        }

        // Geste atomique. writeClock(..., clear:true) = maison unique « horloge à NULL + boîte
        // vidée » : les données de démo ne doivent jamais rester datées dans le futur.
        $this->writeClock($club['id'], null, true);
        // Détache l'animateur (connexion admin, qui traverse la RLS) : le club sort du radar des
        // deux teardowns par adhésion, la purge des conservés le reprend par la table.
        $this->connection()->executeStatement(
            'DELETE FROM club_user WHERE club_id = :club AND user_id = (SELECT id FROM app_user WHERE email = :email)',
            ['club' => $club['id'], 'email' => $email],
        );
        $retainedUntil = new DateTimeImmutable('now')
            ->setTimezone(new DateTimeZone('Europe/Paris'))
            ->modify(\sprintf('+%d days', self::RETENTION_DAYS));
        $this->connection()->executeStatement(
            'UPDATE club SET demo_retained_until = :until WHERE id = :id AND is_demo = TRUE',
            ['until' => $retainedUntil->format('Y-m-d'), 'id' => $club['id']],
        );

        return new JsonResponse(['retainedUntil' => $retainedUntil->format('Y-m-d')]);
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
        // remplace la borne, il ne l'étend pas). Horloge réelle (jamais simulated_today).
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

    /**
     * Réinitialise la démo BCCL (BCK-35) — rail ASYNCHRONE : le POST ENFILE un
     * {@see ResetDemoBcclMessage} et répond 202. Le re-seed (sous-processus `app:demo:seed`,
     * jusqu'à 600 s) tournait auparavant SYNCHRONE depuis la requête — nginx coupait à 120 s
     * en laissant le seed continuer (504 trompeur) et deux clics lançaient deux seeds.
     *
     * Un verrou Redis ({@see DemoResetTracker}) est pris ICI : un 2ᵉ reset pendant qu'un
     * tourne échoue à l'acquisition → 409 net. Le worker ({@see ResetDemoBcclHandler}) remet
     * la date simulée à null, vide la boîte aux lettres, pose l'issue terminale et relâche le
     * verrou. L'état est exposé par `GET /api/admin/demos` (clé `reset`).
     */
    #[Route('/demos/bccl/reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'reset', 'target' => 'bccl'])) instanceof JsonResponse) {
            return $denied;
        }

        $token = $this->resetTracker->begin();
        if (null === $token) {
            return new JsonResponse(['error' => 'Une réinitialisation est déjà en cours.'], 409);
        }

        try {
            $this->messageBus->dispatch(new ResetDemoBcclMessage($token));
        } catch (Throwable $exception) {
            // L'enfilage a échoué : le worker ne rendra pas le verrou — on pose l'issue et on
            // le relâche ici pour ne pas bloquer le club pendant tout le TTL.
            $this->resetTracker->finish($token, false);
            $this->logger->error('Failed to enqueue demo BCCL reset', ['exception' => $exception]);

            return new JsonResponse(['error' => 'La réinitialisation de la démo n’a pas pu être lancée.'], 502);
        }

        return new JsonResponse(['status' => 'accepted'], 202);
    }

    /**
     * Pose (ou relâche) l'« aujourd'hui » simulé d'un compte DÉMO (bccl ou prospect) :
     * {date} ou {clear:true}. Club résolu SERVEUR depuis le compte, jamais depuis la
     * requête ; aucune confirmation (un compte démo a les droits pleins et n'envoie
     * jamais d'e-mail réel). L'écriture et le vidage de boîte au clear passent par
     * {@see self::writeClock()}.
     */
    #[Route('/demos/{target}/clock', methods: ['POST'])]
    public function clock(string $target, Request $request): JsonResponse
    {
        if (($denied = $this->guard($request, ['demoAction' => 'clock', 'target' => $target])) instanceof JsonResponse) {
            return $denied;
        }
        if (null === $this->emailForTarget($target)) {
            return $this->unknownTarget();
        }

        $body = $this->decodeBody($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Corps JSON invalide.'], 400);
        }
        $parsed = $this->parseClockInstruction($body, 400);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        // clubId résolu SERVEUR depuis le compte (jamais depuis la requête) ; resolveDemoClub
        // filtre déjà is_demo = TRUE — un vrai club ne passe jamais par ce chemin.
        $club = $this->resolveDemoClub($target);
        if (null === $club) {
            return new JsonResponse(['error' => 'Le club de démonstration est absent.'], 404);
        }

        // BCK-34 — une date simulée doit rester dans la fenêtre des saisons du club
        // (début de la saison en cours → fin de la saison suivante). Hors bornes → 422.
        if (null !== $parsed['date'] && ($outOfBounds = $this->clockBoundsRefusal($club['id'], $parsed['date'])) instanceof JsonResponse) {
            return $outOfBounds;
        }

        $this->writeClock($club['id'], $parsed['date'], $parsed['clear']);

        return new JsonResponse(['simulatedToday' => $parsed['date']]);
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
     * @return array{email: string, activeUntil: string|null, clubName: string|null, simulatedToday?: string|null}
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
            $state['simulatedToday'] = $club['simulatedToday'] ?? null;
        }

        return $state;
    }

    /**
     * Le club démo courant d'un compte (démo BCCL pour bccl, démo prospect pour prospect) :
     * résolu SERVEUR depuis l'adhésion du compte, jamais depuis la requête.
     *
     * @return array{id: string, name: string, simulatedToday: string|null}|null
     */
    private function resolveDemoClub(string $target): ?array
    {
        $email = $this->emailForTarget($target);
        if (null === $email) {
            return null;
        }

        $row = $this->connection()->fetchAssociative(
            'SELECT c.id, c.name, c.simulated_today AS "simulatedToday"'
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
            'simulatedToday' => \is_string($row['simulatedToday']) ? $row['simulatedToday'] : null,
        ];
    }

    /**
     * Les clubs démo CONSERVÉS (P4-294), lus par la TABLE `club` (`demo_retained_until`
     * non null), jamais par une adhésion — un club conservé est détaché de l'animateur.
     * Nom + échéance, triés par échéance croissante.
     *
     * @return list<array{name: string, retainedUntil: string}>
     */
    private function retainedClubs(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT name, demo_retained_until AS "retainedUntil" FROM club'
            . ' WHERE demo_retained_until IS NOT NULL'
            . ' ORDER BY demo_retained_until ASC, name ASC',
        );

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'retainedUntil' => (string) $row['retainedUntil'],
            ],
            $rows,
        );
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

    /**
     * Valide le corps { date } | { clear:true } : exactement l'un des deux, et une date
     * RÉELLE qui se relit à l'identique (2026-02-31 « parse » en 3 mars — refusée). Retourne
     * la consigne normalisée, ou la réponse d'erreur au statut demandé (`$errorStatus` :
     * l'endpoint démo garde le 400 historique, l'endpoint club générique répond 422).
     *
     * @param array<array-key, mixed> $body
     *
     * @return array{date: string|null, clear: bool}|JsonResponse
     */
    private function parseClockInstruction(array $body, int $errorStatus): array|JsonResponse
    {
        $clear = true === ($body['clear'] ?? null);
        $rawDate = $body['date'] ?? null;
        if ($clear === \is_string($rawDate)) {
            return new JsonResponse(['error' => 'Fournir une date (YYYY-MM-DD) ou clear, pas les deux.'], $errorStatus);
        }

        $date = null;
        if (\is_string($rawDate)) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            if (false === $parsed || $parsed->format('Y-m-d') !== $rawDate) {
                return new JsonResponse(['error' => 'Date invalide (attendu YYYY-MM-DD).'], $errorStatus);
            }
            $date = $rawDate;
        }

        return ['date' => $date, 'clear' => $clear];
    }

    /**
     * Pose/relâche la date simulée d'un club et, au `clear`, VIDE sa boîte aux lettres :
     * hors horloge, le club redevient un club qui envoie pour de vrai, les e-mails boxés
     * n'ont plus de raison d'être (décision fondateur 2026-10-02). Poser/changer une date ne
     * touche jamais la boîte. Maison unique de l'écriture, partagée par les deux endpoints
     * d'horloge — l'appelant a déjà décidé (démo résolu serveur, ou club réel confirmé).
     */
    /**
     * BCK-34 — refuse (422) une date simulée hors des bornes de saison du club, null
     * sinon. Lecture des saisons par la connexion ADMIN (cross-tenant, aucun GUC posé
     * côté console) ; la saison en cours se dérive de l'horloge RÉELLE (`now`, le
     * firewall admin ne porte jamais de club → pas d'horloge simulée de toute façon).
     */
    private function clockBoundsRefusal(string $clubId, string $date): ?JsonResponse
    {
        $bounds = SeasonResolver::simulatedClockBoundsAmong($this->seasonsForClub($clubId), new DateTimeImmutable('now'));
        if (null === $bounds) {
            return null;
        }
        $parsed = new DateTimeImmutable($date);
        if ($parsed < $bounds[0] || $parsed > $bounds[1]) {
            return new JsonResponse([
                'error' => \sprintf(
                    'La date simulée doit être comprise entre le %s et le %s.',
                    $bounds[0]->format('d/m/Y'),
                    $bounds[1]->format('d/m/Y'),
                ),
            ], 422);
        }

        return null;
    }

    /**
     * Les saisons du club, hydratées en entités TRANSIENTES (connexion ADMIN,
     * cross-tenant) pour {@see SeasonResolver::simulatedClockBoundsAmong}.
     *
     * @return list<Season>
     */
    private function seasonsForClub(string $clubId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT start_date, end_date FROM season WHERE club_id = :id ORDER BY start_date ASC',
            ['id' => $clubId],
        );

        return array_map(
            static fn (array $row): Season => new Season()
                ->setClubId($clubId)
                ->setStartDate(new DateTimeImmutable((string) $row['start_date']))
                ->setEndDate(new DateTimeImmutable((string) $row['end_date'])),
            $rows,
        );
    }

    private function writeClock(string $clubId, ?string $date, bool $clear): void
    {
        $this->connection()->executeStatement(
            'UPDATE club SET simulated_today = :date WHERE id = :id',
            ['date' => $date, 'id' => $clubId],
        );
        if ($clear) {
            $this->mailboxPurger->purge($clubId);
        }
    }
}
