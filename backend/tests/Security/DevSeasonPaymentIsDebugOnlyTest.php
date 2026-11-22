<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\DevSeasonPaymentController;
use App\Entity\Club;
use App\Service\ClubSubscriptionWriter;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Abus (lot 8, PR 1) : la route de dev `POST /api/dev/mark-season-paid` écrit une ligne Bêta
 * RÉGLÉE pour la saison suivante — hors debug (production), elle est un 404 et n'écrit rien, sinon
 * n'importe quel club s'offrirait la saison suivante et sa bascule. Contrôleur instancié à la main
 * avec `debug: false` (patron de `DevDemoRegisterTest::testRouteOutsideDebugIsGatedByTheWindowNotA404`) ;
 * témoin : le même contrôleur en debug écrit bien la ligne — sinon « rien n'est écrit » ne
 * prouverait rien. Le parcours qui s'en sert : `frontend/tests/e2e/season-transition.spec.ts`.
 */
#[Group('integration')]
final class DevSeasonPaymentIsDebugOnlyTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testOutsideDebugTheRouteIsA404AndWritesNoLine(): void
    {
        $club = $this->seedClub();

        try {
            $this->controller(debug: false, clubId: $club->getId())();
            self::fail('hors debug, la route de dev doit répondre 404');
        } catch (NotFoundHttpException) {
        }

        self::assertSame(0, $this->linesOf($club), 'hors debug, aucune ligne d\'abonnement n\'est écrite');
    }

    public function testInDebugTheRouteWritesThePaidBetaLineOfTheNextSeason(): void
    {
        $club = $this->seedClub();

        $response = $this->controller(debug: true, clubId: $club->getId())();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $next = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1;
        self::assertSame(1, $this->linesOf($club, $next, 'beta'), 'témoin : en debug, la ligne Bêta réglée de la saison suivante est écrite');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    /** Le contrôleur, le club appelant posé comme le pose le firewall (`_club_id`). */
    private function controller(bool $debug, string $clubId): DevSeasonPaymentController
    {
        $c = self::getContainer();
        $request = Request::create('/api/dev/mark-season-paid', 'POST');
        $request->attributes->set('_club_id', $clubId);
        $stack = new RequestStack([$request]);

        $writer = $c->get(ClubSubscriptionWriter::class);
        \assert($writer instanceof ClubSubscriptionWriter);
        $clock = $c->get(ClockInterface::class);
        \assert($clock instanceof ClockInterface);

        $controller = new DevSeasonPaymentController($this->em, $stack, $clock, $writer, debug: $debug);
        $controller->setContainer($c);

        return $controller;
    }

    private function seedClub(): Club
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Paiement dev ' . $uid);
        $club->setSlug('paiement-dev-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function linesOf(Club $club, ?int $seasonYear = null, ?string $planCode = null): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM club_subscription cs JOIN subscription_plan p ON p.id = cs.plan_id'
            . ' WHERE cs.club_id = ? AND cs.paid_at IS NOT NULL AND (?::int IS NULL OR cs.season_year = ?::int) AND (?::text IS NULL OR p.code = ?::text)',
            [$club->getId(), $seasonYear, $seasonYear, $planCode, $planCode],
        );
    }
}
