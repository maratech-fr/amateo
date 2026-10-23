<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SportCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maison PARTAGÉE du « trouver ou créer une catégorie de sport » : un import d'équipes (FFBB ou
 * parc par IA) doit rattacher chaque équipe à une {@see SportCategory}. La logique vivait dans
 * {@see App\Service\Basketball\FfbbExcelImporter} ; elle est extraite ici pour que les deux
 * importers la partagent sans la dupliquer.
 *
 * Appariement par NOM EXACT (club d'abord, puis catalogue global `clubId = null`) ; à défaut,
 * création d'une catégorie custom rangée APRÈS le catalogue existant. Pas de flush : l'import est
 * tout-ou-rien, l'UUID naît au constructeur de l'entité, le flush final de l'appelant couvre tout.
 */
final class SportCategoryResolver
{
    private ?int $nextSortOrderValue = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function findOrCreate(string $name, string $clubId, string $sportId): SportCategory
    {
        $repository = $this->entityManager->getRepository(SportCategory::class);

        $category = $repository->findOneBy([
            'name' => $name,
            'clubId' => $clubId,
            'sportId' => $sportId,
        ]);

        if ($category instanceof SportCategory) {
            return $category;
        }

        $category = $repository->findOneBy([
            'name' => $name,
            'clubId' => null,
            'sportId' => $sportId,
        ]);

        if ($category instanceof SportCategory) {
            return $category;
        }

        $category = (new SportCategory)
            ->setName($name)
            ->setClubId($clubId)
            ->setSportId($sportId)
            ->setIsCustom(true)
            // Comme SportCategoryStateProcessor::nextSortOrder : une catégorie créée se range APRÈS
            // le catalogue (0 = la place de « Vétéran », elle sautait en tête de tous les
            // sélecteurs). Pas de flush ici — l'import est tout-ou-rien, et l'UUID naît au
            // constructeur.
            ->setSortOrder($this->nextSortOrder());

        $this->entityManager->persist($category);

        return $category;
    }

    /**
     * Le rang d'affichage suivant (même règle que SportCategoryStateProcessor). Mémorisé puis
     * incrémenté : le flush étant final, MAX(sortOrder) ne voit pas les catégories du lot en cours —
     * sans le compteur elles seraient ex æquo.
     */
    private function nextSortOrder(): int
    {
        if (null === $this->nextSortOrderValue) {
            $max = $this->entityManager->createQueryBuilder()
                ->select('MAX(c.sortOrder)')
                ->from(SportCategory::class, 'c')
                ->getQuery()
                ->getSingleScalarResult();
            $this->nextSortOrderValue = null === $max ? 0 : ((int) $max) + 1;
        }

        return $this->nextSortOrderValue++;
    }
}
