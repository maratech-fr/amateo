<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FederalState;
use App\Matches\FederalFollowUp;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le suivi fédéral d'un match : son état dans FBI, ses écarts avec la source, le mémo « FBI affiche encore … » et
 * le libellé de salle gardé. Au plus une ligne par match. Lu et écrit par {@see FederalFollowUp} seulement : un match
 * sans ligne est « à saisir » s'il est suivi (compétition de source FBI) ; la ligne d'un match non suivi ne porte
 * jamais que ses écarts.
 *
 * Clé posée à la main en migration (invisible de Doctrine) : `(fixture_id, club_id)` → `fixture (id, club_id)`
 * `ON DELETE CASCADE` — le suivi ne désigne jamais le match d'un autre club et part avec son match.
 *
 * @phpstan-import-type PendingDeviation from Fixture
 * @phpstan-import-type FbiEcho from Fixture
 */
#[Family(Level::SEASON, cold: [], copiedToNextSeason: false)]
#[ORM\Entity]
#[ORM\Table(name: 'fixture_federal_tracking')]
#[ORM\Index(name: 'idx_fixture_federal_tracking_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class FederalTracking implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    #[RefersTo(Fixture::class, OnDelete::DELETE)]
    private string $fixtureId;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(length: 20, enumType: FederalState::class)]
    private FederalState $state = FederalState::TO_ENTER;

    /**
     * Les écarts source⇄app encore ouverts sur ce match, une entrée par champ divergent, ordre canonique date →
     * kickoff → venue. `seenAt` date la PREMIÈRE apparition de l'écart, `autoApplied` marque une valeur appliquée
     * d'office hors périmètre alors que le match était traité.
     *
     * @var list<PendingDeviation>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $pendingDeviations = [];

    /**
     * Mémo « FBI affiche encore … » posé quand un « prendre le fichier » sur l'HEURE ramène un match saisi ou
     * validé à « à saisir » : FBI montre la valeur `value` pour le champ `field` (posé à l'instant `at`). Effacé dès
     * que le match repasse saisi ou validé.
     *
     * @var FbiEcho|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fbiEcho = null;

    /**
     * Le libellé de salle « gardé » par « Garder l'appli » sur l'écart salle d'un domicile non placé, NORMALISÉ :
     * tant que la source répète ce libellé, l'écart ne se repose plus. Effacé dès qu'une salle non nulle est posée
     * sur le match ({@see followVenue}) et par un « Prendre le fichier » sur la salle.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $keptVenueLabel = null;

    public function __construct(string $fixtureId, string $clubId, string $seasonId)
    {
        $this->fixtureId = $fixtureId;
        $this->clubId = $clubId;
        $this->seasonId = $seasonId;
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getFixtureId(): string
    {
        return $this->fixtureId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function getState(): FederalState
    {
        return $this->state;
    }

    /** Saisi ou validé : FBI est de nouveau à jour, le mémo « FBI affiche encore … » n'a plus d'objet. */
    public function setState(FederalState $state): self
    {
        $this->state = $state;
        if (FederalState::TO_ENTER !== $state) {
            $this->fbiEcho = null;
        }

        return $this;
    }

    /** @return list<PendingDeviation> */
    public function getPendingDeviations(): array
    {
        return $this->pendingDeviations;
    }

    public function hasPendingDeviations(): bool
    {
        return [] !== $this->pendingDeviations;
    }

    /**
     * Vrai si au moins une entrée est à TRANCHER (`autoApplied === false`) : aucun geste en masse (équipe,
     * extérieurs) ne l'acquitte. Une entrée sans la clé (JSON relu tel quel de la base) compte comme arbitrable :
     * dans le doute, jamais vidée en masse.
     */
    public function hasArbitrableDeviations(): bool
    {
        // Élargi exprès : le type PendingDeviation garantit la clé, la colonne JSON non.
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->pendingDeviations;
        foreach ($entries as $entry) {
            if (true !== ($entry['autoApplied'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @return PendingDeviation|null */
    public function getPendingDeviation(string $field): ?array
    {
        foreach ($this->pendingDeviations as $entry) {
            if ($entry['field'] === $field) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Upsert par champ : remplace l'entrée du champ si elle existe, l'ajoute sinon. L'ordre canonique (date,
     * kickoff, venue) est reconstruit pour un JSON stable.
     *
     * @param PendingDeviation $entry
     */
    public function putPendingDeviation(array $entry): self
    {
        $this->removePendingDeviation($entry['field']);
        $this->pendingDeviations[] = $entry;
        $rank = ['date' => 0, 'kickoff' => 1, 'venue' => 2];
        usort(
            $this->pendingDeviations,
            static fn (array $a, array $b): int => $rank[$a['field']] <=> $rank[$b['field']],
        );

        return $this;
    }

    public function removePendingDeviation(string $field): self
    {
        $this->pendingDeviations = array_values(array_filter(
            $this->pendingDeviations,
            static fn (array $entry): bool => $entry['field'] !== $field,
        ));

        return $this;
    }

    public function clearPendingDeviations(): self
    {
        $this->pendingDeviations = [];

        return $this;
    }

    /** @return FbiEcho|null */
    public function getFbiEcho(): ?array
    {
        return $this->fbiEcho;
    }

    /** @param FbiEcho|null $fbiEcho */
    public function setFbiEcho(?array $fbiEcho): self
    {
        $this->fbiEcho = $fbiEcho;

        return $this;
    }

    public function getKeptVenueLabel(): ?string
    {
        return $this->keptVenueLabel;
    }

    public function setKeptVenueLabel(?string $keptVenueLabel): self
    {
        $this->keptVenueLabel = $keptVenueLabel;

        return $this;
    }

    /**
     * Une salle est écrite sur le match : poser une salle non nulle éteint le libellé gardé (le domicile a désormais
     * un gymnase choisi, l'idempotence de l'ancien écart n'a plus d'objet) ; un dépointage (null) n'y touche pas.
     */
    public function followVenue(?string $venueId): self
    {
        if (null !== $venueId) {
            $this->keptVenueLabel = null;
        }

        return $this;
    }
}
