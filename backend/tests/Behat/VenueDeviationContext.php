<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * L'écart salle d'un domicile NON PLACÉ de bout en bout, sur la stack qui tourne : un
 * domicile rattaché à un gymnase du club (par un libellé fédéral) dont un dépôt suivant
 * nomme une AUTRE salle n'est jamais réécrit en silence — l'écart est présenté, puis
 * « Garder l'appli » (le gymnase reste, le libellé source est mémorisé → re-dépôt muet)
 * ou « Prendre le fichier » (l'alias confirmé repose le bon gymnase, sans placer).
 *
 * On possède toutes les ressources jetables et on restaure en fin de scénario.
 */
final class VenueDeviationContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string DIVISION = 'BEHAT DEV D';

    private const string EXTERNAL_REF = 'BDV-1';

    private const string VENUE_NAME = 'GYM DEV BEHAT';

    private const string VENUE_NAME_2 = 'GYM DEV BEHAT BIS';

    private const string LABEL_ORIGIN = 'SALLE ORIGINE';

    private const string LABEL_DRIFT = 'SALLE RAPHAEL DE BARROS';

    private string $token = '';

    private string $clubId = '';

    private string $clubName = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $venueId = '';

    private string $venueId2 = '';

    private string $competitionId = '';

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

        // Le samedi de la semaine ISO SUIVANTE, compté depuis le jour du club
        // (Europe/Paris) — hors de la fenêtre « FBI fait foi » : le domicile naît NEW
        // et un écart s'arbitre au lieu d'être appliqué d'office (même raison que les
        // autres contexts d'import).
        $clubToday = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
        $mondayThisWeek = $clubToday->modify(\sprintf('-%d days', (int) $clubToday->format('N') - 1));
        $this->matchDate = $mondayThisWeek->modify('+12 days')->format('Y-m-d');
    }

    #[Given('une équipe jetable et un gymnase jetable rattaché au libellé « SALLE ORIGINE »')]
    public function uneEquipeEtGymnaseRattache(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'Dev Jetable', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME, 'source' => 'manual'], $this->token), 'gymnase');
        $this->rattacher($this->venueId, self::LABEL_ORIGIN);
    }

    #[Given('un deuxième gymnase jetable rattaché au libellé « SALLE RAPHAEL DE BARROS »')]
    public function unDeuxiemeGymnaseRattache(): void
    {
        $this->venueId2 = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME_2, 'source' => 'manual'], $this->token), 'gymnase');
        $this->rattacher($this->venueId2, self::LABEL_DRIFT);
    }

    #[When('je dépose un domicile non placé au libellé « SALLE ORIGINE »')]
    public function jeDeposeUnDomicile(): void
    {
        $this->deposit(self::LABEL_ORIGIN, [['division' => self::DIVISION, 'teamId' => $this->teamId]]);
        $this->competitionId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
            admin: true,
        );
    }

    #[Then('la rencontre a son gymnase et reste non placée')]
    public function laRencontreASonGymnase(): void
    {
        $json = $this->fixtureJson();
        if (($json['venueId'] ?? null) !== $this->venueId) {
            throw new RuntimeException('le domicile aurait dû être rattaché au gymnase par son libellé');
        }
        if (($json['status'] ?? null) !== 'UNPLACED') {
            throw new RuntimeException('rattacher un libellé ne place jamais la rencontre');
        }
    }

    #[When('la ligue re-dépose le domicile au libellé « SALLE RAPHAEL DE BARROS », sans trancher')]
    public function laLigueRedeposeDivergent(): void
    {
        $this->deposit(self::LABEL_DRIFT, null);
    }

    #[Then('un écart de salle est ouvert et le gymnase reste intact')]
    public function unEcartDeSalleEstOuvert(): void
    {
        $json = $this->fixtureJson();
        if (($json['reviewState'] ?? null) !== 'OUT_OF_SYNC') {
            throw new RuntimeException(\sprintf('la rencontre aurait dû être « déphasée » (état « %s »)', \is_string($json['reviewState'] ?? null) ? $json['reviewState'] : 'inconnu'));
        }
        if (($json['venueId'] ?? null) !== $this->venueId) {
            throw new RuntimeException('le gymnase a été déplacé sans décision');
        }
        if (!$this->hasVenueDeviation($json)) {
            throw new RuntimeException('l\'écart de salle n\'a pas été ouvert');
        }
    }

    #[When('je garde l\'appli sur l\'écart de salle')]
    public function jeGardeLAppli(): void
    {
        $this->trancher('keep_app');
    }

    #[Then('l\'écart de salle est retiré et la rencontre est traitée')]
    public function lEcartEstRetireEtTraitee(): void
    {
        $json = $this->fixtureJson();
        if (($json['reviewState'] ?? null) !== 'REVIEWED') {
            throw new RuntimeException('garder l\'appli aurait dû re-traiter la rencontre');
        }
        if ($this->hasVenueDeviation($json)) {
            throw new RuntimeException('l\'écart de salle aurait dû être retiré');
        }
        if (($json['venueId'] ?? null) !== $this->venueId) {
            throw new RuntimeException('garder l\'appli garde le gymnase courant');
        }
    }

    #[When('la ligue re-dépose le même libellé divergent')]
    public function laLigueRedeposeLeMemeDivergent(): void
    {
        $this->deposit(self::LABEL_DRIFT, null);
    }

    #[Then('aucun nouvel écart de salle n\'est ouvert')]
    public function aucunNouvelEcart(): void
    {
        $json = $this->fixtureJson();
        if ($this->hasVenueDeviation($json)) {
            throw new RuntimeException('un libellé déjà gardé ne devrait plus rouvrir d\'écart (idempotence)');
        }
        if (($json['reviewState'] ?? null) !== 'REVIEWED') {
            throw new RuntimeException('le re-dépôt muet aurait dû laisser la rencontre traitée');
        }
    }

    #[When('j\'adopte le fichier sur l\'écart de salle')]
    public function jAdopteLeFichier(): void
    {
        $this->trancher('take_source');
    }

    #[Then('le domicile bascule sur le deuxième gymnase et reste non placé')]
    public function leDomicileBasculeSurLeDeuxieme(): void
    {
        $json = $this->fixtureJson();
        if (($json['venueId'] ?? null) !== $this->venueId2) {
            throw new RuntimeException('adopter le fichier aurait dû reposer le gymnase depuis l\'alias confirmé');
        }
        if (($json['status'] ?? null) !== 'UNPLACED') {
            throw new RuntimeException('adopter la salle d\'un non placé ne le place jamais');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        $id = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, self::EXTERNAL_REF),
            admin: true,
        );
        if ('' !== $id) {
            $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
        }
        if ('' !== $this->competitionId) {
            $this->apiDelete(\sprintf('competitions/%s', $this->competitionId), $this->token);
        }
        if ('' !== $this->teamId) {
            $this->apiDelete(\sprintf('teams/%s', $this->teamId), $this->token);
        }
        foreach ([$this->venueId, $this->venueId2] as $venue) {
            if ('' !== $venue) {
                $this->apiDelete(\sprintf('venues/%s', $venue), $this->token);
            }
        }
        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /** Un écart de salle est-il ouvert sur la rencontre ? */
    private function hasVenueDeviation(array $json): bool
    {
        $deviations = $json['pendingDeviations'] ?? null;
        foreach (\is_array($deviations) ? $deviations : [] as $deviation) {
            if (\is_array($deviation) && 'venue' === ($deviation['field'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Rattache un libellé fédéral à un gymnase (POST venues/{id}/external-labels). */
    private function rattacher(string $venueId, string $label): void
    {
        $result = $this->apiPost(\sprintf('venues/%s/external-labels', $venueId), ['label' => $label], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le rattachement du libellé a répondu %d (200 attendu)', $result['status']));
        }
    }

    /** Tranche l'écart de salle de la rencontre suivie (keep_app | take_source). */
    private function trancher(string $choice): void
    {
        $result = $this->apiPost('fixtures/review/deviations', [
            'fixtureId' => $this->fixtureId(),
            'field' => 'venue',
            'choice' => $choice,
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('trancher l\'écart de salle a répondu %d (200 attendu)', $result['status']));
        }
    }

    /** @return array<string, mixed> */
    private function fixtureJson(): array
    {
        $json = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId()), $this->token)['json'];

        return \is_array($json) ? $json : [];
    }

    private function fixtureId(): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, self::EXTERNAL_REF),
            admin: true,
        );
        if ('' === $id) {
            throw new RuntimeException('la rencontre importée est introuvable');
        }

        return $id;
    }

    /**
     * Dépose un fichier FBI d'UNE ligne (domicile, salle au choix) via multipart.
     *
     * @param list<array{division: string, teamId: string}>|null $mappings
     */
    private function deposit(string $salle, ?array $mappings): void
    {
        $rows = [[
            self::DIVISION,
            self::EXTERNAL_REF,
            $this->clubName,
            'Adversaire Dev',
            date('d/m/Y', (int) strtotime($this->matchDate)),
            '15:30',
            $salle,
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
        $path = tempnam(sys_get_temp_dir(), 'bdv') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);

        return $path;
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
