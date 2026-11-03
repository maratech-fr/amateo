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
 * c'est le vecteur d'hameçonnage d'un mail « X demande à rejoindre votre club ».
 *
 * RÈGLE (revue de sécurité, PR 1) : LISTE BLANCHE — un nom n'est fait que de lettres (accentuées
 * comprises), d'espaces, de traits d'union et d'apostrophes (droite ou typographique). Un SEUL
 * autre caractère dans le prénom ou le nom (point, virgule, barre, deux-points, arobase,
 * ponctuation… et CHIFFRES — tranché : aucun prénom ni nom réel n'en porte, un chiffre ne sert
 * qu'à fabriquer un domaine ou un faux identifiant) → le nom ENTIER devient « Un demandeur ». On ne
 * nettoie plus mot à mot : la liste noire de formes de domaine laissait passer « x.com, » ou
 * « EVIL.COM! », que Gmail et Outlook rendent cliquables. Un nom démesuré est borné à 80 caractères.
 *
 * Le bout en bout (le nom dans le mail réellement enfilé aux gestionnaires) est prouvé par
 * {@see MembershipRequestNotificationTest::testRequesterNameNeverReachesSubjectOrBody}.
 */
#[Group('phase1')]
final class MailSafeNameTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function hostileNames(): iterable
    {
        // Les quatre formes de la revue : un domaine suivi d'une ponctuation, rendu en lien.
        yield 'domaine + point final' => ['Gagnez', 'amateo-verif.com.'];
        yield 'domaine + virgule' => ['x.com,', 'Martin'];
        yield 'domaine majuscule + exclamation' => ['Gagnez', 'EVIL.COM!'];
        yield 'domaine + parenthèse' => ['Voir', 'x.com)'];
        // Les formes déjà connues, et les caractères tranchés hors liste.
        yield 'domaine nu' => ['Gagnez', 'arnaque.fr'];
        yield 'www' => ['Gagnez', 'www.arnaque.fr'];
        yield 'schéma http' => ['Gagnez', 'http://arnaque.fr'];
        yield 'adresse e-mail' => ['Gagnez', 'contact@arnaque.fr'];
        yield 'barre' => ['Gagnez', 'x/chemin'];
        yield 'deux-points' => ['Gagnez', 'ici:la'];
        yield 'chiffres' => ['Gagnez', 'Martin2024'];
        yield 'prénom seul hostile' => ['http://arnaque.fr', 'Martin'];
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function realNames(): iterable
    {
        yield 'simple' => ['Léa', 'Martin', 'Léa Martin'];
        yield 'trait d\'union' => ['Jean-Pierre', 'Durand', 'Jean-Pierre Durand'];
        yield 'apostrophe droite' => ['Zoé', 'O\'Neil', 'Zoé O\'Neil'];
        yield 'apostrophe typographique' => ['Zoé', 'O’Neil', 'Zoé O’Neil'];
        yield 'majuscule accentuée' => ['Élodie', 'Lefèvre', 'Élodie Lefèvre'];
        yield 'espaces de bord' => ['  Jean-Éric ', ' N\'Diaye ', 'Jean-Éric N\'Diaye'];
        yield 'particule' => ['Anne', 'de La Tour', 'Anne de La Tour'];
    }

    #[DataProvider('realNames')]
    public function testARealNameIsKeptAsIs(string $first, string $last, string $expected): void
    {
        self::assertSame($expected, MailSafeName::of($first, $last));
    }

    #[DataProvider('hostileNames')]
    public function testAnyCharacterOutsideTheWhitelistReplacesTheWholeName(string $first, string $last): void
    {
        self::assertSame('Un demandeur', MailSafeName::of($first, $last), 'un seul caractère hors liste blanche → le nom ENTIER est remplacé, jamais nettoyé mot à mot');
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
