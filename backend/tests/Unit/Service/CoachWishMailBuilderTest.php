<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Club;
use App\Entity\CoachWishCampaign;
use App\Mail\EmailTemplateRenderer;
use App\Service\CoachWishMailBuilder;
use App\Service\ProductIdentity;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rendu des emails de collecte (feature #10, lot C3 ; expéditeur personnalisé + CTA D1). Point
 * sensible (revue #7) : sans base front configurée, on OMET le lien plutôt que d'envoyer un
 * chemin nu non cliquable.
 */
#[Group('phase1')]
final class CoachWishMailBuilderTest extends TestCase
{
    public function testCoachLinkEmailEmbedsTheAbsoluteLinkWhenBaseIsSet(): void
    {
        $builder = new CoachWishMailBuilder('https://app.example.test');
        $body = $builder->buildCoachLink('c@x.fr', 'Maxime', 'Club', $this->campaign(), 'Toussaint', str_repeat('a', 64), 'Gérard')->getTextBody();

        self::assertStringContainsString('https://app.example.test/doleances/' . str_repeat('a', 64), (string) $body);
    }

    public function testCoachLinkEmailOmitsTheLinkWhenBaseIsEmpty(): void
    {
        $builder = new CoachWishMailBuilder('');
        $body = (string) $builder->buildCoachLink('c@x.fr', 'Maxime', 'Club', $this->campaign(), 'Toussaint', str_repeat('a', 64), 'Gérard')->getTextBody();

        // Aucun chemin nu « /doleances/… » non cliquable ne doit fuiter dans le corps.
        self::assertStringNotContainsString('/doleances/', $body);
    }

    public function testCoachLinkBodyUsesFormulaAWithSenderAndClubLabel(): void
    {
        // Formule A (D1) : « {Prénom gestionnaire} ({libellé = nom court}) prépare le planning … ».
        $club = (new Club)->setName('Basket Club de la Côte Léman')->setShortName('BCCL');
        $builder = new CoachWishMailBuilder('https://app.example.test');
        $body = (string) $builder->buildCoachLink('c@x.fr', 'Maxime', 'Nom long', $this->campaign(), 'Toussaint', str_repeat('a', 64), 'Gérard', club: $club)->getTextBody();

        self::assertStringContainsString('Gérard (BCCL) prépare le planning de « Toussaint » et a besoin de vos souhaits d\'entraînement.', $body);
        // Le reste du texte est conservé (date limite + « C'est un souhait… »).
        self::assertStringContainsString('Merci de répondre avant le 30/06/2027', $body);
        self::assertStringContainsString('C\'est un souhait, pas un engagement : le club arbitre ensuite.', $body);
    }

    public function testCoachLinkSenderDisplayNameIsPrenomClubViaProduct(): void
    {
        $club = (new Club)->setName('Nom long')->setShortName('BCCL');
        $builder = new CoachWishMailBuilder('https://app.example.test', productIdentity: new ProductIdentity(productName: 'Amateo'));
        $from = $builder->buildCoachLink('c@x.fr', 'Maxime', 'Nom long', $this->campaign(), 'Toussaint', str_repeat('a', 64), 'Gérard', club: $club)->getFrom();

        self::assertCount(1, $from);
        self::assertSame('no-reply@amateo.app', $from[0]->getAddress(), 'l\'adresse ne change pas');
        self::assertSame('Gérard (BCCL) via Amateo', $from[0]->getName());
    }

    public function testCoachLinkCarriesCtaHeadersWithTheLink(): void
    {
        $builder = new CoachWishMailBuilder('https://app.example.test');
        $headers = $builder->buildCoachLink('c@x.fr', 'Maxime', 'Club', $this->campaign(), 'Toussaint', str_repeat('a', 64), 'Gérard')->getHeaders();

        self::assertSame('https://app.example.test/doleances/' . str_repeat('a', 64), $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER));
        self::assertSame('Donner mes disponibilités', $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
    }

    public function testNoCtaHeadersWhenTheLinkIsOmitted(): void
    {
        $builder = new CoachWishMailBuilder('');
        $headers = $builder->buildCoachLink('c@x.fr', 'Maxime', 'Club', $this->campaign(), 'Toussaint', str_repeat('a', 64), 'Gérard')->getHeaders();

        self::assertFalse($headers->has(EmailTemplateRenderer::CTA_URL_HEADER), 'pas de bouton sans lien cliquable');
    }

    public function testFromDisplayNameWithCrlfCannotInjectHeaders(): void
    {
        // Prénom gestionnaire ET nom court sont des données NON fiables : un CRLF/`<>`/`"`
        // n'ouvre jamais une ligne d'en-tête (Address strippe CR/LF et encode le reste).
        $club = (new Club)->setName('Club Normal')->setShortName('BC"><script>');
        $builder = new CoachWishMailBuilder('https://app.example.test');
        $email = $builder->buildCoachLink(
            'c@x.fr',
            'Maxime',
            'Club',
            $this->campaign(),
            'Toussaint',
            str_repeat('a', 64),
            "Gérard\r\nBcc: victime@evil.test\r\nX-Injected: 1",
            club: $club,
        );

        $from = $email->getFrom();
        self::assertCount(1, $from, 'un seul expéditeur');
        self::assertSame('no-reply@amateo.app', $from[0]->getAddress(), 'l\'adresse ne change jamais');
        // Address a stripé les CR/LF du nom à la construction → aucune ligne injectée.
        self::assertStringNotContainsString("\n", $from[0]->getName());
        self::assertStringNotContainsString("\r", $from[0]->getName());
        self::assertFalse($email->getHeaders()->has('Bcc'), 'aucun Bcc injecté');
        self::assertFalse($email->getHeaders()->has('X-Injected'), 'aucun en-tête arbitraire injecté');
        // Le rendu RFC 2047 de l'en-tête From ne contient aucune coupure qui ouvre un en-tête.
        $rendered = $email->getHeaders()->get('From')->getBodyAsString();
        self::assertStringNotContainsString("\r\nBcc:", $rendered);
        self::assertStringNotContainsString("\nBcc:", $rendered);
    }

    public function testMailsCarryOnlyNamesAndCounters(): void
    {
        // D2 décision 7 : le DÉTAIL (séances souhaitées, jours, partenaires de mutualisation,
        // commentaires) reste HORS des e-mails — digest et récap final ne portent que des NOMS
        // et des COMPTEURS. Garde de non-régression : aucun vocabulaire de détail ne doit
        // apparaître, même si une future évolution ajoutait une mutualisation au walker.
        $builder = new CoachWishMailBuilder('https://app.example.test');
        $digest = (string) $builder->buildDigest('m@x.fr', 'Club', 'Toussaint', ['Anna Martin'], ['Anna Martin'], ['Bob Durand'])->getTextBody();
        $recap = (string) $builder->buildFinalRecap('m@x.fr', 'Club', 'Toussaint', ['Anna Martin'], ['Bob Durand'], 3)->getTextBody();

        foreach ([$digest, $recap] as $body) {
            // Les noms et les compteurs voyagent.
            self::assertStringContainsString('Anna Martin', $body);
            self::assertStringContainsString('Bob Durand', $body);
            // Mais AUCUN détail de doléance ni de mutualisation.
            self::assertStringNotContainsString('mutualis', $body, 'aucun détail de mutualisation dans l’e-mail');
            self::assertStringNotContainsString('indispo', $body);
            self::assertStringNotContainsString('souhait', $body);
            self::assertStringNotContainsString('partenaire', $body);
        }
        // Le digest porte bien un compteur « Ont répondu (N) ».
        self::assertStringContainsString('Ont répondu (1)', $digest);
        // Le récap final porte bien le compteur « à traiter » (un nombre, pas le détail).
        self::assertStringContainsString('à traiter : 3', $recap);
    }

    private function campaign(): CoachWishCampaign
    {
        return (new CoachWishCampaign)->setDeadline(new DateTimeImmutable('2027-06-30'));
    }
}
