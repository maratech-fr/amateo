<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Entity\Team;
use App\Export\MatchesExportDataProvider;
use App\Service\MatchesPdfGenerator;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * La liste des matchs d'une équipe ou du club, de la date A à la date B, pour les parents. Rail
 * SYNCHRONE : le crédit se décompte au 200 (`CreditBudgetSubscriber`), donc jamais sans fichier
 * livré. Tout membre y accède, comme à l'export du planning : aucune garde gestionnaire ici.
 */
#[AsController]
final class ExportMatchesPdfController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly MatchesExportDataProvider $exportData,
        private readonly MatchesPdfGenerator $pdfGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    // priority > 0 : le chemin statique passe avant /api/fixtures/{id} d'API Platform.
    #[Route('/api/fixtures/export-pdf', name: 'api_fixtures_export_pdf', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request): Response
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $season = null === $clubId ? null : $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (null === $clubId || !$season instanceof Season) {
            return $this->json(['error' => 'Club ou saison introuvable dans le contexte.'], Response::HTTP_BAD_REQUEST);
        }

        $body = json_decode($request->getContent(), true);
        $from = \is_array($body) ? $this->dateOf($body['from'] ?? null) : null;
        $to = \is_array($body) ? $this->dateOf($body['to'] ?? null) : null;
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $from > $to) {
            return $this->json(['error' => 'Plage de dates invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $team = null;
        $teamId = $body['teamId'] ?? null;
        if (null !== $teamId) {
            // Sous RLS, une équipe d'un autre club se lit comme une équipe inconnue : même 404.
            $team = \is_string($teamId) && Uuid::isValid($teamId)
                ? $this->entityManager->getRepository(Team::class)->findOneBy(['id' => $teamId, 'clubId' => $clubId, 'seasonId' => $season->getId()])
                : null;
            if (!$team instanceof Team) {
                return $this->json(['error' => 'Équipe introuvable.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $bytes = $this->pdfGenerator->generate($this->exportData->load($clubId, $season, $from, $to, $team));
        } catch (RuntimeException $e) {
            $this->logger->error('Export des matchs : rendu PDF en échec.', ['exception' => $e]);

            return $this->json(['error' => 'Le document n\'a pas pu être généré.'], Response::HTTP_BAD_GATEWAY);
        }

        $response = new Response($bytes, Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="matchs.pdf"');
        // La liste dit où et quand des enfants jouent : jamais en cache, navigateur ni partagé.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function dateOf(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // Le retour au format écarte le 31 février, que PHP reporterait en silence sur mars.
        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
