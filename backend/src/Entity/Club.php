<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClubRepository::class)]
#[ORM\Table(name: 'club')]
#[ORM\Index(name: 'idx_club_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_club_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_club_ffbb_club_code', columns: ['ffbb_club_code'])]
#[ORM\HasLifecycleCallbacks]
class Club
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
    private string $name;

    #[ORM\Column(type: 'string', length: 180)]
    private string $slug;

    // P1-3 — offre souscrite : FK UUID (nullable) vers subscription_plan. null =
    // offre Découverte (le défaut de tout compte). L'offre EFFECTIVE se calcule à
    // la lecture (PlanEntitlements) — une offre payante/bêta dont paidSeasonYear
    // est périmé retombe sur Découverte sans qu'on touche ce champ.
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $planId = null;

    // P1-3 — crédits de SORTIE consommés (offre Découverte : pool club de 10,
    // partagé entre gestionnaires). Décompté par l'enforcement (PR B) ; posé à 0
    // par défaut, remis à 0 par l'action superadmin app:clubs:reset-credits.
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $outputCreditsUsed = 0;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $billingCycle = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $planExpiresAt = null;

    #[ORM\Column(type: 'integer')]
    private int $generationCountSeason = 0;

    /** Last authenticated or generation activity observed for this club. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(type: 'string', length: 24, nullable: true)]
    private ?string $schoolZone = null;

    /** FFBB league (région) derived from ffbbClubCode (LeagueResolver) — match-window catalog envelope. */
    #[ORM\Column(type: 'string', length: 24, nullable: true)]
    private ?string $league = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $timezone = 'Europe/Paris';

    // Sport du club (retour fondateur 2026-07-18 : le club sait son sport). Référence
    // l'entité `Sport` (déjà le modèle, via SportCategory) — posé au seed depuis le
    // sport basketball. Nullable : les clubs d'avant la migration sont backfillés
    // depuis leurs SportCategory, un club sans catégorie reste null. Aucune logique
    // multi-sport ouverte (roadmap : « attendre une vraie demande »).
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $sportId = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $locale = 'fr';

    #[ORM\Column(type: 'boolean')]
    private bool $onboardingCompleted = false;

    // P4-271 — le club a-t-il un modèle de week-end sur deux semaines (A/B) ? Aide
    // visuelle PURE pour le gestionnaire : quand vrai, l'écran « Semaine type »
    // sépare les créneaux idéaux en semaines A et B, sinon une vue unique les montre
    // tous. Aucun effet sur le placement — le tag de semaine ne voyage jamais au
    // moteur. Décoché, les créneaux restés en B ne sont pas réécrits (réversible).
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $weekendAlternates = false;

    // P1-5 — l'abonnement se paie PAR SAISON (décision fondateur 2026-08-04) :
    // l'année-pivot de la DERNIÈRE saison réglée (2026 = saison 2026-2027). La
    // bascule vers la saison N+1 exige paidSeasonYear >= N+1 — le gate est la
    // BASCULE, pas la génération : préparer la saison suivante dès mai sans la
    // payer était la fuite. Posée à l'inscription (saison de naissance couverte),
    // avancée par l'action support « Marquer la saison suivante payée » en
    // attendant le paiement en ligne (P1-3).
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $paidSeasonYear = null;

    // P2-21 lot A : non-null = les équipes de ce club ont été créées par l'import
    // FFBB à l'onboarding. Vérité SERVEUR qui gate la modale « équipes importées »
    // et l'atterrissage forcé sur Équipes — sans ce marqueur, un club à saisie
    // manuelle (ou une spec e2e) verrait la modale mentir dès que le flag
    // localStorage manque (revue CI 2026-08-04 : e2e suspendue 30 min).
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $ffbbTeamsImportedAt = null;

    // P2-4 — club de DÉMONSTRATION (vendeur) : hors métriques réelles de la console,
    // horloge simulable (demo_today), et — quand le bridage Découverte existera (P1-3) —
    // exempté de tout quota. Jamais posé par un parcours utilisateur : seule la
    // commande support app:demo:create le crée.
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDemo = false;

    // P4-16 / P2-4 — l'« aujourd'hui » SIMULÉ d'un club de démonstration. Non-null =
    // toute l'application (serveur : DemoAwareClock ; front : /api/me → clock.ts) vit
    // à cette date pour CE club — rejouer « à trois semaines des vacances » en plein
    // été. Null = horloge réelle, le cas de tous les vrais clubs. Posé par la commande
    // app:demo:clock (console superadmin à venir, PR 2 du lot démo).
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $demoToday = null;

    // RGPD (droit à l'effacement) : non-null = purge du workspace programmée à
    // cette date (dernier admin effacé + délai de grâce 30 j). Annulable en la
    // remettant à null tant que app:clubs:purge-erased n'est pas passé.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $erasureScheduledAt = null;

    // Posé par la purge RGPD : le workspace a été vidé, seule l'identité
    // publique FFBB du club subsiste (référentiel adverse / win-back).
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
    private ?string $ffbbClubCode = null;

    /** Public URL of the club logo (served by the logo endpoint / storage). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logoUrl = null;

    /** Club accent colour for the LIGHT theme (hex, e.g. #3498DB) driving the UI `--accent`. */
    #[ORM\Column(type: 'string', length: 9, nullable: true)]
    private ?string $accentColor = null;

    /** Club accent colour for the DARK theme (hex). null = derive from accentColor. */
    #[ORM\Column(type: 'string', length: 9, nullable: true)]
    private ?string $accentColorDark = null;

    /**
     * Up to 3 hex colours extracted from the club logo, used to tint the theme.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $accentPalette = null;

    // FFBB club info (lot B — manual entry, FFBB autofill in lot C). All nullable.
    // President/correspondent are professional contacts (public FFBB data); home
    // addresses are deliberately NOT stored (RGPD minimisation).
    #[ORM\Column(type: 'string', length: 24, nullable: true)]
    private ?string $committeeCode = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $correspondentName = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $correspondentPhone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $correspondentEmail = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $presidentName = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $presidentPhone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $presidentEmail = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $mainVenueName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mainVenueAddress = null;

    // FFBB autofill (lot C): institutional club data pulled from the FFBB API at
    // creation. Complement the lot B contact fields (address/phone/email above).
    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $website = null;

    // Club geolocation (from the FFBB API cartography). Drives club-to-club
    // travel-time estimation for match scheduling (cf. MatchFootprint).
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPlanId(): ?string
    {
        return $this->planId;
    }

    public function setPlanId(?string $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function getOutputCreditsUsed(): int
    {
        return $this->outputCreditsUsed;
    }

    public function setOutputCreditsUsed(int $outputCreditsUsed): self
    {
        $this->outputCreditsUsed = $outputCreditsUsed;

        return $this;
    }

    public function getBillingCycle(): ?string
    {
        return $this->billingCycle;
    }

    public function setBillingCycle(?string $billingCycle): self
    {
        $this->billingCycle = $billingCycle;

        return $this;
    }

    public function getPlanExpiresAt(): ?DateTimeImmutable
    {
        return $this->planExpiresAt;
    }

    public function setPlanExpiresAt(?DateTimeImmutable $planExpiresAt): self
    {
        $this->planExpiresAt = $planExpiresAt;

        return $this;
    }

    public function getGenerationCountSeason(): int
    {
        return $this->generationCountSeason;
    }

    public function setGenerationCountSeason(int $generationCountSeason): self
    {
        $this->generationCountSeason = $generationCountSeason;

        return $this;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): self
    {
        $this->lastActivityAt = $lastActivityAt;

        return $this;
    }

    public function getSchoolZone(): ?string
    {
        return $this->schoolZone;
    }

    public function setSchoolZone(?string $schoolZone): self
    {
        $this->schoolZone = $schoolZone;

        return $this;
    }

    public function getLeague(): ?string
    {
        return $this->league;
    }

    public function setLeague(?string $league): self
    {
        $this->league = $league;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getSportId(): ?string
    {
        return $this->sportId;
    }

    public function setSportId(?string $sportId): self
    {
        $this->sportId = $sportId;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    public function isOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    public function getPaidSeasonYear(): ?int
    {
        return $this->paidSeasonYear;
    }

    public function setPaidSeasonYear(?int $paidSeasonYear): self
    {
        $this->paidSeasonYear = $paidSeasonYear;

        return $this;
    }

    public function getFfbbTeamsImportedAt(): ?DateTimeImmutable
    {
        return $this->ffbbTeamsImportedAt;
    }

    public function isDemo(): bool
    {
        return $this->isDemo;
    }

    public function setIsDemo(bool $isDemo): self
    {
        $this->isDemo = $isDemo;

        return $this;
    }

    public function getDemoToday(): ?DateTimeImmutable
    {
        return $this->demoToday;
    }

    public function setDemoToday(?DateTimeImmutable $demoToday): self
    {
        $this->demoToday = $demoToday;

        return $this;
    }

    public function setFfbbTeamsImportedAt(?DateTimeImmutable $ffbbTeamsImportedAt): self
    {
        $this->ffbbTeamsImportedAt = $ffbbTeamsImportedAt;

        return $this;
    }

    public function getErasureScheduledAt(): ?DateTimeImmutable
    {
        return $this->erasureScheduledAt;
    }

    public function setErasureScheduledAt(?DateTimeImmutable $erasureScheduledAt): self
    {
        $this->erasureScheduledAt = $erasureScheduledAt;

        return $this;
    }

    public function getUnsubscribedAt(): ?DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }

    public function setUnsubscribedAt(?DateTimeImmutable $unsubscribedAt): self
    {
        $this->unsubscribedAt = $unsubscribedAt;

        return $this;
    }

    public function setOnboardingCompleted(bool $onboardingCompleted): self
    {
        $this->onboardingCompleted = $onboardingCompleted;

        return $this;
    }

    public function weekendAlternates(): bool
    {
        return $this->weekendAlternates;
    }

    public function setWeekendAlternates(bool $weekendAlternates): self
    {
        $this->weekendAlternates = $weekendAlternates;

        return $this;
    }

    public function getFfbbClubCode(): ?string
    {
        return $this->ffbbClubCode;
    }

    public function setFfbbClubCode(?string $ffbbClubCode): self
    {
        $this->ffbbClubCode = $ffbbClubCode;

        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): self
    {
        $this->logoUrl = $logoUrl;

        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): self
    {
        $this->accentColor = $accentColor;

        return $this;
    }

    public function getAccentColorDark(): ?string
    {
        return $this->accentColorDark;
    }

    public function setAccentColorDark(?string $accentColorDark): self
    {
        $this->accentColorDark = $accentColorDark;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAccentPalette(): ?array
    {
        return $this->accentPalette;
    }

    /**
     * @param list<string>|null $accentPalette
     */
    public function setAccentPalette(?array $accentPalette): self
    {
        $this->accentPalette = $accentPalette;

        return $this;
    }

    public function getCommitteeCode(): ?string
    {
        return $this->committeeCode;
    }

    public function setCommitteeCode(?string $committeeCode): self
    {
        $this->committeeCode = $committeeCode;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): self
    {
        $this->contactPhone = $contactPhone;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getCorrespondentName(): ?string
    {
        return $this->correspondentName;
    }

    public function setCorrespondentName(?string $correspondentName): self
    {
        $this->correspondentName = $correspondentName;

        return $this;
    }

    public function getCorrespondentPhone(): ?string
    {
        return $this->correspondentPhone;
    }

    public function setCorrespondentPhone(?string $correspondentPhone): self
    {
        $this->correspondentPhone = $correspondentPhone;

        return $this;
    }

    public function getCorrespondentEmail(): ?string
    {
        return $this->correspondentEmail;
    }

    public function setCorrespondentEmail(?string $correspondentEmail): self
    {
        $this->correspondentEmail = $correspondentEmail;

        return $this;
    }

    public function getPresidentName(): ?string
    {
        return $this->presidentName;
    }

    public function setPresidentName(?string $presidentName): self
    {
        $this->presidentName = $presidentName;

        return $this;
    }

    public function getPresidentPhone(): ?string
    {
        return $this->presidentPhone;
    }

    public function setPresidentPhone(?string $presidentPhone): self
    {
        $this->presidentPhone = $presidentPhone;

        return $this;
    }

    public function getPresidentEmail(): ?string
    {
        return $this->presidentEmail;
    }

    public function setPresidentEmail(?string $presidentEmail): self
    {
        $this->presidentEmail = $presidentEmail;

        return $this;
    }

    public function getMainVenueName(): ?string
    {
        return $this->mainVenueName;
    }

    public function setMainVenueName(?string $mainVenueName): self
    {
        $this->mainVenueName = $mainVenueName;

        return $this;
    }

    public function getMainVenueAddress(): ?string
    {
        return $this->mainVenueAddress;
    }

    public function setMainVenueAddress(?string $mainVenueAddress): self
    {
        $this->mainVenueAddress = $mainVenueAddress;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): self
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
