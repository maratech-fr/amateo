<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\OrphanAccountMailBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-301 — rendu du préavis « compte sans club ». Deux variantes, un texte sobre,
 * la date d'échéance au format FR, le nom produit, le lien /login quand une base
 * front est configurée (sinon OMIS, jamais un chemin nu non cliquable), et AUCUN
 * identifiant interne (gardé globalement par PublicTextIsFreeOfInternalIdentifiersTest).
 */
#[Group('phase1')]
final class OrphanAccountMailBuilderTest extends TestCase
{
    private const DEADLINE = '2026-11-03';

    public function testAccessRemovedNoticeNamesTheDeadlineAndTheProduct(): void
    {
        $body = (string) new OrphanAccountMailBuilder('https://app.example.test')
            ->buildAccessRemoved('x@y.fr', 'Camille', new DateTimeImmutable(self::DEADLINE))
            ->getTextBody();

        self::assertStringContainsString('Bonjour Camille,', $body);
        self::assertStringContainsString('Vous n\'avez plus accès à aucun club sur Amateo.', $body);
        self::assertStringContainsString('supprimé le 03/11/2026', $body);
        self::assertStringContainsString('réinscrire', $body);
        self::assertStringContainsString('https://app.example.test/login', $body);
    }

    public function testClubDeletedNoticeSaysTheSpaceWasRemoved(): void
    {
        $body = (string) new OrphanAccountMailBuilder('https://app.example.test')
            ->buildClubDeleted('x@y.fr', 'Camille', new DateTimeImmutable(self::DEADLINE))
            ->getTextBody();

        self::assertStringContainsString('L\'espace Amateo de votre club a été supprimé.', $body);
        self::assertStringContainsString('supprimé le 03/11/2026', $body);
    }

    public function testTheLoginLinkIsOmittedWhenNoFrontendBaseIsSet(): void
    {
        $body = (string) new OrphanAccountMailBuilder('')
            ->buildAccessRemoved('x@y.fr', 'Camille', new DateTimeImmutable(self::DEADLINE))
            ->getTextBody();

        self::assertStringNotContainsString('/login', $body);
    }
}
