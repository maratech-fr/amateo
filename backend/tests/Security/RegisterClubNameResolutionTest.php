<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\StartsFreshBrowserSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR d'axe « auth & memberships » (§7.1) — l'inscription RE-RÉSOUT elle-même le nom du club
 * auprès de la FFBB et IGNORE TOUJOURS le `club_name` soumis quand la fédération connaît le code.
 * Le nom tapé n'est qu'un repli : dès que `FfbbClubDirectory::lookupIdentity` répond `found`, c'est
 * le nom FÉDÉRAL qui est porté par le jeton de vérification (donc par la demande de création, puis
 * par le club). Reprise d'un club existant : AUCUN appel FFBB (inchangé).
 *
 * Gardé par le flag d'environnement `FFBB_REGISTER_EXISTENCE_CHECK` (patron Turnstile / existence) :
 * la re-résolution est le régime PROD (flag ON), inerte en dev/Behat & démos (flag OFF, codes
 * synthétiques → le `club_name` soumis reste le nom).
 *
 * Abus NOMMÉS du cadrage (améliorations légères #1) :
 *  - A-nom-menteur : `club_name` = « Mon Faux Club » + un ARA trouvé → le jeton porte le nom FFBB,
 *    jamais le mensonge ;
 *  - A-oracle : la réponse du register ne révèle JAMAIS le nom résolu (202 byte-identique partout),
 *    et un ARA malformé (valide pour le register, hors norme fédérale) ne déclenche AUCUN appel
 *    sortant (panne transport armée → resterait un refus FRANC, jamais `unavailable`) ;
 *  - A-ffbb-muette : FFBB muette (transport en échec) + nom VIDE → 400 « nom requis », jamais 500 ;
 *  - A-énumération : la réponse 202 ne dépend JAMAIS de l'existence d'un compte (e-mail frais vs
 *    e-mail déjà connu → corps identique). Le rate-limit IP du register, prioritaire sur tout ce
 *    chemin, est DÉJÀ prouvé par {@see RegisterTurnstileTest::testRegisterRateLimitStillFiresBeforeTurnstile}
 *    (on ne le re-prouve pas ici).
 *
 * ⚠ Redis des limiteurs : chaque requête vise une IP DISTINCTE (jamais FLUSHALL). APP_ENV=test.
 */
#[Group('phase1')]
#[Group('integration')]
final class RegisterClubNameResolutionTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private static int $ipCounter = 0;

    /**
     * A-nom-menteur — un ARA CONNU de la fédération + un `club_name` menteur : le serveur re-résout
     * le nom lui-même et IGNORE le nom soumis. Observé au plus bas coût sur le jeton de vérification,
     * qui PORTE le nom qui deviendra celui du club (jeton → demande de création → approbation).
     */
    public function testFoundClubResolvesFfbbNameAndIgnoresSubmittedClubName(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status] = $this->register($client, 'liar@ffbb.fr', FfbbHttpClientStub::CLUB_CODE, 'Mon Faux Club');

        self::assertSame(202, $status);
        self::assertSame(
            FfbbHttpClientStub::CLUB_NAME,
            $this->intentClubNameFor('liar@ffbb.fr'),
            'un ARA trouvé → le jeton porte le nom FFBB, jamais le club_name soumis',
        );
    }

    /**
     * A-oracle (fuite du nom) — la réponse du register reste le 202 byte-identique de partout : elle
     * ne renvoie NI le nom résolu, NI la ville. Le front ne lit l'identité QUE via `club-lookup`.
     */
    public function testRegisterResponseNeverRevealsResolvedName(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status, $body] = $this->register($client, 'oracle@ffbb.fr', FfbbHttpClientStub::CLUB_CODE, 'Mon Faux Club');

        self::assertSame(202, $status);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame(['status'], array_keys($decoded), 'la réponse ne porte QUE son statut');
        self::assertSame('verification_pending', $decoded['status'] ?? null);
        self::assertStringNotContainsString(FfbbHttpClientStub::CLUB_NAME, $body, 'le nom résolu ne fuit jamais dans la réponse');
        self::assertStringNotContainsString(FfbbHttpClientStub::CLUB_CITY, $body, 'la ville ne fuit jamais dans la réponse');
    }

    /**
     * A-oracle (pas d'appel sortant) — un code qui passe la validation du register (`^[A-Z0-9]{3,20}$`)
     * mais hors norme fédérale (`^[A-Z]{2,4}\d{7}$`) : avec la panne transport ARMÉE, il est refusé
     * FRANCHEMENT (400) sans jamais toucher la FFBB. S'il avait appelé la recherche, la panne armée
     * l'aurait dégradé en `unavailable` (non bloquant) → l'inscription serait passée (202).
     */
    public function testMalformedAraTriggersNoOutboundCall(): void
    {
        $this->setFlag('true');
        FfbbHttpClientStub::$failSearch = true;
        $client = self::createClient();
        [$status] = $this->register($client, 'malformed@ffbb.fr', 'NOTAFFBBCODE', 'Club Malformé');

        self::assertSame(400, $status, 'un code hors norme fédérale est refusé sans sortir vers la FFBB');
        self::assertSame(0, $this->userCount('malformed@ffbb.fr'), 'aucun compte créé pour un code malformé');
    }

    /**
     * A-ffbb-muette — FFBB muette (transport en échec) + nom VIDE : la demande de création exige un
     * nom, donc 400 « nom requis » (texte stable), JAMAIS une 500 née de la panne de la fédération.
     */
    public function testFfbbSilentWithEmptyNameReturns400NotA500(): void
    {
        $this->setFlag('true');
        FfbbHttpClientStub::$failSearch = true;
        $client = self::createClient();
        [$status, $body] = $this->register($client, 'silent@ffbb.fr', FfbbHttpClientStub::CLUB_CODE, '');

        self::assertSame(400, $status, 'une FFBB muette ne doit jamais devenir une 500');
        // Le corps JSON peut échapper les accents : on décode avant d'asserter le texte stable.
        self::assertStringContainsString('nom du club est requis', (string) (json_decode($body, true)['error'] ?? ''));
        self::assertSame(0, $this->userCount('silent@ffbb.fr'), 'aucun compte créé quand le nom manque');
    }

    /**
     * A-énumération — la réponse 202 ne dépend JAMAIS de l'existence d'un compte : un e-mail frais et
     * le MÊME e-mail déjà connu rendent un corps byte-identique. La re-résolution FFBB, keyée sur
     * l'ARA (donnée publique), ne rouvre pas l'oracle que le register ferme. (Le rate-limit IP
     * prioritaire est tenu par {@see RegisterTurnstileTest::testRegisterRateLimitStillFiresBeforeTurnstile}.).
     */
    public function testResponseDoesNotDependOnAccountExistence(): void
    {
        $this->setFlag('true');
        $client = self::createClient();

        [$firstStatus, $firstBody] = $this->register($client, 'enum@ffbb.fr', FfbbHttpClientStub::CLUB_CODE, 'Mon Faux Club');
        // Deuxième inscription du MÊME e-mail (désormais connu, non vérifié) depuis une AUTRE IP.
        [$secondStatus, $secondBody] = $this->register($client, 'enum@ffbb.fr', FfbbHttpClientStub::CLUB_CODE, 'Mon Faux Club');

        self::assertSame(202, $firstStatus);
        self::assertSame(202, $secondStatus, 'un e-mail connu rend le même 202 qu\'un e-mail frais');
        self::assertSame($firstBody, $secondBody, 'rien dans le corps ne distingue un compte existant d\'un compte neuf');
    }

    protected function setUp(): void
    {
        FfbbHttpClientStub::$failSearch = false;
    }

    protected function tearDown(): void
    {
        FfbbHttpClientStub::$failSearch = false;
        $this->clearFlag();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: string} [status, raw body]
     */
    private function register(KernelBrowser $client, string $email, string $ara, string $clubName): array
    {
        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Resolve', 'ara' => $ara, 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        return [$client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent()];
    }

    /** Le nom de club PORTÉ par le jeton de vérification de ce compte (l'intention de création). */
    private function intentClubNameFor(string $email): ?string
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        self::assertInstanceOf(User::class, $user, 'le compte doit avoir été créé');
        $token = $this->em()->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(EmailVerificationToken::class, $token, 'un jeton de vérification doit avoir été émis');

        return $token->getClubName();
    }

    private function userCount(string $email): int
    {
        return $this->em()->getRepository(User::class)->count(['email' => strtolower($email)]);
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

        return \sprintf('10.46.%d.%d', intdiv($n, 254), $n % 254 + 1);
    }

    private function setFlag(string $value): void
    {
        $_SERVER['FFBB_REGISTER_EXISTENCE_CHECK'] = $value;
        $_ENV['FFBB_REGISTER_EXISTENCE_CHECK'] = $value;
        putenv('FFBB_REGISTER_EXISTENCE_CHECK=' . $value);
    }

    private function clearFlag(): void
    {
        // RESTAURE le régime de test (`false`), ne PAS unset : le défaut de conteneur est `true`
        // (fail-closed prod), un unset le ferait voir aux tests suivants (fuite inter-tests).
        $this->setFlag('false');
    }
}
