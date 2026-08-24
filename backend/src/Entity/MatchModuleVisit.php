<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MatchModuleVisitRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'instantané de « ta dernière visite » du module matchs, PAR UTILISATEUR (RMM-3,
 * « le gardien à l'ouverture »). Le radar de conflits est stateless : recalculé à
 * chaque appel, il ne peut pas dire ce qui a CHANGÉ depuis la dernière fois. Cette
 * table est la persistance légère qui lui manque — une référence figée à la
 * visite, contre laquelle le prochain passage se compare (matchs arrivés, conflits
 * neufs, planning de saison qui a bougé).
 *
 * Tenant-owned (`club_id`) + season-scoped (`season_id`) : les filtres Doctrine
 * (TenantFilter + SeasonFilter) et RLS s'appliquent par colonne, automatiquement.
 * Le scoping UTILISATEUR est APPLICATIF (le contrôleur filtre sur `user_id`, patron
 * Feedback) : une visite appartient à UN membre, pas au club — deux gestionnaires
 * du même club ont chacun leur propre « dernière visite ». D'où l'unicité
 * (club_id, season_id, user_id).
 *
 * Contrairement au signalement (Feedback survit à l'effacement de son auteur car
 * il appartient au club), la visite est une donnée PERSONNELLE de l'utilisateur :
 * elle est SUPPRIMÉE au DELETE de compte (AccountErasureService), et exportée dans
 * la portabilité RGPD (horodatages = donnée personnelle).
 */
#[ORM\Entity(repositoryClass: MatchModuleVisitRepository::class)]
#[ORM\Table(name: 'match_module_visit')]
#[ORM\UniqueConstraint(name: 'uniq_match_module_visit_scope', columns: ['club_id', 'season_id', 'user_id'])]
class MatchModuleVisit implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid')]
    private string $userId;

    /**
     * L'état figé à la référence : les empreintes des conflits alors présents, et
     * les identifiants du planning de saison (version choisie + dernière COMPLETED)
     * — comparés PAR ID, jamais par horodatage (§3 du cadrage).
     *
     * @var array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null}
     */
    #[ORM\Column(type: 'json')]
    private array $referenceSnapshot;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $referenceTakenAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $lastOpenedAt;

    /**
     * @param array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null} $referenceSnapshot
     */
    public function __construct(string $clubId, string $seasonId, string $userId, array $referenceSnapshot, DateTimeImmutable $takenAt)
    {
        $this->id = $this->newUuid();
        $this->clubId = $clubId;
        $this->seasonId = $seasonId;
        $this->userId = $userId;
        $this->referenceSnapshot = $referenceSnapshot;
        $this->referenceTakenAt = $takenAt;
        $this->lastOpenedAt = $takenAt;
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

    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * @return array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null}
     */
    public function getReferenceSnapshot(): array
    {
        return $this->referenceSnapshot;
    }

    /**
     * @param array{fingerprints: list<string>, chosenScheduleId: string|null, latestCompletedSeasonScheduleId: string|null} $snapshot
     */
    public function setReferenceSnapshot(array $snapshot): self
    {
        $this->referenceSnapshot = $snapshot;

        return $this;
    }

    public function getReferenceTakenAt(): DateTimeImmutable
    {
        return $this->referenceTakenAt;
    }

    public function setReferenceTakenAt(DateTimeImmutable $takenAt): self
    {
        $this->referenceTakenAt = $takenAt;

        return $this;
    }

    public function getLastOpenedAt(): DateTimeImmutable
    {
        return $this->lastOpenedAt;
    }

    public function setLastOpenedAt(DateTimeImmutable $lastOpenedAt): self
    {
        $this->lastOpenedAt = $lastOpenedAt;

        return $this;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
