<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamResource;
use App\Dto\TeamInput;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Service\ManagementAccessGuard;
use App\Service\PlanEntitlements;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\TeamEngagementGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @extends AbstractStateProcessor<Team, TeamInput, TeamResource>
 */
class TeamStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly TeamEngagementGuard $teamEngagementGuard,
        private readonly PlanEntitlements $planEntitlements,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Team::class;
    }

    /**
     * Une équipe ENGAGÉE (elle joue déjà) n'est pas supprimable : ses matchs sont connus
     * de la fédération, et `purgeChildrenOfTeam` les emporterait avec elle.
     *
     * Le refus vit dans ce hook parce qu'il est le dernier point AVANT la cascade et le
     * `remove()` — le placer après ne refuserait rien, les matchs seraient déjà détruits.
     * Et le parent a déjà chargé l'entité ET vérifié le tenant : surcharger
     * `processDelete` pour re-chercher l'équipe y ajouterait une troisième variante
     * maison du même contrôle d'appartenance.
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        if (!$entity instanceof Team) {
            return;
        }

        $this->teamEngagementGuard->assertNotEngaged(
            $entity->getId(),
            'Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération. Elle ne peut plus être supprimée.',
        );

        $this->cascadeDeleter?->purgeChildrenOfTeam($entity);
    }

    /**
     * @param TeamInput $input
     */
    protected function createEntityFromInput(object $input): Team
    {
        // P1-3 — cap payant aux portes de création : au-delà du cap de l'offre effective, refus
        // 422 nommant le palier supérieur. Découverte/Bêta/démo (cap null) passent sans test.
        $this->assertWithinTeamCap();

        $entity = new Team;
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->tierOrder) {
            $entity->setTierOrder($input->tierOrder);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->gender) {
            $gender = Gender::tryFrom($input->gender);
            if (null !== $gender) {
                $entity->setGender($gender);
            }
        }
        $entity->setLevel(null !== $input->level ? TeamLevel::tryFrom($input->level) : null);
        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? true);
        $entity->setParentTeamId($input->parentTeamId);

        return $entity;
    }

    /**
     * @param Team      $entity
     * @param TeamInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->tierOrder) {
            $entity->setTierOrder($input->tierOrder);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->gender) {
            $gender = Gender::tryFrom($input->gender);
            if (null !== $gender) {
                $entity->setGender($gender);
            }
        }

        // Le NIVEAU d'une équipe engagée est FIGÉ — sans exception. Le tier, lui, reste
        // libre : c'est la perception interne du club, elle peut bouger sans rien
        // remettre en cause auprès de la fédération.
        //
        // Le niveau se saisit AVANT de générer : il alimente le tag NIVEAU, donc les
        // contraintes du solveur, donc la photo de structure de la version. Le laisser
        // bouger après ferait diverger la photo (qui l'a figé) et la base — deux vérités
        // sur la même chose, et « Charger cette version » ramènerait silencieusement
        // l'ancienne. Le figer VRAIMENT rend cette divergence impossible.
        //
        // Seule tolérance, et ce n'est pas une exception à la règle : un ÉCHO à
        // l'identique passe. Le front renvoie le payload complet à chaque PUT ; refuser
        // une valeur inchangée casserait un simple renommage sans rien protéger.
        //
        // (Un jour l'import FFBB pourra vouloir changer un niveau — ce cas sera traité
        // à ce moment-là, avec la photo. Pas avant, et pas en douce.)
        // DOC-3 (arbitré fondateur 2026-07-31 : « absent = on garde ») — un PUT qui
        // OMET `level` est indiscernable d'un PUT qui l'efface, et l'écrire mettait le
        // niveau à NULL : le tag NIVEAU disparaissait du payload solveur en silence.
        // Aligné sur les 4 champs du bloc « PUT PARTIEL » ci-dessous. Effacer un niveau
        // par l'API devient impossible — assumé : le wizard ré-échoit toujours `level`,
        // et un niveau ne se retire pas « en douce » sur une équipe qui joue.
        if (null !== $input->level) {
            $newLevel = TeamLevel::tryFrom($input->level);
            if ($newLevel !== $entity->getLevel()) {
                $this->teamEngagementGuard->assertNotEngaged(
                    $entity->getId(),
                    'Cette équipe joue en compétition : elle est inscrite sous son niveau actuel auprès de la fédération, il ne peut plus changer.',
                );
            }
            $entity->setLevel($newLevel);
        }

        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }

        // PUT PARTIEL : un champ ABSENT du payload garde sa valeur. Aucun client
        // n'envoie ces quatre-là — `TeamPayload` ne les déclare même pas — donc les
        // écrire inconditionnellement les mettait à NULL à CHAQUE édition. Renommer une
        // équipe effaçait ainsi son gymnase forcé, son jour de match, son plancher de
        // séances et sa filiation de saison (posée par la transition N→N+1). Les quatre
        // partent DIRECTEMENT au payload du solveur (`ScheduleConstraintBuilder`) : la
        // génération suivante replaçait l'équipe n'importe où, n'importe quand, sans une
        // erreur ni un moyen de s'en apercevoir.
        //
        // Même idiome que `SeasonStateProcessor` (« absent dates keep the current
        // values »). Ils ne deviennent pas non plus effaçables par l'API : ils ne l'ont
        // jamais été — aucun client ne peut les poser.
        if (null !== $input->minSessionsOverride) {
            $entity->setMinSessionsOverride($input->minSessionsOverride);
        }
        if (null !== $input->matchDay) {
            $entity->setMatchDay($input->matchDay);
        }
        if (null !== $input->forcedVenueId) {
            $entity->setForcedVenueId($input->forcedVenueId);
        }
        if (null !== $input->parentTeamId) {
            $entity->setParentTeamId($input->parentTeamId);
        }
        $entity->setIsActive($input->isActive ?? $entity->getIsActive());
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        $dto = TeamResource::fromEntity($entity);
        // `fromEntity` laisse isEngaged à false : sans ça, un POST/PUT répondrait
        // « isEngaged: false » là où le GET de la même équipe répond true — le même
        // champ donnant deux réponses selon le verbe, donc deux vérités. Un client qui
        // fait confiance au corps de l'écriture (setQueryData plutôt qu'invalidate)
        // ré-ouvrirait « Supprimer » sur une équipe que le serveur refuse.
        $dto->isEngaged = $this->teamEngagementGuard->isEngaged($entity->getId());

        return $dto;
    }

    /**
     * Refuse la création d'une équipe de plus si l'offre effective (payante) a atteint son
     * cap. La décision est centralisée dans `PlanEntitlements` — pas de règle dupliquée. Le
     * club/saison viennent du tenant résolu par le listener (mêmes attributs que `process()`).
     */
    private function assertWithinTeamCap(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return;
        }

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return;
        }

        $seasonId = $request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id');
        $season = \is_string($seasonId) && '' !== $seasonId
            ? $this->entityManager->getRepository(Season::class)->find($seasonId)
            : $this->seasonResolver->currentSeason($clubId);
        if (!$season instanceof Season) {
            return;
        }

        $message = $this->planEntitlements->teamCapExceededMessage($club, $season);
        if (null !== $message) {
            throw new UnprocessableEntityHttpException($message);
        }
    }
}
