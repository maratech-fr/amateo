<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\ClubSubscriptionWriter;
use App\Tests\Support\SubscribesClub;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * L'écrivain de l'abonnement en environnement de test : la règle est celle de
 * {@see ClubSubscriptionWriter}, seul le lieu de l'écriture change.
 *
 * Sous DAMA, chaque connexion a sa propre transaction de test : un club semé par la connexion
 * applicative (ORM, inscription par l'API) est invisible de la connexion propriétaire, où l'écrivain
 * réel écrit (clé étrangère vers `club` en échec, 23503) ; et une ligne écrite par la connexion
 * propriétaire est invisible des lecteurs de l'application. Quand le club est visible de la
 * connexion applicative, ce double lit et écrit donc DANS SA transaction :
 *   - l'écriture passe par une fonction `SECURITY DEFINER` de la base de test
 *     (`amateo_test_record_club_subscription`, détenue par le propriétaire, EXECUTE au seul rôle
 *     applicatif), créée une fois par processus par une connexion propriétaire hors DAMA ;
 *     elle rejoue le même `INSERT … ON CONFLICT` que l'écrivain réel ;
 *   - les saisons se lisent sous le locataire du club, posé le temps de la lecture puis rétabli.
 * Sinon (club semé par la connexion propriétaire, patron de `SetClubPlanCommandTest`), c'est
 * l'écrivain réel, inchangé.
 *
 * Le rôle applicatif ne reçoit jamais `INSERT` ni `UPDATE` sur `club_subscription` : la fonction
 * n'existe que dans la base de test, aucune migration ne la crée, et
 * `RlsIsolationTest::testTheApplicationRoleCanNeitherCreateNorChangeASubscriptionLineEvenOnItsOwnClub`
 * prouve le refus d'une écriture directe. Décor des tests : {@see SubscribesClub}.
 */
final readonly class SameTransactionClubSubscriptionWriter extends ClubSubscriptionWriter
{
    public const string FUNCTION_NAME = 'amateo_test_record_club_subscription';

    protected function upsert(array $parameters): int
    {
        if (!$this->visibleOnTheApplicationConnection($parameters['clubId'])) {
            return parent::upsert($parameters);
        }
        $this->ensureTheRecordFunctionExists($this->ownerParams(), (string) $this->application()->fetchOne('SELECT session_user'));

        return (int) $this->application()->fetchOne(
            \sprintf('SELECT %s(CAST(:clubId AS uuid), CAST(:seasonYear AS integer), CAST(:planCode AS text), CAST(:origin AS text))', self::FUNCTION_NAME),
            $parameters,
        );
    }

    protected function clubPin(string $clubId): string|false|null
    {
        if (!$this->visibleOnTheApplicationConnection($clubId)) {
            return parent::clubPin($clubId);
        }
        $pin = $this->application()->fetchOne(self::CLUB_PIN_SQL, ['clubId' => $clubId]);

        return null === $pin ? null : (string) $pin;
    }

    protected function seasonStarts(string $clubId): array
    {
        if (!$this->visibleOnTheApplicationConnection($clubId)) {
            return parent::seasonStarts($clubId);
        }
        $application = $this->application();
        $previous = (string) $application->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
        $application->executeStatement('SELECT set_config(\'app.club_id\', :clubId, true)', ['clubId' => $clubId]);
        try {
            return array_map(strval(...), $application->fetchFirstColumn(self::SEASON_STARTS_SQL, ['clubId' => $clubId]));
        } finally {
            $application->executeStatement('SELECT set_config(\'app.club_id\', :previous, true)', ['previous' => $previous]);
        }
    }

    /**
     * Crée la fonction d'écriture une fois par processus, par une connexion propriétaire NEUVE (hors
     * DAMA, en autocommit) : elle doit être visible de la transaction de test en cours.
     *
     * @param array<string, mixed> $ownerParams
     */
    private function ensureTheRecordFunctionExists(array $ownerParams, string $appRole): void
    {
        static $created = false;
        if ($created) {
            return;
        }
        $upsert = str_replace(
            [':clubId', ':seasonYear', ':planCode', ':origin'],
            ['p_club_id', 'p_season_year', 'p_plan_code', 'p_origin'],
            self::UPSERT_SQL,
        );
        $owner = DriverManager::getConnection(array_intersect_key($ownerParams, array_flip(['driver', 'host', 'port', 'user', 'password', 'dbname', 'charset', 'serverVersion'])));
        try {
            $owner->executeStatement(\sprintf(
                'CREATE OR REPLACE FUNCTION public.%s(p_club_id uuid, p_season_year integer, p_plan_code text, p_origin text) RETURNS integer'
                . ' LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp'
                . ' AS $fn$ DECLARE written integer; BEGIN %s; GET DIAGNOSTICS written = ROW_COUNT; RETURN written; END $fn$',
                self::FUNCTION_NAME,
                $upsert,
            ));
            $owner->executeStatement(\sprintf('REVOKE ALL ON FUNCTION public.%s(uuid, integer, text, text) FROM PUBLIC', self::FUNCTION_NAME));
            $owner->executeStatement(\sprintf('GRANT EXECUTE ON FUNCTION public.%s(uuid, integer, text, text) TO %s', self::FUNCTION_NAME, $appRole));
        } finally {
            $owner->close();
        }
        $created = true;
    }

    private function visibleOnTheApplicationConnection(string $clubId): bool
    {
        return false !== $this->application()->fetchOne('SELECT 1 FROM club WHERE id = :clubId', ['clubId' => $clubId]);
    }

    private function application(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function ownerParams(): array
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection->getParams();
    }
}
