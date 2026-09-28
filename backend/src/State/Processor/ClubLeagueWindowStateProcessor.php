<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ClubLeagueWindowResource;
use App\Dto\ClubLeagueWindowInput;
use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueMatchWindow;
use App\Repository\LeagueMatchWindowRepository;
use DateTimeImmutable;

/**
 * P4-272 ① — CRUD gestionnaire de la copie club des fenêtres de ligue. Écriture
 * = management par défaut (403 sinon, AbstractStateProcessor). Le club+saison
 * sont estampillés par le socle ; on n'ajoute ici que la validation métier
 * (min ≤ max, doublon nommé) et la provenance `league` (ligue effective du club).
 *
 * @extends AbstractStateProcessor<ClubLeagueWindow, ClubLeagueWindowInput, ClubLeagueWindowResource>
 */
class ClubLeagueWindowStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return ClubLeagueWindow::class;
    }

    /**
     * @param ClubLeagueWindowInput $input
     */
    protected function createEntityFromInput(object $input): ClubLeagueWindow
    {
        $entity = new ClubLeagueWindow;
        // Provenance : la ligue EFFECTIVE du club (sa ligue si cataloguée, sinon la
        // défaut fédérale). Posée à la naissance de la ligne ; jamais réécrite au PUT.
        $entity->setLeague($this->effectiveLeagueOfCurrentClub());
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param ClubLeagueWindow      $entity
     * @param ClubLeagueWindowInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param ClubLeagueWindow $entity
     */
    protected function mapEntityToOutput(object $entity): ClubLeagueWindowResource
    {
        return ClubLeagueWindowResource::fromEntity($entity);
    }

    private function applyInput(ClubLeagueWindow $entity, ClubLeagueWindowInput $input): void
    {
        if (null !== $input->category) {
            $entity->setCategory($input->category);
        }
        if (null !== $input->level) {
            $entity->setLevel($input->level);
        }
        // Genre absent ('' ou null) = fenêtre tous-genres (la ligne éditable envoie
        // toujours le champ complet — sémantique full-replace du PUT).
        $entity->setGender('' === $input->gender ? null : $input->gender);
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->kickoffMin) {
            $entity->setKickoffMin(new DateTimeImmutable($input->kickoffMin));
        }
        if (null !== $input->kickoffMax) {
            $entity->setKickoffMax(new DateTimeImmutable($input->kickoffMax));
        }

        if ($entity->getKickoffMin() > $entity->getKickoffMax()) {
            $this->refuse('L\'heure de début doit précéder l\'heure de fin.');
        }

        // Doublon de clé naturelle → 422 lisible plutôt qu'un 500 (la contrainte
        // d'unicité en base est le filet, ceci est le message).
        $existing = $this->entityManager->getRepository(ClubLeagueWindow::class)->findOneBy([
            'category' => $entity->getCategory(),
            'level' => $entity->getLevel(),
            'gender' => $entity->getGender(),
            'dayOfWeek' => $entity->getDayOfWeek(),
            'kickoffMin' => $entity->getKickoffMin(),
        ]);
        if ($existing instanceof ClubLeagueWindow && $existing->getId() !== $entity->getId()) {
            $this->refuse('Une fenêtre de ligue identique existe déjà (même catégorie, niveau, genre, jour et heure de début).');
        }
    }

    private function effectiveLeagueOfCurrentClub(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        $league = \is_string($clubId) ? $this->entityManager->getRepository(Club::class)->find($clubId)?->getLeague() : null;

        /** @var LeagueMatchWindowRepository $seedRepository */
        $seedRepository = $this->entityManager->getRepository(LeagueMatchWindow::class);

        return $seedRepository->effectiveLeague($league);
    }
}
