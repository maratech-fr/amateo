<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FeedbackMailBuilder;
use App\Tests\AssertsActionMailCta;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PROMESSE (D1) : l'e-mail « signalement traité » porte un bouton « Voir les nouveautés » vers le
 * lien `/nouveautes` du corps ; l'ACCUSÉ de réception, lui, ne porte AUCUN lien d'action (exempt —
 * message rassurant à texte constant). ROUGE tant que {@see FeedbackMailBuilder::buildTreated} ne
 * pose pas l'en-tête CTA.
 */
#[Group('phase1')]
final class FeedbackMailBuilderTest extends TestCase
{
    use AssertsActionMailCta;

    public function testTreatedNoticeCarriesTheNewsCta(): void
    {
        $email = new FeedbackMailBuilder('https://app.example.test')->buildTreated('x@y.fr');

        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/nouveautes', 'Voir les nouveautés');
    }

    public function testTreatedNoticeHasNoCtaWithoutFrontendBase(): void
    {
        $email = new FeedbackMailBuilder('')->buildTreated('x@y.fr');

        $this->assertCarriesNoCtaHeaders($email);
    }

    public function testAcknowledgementIsExemptAndCarriesNoCta(): void
    {
        // L'accusé « bien reçu » n'a aucune action à proposer : jamais de bouton (exempt).
        $email = new FeedbackMailBuilder('https://app.example.test')->build('x@y.fr');

        $this->assertCarriesNoCtaHeaders($email);
    }
}
