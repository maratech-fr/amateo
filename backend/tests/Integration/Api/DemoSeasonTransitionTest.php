<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Service\SeasonResolver;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-4 (fondateur 2026-08-07) — « pour un compte démo j'ai un abonnement
 * illimité donc je peux changer de saison » : le gate P1-5 (bascule réservée
 * aux saisons payées) est EXEMPTÉ pour un club de démonstration.
 *
 * MÊME club dans les deux cas — seule la variable testée change (le flag) :
 * sans lui le 409 tombe (le gate P1-5 tient, témoin), avec lui la bascule
 * passe sans aucun paiement.
 */
#[Group('integration')]
final class DemoSeasonTransitionTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testDemoClubTransitionsWithoutPaymentAndRealClubStillCannot(): void
    {
        $token = $this->register();
        $me = $this->request('GET', '/api/me', $token);
        $seasonId = $me['seasons'][0]['id'];
        $clubId = $me['club']['id'];
        $this->pinInPreparationWindow();

        // TÉMOIN — club réel, rien payé : le gate P1-5 refuse. Sans ce cas, le
        // vert du suivant pourrait venir d'un gate globalement mort.
        $refused = $this->request('POST', '/api/seasons/' . $seasonId . '/transition', $token);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('n\'est pas réglée', (string) ($refused['error'] ?? ''), 'le témoin refuse pour le paiement, pas pour une autre précondition');

        // Le flag démo — posé en SQL : aucun parcours utilisateur ne peut le faire,
        // c'est la commande support qui le pose en prod (app:demo:create).
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setIsDemo(true);
        $em->flush();

        $this->request('POST', '/api/seasons/' . $seasonId . '/transition', $token);
        self::assertResponseStatusCodeSame(201, 'abonnement illimité : un club démo bascule sans paiement');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        // Jamais une horloge figée laissée aux tests suivants.
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /**
     * La saison suivante ne se prépare qu'à partir du 1er mai : l'horloge se fige au 15 mai de la
     * saison en cours, pour les deux appels (seul le drapeau démo change entre eux), APRÈS
     * l'inscription (qui vit à l'heure réelle). La date suivie par l'horloge simulée d'un club démo
     * est la promesse de `features/la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`.
     */
    private function pinInPreparationWindow(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable(\sprintf('%d-05-15T09:00:00', SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1)));
    }

    /** Même idiome que SeasonClockThreadingTest : register + verify → JWT. */
    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'dmo' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'D', 'lastName' => 'Demo', 'ara' => strtoupper($suffix), 'club_name' => 'Club Demo Transition', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        return $token;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $uri, string $token): array
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
