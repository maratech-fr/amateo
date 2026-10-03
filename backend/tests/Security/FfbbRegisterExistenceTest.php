<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\StartsFreshBrowserSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR d'axe « auth & memberships » (§7.1) — SEC-27 : vérification de l'existence
 * fédérale du code FFBB à l'inscription, chemin CRÉATION uniquement.
 *
 * Gardée par le flag d'environnement `FFBB_REGISTER_EXISTENCE_CHECK` (patron Turnstile :
 * le flag est posé AVANT le boot, `%env(bool:…)%` est résolu au runtime). Ce que ce garde
 * tient :
 *  - flag OFF (défaut test/dev) → un code SYNTHÉTIQUE passe (régime Behat/démos) ;
 *  - flag ON + code CONNU de la fédération (stub ARA0000001) → le compte se crée (202) ;
 *  - flag ON + code au bon FORMAT mais inconnu → 400 « inconnu de la fédération », AUCUN
 *    compte créé ;
 *  - flag ON + FORMAT invalide → 400 « n'est pas valide », avant tout appel réseau.
 *
 * Les 400 sont keyés sur l'ARA (donnée publique FFBB), jamais sur l'e-mail → ils ne
 * rouvrent pas l'oracle d'énumération A3 que le register ferme (202 identique partout).
 */
#[Group('phase1')]
#[Group('integration')]
final class FfbbRegisterExistenceTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private static int $ipCounter = 0;

    public function testDisabledByDefaultAllowsSyntheticCode(): void
    {
        $client = self::createClient();
        [$status] = $this->register($client, 'synthetic@ffbb.fr', 'SYNTH1');

        self::assertSame(202, $status, 'flag OFF : un code synthétique passe (régime Behat/démos)');
    }

    public function testKnownFederationCodePasses(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status] = $this->register($client, 'known@ffbb.fr', FfbbHttpClientStub::CLUB_CODE);

        self::assertSame(202, $status, 'code connu de la fédération → l\'inscription se poursuit');
        self::assertSame(1, $this->em()->getRepository(User::class)->count(['email' => 'known@ffbb.fr']), 'le compte a été créé');
    }

    public function testUnknownFederationCodeIsRejected(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status, $body] = $this->register($client, 'unknown@ffbb.fr', 'ARA9999998');

        self::assertSame(400, $status);
        // Le corps JSON échappe l'accent (é) → on décode avant d'asserter.
        self::assertStringContainsString('inconnu de la fédération', (string) (json_decode($body, true)['error'] ?? ''));
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'unknown@ffbb.fr']), 'aucun compte créé pour un code inconnu');
    }

    public function testInvalidFormatIsRejected(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status, $body] = $this->register($client, 'badformat@ffbb.fr', 'NOTAFFBBCODE');

        self::assertSame(400, $status);
        // Le corps JSON échappe l'apostrophe (') → on décode avant d'asserter.
        self::assertStringContainsString('n\'est pas valide', (string) (json_decode($body, true)['error'] ?? ''));
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'badformat@ffbb.fr']), 'aucun compte créé pour un format invalide');
    }

    protected function tearDown(): void
    {
        $this->clearFlag();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: string} [status, raw body]
     */
    private function register(KernelBrowser $client, string $email, string $ara): array
    {
        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Existence', 'ara' => $ara, 'club_name' => 'Club Existence', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        return [$client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent()];
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function nextIp(): string
    {
        $n = self::$ipCounter++;

        return \sprintf('10.44.%d.%d', intdiv($n, 254), $n % 254 + 1);
    }

    private function setFlag(string $value): void
    {
        $_SERVER['FFBB_REGISTER_EXISTENCE_CHECK'] = $value;
        $_ENV['FFBB_REGISTER_EXISTENCE_CHECK'] = $value;
        putenv('FFBB_REGISTER_EXISTENCE_CHECK=' . $value);
    }

    private function clearFlag(): void
    {
        // RESTAURE le régime de test (`false`), ne PAS unset : le défaut de conteneur
        // de `env(FFBB_REGISTER_EXISTENCE_CHECK)` est `true` (fail-closed prod), donc un
        // unset ferait voir `true` aux tests suivants (fuite inter-tests). `.env.test`
        // pose `false` au bootstrap ; on y revient.
        $this->setFlag('false');
    }
}
