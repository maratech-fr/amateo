<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClubRepository;
use App\Service\ManagementAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pose le NOM COURT du club (libellé d'e-mail), scopé au club de l'appelant résolu depuis le
 * tenant JWT. Endpoint partiel dédié (patron {@see ClubAppearanceController}/{@see ClubSiegeController}) :
 * le nom court se saisit sans les champs NotBlank du Club resource générique (name/slug/timezone…).
 *
 * Choix PATCH dédié (vs. PUT + ClubInput) : le PUT générique est un full-replace que le front doit
 * relire-et-renvoyer en entier (champs NotBlank), et le nom court n'y figure PAS — il ne peut donc
 * JAMAIS être écrasé en silence par un PUT qui ne l'envoie pas. Le PATCH n'envoie QUE le nom court.
 *
 * Validation : 1-20 caractères (lettres accentuées comprises, chiffres, espaces, `& . - '`) ;
 * trim ; chaîne vide/null → le nom court est RETIRÉ (repli sur le nom long, {@see
 * App\Entity\Club::emailLabel}). La normalisation (trim, vide → null) vit dans le setter de
 * l'entité, foyer unique. Management-gated (SEC-07).
 */
#[AsController]
final class ClubShortNameController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /**
     * 1-20 caractères : lettres (Unicode, accents compris), chiffres, espace et `& . - '`. Appliqué
     * à la valeur TRIMÉE. Les cas null et chaîne vide sont traités à part (retrait), pas par ce motif.
     */
    private const string SHORT_NAME = '/^[\\p{L}0-9 &.\\-\']{1,20}$/u';

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/club/short-name', name: 'club_short_name', methods: ['PATCH'])]
    public function __invoke(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$request instanceof Request || null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data) || !\array_key_exists('shortName', $data)) {
            return $this->json(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $value = $data['shortName'];
        if (null !== $value && !\is_string($value)) {
            return $this->json(['error' => 'shortName must be a string or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validation sur la valeur TRIMÉE ; null/vide = retrait (pas de motif à vérifier).
        $trimmed = null === $value ? '' : trim($value);
        if ('' !== $trimmed && 1 !== preg_match(self::SHORT_NAME, $trimmed)) {
            return $this->json(
                ['error' => 'Le nom court doit faire 1 à 20 caractères : lettres, chiffres, espaces et & . - \' uniquement.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Le setter normalise (trim, chaîne vide → null) : foyer unique.
        $club->setShortName($value);
        $this->entityManager->flush();

        return $this->json(['shortName' => $club->getShortName()]);
    }
}
