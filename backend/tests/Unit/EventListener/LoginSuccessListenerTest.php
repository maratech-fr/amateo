<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\LoginSuccessListener;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * La trace de connexion s'écrit au plus une fois par jour calendaire Europe/Paris et par
 * utilisateur, et l'adresse exclue (`app.activity_excluded_emails`, normalisée : trim +
 * minuscules) ne marque jamais ses clubs — sa trace RGPD (`app_user`) avance toujours.
 *
 * Unitaire à horloge figée : seul ce niveau pose « minuit Paris » à volonté. La paire
 * 22:30 / 23:30 UTC prouve le FUSEAU dans les deux sens (un throttle au jour UTC rougit l'un ou
 * l'autre). Jumeau intégré, à l'horloge réelle et sur la vraie base :
 * `Security/ClubActivityTraceTest` (c1, c2, h, i).
 */
#[Group('phase1')]
final class LoginSuccessListenerTest extends TestCase
{
    /** @var list<string> les requêtes SQL écrites, dans l'ordre */
    private array $statements = [];

    public function testWithoutExclusionBothTracesAreWritten(): void
    {
        $this->login(email: 'x@y.test', lastLoginAt: null, now: '2026-11-10 08:00:00', excluded: []);

        self::assertSame(['app_user', 'club'], $this->tables(), 'liste vide : trace RGPD ET trace club');
    }

    public function testTheExcludedAddressWritesOnlyItsOwnTraceWhateverTheCase(): void
    {
        $this->login(email: 'x@y.test', lastLoginAt: null, now: '2026-11-10 08:00:00', excluded: ['X@Y.test']);

        self::assertSame(['app_user'], $this->tables(), 'adresse exclue (casse ignorée) : la trace RGPD seule, le club ne bouge pas');
    }

    public function testTheExclusionListIsTrimmed(): void
    {
        $this->login(email: 'a@b.test', lastLoginAt: null, now: '2026-11-10 08:00:00', excluded: ['  a@b.test ']);

        self::assertSame(['app_user'], $this->tables(), 'espaces de la config retirés');
    }

    /** 9 nov. 23:30 Paris puis 10 nov. 00:30 Paris : une heure d'écart mais un nouveau jour Paris → écriture. */
    public function testANewParisDayWritesEvenOneHourLater(): void
    {
        $this->login(email: 'x@y.test', lastLoginAt: '2026-11-09 22:30:00', now: '2026-11-09 23:30:00', excluded: []);

        self::assertSame(['app_user', 'club'], $this->tables(), 'nouveau jour Paris (le jour UTC, lui, n\'a pas changé)');
    }

    /** 10 nov. 00:30 Paris puis 10 nov. 01:30 Paris : même jour Paris → zéro écriture, alors que le jour UTC a changé. */
    public function testTheSameParisDayWritesNothingEvenAcrossUtcMidnight(): void
    {
        $this->login(email: 'x@y.test', lastLoginAt: '2026-11-09 23:30:00', now: '2026-11-10 00:30:00', excluded: []);

        self::assertSame([], $this->tables(), 'même jour Paris : aucune écriture (le jour UTC, lui, a changé)');
    }

    /**
     * @param list<string> $excluded
     * @param string|null  $lastLoginAt instant UTC
     * @param string       $now         instant UTC
     */
    private function login(string $email, ?string $lastLoginAt, string $now, array $excluded): void
    {
        $user = (new User)->setEmail($email)->setFirstName('T')->setLastName('R');
        $user->setId('11111111-1111-4111-8111-111111111111');
        $user->setLastLoginAt(null === $lastLoginAt ? null : new DateTimeImmutable($lastLoginAt . ' UTC'));

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;

            return 1;
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $listener = new LoginSuccessListener($entityManager, new MockClock(new DateTimeImmutable($now . ' UTC')), $excluded);
        $listener(new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($email, static fn (): User => $user)),
            $this->createMock(TokenInterface::class),
            new Request,
            null,
            'api',
        ));
    }

    /** @return list<string> la table visée par chaque UPDATE, dans l'ordre */
    private function tables(): array
    {
        return array_map(static function (string $sql): string {
            self::assertMatchesRegularExpression('/^UPDATE (\w+) /', $sql);
            preg_match('/^UPDATE (\w+) /', $sql, $match);

            return $match[1];
        }, $this->statements);
    }
}
