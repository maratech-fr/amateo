<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\MailSafeName;
use App\Tests\Security\MembershipRequestNotificationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PROMESSE (chantier Membre, PR 1) : le nom qu'un inconnu saisit à l'inscription ne fait jamais
 * entrer un lien, une adresse ou un domaine dans un mail envoyé à un club (sujet compris) —
 * c'est le vecteur d'hameçonnage d'un mail « X demande à rejoindre votre club ». Un nom réduit à
 * rien devient « Un demandeur » ; un nom démesuré est borné à 80 caractères.
 *
 * Le bout en bout (le nom nettoyé dans le mail réellement enfilé aux gestionnaires) est prouvé par
 * {@see MembershipRequestNotificationTest::testRequesterNameNeverReachesSubjectOrBody}.
 */
#[Group('phase1')]
final class MailSafeNameTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function hostileNames(): iterable
    {
        yield 'domaine nu en nom' => ['Gagnez', 'arnaque.fr'];
        yield 'www' => ['Gagnez', 'www.arnaque.fr'];
        yield 'schéma http' => ['Gagnez', 'http://arnaque.fr'];
        yield 'adresse e-mail' => ['Gagnez', 'contact@arnaque.fr'];
    }

    public function testAnOrdinaryNameIsKeptAsIs(): void
    {
        self::assertSame('Léa Martin', MailSafeName::of('Léa', 'Martin'));
        self::assertSame('Jean-Éric N\'Diaye', MailSafeName::of('  Jean-Éric ', ' N\'Diaye '));
    }

    #[DataProvider('hostileNames')]
    public function testNoLinkAddressOrDomainSurvives(string $first, string $last): void
    {
        $safe = MailSafeName::of($first, $last);

        self::assertStringNotContainsString('://', $safe);
        self::assertStringNotContainsString('www.', $safe);
        self::assertStringNotContainsString('@', $safe);
        self::assertStringNotContainsString('arnaque', $safe, 'le domaine final est retiré');
        self::assertSame('Gagnez', $safe, 'le reste du nom est gardé');
    }

    public function testANameReducedToNothingBecomesAGenericLabel(): void
    {
        self::assertSame('Un demandeur', MailSafeName::of('www.arnaque.fr', 'contact@arnaque.fr'));
        self::assertSame('Un demandeur', MailSafeName::of('', ''));
        self::assertSame('Un demandeur', MailSafeName::of('   ', ' '));
    }

    public function testAnOversizedNameIsBoundedTo80Characters(): void
    {
        $safe = MailSafeName::of(str_repeat('A', 60), str_repeat('B', 60));

        self::assertLessThanOrEqual(80, mb_strlen($safe));
        self::assertStringStartsWith('AAAA', $safe);
    }
}
