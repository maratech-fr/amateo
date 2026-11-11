<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\MemberReadOnlyDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Promesse (abus) : « Placer automatiquement » est borné PAR CLUB — chaque demande d'un
 * gestionnaire, refusée ou non (409 compris : un clic refusé reste un clic), puise une unité
 * dans le seau `match_placement` du club, et le seau vide répond 429 avec le message métier.
 * Sans ce cap, un club pouvait occuper le solveur (jusqu'à 45 min par run) sans limite.
 *
 * Le cap décidé pour la production : `ClubQuotaTest::testTheMatchPlacementCapIsTheOneWeDecided`.
 * L'exemption du Membre : `MemberReadsAreSideEffectFreeTest::testMemberPlaceIsRefusedWithoutSpendingTheClubBudget`.
 * Seaux remis à zéro par `reset()` ciblé — jamais FLUSHALL.
 */
#[Group('phase1')]
#[Group('integration')]
final class PlacementQuotaTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use MemberReadOnlyDecorTrait;
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testAPlacementRequestSpendsTheClubQuota(): void
    {
        $token = $this->registerManager('pq');
        $key = 'club-' . $this->clubId;
        $this->resetBucket('limiter.match_placement', $key);
        $before = $this->remaining($key);

        try {
            $this->api($token, 'POST', '/api/fixtures/place');
            self::assertNotSame(429, $this->client->getResponse()->getStatusCode(), 'un club neuf a tout son budget de placement');
            self::assertSame($before - 1, $this->remaining($key), 'une demande de placement d\'un gestionnaire puise une unité dans le seau du club');
        } finally {
            $this->resetBucket('limiter.match_placement', $key);
        }
    }

    public function testADrainedClubQuotaRefusesThePlacement(): void
    {
        $token = $this->registerManager('pr');
        $key = 'club-' . $this->clubId;
        $limiter = $this->factory()->create($key);
        $limiter->reset();
        // Draine le seau du club (patron `RegisterTurnstileTest`) plutôt que 20 POST : le limiteur
        // `api` de test (30/min par utilisateur) ne doit pas répondre 429 à sa place.
        while ($limiter->consume(1)->isAccepted()) {
            // vidange
        }

        try {
            $this->api($token, 'POST', '/api/fixtures/place');
            self::assertResponseStatusCodeSame(429, 'seau du club vide : le placement est refusé avant le contrôleur');
            self::assertMatchesRegularExpression(
                '/Quota du club atteint pour cette opération\. Réessayez dans \d+ minute\(s\)\./u',
                json_encode(json_decode((string) $this->client->getResponse()->getContent(), true), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                'le refus porte le message métier du quota',
            );
        } finally {
            $limiter->reset();
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function factory(): RateLimiterFactory
    {
        $factory = self::getContainer()->get('limiter.match_placement');
        \assert($factory instanceof RateLimiterFactory);

        return $factory;
    }

    private function remaining(string $key): int
    {
        return $this->factory()->create($key)->consume(0)->getRemainingTokens();
    }
}
