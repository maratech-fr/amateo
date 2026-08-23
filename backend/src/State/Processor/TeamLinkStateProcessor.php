<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamLinkResource;
use App\Dto\TeamLinkInput;
use App\Entity\Team;
use App\Entity\TeamLink;
use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;

/**
 * Wizard-surface structure entity (VenueMatchWindow idiom): not management
 * gated. Normalizes the couple (teamAId < teamBId) so A–B ≡ B–A.
 *
 * @extends AbstractStateProcessor<TeamLink, TeamLinkInput, TeamLinkResource>
 */
class TeamLinkStateProcessor extends AbstractStateProcessor
{
    /**
     * Miroir MANUEL de `MAX_TEAM_LINKS` (engine/app/schemas/input_schema.py) — même
     * régime que le contrat : pas de codegen, l'égalité est une discipline. Sans ce
     * plafond à la SAISIE, la 51ᵉ passerelle ne casserait pas ici : elle ferait
     * 422-FAILED la GÉNÉRATION (le bord Pydantic la refuse) — une panne loin de sa
     * cause, le pire des modes. 50 ≈ 10x un gros club FFBB.
     */
    private const int MAX_TEAM_LINKS = 50;

    protected function getEntityClass(): string
    {
        return TeamLink::class;
    }

    /**
     * @param TeamLinkInput $input
     */
    protected function createEntityFromInput(object $input): TeamLink
    {
        // Le count passe sous les filtres Doctrine tenant+saison : il ne voit que
        // les passerelles de CE club et CETTE saison.
        if ($this->entityManager->getRepository(TeamLink::class)->count([]) >= self::MAX_TEAM_LINKS) {
            $this->refuse('Le nombre maximal de passerelles est atteint — supprimez-en une avant d\'en créer une nouvelle.');
        }

        $entity = new TeamLink;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param TeamLink      $entity
     * @param TeamLinkInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param TeamLink $entity
     */
    protected function mapEntityToOutput(object $entity): TeamLinkResource
    {
        return TeamLinkResource::fromEntity($entity);
    }

    private function applyInput(TeamLink $entity, TeamLinkInput $input): void
    {
        $teamAId = $input->teamAId;
        $teamBId = $input->teamBId;
        if (null === $teamAId || null === $teamBId) {
            $this->refuse('Les deux équipes sont requises.');
        }
        if ($teamAId === $teamBId) {
            $this->refuse('Une équipe ne peut pas être liée à elle-même.');
        }
        // SYMMETRIC couple: normalize so SM1–SM2 and SM2–SM1 are the SAME row
        // (the DB unique then makes the duplicate a clean 422 below).
        if (strcasecmp($teamAId, $teamBId) > 0) {
            [$teamAId, $teamBId] = [$teamBId, $teamAId];
        }
        $entity->setTeamAId($teamAId);
        $entity->setTeamBId($teamBId);
        if (null !== $input->linkType) {
            $entity->setLinkType(TeamLinkType::from($input->linkType));
        }
        // Absent ⇒ PREFERRED (rétro-compatible) : le défaut de l'entité tient sur une
        // création ; sur une édition on ne rétrograde pas silencieusement une valeur
        // envoyée. Seule une intensité EXPLICITE écrase.
        if (null !== $input->trainingIntensity) {
            $entity->setTrainingIntensity(TeamLinkIntensity::from($input->trainingIntensity));
        }

        // Foreign/unknown teams resolve to null through the tenant+season
        // filters → 422 (`findOneBy`, never `find()` — leçon PR B).
        $teamRepository = $this->entityManager->getRepository(Team::class);
        foreach ([$teamAId, $teamBId] as $teamId) {
            if (!$teamRepository->findOneBy(['id' => $teamId]) instanceof Team) {
                $this->refuse('Équipe inconnue pour ce club.');
            }
        }
        // One couple = one link (readable 422; the DB unique is the backstop).
        $existing = $this->entityManager->getRepository(TeamLink::class)->findOneBy([
            'teamAId' => $teamAId,
            'teamBId' => $teamBId,
        ]);
        if ($existing instanceof TeamLink && $existing->getId() !== $entity->getId()) {
            $this->refuse('Ces deux équipes sont déjà liées — modifiez la passerelle existante.');
        }
    }
}
