<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\ClubCreationRequest;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Service\ClubApprovalService;
use App\Service\EmailVerifier;
use App\Tests\AssertsActionMailCta;
use App\Tests\Double\FfbbHttpClientStub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * PROMESSE (D1) — les e-mails du flux d'approbation de club portent leur bouton d'action :
 *  - la DEMANDE au mail institutionnel FFBB, et son RAPPEL, portent « Examiner la demande » vers
 *    `/club-approval/{token}` ({@see ClubApprovalService::sendApprovalEmail}, foyer bi-usage
 *    demande/rappel) ;
 *  - la notification d'APPROBATION au demandeur porte « Me connecter » vers `/login` ;
 *  - la notification de REFUS est EXEMPTE (aucune action à proposer) → aucun bouton.
 *
 * WebTestCase : on capte le clone SIGNÉ à l'enfilage (HTML rendu, en-têtes CTA encore présents),
 * d'où l'assertion bouton complète ({@see assertCtaButtonRendered}, jumeau d'
 * {@see IdentityEmailCtaButtonTest::assertCtaButton}). ROUGE tant que les producteurs ne posent
 * pas l'en-tête CTA.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubApprovalEmailCtaTest extends WebTestCase
{
    use AssertsActionMailCta;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testApprovalRequestEmailCarriesTheReviewButton(): void
    {
        $this->registerUnknownAraAndVerify('demande-' . uniqid() . '@t.fr', 'Club Demande');

        $email = $this->mailBySubjectPart('demande à créer');
        $link = $this->linkIn($email, 'club-approval');
        $this->assertCtaButtonRendered($email, $link, 'Examiner la demande');
    }

    public function testApprovalReminderEmailCarriesTheReviewCta(): void
    {
        $userId = $this->registerUnknownAraAndVerify('rappel-' . uniqid() . '@t.fr', 'Club Rappel');

        $request = self::getContainer()->get(ClubCreationRequestRepository::class)->findPendingByUser($userId);
        self::assertInstanceOf(ClubCreationRequest::class, $request);
        $requester = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $requester);

        // Le RAPPEL réutilise le même producteur que la demande (seul le sujet change).
        self::getContainer()->get(ClubApprovalService::class)->sendApprovalEmail($request, $requester, reminder: true);

        $reminder = $this->mailBySubjectPart('Rappel —');
        $link = $this->linkIn($reminder, 'club-approval');
        $this->assertCtaButtonRendered($reminder, $link, 'Examiner la demande');
    }

    public function testApprovedNotificationCarriesTheLoginButton(): void
    {
        $userId = $this->registerUnknownAraAndVerify('ok-' . uniqid() . '@t.fr', 'Club Approuve');
        $token = $this->tokenOf($userId);

        $this->client->request('POST', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseIsSuccessful();

        $email = $this->mailBySubjectPart('est prêt');
        $link = $this->linkIn($email, 'login');
        $this->assertCtaButtonRendered($email, $link, 'Me connecter');
    }

    public function testRefusalNotificationIsExemptAndCarriesNoButton(): void
    {
        $userId = $this->registerUnknownAraAndVerify('ko-' . uniqid() . '@t.fr', 'Club Refuse');
        $token = $this->tokenOf($userId);

        $this->client->request('POST', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'refuse']));
        self::assertResponseIsSuccessful();

        $email = $this->mailBySubjectPart('refusée');
        $this->assertCarriesNoCtaHeaders($email);
        self::assertStringNotContainsString('/login', (string) $email->getHtmlBody(), 'aucun bouton d\'action dans un refus');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // L'ARA du stub FFBB est partagé entre suites : purge avant pour un décor propre.
        $this->em->getConnection()->executeStatement('DELETE FROM club WHERE ffbb_club_code = :ara', ['ara' => FfbbHttpClientStub::CLUB_CODE]);
    }

    /** Register (ARA connu du stub → mail FFBB résolu) + verify, sans le relais dev. Retourne l'userId. */
    private function registerUnknownAraAndVerify(string $email, string $clubName): string
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], (string) json_encode([
            'email' => $email, 'password' => 'Password123!', 'firstName' => 'Ana', 'lastName' => 'Approb',
            'ara' => FfbbHttpClientStub::CLUB_CODE, 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(202);

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        self::assertInstanceOf(User::class, $user);
        $pending = $em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(EmailVerificationToken::class, $pending);
        $raw = $container->get(EmailVerifier::class)->generateToken($user, $pending->getAra(), $pending->getClubName());
        $em->flush();

        $this->client->request('POST', '/api/register/verify', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], (string) json_encode(['token' => $raw], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return $user->getId();
    }

    private function tokenOf(string $userId): string
    {
        $request = self::getContainer()->get(ClubCreationRequestRepository::class)->findPendingByUser($userId);
        self::assertInstanceOf(ClubCreationRequest::class, $request);

        return $request->getToken();
    }

    private function mailBySubjectPart(string $needle): Email
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && str_contains((string) $message->getSubject(), $needle)) {
                return $message;
            }
        }
        self::fail(\sprintf('aucun e-mail dont le sujet contient « %s »', $needle));
    }

    private function linkIn(Email $email, string $pathSegment): string
    {
        $matched = preg_match('~https?://\S+/' . preg_quote($pathSegment, '~') . '\S*~', (string) $email->getTextBody(), $m);
        self::assertSame(1, $matched, "le texte doit porter un lien /{$pathSegment}");

        return $m[0];
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }
}
