<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OpponentVenueSuggestionSource;
use App\Repository\OpponentVenueSuggestionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une SUGGESTION de gymnase d'un club adverse FFBB (P2-54 « adversaire multi-gymnases »
 * PR-2), PARTAGÉE entre tous les clubs et keyée sur le code organisme fédéral PUBLIC
 * de l'adversaire. Un même organisme joue parfois dans plusieurs gymnases selon son
 * équipe : cette table collectionne les gymnases connus pour un adversaire, chacun
 * `FFBB_API` (vu dans le calendrier fédéral) ou `MANUAL` (choisi par des clubs), avec
 * un COMPTE de choix — pour aider un club à retrouver le bon gymnase d'un adversaire
 * sans re-résoudre.
 *
 * NOTE: table de RÉFÉRENCE GLOBALE — elle ne porte AUCUNE colonne club-identifiante
 * (pas de club_id, pas de user_id, pas de provenance, pas de « qui » — PAR CONCEPTION,
 * OpponentVenueSuggestionShareTest l'assertionne sur le catalogue Postgres). Elle
 * n'implémente PAS TenantOwnedInterface, est donc HORS RLS et hors filtre saison (même
 * patron que `opponent_directory` / `shared_competition_deadline`), et ne porte pas de
 * colonne saison : un gymnase d'organisme est indépendant de la saison.
 *
 * ⚠ COROLLAIRE OPPOSABLE (revue sécurité 2026-08-28, patron {@see OpponentDirectoryEntry}) :
 * le partagé porte « un COMPTE, jamais un QUI ». N'y ajouter JAMAIS — sans REPASSER la
 * revue sécurité — la moindre identité (quel club a choisi, quel user, un horodatage
 * par club), ni du TEXTE LIBRE saisi par un club. Le `venue_label` d'une ligne MANUAL
 * vient de l'index FFBB des salles (fédéral), JAMAIS d'une saisie libre ; une ligne
 * FFBB_API vient du calendrier fédéral. Tout ajout de donnée non fédérale-publique
 * ferait de ce partage hors-tenant un vecteur de fuite entre clubs.
 */
#[ORM\Entity(repositoryClass: OpponentVenueSuggestionRepository::class)]
#[ORM\Table(name: 'opponent_venue_suggestion')]
class OpponentVenueSuggestion
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'ffbb_organisme_code', length: 64)]
    private string $ffbbOrganismeCode;

    /**
     * Le numéro de salle FFBB (`Venue.externalRef`) d'une ligne MANUAL — le gymnase
     * a été choisi dans l'index `/api/ffbb/salles`. NULL pour une ligne FFBB_API :
     * le hit rencontre ne porte pas ce `numero` (sondé le 2026-09-15, seulement un
     * `id` distinct), la ligne est alors dédupliquée par libellé.
     */
    #[ORM\Column(name: 'venue_external_ref', length: 64, nullable: true)]
    private ?string $venueExternalRef = null;

    #[ORM\Column(name: 'venue_label', length: 180)]
    private string $venueLabel;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'postal_code', length: 16, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 8, enumType: OpponentVenueSuggestionSource::class)]
    private OpponentVenueSuggestionSource $source;

    /** COMBIEN de fois ce gymnase a été choisi (par club/saison/équipe) — jamais par QUI. Jamais < 0 (CHECK). */
    #[ORM\Column(name: 'chosen_by_count', type: 'integer')]
    private int $chosenByCount = 0;

    #[ORM\Column(name: 'last_chosen_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastChosenAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * Les écritures passent par des upserts NATIFS ({@see OpponentVenueSuggestionRepository})
     * — concurrence oblige sur une table partagée. Ce constructeur tient l'identité et
     * les champs requis (l'ORM hydrate par réflexion à la lecture, sans l'appeler).
     */
    public function __construct(string $ffbbOrganismeCode, string $venueLabel, OpponentVenueSuggestionSource $source)
    {
        $this->id = $this->newUuid();
        $this->ffbbOrganismeCode = $ffbbOrganismeCode;
        $this->venueLabel = $venueLabel;
        $this->source = $source;
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFfbbOrganismeCode(): string
    {
        return $this->ffbbOrganismeCode;
    }

    public function getVenueExternalRef(): ?string
    {
        return $this->venueExternalRef;
    }

    public function getVenueLabel(): string
    {
        return $this->venueLabel;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function getSource(): OpponentVenueSuggestionSource
    {
        return $this->source;
    }

    public function getChosenByCount(): int
    {
        return $this->chosenByCount;
    }

    public function getLastChosenAt(): ?DateTimeImmutable
    {
        return $this->lastChosenAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
