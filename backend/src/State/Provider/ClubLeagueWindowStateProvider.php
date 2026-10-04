<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ClubLeagueWindowResource;
use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueMatchWindow;
use App\Repository\LeagueMatchWindowRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<ClubLeagueWindow, ClubLeagueWindowResource>
 */
class ClubLeagueWindowStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ClubLeagueWindow::class;
    }

    /**
     * @param ClubLeagueWindow $entity
     */
    protected function mapEntityToOutput(object $entity): ClubLeagueWindowResource
    {
        return ClubLeagueWindowResource::fromEntity($entity);
    }

    /**
     * The whole club copy is ONE bounded set (a manager edits the full list) →
     * ordered, unpaginated, and decorated with the seed-diff badge in batch.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $qb->orderBy('e.category', 'ASC')
            ->addOrderBy('e.level', 'ASC')
            ->addOrderBy('e.dayOfWeek', 'ASC')
            ->addOrderBy('e.kickoffMin', 'ASC');

        return true;
    }

    /**
     * P4-272 ① — le badge « modifié »/« ajouté » est calculé ICI, une fois, par clé
     * naturelle face au seed de la ligue EFFECTIVE du club (le front l'affiche, il ne
     * le redérive pas). Zéro N+1 : le seed est chargé une fois pour tout le lot.
     *
     * @param array<int, ClubLeagueWindowResource> $outputs
     */
    protected function decorateCollection(array $outputs): void
    {
        if ([] === $outputs) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        $league = \is_string($clubId) ? $this->entityManager->getRepository(Club::class)->find($clubId)?->getLeague() : null;

        /** @var LeagueMatchWindowRepository $seedRepository */
        $seedRepository = $this->entityManager->getRepository(LeagueMatchWindow::class);
        $seedMaxByKey = [];
        foreach ($seedRepository->findEnvelopeForLeague($league) as $seed) {
            $seedMaxByKey[$this->seedKey($seed)] = $seed->getKickoffMax()->format('H:i');
        }

        foreach ($outputs as $output) {
            $output->badge = $this->badgeFor($output, $seedMaxByKey);
        }
    }

    /** @param array<string, string> $seedMaxByKey key → seed kickoffMax (H:i) */
    private function badgeFor(ClubLeagueWindowResource $output, array $seedMaxByKey): ?string
    {
        $key = $this->outputKey($output);
        if (!\array_key_exists($key, $seedMaxByKey)) {
            return 'added';
        }
        if ($seedMaxByKey[$key] !== $output->kickoffMax) {
            return 'modified';
        }

        return null;
    }

    private function seedKey(LeagueMatchWindow $seed): string
    {
        return implode('|', [$seed->getCategory(), $seed->getLevel(), $seed->getGender() ?? '', $seed->getDayOfWeek(), $seed->getKickoffMin()->format('H:i')]);
    }

    private function outputKey(ClubLeagueWindowResource $output): string
    {
        return implode('|', [$output->category, $output->level, $output->gender ?? '', $output->dayOfWeek, $output->kickoffMin]);
    }
}
