<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubSubscription;
use App\Entity\Season;
use App\Enum\SubscriptionOrigin;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Le SEUL écrivain de l'abonnement par saison ({@see ClubSubscription}), au sens de la base : il
 * prend toujours la connexion propriétaire (`admin`), aucun appelant ne choisit. La base retire
 * `INSERT` et `UPDATE` sur `club_subscription` au rôle applicatif ; la session de la console ne pose
 * jamais le locataire, et les gestes de support tournent hors requête.
 *
 * Il porte aussi les deux lectures dont un geste a besoin hors requête, par la même connexion :
 * « aujourd'hui » pour le club (sa date simulée s'il est de démonstration, l'horloge sinon) et les
 * deux années d'un geste, calculées comme leurs lecteurs :
 *   - « en cours » = l'année de la saison courante du club (celle de `/api/me`), ou l'année
 *     d'horloge s'il n'a pas de saison ;
 *   - « suivante » = l'année que la bascule créera, `max(année en cours + 1, année d'horloge + 1)`.
 *
 * Non final : l'environnement de test le remplace par un double qui écrit dans la transaction de
 * test de la connexion applicative quand le club n'est visible que d'elle (sous DAMA, chaque
 * connexion a sa transaction) — voir `tests/Support/SubscribesClub.php`. Les trois accès en base
 * sont les seules méthodes protégées, la règle reste ici.
 */
readonly class ClubSubscriptionWriter
{
    /**
     * Insertion ou mise à jour de la ligne (club, année) : réglée à l'instant, sauf Découverte. Ne
     * touche aucune autre saison. Zéro ligne écrite = code d'offre inconnu.
     */
    protected const string UPSERT_SQL = <<<'SQL'
        INSERT INTO club_subscription (id, club_id, season_year, plan_id, paid_at, origin, created_at, updated_at)
        SELECT gen_random_uuid(), :clubId, :seasonYear, p.id, CASE WHEN p.code = 'decouverte' THEN NULL ELSE now() END, :origin, now(), now()
        FROM subscription_plan p
        WHERE p.code = :planCode
        ON CONFLICT (club_id, season_year) DO UPDATE
        SET plan_id = EXCLUDED.plan_id, paid_at = EXCLUDED.paid_at, origin = EXCLUDED.origin, updated_at = now()
        SQL;

    /** La date simulée d'un club de démonstration qui en a une (null sinon) ; aucune ligne = club inconnu. */
    protected const string CLUB_PIN_SQL = 'SELECT CASE WHEN is_demo AND simulated_today IS NOT NULL THEN simulated_today END FROM club WHERE id = :clubId';

    protected const string SEASON_STARTS_SQL = 'SELECT start_date FROM season WHERE club_id = :clubId ORDER BY start_date';

    public function __construct(
        protected ManagerRegistry $managerRegistry,
        protected ClockInterface $clock,
    ) {}

    public function record(string $clubId, int $seasonYear, string $planCode, SubscriptionOrigin $origin): void
    {
        $written = $this->upsert([
            'clubId' => $clubId,
            'seasonYear' => $seasonYear,
            'planCode' => $planCode,
            'origin' => $origin->value,
        ]);
        if (0 === $written) {
            throw new InvalidArgumentException(\sprintf('Unknown offer code "%s".', $planCode));
        }
    }

    /** @return array{current: int, next: int} */
    public function seasonYears(string $clubId, DateTimeImmutable $today): array
    {
        $seasons = [];
        foreach ($this->seasonStarts($clubId) as $start) {
            $seasons[] = (new Season)->setStartDate(new DateTimeImmutable($start));
        }
        $current = SeasonResolver::currentAmong($seasons, $today);
        $todayYear = SeasonResolver::seasonYear($today);
        $currentYear = $current instanceof Season ? SeasonResolver::seasonYear($current->getStartDate()) : $todayYear;

        return ['current' => $currentYear, 'next' => max($currentYear + 1, $todayYear + 1)];
    }

    /** « Aujourd'hui » pour le club : sa date simulée s'il est de démonstration, l'horloge sinon ; null = club inconnu. */
    public function today(string $clubId): ?DateTimeImmutable
    {
        $pin = $this->clubPin($clubId);
        if (false === $pin) {
            return null;
        }

        return null === $pin ? DateTimeImmutable::createFromInterface($this->clock->now()) : new DateTimeImmutable($pin);
    }

    /** @param array{clubId: string, seasonYear: int, planCode: string, origin: string} $parameters */
    protected function upsert(array $parameters): int
    {
        return (int) $this->owner()->executeStatement(self::UPSERT_SQL, $parameters);
    }

    protected function clubPin(string $clubId): string|false|null
    {
        $pin = $this->owner()->fetchOne(self::CLUB_PIN_SQL, ['clubId' => $clubId]);

        return false === $pin || null === $pin ? $pin : (string) $pin;
    }

    /** @return list<string> */
    protected function seasonStarts(string $clubId): array
    {
        return array_map(strval(...), $this->owner()->fetchFirstColumn(self::SEASON_STARTS_SQL, ['clubId' => $clubId]));
    }

    private function owner(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
