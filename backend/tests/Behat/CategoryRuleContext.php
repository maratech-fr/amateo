<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : une règle écrite pour une catégorie (CLUB + `targetTags`) suit ses équipes — une
 * équipe qui change de catégorie change d'étiquette, et la génération honore la règle pour toutes
 * les équipes du groupe et seulement pour elles.
 *
 * Décor DÉDIÉ et léger ({@see SmallClubFixtureTrait::registerSmallClub}, 4 équipes, 2 gymnases de
 * 9 créneaux) ; catégories du catalogue semé à l'inscription (« U13 », « U15 », « Senior »). Le
 * « seulement pour elles » se lit dans la demande FIGÉE au moteur (`schedule.snapshot_data`) : la
 * règle de groupe y est éclatée en une ligne par équipe (`<id de règle>:<id d'équipe>`). Plus bas :
 * `TeamTagResolver` (foyer unique de la résolution) et `PeriodGatePayloadParityTest`.
 */
final class CategoryRuleContext extends BaseContext
{
    use SmallClubFixtureTrait;

    /** @var array<string, string> nom de catégorie → id */
    private array $categoryIds = [];

    private string $ruleId = '';

    private string $scheduleId = '';

    private string $status = '';

    #[Given('un petit club de deux équipes U13, une équipe U15 « U15 A » et une équipe senior, sur deux gymnases')]
    public function unPetitClub(): void
    {
        $this->registerSmallClub();
        $categories = $this->apiGet('sport_categories', $this->smallClubToken);
        foreach ($categories['json']['member'] ?? [] as $category) {
            if (\is_array($category) && \is_string($category['name'] ?? null) && \is_string($category['id'] ?? null)) {
                $this->categoryIds[$category['name']] = $category['id'];
            }
        }
        foreach (['U13 A' => 'U13', 'U13 B' => 'U13', 'U15 A' => 'U15', 'SM1' => 'Senior'] as $team => $category) {
            if (!isset($this->categoryIds[$category])) {
                throw new RuntimeException(\sprintf('la catégorie « %s » n\'est pas semée pour un club neuf', $category));
            }
            $this->smallClubTeamIds[$team] = $this->createdId($this->apiPost('teams', [
                'name' => $team, 'sportCategoryId' => $this->categoryIds[$category], 'priorityTierId' => 3, 'sessionsPerWeek' => 2,
            ], $this->smallClubToken), 'équipe ' . $team);
        }
        foreach (['Gymnase Nord', 'Gymnase Sud'] as $venue) {
            $venueId = $this->createdId($this->apiPost('venues', ['name' => $venue, 'source' => 'manual'], $this->smallClubToken), $venue);
            foreach ([1, 2, 4] as $day) {
                foreach (['18:00', '19:30', '21:00'] as $start) {
                    $this->createdId($this->apiPost('venue_training_slots', [
                        'venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1,
                    ], $this->smallClubToken), 'créneau de ' . $venue);
                }
            }
        }
    }

    #[Given('/^une règle de groupe : les U13 ne commencent pas avant 19:30$/u')]
    public function uneRegleDeGroupe(): void
    {
        $this->ruleId = $this->createdId($this->apiPost('constraints', [
            'name' => 'Groupe U13 · pas avant 19:30', 'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'HARD',
            'config' => ['targetTags' => ['U13'], 'minStartTime' => '19:30'], 'isActive' => true,
        ], $this->smallClubToken), 'règle des U13');
    }

    #[When('/^l\'équipe « (?P<team>[^»]+) » passe en catégorie (?P<category>\S+)$/u')]
    public function lEquipePasseEnCategorie(string $team, string $category): void
    {
        // PUT complet : un champ omis reprendrait sa valeur par défaut (TeamStateProcessor).
        $updated = $this->apiPut(\sprintf('teams/%s', $this->smallClubTeamIds[$team]), [
            'name' => $team, 'sportCategoryId' => $this->categoryIds[$category], 'priorityTierId' => 3, 'sessionsPerWeek' => 2,
        ], $this->smallClubToken);
        if (200 !== $updated['status']) {
            throw new RuntimeException(\sprintf('changer la catégorie de %s a répondu %d (200 attendu) : %s', $team, $updated['status'], json_encode($updated['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('/^« (?P<team>[^»]+) » porte l\'étiquette (?P<gained>\S+) et plus l\'étiquette (?P<lost>\S+)$/u')]
    public function lEquipePorteLEtiquette(string $team, string $gained, string $lost): void
    {
        $tags = $this->tagsOf($this->smallClubTeamIds[$team]);
        if (!\in_array($gained, $tags, true) || \in_array($lost, $tags, true)) {
            throw new RuntimeException(\sprintf('%s porte les étiquettes [%s] (attendu %s, sans %s)', $team, implode(', ', $tags), $gained, $lost));
        }
    }

    #[When('je génère le planning de saison')]
    public function jeGenere(): void
    {
        $created = $this->apiPost('schedules', ['name' => 'Socle ' . date('H:i:s'), 'status' => 'DRAFT'], $this->smallClubToken);
        $this->scheduleId = $this->createdId($created, 'planning de saison');
        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $this->scheduleId), [], $this->smallClubToken);
        if (!\in_array($launched['status'], [200, 202], true)) {
            throw new RuntimeException(\sprintf('la génération a répondu %d : %s', $launched['status'], json_encode($launched['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->status = $this->pollSmallClubUntilTerminal($this->scheduleId);
    }

    #[Then('la génération aboutit')]
    public function laGenerationAboutit(): void
    {
        if ('COMPLETED' !== $this->status) {
            throw new RuntimeException(\sprintf('la génération aurait dû aboutir (COMPLETED), obtenu « %s »', $this->status));
        }
    }

    #[Then('/^aucune séance des trois équipes U13 ne commence avant 19:30$/u')]
    public function aucuneSeanceAvant1930(): void
    {
        $u13 = $this->u13TeamIds();
        $placed = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id IN (%s)', $this->scheduleId, $this->inList($u13)), admin: true);
        if (6 !== $placed) {
            throw new RuntimeException(\sprintf('les trois équipes U13 portent %d séances (attendu 6) — la règle serait honorée par le vide', $placed));
        }
        $early = (int) $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id IN (%s) AND start_time < \'19:30\'', $this->scheduleId, $this->inList($u13)), admin: true);
        if (0 !== $early) {
            throw new RuntimeException(\sprintf('%d séance(s) d\'équipes U13 commencent avant 19:30', $early));
        }
    }

    #[Then('la demande au moteur porte la règle pour ces trois équipes, et pour aucune autre')]
    public function laDemandeAuMoteurPorteLaRegle(): void
    {
        $targets = $this->dbalScalar(\sprintf(
            'SELECT string_agg(c->>\'scopeTargetId\', \',\' ORDER BY c->>\'scopeTargetId\') AS behatval FROM schedule s, jsonb_array_elements(s.snapshot_data::jsonb->\'constraints\') c WHERE s.id=\'%s\' AND c->>\'id\' LIKE \'%s:%%\'',
            $this->scheduleId,
            $this->ruleId,
        ), admin: true);
        $expected = $this->u13TeamIds();
        sort($expected);
        if (implode(',', $expected) !== $targets) {
            throw new RuntimeException(\sprintf('la règle des U13 vise [%s] dans la demande au moteur (attendu [%s])', $targets, implode(',', $expected)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @return list<string> */
    private function u13TeamIds(): array
    {
        return [$this->smallClubTeamIds['U13 A'], $this->smallClubTeamIds['U13 B'], $this->smallClubTeamIds['U15 A']];
    }

    /** @return list<string> */
    private function tagsOf(string $teamId): array
    {
        $raw = $this->dbalScalar(\sprintf(
            'SELECT string_agg(t.name, \',\') AS behatval FROM team_tag_assignment a JOIN team_tag t ON t.id = a.tag_id WHERE a.team_id=\'%s\'',
            $teamId,
        ), admin: true);

        return '' === $raw ? [] : explode(',', $raw);
    }

    /** @param list<string> $ids */
    private function inList(array $ids): string
    {
        return implode(',', array_map(static fn (string $id): string => '\'' . $id . '\'', $ids));
    }

    /** @param array{status: int, json: array<mixed>} $response */
    private function createdId(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\in_array($response['status'], [200, 201], true) || !\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création de « %s » refusée (HTTP %d) : %s', $what, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }

        return $id;
    }
}
