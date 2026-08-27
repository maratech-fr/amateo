<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FbiIngestionSource;
use App\Repository\FbiIngestionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une INGESTION DATÉE de rencontres (RMM-4, réconciliation FBI). Chaque dépôt du
 * xlsx FBI en écrit une : l'app traite désormais le fichier comme une source de
 * données de plein droit (docs/archive/refonte-module-matchs.md §4/§5), pas comme une corvée
 * annexe. Elle porte la FRAÎCHEUR (« dernier dépôt : il y a N jours ») et la
 * TRACE de réconciliation.
 *
 * Donnée de CLUB, pas personnelle (contrairement à MatchModuleVisit) : PAS de
 * user_id — c'est le dépôt du club, pas la visite d'un membre. Tenant-owned
 * (`club_id`) + season-scoped (`season_id`) : les filtres Doctrine (TenantFilter
 * + SeasonFilter) et RLS s'appliquent par colonne, automatiquement ; l'export de
 * portabilité RGPD la ramasse par la boucle générique de RgpdExportService (elle
 * est TenantOwnedInterface), la purge de saison la supprime (SeasonDataPurger),
 * la purge de club effacé la suit (ErasedClubPurger passe par SeasonDataPurger).
 *
 * La TRACE (`pendingDeviations`) est un pense-bête, pas un journal : les écarts
 * « garder l'app » du dépôt, à re-vérifier au dépôt SUIVANT — le fichier redit la
 * valeur divergente → l'écart re-paraît `persisting` et la trace se REPORTE ; le
 * fichier est revenu à la valeur app, ou le fixture a disparu → la trace meurt en
 * silence. Seul un dépôt FBI_XLSX tue ou reporte une trace (cf. FbiIngestionSource).
 */
#[ORM\Entity(repositoryClass: FbiIngestionRepository::class)]
#[ORM\Table(name: 'fbi_ingestion')]
#[ORM\Index(name: 'idx_fbi_ingestion_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_fbi_ingestion_deposited', columns: ['club_id', 'season_id', 'deposited_at'])]
class FbiIngestion implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $depositedAt;

    #[ORM\Column(type: 'string', length: 16, enumType: FbiIngestionSource::class)]
    private FbiIngestionSource $source;

    #[ORM\Column(type: 'integer')]
    private int $created = 0;

    #[ORM\Column(type: 'integer')]
    private int $updated = 0;

    #[ORM\Column(type: 'integer')]
    private int $unchanged = 0;

    #[ORM\Column(type: 'integer')]
    private int $deviationsCount = 0;

    /**
     * Les écarts encore ouverts que ce dépôt reporte (« garder l'app » du dépôt +
     * ceux d'un dépôt antérieur qui divergent toujours) — le pense-bête relu au
     * dépôt suivant. Chaque entrée identifie l'écart (fixtureId, field) et garde
     * la valeur pour l'affichage ; `field` ∈ {date, kickoff, venue}.
     *
     * @var list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $pendingDeviations = [];

    /**
     * @param list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}> $pendingDeviations
     */
    public function __construct(
        string $clubId,
        string $seasonId,
        FbiIngestionSource $source,
        DateTimeImmutable $depositedAt,
        int $created,
        int $updated,
        int $unchanged,
        int $deviationsCount,
        array $pendingDeviations,
    ) {
        $this->id = $this->newUuid();
        $this->clubId = $clubId;
        $this->seasonId = $seasonId;
        $this->source = $source;
        $this->depositedAt = $depositedAt;
        $this->created = $created;
        $this->updated = $updated;
        $this->unchanged = $unchanged;
        $this->deviationsCount = $deviationsCount;
        $this->pendingDeviations = $pendingDeviations;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getClubId(): ?string
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

    public function getDepositedAt(): DateTimeImmutable
    {
        return $this->depositedAt;
    }

    public function getSource(): FbiIngestionSource
    {
        return $this->source;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    public function getDeviationsCount(): int
    {
        return $this->deviationsCount;
    }

    /**
     * @return list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}>
     */
    public function getPendingDeviations(): array
    {
        return $this->pendingDeviations;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
