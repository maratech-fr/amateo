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
 * L'espace « Importer » de bout en bout, sur la stack qui tourne (PR-3a) : un
 * dépôt FBI naît « à traiter » (NEW), un placement + validation le rend « traité »
 * (REVIEWED), un re-dépôt identique le passe « validé ligue » (VALIDATED, D9), un
 * re-dépôt divergent sans décision le rend « déphasé » (OUT_OF_SYNC) sans écraser
 * l'app, et trancher l'écart en adoptant la source le déplace ET le re-traite. Une
 * rencontre absente d'un dépôt reste intouchée (l'import est partiel).
 *
 * On possède TOUTES les ressources (équipe + gymnase + division jetables, plus une
 * rencontre-témoin) pour ne pas dépendre de la donnée seedée, et on restaure en
 * fin de scénario — fixtures d'abord (une équipe engagée refuse sa suppression).
 */
final class FixtureReviewContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string DIVISION = 'BEHAT REVIEW D';

    private const string EXTERNAL_REF = 'BHR-1001';

    private const string VENUE_NAME = 'GYM BEHAT';

    private string $token = '';

    private string $clubId = '';

    private string $clubName = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $venueId = '';

    private string $fixtureId = '';

    private string $controlFixtureId = '';

    private string $competitionId = '';

    private string $matchDate = '';

    private string $rescheduledDate = '';

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

        // Le module matchs est gardé par un planning de saison EN VIGUEUR (SocleGuard).
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
        $this->rescheduledDate = date('Y-m-d', (int) strtotime($this->matchDate . ' +7 days'));
    }

    #[Given('une équipe jetable et un gymnase jetable « GYM BEHAT »')]
    public function uneEquipeEtGymnaseJetables(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'Review Jetable', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME, 'source' => 'manual'], $this->token), 'gymnase');

        // La rencontre-témoin : créée à la main (donc « traitée »), jamais reprise
        // dans un dépôt — elle prouve qu'un import partiel ne la touche pas.
        $this->controlFixtureId = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->matchDate, 'homeAway' => 'AWAY', 'opponentLabel' => 'Témoin absent'], $this->token),
            'rencontre-témoin',
        );
    }

    #[When('je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30')]
    public function jeDeposeUnFichier(): void
    {
        $this->deposit($this->matchDate, [['division' => self::DIVISION, 'teamId' => $this->teamId]]);

        $id = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, self::EXTERNAL_REF),
            admin: true,
        );
        if ('' === $id) {
            throw new RuntimeException('la rencontre importée est introuvable après le dépôt');
        }
        $this->fixtureId = $id;
        $this->competitionId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
            admin: true,
        );
    }

    #[Then('la rencontre importée est « à traiter »')]
    public function laRencontreEstATraiter(): void
    {
        $this->assertReviewState('NEW');
    }

    #[When('je place la rencontre dans le gymnase « GYM BEHAT » à 15h30')]
    public function jePlaceLaRencontre(): void
    {
        $current = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->token)['json'];
        $result = $this->apiPut(\sprintf('fixtures/%s', $this->fixtureId), [
            'teamId' => $this->teamId,
            'matchDate' => \is_string($current['matchDate'] ?? null) ? $current['matchDate'] : $this->matchDate,
            'homeAway' => 'HOME',
            'opponentLabel' => \is_string($current['opponentLabel'] ?? null) ? $current['opponentLabel'] : 'Adversaire Behat',
            'venueId' => $this->venueId,
            'kickoffTime' => '15:30',
            'status' => 'PLACED',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le placement de la rencontre a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('la rencontre est « traitée »')]
    public function laRencontreEstTraitee(): void
    {
        $this->assertReviewState('REVIEWED');
    }

    #[When('je re-dépose le même fichier')]
    public function jeReDeposeLeMemeFichier(): void
    {
        // La correspondance division↔équipe est persistée : plus besoin de mapping.
        $this->deposit($this->matchDate, null);
    }

    #[Then('la rencontre est « validée ligue »')]
    public function laRencontreEstValideeLigue(): void
    {
        $this->assertStatus('VALIDATED');
    }

    #[When('je re-dépose le fichier avec une date différente, sans trancher')]
    public function jeReDeposeAvecDateDifferente(): void
    {
        $this->deposit($this->rescheduledDate, null);
    }

    #[Then('la rencontre est « déphasée » et sa date d\'origine est intacte')]
    public function laRencontreEstDephasee(): void
    {
        $this->assertReviewState('OUT_OF_SYNC');
        $date = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->token)['json']['matchDate'] ?? null;
        if ($date !== $this->matchDate) {
            throw new RuntimeException(\sprintf('la date d\'origine a été écrasée : « %s » au lieu de « %s »', \is_string($date) ? $date : 'inconnue', $this->matchDate));
        }
    }

    #[When('je tranche l\'écart de date en adoptant la source')]
    public function jeTrancheEcartAdoptantSource(): void
    {
        $result = $this->apiPost('fixtures/review/deviations', [
            'fixtureId' => $this->fixtureId,
            'field' => 'date',
            'choice' => 'take_source',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('trancher l\'écart a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('la rencontre est « à replacer » et de nouveau « traitée »')]
    public function laRencontreEstAReplacerEtTraitee(): void
    {
        $json = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->token)['json'];
        if (($json['status'] ?? null) !== 'UNPLACED') {
            throw new RuntimeException(\sprintf('adopter la date source aurait dû dé-placer la rencontre (statut « %s »)', \is_string($json['status'] ?? null) ? $json['status'] : 'inconnu'));
        }
        if (($json['reviewState'] ?? null) !== 'REVIEWED') {
            throw new RuntimeException(\sprintf('l\'écart tranché aurait dû re-traiter la rencontre (état « %s »)', \is_string($json['reviewState'] ?? null) ? $json['reviewState'] : 'inconnu'));
        }
        if (($json['matchDate'] ?? null) !== $this->rescheduledDate) {
            throw new RuntimeException('la date de la source n\'a pas été adoptée');
        }
    }

    #[Then('une rencontre absente du dépôt reste intouchée')]
    public function uneRencontreAbsenteResteIntouchee(): void
    {
        $json = $this->apiGet(\sprintf('fixtures/%s', $this->controlFixtureId), $this->token)['json'];
        if (($json['matchDate'] ?? null) !== $this->matchDate || ($json['reviewState'] ?? null) !== 'REVIEWED') {
            throw new RuntimeException('la rencontre-témoin, jamais reprise dans un dépôt, a été modifiée');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        foreach ([$this->fixtureId, $this->controlFixtureId] as $id) {
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
     * Dépose un fichier FBI d'UNE ligne (HOME au gymnase GYM BEHAT à 15:30) via
     * multipart, exactement comme le dialog d'import.
     *
     * @param list<array{division: string, teamId: string}>|null $mappings
     */
    private function deposit(string $date, ?array $mappings): void
    {
        $rows = [[
            self::DIVISION,
            self::EXTERNAL_REF,
            $this->clubName,
            'Adversaire Behat',
            date('d/m/Y', (int) strtotime($date)),
            '15:30',
            self::VENUE_NAME,
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
        $path = tempnam(sys_get_temp_dir(), 'bhr') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);

        return $path;
    }

    private function assertReviewState(string $expected): void
    {
        $state = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->token)['json']['reviewState'] ?? null;
        if ($state !== $expected) {
            throw new RuntimeException(\sprintf('état de traitement « %s » au lieu de « %s »', \is_string($state) ? $state : 'inconnu', $expected));
        }
    }

    private function assertStatus(string $expected): void
    {
        $status = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->token)['json']['status'] ?? null;
        if ($status !== $expected) {
            throw new RuntimeException(\sprintf('statut « %s » au lieu de « %s »', \is_string($status) ? $status : 'inconnu', $expected));
        }
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
