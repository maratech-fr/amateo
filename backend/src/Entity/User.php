<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'string', length: 120)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 120)]
    private string $lastName;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    // P4-74 : adresse e-mail EN ATTENTE de confirmation. Le patron « confirmer
    // d'abord, basculer ensuite » : `email` reste actif et inchangé tant que la
    // nouvelle adresse n'est pas confirmée (via EmailChangeToken envoyé à CETTE
    // adresse). Unique en base — deux comptes ne peuvent pas réserver la même.
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $pendingEmail = null;

    // RGPD (droit à l'effacement) : non-null = compte anonymisé — l'identité a
    // été écrasée (email/nom/hash) et ne peut plus servir à s'authentifier.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $anonymizedAt = null;

    // RGPD (consentement) : acceptation CGU + politique de confidentialité au
    // register (preuve : horodatage + version des textes acceptés).
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $termsVersion = null;

    // RGPD (rétention) : dernier login réussi — l'inactivité se mesure sur
    // COALESCE(lastLoginAt, createdAt). Posé par LoginSuccessListener.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    // RGPD (rétention) : préavis d'inactivité envoyé (23 mois). Remis à null au
    // login ; l'anonymisation (24 mois) exige un préavis vieux d'au moins 14 j.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $inactivityWarnedAt = null;

    // P4-301 — préavis « compte sans club » envoyé (orphelin : plus aucune
    // adhésion active, pending ni demande de création). Non-null = la date d'envoi
    // RÉUSSI du mail ; l'échéance de suppression = ce stamp + 30 j. Remis à null
    // dès que le compte regagne une adhésion active (OrphanAccountNotifier::cancelFor) —
    // sinon un stamp d'un cycle annulé ferait supprimer sans nouveau mail au cycle suivant.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $orphanNoticeSentAt = null;

    // P5-12 : jusqu'où l'utilisateur a lu le journal de nouveautés — posé par
    // POST /api/release-notes/seen. La modale « quoi de neuf » ne s'ouvre que sur
    // une note publiée APRÈS cet instant. Null = jamais marqué (nouvel inscrit).
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $releaseNotesSeenAt = null;

    // Démos — la FENÊTRE d'activation d'un compte démo (animateur `demo@`, BCCL
    // `demo-bccl@`). NULL = inactif (défaut) ; un instant futur = fenêtre ouverte.
    // Lue par UserChecker (connexion) et le raccourci démo du register ; tout autre
    // compte y est insensible. Toujours confrontée à l'horloge RÉELLE, jamais à
    // `simulated_today` (un club démo ne doit pas pouvoir rouvrir sa propre porte).
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $demoActiveUntil = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): self
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function getAnonymizedAt(): ?DateTimeImmutable
    {
        return $this->anonymizedAt;
    }

    public function setAnonymizedAt(?DateTimeImmutable $anonymizedAt): self
    {
        $this->anonymizedAt = $anonymizedAt;

        return $this;
    }

    public function getTermsAcceptedAt(): ?DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?DateTimeImmutable $termsAcceptedAt): self
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function getTermsVersion(): ?string
    {
        return $this->termsVersion;
    }

    public function setTermsVersion(?string $termsVersion): self
    {
        $this->termsVersion = $termsVersion;

        return $this;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getInactivityWarnedAt(): ?DateTimeImmutable
    {
        return $this->inactivityWarnedAt;
    }

    public function setInactivityWarnedAt(?DateTimeImmutable $inactivityWarnedAt): self
    {
        $this->inactivityWarnedAt = $inactivityWarnedAt;

        return $this;
    }

    public function getOrphanNoticeSentAt(): ?DateTimeImmutable
    {
        return $this->orphanNoticeSentAt;
    }

    public function setOrphanNoticeSentAt(?DateTimeImmutable $orphanNoticeSentAt): self
    {
        $this->orphanNoticeSentAt = $orphanNoticeSentAt;

        return $this;
    }

    public function getReleaseNotesSeenAt(): ?DateTimeImmutable
    {
        return $this->releaseNotesSeenAt;
    }

    public function setReleaseNotesSeenAt(?DateTimeImmutable $releaseNotesSeenAt): self
    {
        $this->releaseNotesSeenAt = $releaseNotesSeenAt;

        return $this;
    }

    public function setEmailVerifiedAt(?DateTimeImmutable $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function getDemoActiveUntil(): ?DateTimeImmutable
    {
        return $this->demoActiveUntil;
    }

    public function setDemoActiveUntil(?DateTimeImmutable $demoActiveUntil): self
    {
        $this->demoActiveUntil = $demoActiveUntil;

        return $this;
    }

    /**
     * La fenêtre d'activation démo est-elle ouverte à l'instant `$now` ? NULL =
     * inactif → jamais ouverte. `$now` DOIT être l'horloge réelle (le contrôleur/
     * checker la passe), jamais l'horloge démo simulée.
     */
    public function isDemoWindowOpen(DateTimeImmutable $now): bool
    {
        return $this->demoActiveUntil instanceof DateTimeImmutable && $this->demoActiveUntil > $now;
    }

    public function getPendingEmail(): ?string
    {
        return $this->pendingEmail;
    }

    public function setPendingEmail(?string $pendingEmail): self
    {
        $this->pendingEmail = $pendingEmail;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * Requis par `UserInterface` en Symfony 7.x (déprécié, retiré en 8.0).
     *
     * Rector l'avait SUPPRIMÉE (RemoveEraseCredentialsRector) parce qu'il lit la
     * version INSTALLÉE : `symfony/security-core` est transitivement en 8.0.x
     * alors que tout le reste du stack — et `extra.symfony.require` — vise 7.4.
     * Sans cette méthode, le jour où security-core est réaligné sur 7.4, `User`
     * n'implémente plus une méthode abstraite de l'interface : fatal au boot du
     * conteneur, plus aucune authentification. La garder est inoffensif en 8.0
     * (simple méthode publique en trop) : c'est l'option sûre dans les deux sens.
     */
    public function eraseCredentials(): void {}

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
