<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\EmailChangeToken;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Service\Basketball\FfbbClubDirectory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * RGPD — droit à l'effacement (responsable de traitement, comptes User).
 *
 * L'effacement d'un compte est une ANONYMISATION immédiate (l'email devient
 * introuvable → le JWT en cours et tout login futur sont inertes), jamais un
 * DELETE : ClubUser garde une ligne inactive pointant sur un User vidé, ce qui
 * préserve l'intégrité référentielle sans conserver de donnée personnelle.
 *
 * Si, après cet effacement, un club n'a PLUS AUCUN membre actif (quel que soit
 * le rôle — un editor actif suffit à bloquer : on ne détruit jamais un
 * workspace utilisé), sa purge est PROGRAMMÉE (erasureScheduledAt = +30 j,
 * délai de grâce) et exécutée par app:clubs:purge-erased — qui REVALIDE à
 * l'échéance et auto-annule si un membre actif est revenu entre-temps.
 * L'identité publique FFBB du club survit à la purge (ErasedClubPurger).
 *
 * Tout le flux s'exécute dans UNE transaction : un échec à mi-course (flush,
 * verrou optimiste…) annule aussi la désactivation des memberships — sans quoi
 * un retry verrait findActiveClubIds() vide et ne programmerait jamais la
 * purge du club orphelin (revue sécurité PR-1).
 */
final class AccountErasureService
{
    public const GRACE_PERIOD = '+30 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly TenantConnectionContext $tenantConnectionContext,
        // BCK-34 — horloge RÉELLE : le délai de grâce RGPD (+30 j) est une durée de
        // SÉCURITÉ/rétention. DELETE /api/me s'exécute dans le contexte tenant de
        // l'appelant (potentiellement un club démo à date simulée) — l'échéance doit
        // rester sur le calendrier réel, exactement comme app:clubs:purge-erased (CLI,
        // déjà à l'heure réelle hors requête).
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly FfbbClubDirectory $ffbbClubDirectory,
        private readonly MailerInterface $mailer,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Anonymise le compte et programme la purge des clubs orphelins.
     *
     * @return list<string> ids des clubs dont la purge vient d'être programmée
     */
    public function erase(User $user): array
    {
        try {
            /** @var list<string> $scheduled */
            $scheduled = $this->entityManager->wrapInTransaction(fn (): array => $this->doErase($user));
        } finally {
            $this->tenantConnectionContext->clear();
        }

        // Post-commit, best-effort : prévenir le contact officiel de chaque club
        // devenu orphelin que son espace sera supprimé à l'échéance. Hors transaction
        // (le mail ne doit jamais référencer une programmation non persistée) ; un
        // échec n'annule pas l'effacement, déjà commité.
        $this->notifyOrphanedClubs($scheduled);

        return $scheduled;
    }

    /** Membre actif, TOUS rôles confondus (raw DBAL — lecture cross-tenant par design). */
    public function hasActiveMember(string $clubId): bool
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM club_user WHERE club_id = :cid AND is_active = true',
            ['cid' => $clubId],
        );

        return ((int) $count) > 0;
    }

    /** @return list<string> */
    private function doErase(User $user): array
    {
        // Horloge RÉELLE (BCK-34) : la MÊME que celle de app:clubs:purge-erased
        // (CLI, hors requête → heure réelle), sinon le délai de grâce se lirait sur
        // deux horloges différentes — et un club démo à date simulée fausserait le
        // délai RGPD.
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        // 1. Tokens rattachés au compte (vérification email, reset password) —
        //    supprimés : ils portent l'email/l'identité.
        $this->entityManager->createQueryBuilder()
            ->delete(EmailVerificationToken::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()->execute();
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()->execute();
        // ⚠ Revue sécu P4-74 : le token de CHANGEMENT d'e-mail doit mourir ici
        // aussi. Sans lui, un lien vivant (24 h) réécrivait une VRAIE adresse sur
        // le compte anonymisé ET rendait un cookie JWT valide — l'effacement
        // cessait d'être terminal. Le `pendingEmail` part avec (§3), sinon il
        // garderait une réservation unique éternelle sur l'adresse d'un tiers.
        $this->entityManager->createQueryBuilder()
            ->delete(EmailChangeToken::class, 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()->execute();

        // 2. Memberships désactivés AVANT le comptage des clubs orphelins.
        //    club_user se LIT hors tenant (policy SELECT USING(true)) mais son
        //    UPDATE est tenant-gardé (WITH CHECK sur le GUC) : un UPDATE global
        //    sauterait silencieusement les memberships des AUTRES clubs d'un
        //    user multi-club → on scope le GUC club par club. Le set_config est
        //    session-scoped : il traverse la transaction sans être annulé.
        //
        //    `deactivated_at = NOW()` est POSÉ ici (P4-75) : sans lui, l'adhésion
        //    effacée (is_active=false, deactivated_at=null) se relit comme une
        //    demande d'approbation JAMAIS entrée et réapparaît dans la file
        //    pending du club (GET /api/memberships/pending filtre exactement
        //    is_active=false AND deactivated_at IS NULL) — une adhésion fantôme.
        //    `deactivated_at` sépare « sorti » de « en attente » (ClubUser).
        $clubIds = $this->clubUserRepository->findActiveClubIds($user->getId());
        foreach ($clubIds as $clubId) {
            $this->tenantConnectionContext->setClubId($clubId);
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE club_user SET is_active = false, deactivated_at = NOW(), updated_at = NOW() WHERE user_id = :uid AND club_id = :cid',
                ['uid' => $user->getId(), 'cid' => $clubId],
            );
        }

        // RMM-3 — l'instantané de visite du module matchs est une donnée PERSONNELLE
        // (horodatages), pas un bien du club comme un signalement : il MEURT avec le
        // compte. La boucle porte sur TOUS les clubs d'adhésion (désactivés compris,
        // `is_active` ignoré) : une visite stampée dans un club QUITTÉ avant
        // l'effacement doit mourir aussi — la boucle des clubs ACTIFS ci-dessus la
        // raterait. Une visite n'existe que là où une adhésion a existé (ouvrir le
        // module exige d'être membre), donc ce périmètre est complet. FORCE RLS
        // scope chaque DELETE au club dont le GUC est posé.
        foreach ($this->clubUserRepository->findMemberClubIds($user->getId()) as $clubId) {
            $this->tenantConnectionContext->setClubId($clubId);
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM match_module_visit WHERE user_id = :uid',
                ['uid' => $user->getId()],
            );
        }
        $this->tenantConnectionContext->clear();

        // 3. Anonymisation : l'email devient un jeton non-adressable, le hash un
        //    aléa jamais valide. random_bytes garantit qu'aucun mot de passe ne
        //    matchera jamais ce "hash" (ce n'est pas un hash bcrypt/argon).
        $user->setEmail(\sprintf('deleted-%s@anonymized.invalid', $user->getId()));
        $user->setPendingEmail(null);
        $user->setFirstName('Compte');
        $user->setLastName('Supprimé');
        $user->setPasswordHash(bin2hex(random_bytes(32)));
        $user->setAnonymizedAt($now);
        $this->entityManager->flush();

        // 4. Clubs orphelins : plus AUCUN membre actif, tous rôles confondus
        //    (un editor/viewer actif utilise encore le workspace — on ne
        //    programme pas sa destruction sous ses pieds) → purge à +30 j.
        $scheduled = [];
        foreach ($clubIds as $clubId) {
            if ($this->hasActiveMember($clubId)) {
                continue;
            }
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if ($club instanceof Club && !$club->getErasureScheduledAt() instanceof DateTimeImmutable) {
                $club->setErasureScheduledAt($now->modify(self::GRACE_PERIOD));
                // Nouveau cycle d'effacement → le rappel J-7 doit pouvoir repartir
                // (un stamp résiduel d'un cycle annulé bloquerait sinon le rappel).
                $club->setErasureReminderSentAt(null);
                $scheduled[] = $clubId;
            }
        }
        $this->entityManager->flush();

        return $scheduled;
    }

    /**
     * Prévient le contact officiel de chaque club orphelin de la suppression à venir —
     * mail institutionnel FFBB (même ancre que l'approbation), repli `Club.contactEmail`
     * si la FFBB est muette/introuvable. Ni l'un ni l'autre → rien à envoyer (le
     * superadmin voit la demande). Best-effort : un échec d'envoi est tracé, jamais fatal.
     *
     * @param list<string> $clubIds
     */
    private function notifyOrphanedClubs(array $clubIds): void
    {
        foreach ($clubIds as $clubId) {
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if (!$club instanceof Club) {
                continue;
            }
            $deadline = $club->getErasureScheduledAt();
            if (!$deadline instanceof DateTimeImmutable) {
                continue;
            }
            $ffbbCode = $club->getFfbbClubCode();
            $to = (null !== $ffbbCode ? $this->ffbbClubDirectory->lookupClubEmail($ffbbCode) : null) ?? $club->getContactEmail();
            if (null === $to || '' === $to) {
                continue;
            }

            $product = $this->productIdentity->name();
            try {
                $this->mailer->send(
                    (new Email)
                        ->from($this->mailFrom->address())
                        ->to($to)
                        ->subject(\sprintf('L\'espace %s du club %s va être supprimé', $product, $club->getName()))
                        ->text(\sprintf(
                            "Bonjour,\n\nLe dernier gestionnaire de l'espace {$product} du club %s vient de supprimer son compte : cet espace n'a plus de gestionnaire.\n\nSans reprise, il sera supprimé DÉFINITIVEMENT avec toutes ses données le %s.\n\nPour le conserver, un gestionnaire du club doit s'inscrire sur {$product} avec le code FFBB du club : sa demande vous sera soumise pour approbation.\n\n{$product}",
                            $club->getName(),
                            $deadline->format('d/m/Y'),
                        )),
                );
            } catch (Throwable $e) {
                $this->logger->warning('Orphaned-club erasure notice failed', ['clubId' => $clubId, 'error' => $e->getMessage()]);
            }
        }
    }
}
