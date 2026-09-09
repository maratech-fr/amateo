<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * P4-187a de bout en bout, sur la stack qui tourne : un domicile importé au libellé
 * fédéral « GYMNASE BEHAT ALIAS » naît SANS gymnase ; rattacher ce libellé au gymnase
 * jetable backfille le domicile déjà déposé (il a son gymnase, reste UNPLACED) et
 * rattache d'office tout dépôt suivant au même libellé ; une fois le gymnase fermé à la
 * date des matchs, le domicile — bien que non placé — est visible du conflit « gymnase
 * indisponible » (GET /api/fixtures/conflicts).
 *
 * On possède toutes les ressources jetables et on restaure en fin de scénario.
 */
final class VenueAliasContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string DIVISION = 'BEHAT ALIAS D';

    private const string REF_1 = 'BAL-1';

    private const string REF_2 = 'BAL-2';

    private const string VENUE_NAME = 'GYM ALIAS BEHAT';

    private const string FBI_LABEL = 'GYMNASE BEHAT ALIAS';

    private string $token = '';

    private string $clubId = '';

    private string $clubName = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $venueId = '';

    private string $competitionId = '';

    private string $unavailabilityId = '';

    private string $matchDate = '';

    #[Given('le club de démonstration, connecté, dont le planning de saison est en vigueur')]
    public function leClubConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        $clubName = \is_array($club) ? ($club['name'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId || !\is_string($clubName) || '' === $clubName) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;
        $this->clubName = $clubName;

        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM schedule WHERE club_id=\'%s\' AND status=\'COMPLETED\' ORDER BY created_at DESC LIMIT 1', $this->clubId),
                admin: true,
            );
            if ('' === $completed) {
                throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
            }
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=\'%s\' WHERE club_id=\'%s\' AND type=\'SEASON\'', $completed, $this->clubId),
                admin: true,
            );
            $this->pointerSetBySelf = true;
        }

        $this->matchDate = date('Y-m-d', (int) strtotime('next saturday'));
    }

    #[Given('une équipe jetable et un gymnase jetable pour les alias')]
    public function uneEquipeEtGymnaseJetables(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'Alias Jetable', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME, 'source' => 'manual'], $this->token), 'gymnase');
    }

    #[When('je dépose un fichier FBI dont la salle est « GYMNASE BEHAT ALIAS »')]
    public function jeDeposeUnFichier(): void
    {
        $this->deposit(self::REF_1, [['division' => self::DIVISION, 'teamId' => $this->teamId]]);
        $this->competitionId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
            admin: true,
        );
    }

    #[Then('la rencontre importée n\'a pas de gymnase')]
    public function laRencontreNaPasDeGymnase(): void
    {
        if (null !== $this->venueIdOf(self::REF_1)) {
            throw new RuntimeException('un libellé inconnu ne devrait rattacher aucun gymnase');
        }
    }

    #[When('je rattache le libellé « GYMNASE BEHAT ALIAS » au gymnase jetable')]
    public function jeRattacheLeLibelle(): void
    {
        $result = $this->apiPost(\sprintf('venues/%s/external-labels', $this->venueId), ['label' => self::FBI_LABEL], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le rattachement a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('la rencontre a son gymnase et reste à placer')]
    public function laRencontreASonGymnaseEtResteAPlacer(): void
    {
        $json = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId(self::REF_1)), $this->token)['json'];
        if (($json['venueId'] ?? null) !== $this->venueId) {
            throw new RuntimeException('le backfill n\'a pas posé le gymnase sur le domicile déjà déposé');
        }
        if (($json['status'] ?? null) !== 'UNPLACED') {
            throw new RuntimeException('rattacher un libellé ne doit jamais placer la rencontre');
        }
    }

    #[When('je re-dépose un autre match au même libellé')]
    public function jeReDeposeUnAutreMatch(): void
    {
        // Correspondance division↔équipe déjà persistée → plus besoin de mapping.
        $this->deposit(self::REF_2, null);
    }

    #[Then('le nouveau match est rattaché d\'office')]
    public function leNouveauMatchEstRattacheDOffice(): void
    {
        if ($this->venueIdOf(self::REF_2) !== $this->venueId) {
            throw new RuntimeException('un dépôt au libellé rattaché aurait dû recevoir le gymnase d\'office');
        }
    }

    #[When('le gymnase jetable est fermé à la date des matchs')]
    public function leGymnaseEstFerme(): void
    {
        $result = $this->apiPost('venue_unavailabilities', [
            'venueId' => $this->venueId,
            'startDate' => $this->matchDate,
            'endDate' => $this->matchDate,
        ], $this->token);
        $this->unavailabilityId = $this->createdId($result, 'indisponibilité');
    }

    #[Then('un conflit « gymnase indisponible » vise la rencontre')]
    public function unConflitGymnaseIndisponible(): void
    {
        $fixtureId = $this->fixtureId(self::REF_1);
        $conflicts = $this->apiGet('fixtures/conflicts', $this->token)['json']['conflicts'] ?? [];
        foreach (\is_array($conflicts) ? $conflicts : [] as $conflict) {
            if (\is_array($conflict)
                && 'VENUE_UNAVAILABLE' === ($conflict['type'] ?? null)
                && ($conflict['fixture']['fixtureId'] ?? null) === $fixtureId) {
                return;
            }
        }
        throw new RuntimeException('le domicile rattaché, bien que non placé, aurait dû apparaître en « gymnase indisponible »');
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        if ('' !== $this->unavailabilityId) {
            $this->apiDelete(\sprintf('venue_unavailabilities/%s', $this->unavailabilityId), $this->token);
        }
        foreach ([self::REF_1, self::REF_2] as $ref) {
            $id = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, $ref),
                admin: true,
            );
            if ('' !== $id) {
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        if ('' !== $this->competitionId) {
            $this->apiDelete(\sprintf('competitions/%s', $this->competitionId), $this->token);
        }
        if ('' !== $this->teamId) {
            $this->apiDelete(\sprintf('teams/%s', $this->teamId), $this->token);
        }
        if ('' !== $this->venueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->venueId), $this->token);
        }
        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /**
     * Dépose un fichier FBI d'UNE ligne (HOME au libellé FBI_LABEL) via multipart.
     *
     * @param list<array{division: string, teamId: string}>|null $mappings
     */
    private function deposit(string $ref, ?array $mappings): void
    {
        $rows = [[
            self::DIVISION,
            $ref,
            $this->clubName,
            'Adversaire Alias',
            date('d/m/Y', (int) strtotime($this->matchDate)),
            '15:30',
            self::FBI_LABEL,
        ]];
        $path = $this->writeXlsx($rows);

        $parts = ['file' => DataPart::fromPath($path)];
        if (null !== $mappings) {
            $parts['mappings'] = json_encode($mappings, \JSON_THROW_ON_ERROR);
        }
        $form = new FormDataPart($parts);

        $response = $this->client->request('POST', 'fixtures/import', [
            'headers' => array_merge(['Authorization' => 'Bearer ' . $this->token], $form->getPreparedHeaders()->toArray()),
            'body' => $form->bodyToIterable(),
        ]);
        $status = $response->getStatusCode();
        @unlink($path);
        if (200 !== $status) {
            throw new RuntimeException(\sprintf('le dépôt FBI a répondu %d (200 attendu) : %s', $status, $response->getContent(false)));
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function writeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'bal') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);

        return $path;
    }

    private function fixtureId(string $ref): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, $ref),
            admin: true,
        );
        if ('' === $id) {
            throw new RuntimeException(\sprintf('rencontre %s introuvable', $ref));
        }

        return $id;
    }

    private function venueIdOf(string $ref): ?string
    {
        $venueId = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId($ref)), $this->token)['json']['venueId'] ?? null;

        return \is_string($venueId) ? $venueId : null;
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function createdId(array $response, string $what): string
    {
        if (!\in_array($response['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création %s refusée (HTTP %d)', $what, $response['status']));
        }
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour', $what));
        }

        return $id;
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $json): array
    {
        $members = $json['member'] ?? $json;

        return array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
    }
}
