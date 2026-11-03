<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\EmailChangeToken;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ClubCreationRequestRepository;
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
 * Chantier Membre — si l'effacé était le SEUL GESTIONNAIRE actif d'un club, ce
 * club FERME, même si des Membres restent (ils gardent la consultation et sont
 * prévenus J0/J-7) : sa purge est PROGRAMMÉE (erasureScheduledAt = +30 j, délai
 * de grâce) et exécutée par app:clubs:purge-erased — qui REVALIDE à l'échéance
 * et annule si un gestionnaire actif est revenu entre-temps. Un Membre effacé ne
 * ferme jamais un club.
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
        private readonly MembershipRequestMailBuilder $membershipMailBuilder,
        private readonly ClubCreationRequestRepository $clubCreationRequests,
        // Origine navigateur pour le lien d'inscription du préavis orphelin — bind global
        // `$frontendBaseUrl` (%env(FRONTEND_BASE_URL)%) ; vide → lien omis (patron des crons).
        private readonly string $frontendBaseUrl = '',
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
        // Chantier Membre — le RÔLE est lu AVANT la désactivation : seuls les clubs dont
        // l'effacé était le SEUL gestionnaire actif peuvent fermer (un Membre effacé ne ferme
        // jamais un club, même s'il en était le dernier compte actif).
        $soleManagedClubIds = array_column($this->clubUserRepository->clubsWhereSoleActiveManager($user->getId()), 'id');
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

        // Chantier Membre — ses demandes de club VIVANTES (pending/expired) meurent avec le
        // compte : statut `cancelled`, lien d'approbation mort (404 identique), aucun mail.
        foreach ($this->clubCreationRequests->findBy(['userId' => $user->getId(), 'status' => [ClubCreationRequest::STATUS_PENDING, ClubCreationRequest::STATUS_EXPIRED]]) as $request) {
            $request->setStatus(ClubCreationRequest::STATUS_CANCELLED);
            $request->setDecidedAt($now);
        }

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

        // 4. Clubs sans gestionnaire : l'effacé en était le SEUL gestionnaire actif et aucun
        //    autre n'est revenu entre-temps → fermeture à +30 j, même si des Membres restent
        //    (ils gardent la consultation et sont prévenus). Revalidée à l'échéance par la purge.
        $scheduled = [];
        foreach ($soleManagedClubIds as $clubId) {
            if ($this->clubUserRepository->hasActiveManager($clubId)) {
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
     * Prévient de la fermeture de chaque club devenu sans gestionnaire : les MEMBRES restants
     * d'abord (ils gardent la consultation jusqu'à l'échéance), PUIS le contact officiel — mail
     * institutionnel FFBB (même ancre que l'approbation), repli `Club.contactEmail`. L'absence
     * d'adresse officielle ne prive jamais les Membres de leur avis. Best-effort : un échec
     * d'envoi est tracé, jamais fatal.
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

            foreach ($this->clubUserRepository->findActiveMemberRecipients($clubId) as $member) {
                try {
                    $this->mailer->send($this->membershipMailBuilder->buildClubClosing($member['email'], $club, $member['firstName'], $deadline, false));
                } catch (Throwable $e) {
                    $this->logger->warning('Managerless-club member notice failed', ['clubId' => $clubId, 'error' => $e->getMessage()]);
                }
            }

            $ffbbCode = $club->getFfbbClubCode();
            $to = (null !== $ffbbCode ? $this->ffbbClubDirectory->lookupClubEmail($ffbbCode) : null) ?? $club->getContactEmail();
            if (null === $to || '' === $to) {
                continue;
            }

            $product = $this->productIdentity->name();
            // Lien d'inscription (reprendre le club) sur sa propre ligne, omis sans base front.
            $registerLink = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') . '/register' : null;
            $registerBlock = null !== $registerLink ? "\n\nInscrire un gestionnaire :\n" . $registerLink : '';
            try {
                $email = (new Email)
                    ->from($this->mailFrom->address())
                    ->to($to)
                    ->subject(\sprintf('L\'espace %s du club %s va être supprimé', $product, $club->getName()))
                    ->text(\sprintf(
                        "Bonjour,\n\nLe dernier gestionnaire de l'espace {$product} du club %s a quitté le service : cet espace n'a plus de gestionnaire.\n\nSans reprise, il sera supprimé DÉFINITIVEMENT avec toutes ses données le %s.\n\nPour le conserver, un gestionnaire du club doit s'inscrire sur {$product} avec le code FFBB du club : sa demande vous sera soumise pour approbation.%s\n\n{$product}",
                        $club->getName(),
                        $deadline->format('d/m/Y'),
                        $registerBlock,
                    ));
                // Bouton CTA du HTML (D1) : s'inscrire pour reprendre le club (seulement si lien cliquable).
                if (null !== $registerLink) {
                    $headers = $email->getHeaders();
                    $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $registerLink);
                    $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Inscrire un gestionnaire');
                }
                // Identité du club (logo + nom court) sur la carte d'e-mail (D1).
                ClubMailMetadata::mark($email, $club);
                $this->mailer->send($email);
            } catch (Throwable $e) {
                $this->logger->warning('Orphaned-club erasure notice failed', ['clubId' => $clubId, 'error' => $e->getMessage()]);
            }
        }
    }
}
