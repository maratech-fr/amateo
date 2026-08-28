<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\ValidateImpactController;
use App\Entity\Schedule;
use App\Service\OrphanedFixtureFinder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * BCK-21 — les gardes de {@see ValidateImpactController} que RLS masque au niveau HTTP
 * (sans club résolu, la base ne rend rien et le 404 précède ces branches ; avec un club
 * résolu il n'y a plus de mismatch). On les exerce donc en unitaire, l'EntityManager
 * mocké rendant un planning là où RLS n'en rendrait pas.
 */
final class ValidateImpactControllerGuardTest extends TestCase
{
    /** Contexte club IRRÉSOLU → 400 fail-closed (durcissement revue P2-52). */
    public function testUnresolvedClubContextFailsClosedWith400(): void
    {
        // Aucun `_club_id` dans la requête, aucun en-tête X-Club-Id : le contexte club
        // est irrésolu. Le garde doit refuser AVANT de comparer au club du planning.
        $response = $this->invoke(new Request, $this->scheduleForClub('club-a'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /** Planning d'un club DIFFÉRENT du contexte → 403 (défense en profondeur). */
    public function testForeignClubIsRefusedWith403(): void
    {
        $request = new Request;
        $request->attributes->set('_club_id', 'club-current');

        $response = $this->invoke($request, $this->scheduleForClub('club-other'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function scheduleForClub(string $clubId): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId('season-x');

        return $schedule;
    }

    private function invoke(Request $request, Schedule $schedule): JsonResponse
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($schedule);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $requestStack = new RequestStack([$request]);

        // Le prédicat (final, non doublable) n'est jamais atteint sur un refus : il
        // court-circuite avant toute lecture d'impact. On en passe une instance réelle
        // sur l'EM mocké ; le code de statut est la preuve.
        $finder = new OrphanedFixtureFinder($em);

        $controller = new ValidateImpactController($em, $requestStack, $finder);
        // AbstractController::json() lit `$this->container->has('serializer')` — un
        // conteneur vide suffit (retombe sur un JsonResponse natif).
        $controller->setContainer(new Container);

        return $controller('schedule-id');
    }
}
