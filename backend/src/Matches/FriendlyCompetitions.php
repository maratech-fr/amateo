<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Enum\CompetitionType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * La seule porte vers « est-ce un amical ? » et vers « Amicaux », la compétition d'office de chaque saison : un
 * match sans compétition désignée y est rangé, l'équipe engagée au passage. Elle accepte plusieurs engagements et
 * n'est jamais balayée ({@see CompetitionRegistry::pruneEmpty}).
 *
 * Écritures IMMÉDIATES (`INSERT … ON CONFLICT DO NOTHING`, puis relecture) : deux gestes concurrents donnent une
 * compétition et un engagement, jamais un doublon ni une erreur d'unicité. Elles passent sous la RLS du club
 * courant.
 */
final readonly class FriendlyCompetitions
{
    public const string DEFAULT_NAME = 'Amicaux';

    public function __construct(private EntityManagerInterface $entityManager) {}

    /** « Amicaux » de la saison, créée si elle manque. */
    public function defaultFor(string $clubId, string $seasonId): Competition
    {
        $id = $this->ensureDefault($clubId, $seasonId);

        return $this->entityManager->find(Competition::class, $id)
            ?? throw new LogicException(\sprintf('La compétition d\'office %s est hors de portée.', $id));
    }

    /** « Amicaux » de la saison, l'équipe y engagée (une fois). */
    public function engageInDefault(string $clubId, string $seasonId, string $teamId): Competition
    {
        $competition = $this->defaultFor($clubId, $seasonId);
        $this->engageIn($competition, $teamId);

        return $competition;
    }

    /**
     * Engage l'équipe dans une compétition AMICALE (une fois) : un amical se range sans engagement préalable, et une
     * compétition amicale accepte plusieurs équipes.
     */
    public function engageIn(Competition $friendly, string $teamId): void
    {
        if (!$friendly->getCompetitionType()->isFriendly()) {
            throw new LogicException(\sprintf('La compétition %s n\'est pas amicale.', $friendly->getId()));
        }
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO engagement (id, version, created_at, updated_at, club_id, season_id, competition_id, team_id)'
            . ' VALUES (gen_random_uuid(), 1, now(), now(), :club, :season, :competition, :team)'
            . ' ON CONFLICT (competition_id, team_id) DO NOTHING',
            ['club' => $friendly->getClubId(), 'season' => $friendly->getSeasonId(), 'competition' => $friendly->getId(), 'team' => $teamId],
        );
    }

    /**
     * Les compétitions amicales de la saison, d'office ou de la fédération.
     *
     * @return array<string, true>
     */
    public function friendlyIdsOf(string $seasonId): array
    {
        /** @var list<string> $ids */
        $ids = $this->entityManager->createQuery(
            'SELECT c.id FROM ' . Competition::class . ' c WHERE c.seasonId = :seasonId AND c.competitionType = :friendly',
        )->setParameter('seasonId', $seasonId)->setParameter('friendly', CompetitionType::FRIENDLY)->getSingleColumnResult();

        return array_fill_keys($ids, true);
    }

    /** Un match dont la compétition est introuvable n'est pas un amical : le doute le traite en compétition. */
    public function isFriendly(Fixture $fixture): bool
    {
        $competitionId = $fixture->getCompetitionId();
        if (null === $competitionId) {
            return false;
        }

        return $this->entityManager->find(Competition::class, $competitionId)?->getCompetitionType()->isFriendly() ?? false;
    }

    /** Crée « Amicaux » si elle manque (écriture immédiate) ; rend son identifiant. */
    public function ensureDefault(string $clubId, string $seasonId): string
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'INSERT INTO competition (id, version, created_at, updated_at, club_id, season_id, name, competition_type, default_friendly)'
            . ' VALUES (gen_random_uuid(), 1, now(), now(), :club, :season, :name, :friendly, true)'
            . ' ON CONFLICT (club_id, season_id) WHERE default_friendly = true DO NOTHING',
            ['club' => $clubId, 'season' => $seasonId, 'name' => self::DEFAULT_NAME, 'friendly' => CompetitionType::FRIENDLY->value],
        );
        $id = $connection->fetchOne('SELECT id FROM competition WHERE club_id = :club AND season_id = :season AND default_friendly', ['club' => $clubId, 'season' => $seasonId]);
        if (!\is_string($id)) {
            throw new LogicException(\sprintf('Aucune compétition d\'office pour la saison %s.', $seasonId));
        }

        return $id;
    }
}
