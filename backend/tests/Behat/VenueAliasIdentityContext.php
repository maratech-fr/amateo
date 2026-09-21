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
 * Le nom que FBI donne à un gymnase, de bout en bout sur la stack qui tourne. Deux
 * promesses :
 *  - la source qui nomme le gymnase PLACÉ par un alias CONFIRMÉ (pas le nom Amateo)
 *    n'est pas un écart — le chemin placé porte la même clause alias que le non placé —
 *    et, la source attestant tout, la rencontre passe enfin en « validée » ; un alias
 *    qui désigne un AUTRE gymnase lève toujours l'écart (identité stricte) ;
 *  - le registre « à corriger dans FBI » propose la GRAPHIE BRUTE que la source atteste
 *    pour ce gymnase (les alias sont stockés normalisés, illisibles à recopier), en la
 *    retrouvant sur une rencontre sœur.
 *
 * On possède toutes les ressources jetables et on restaure en fin de scénario.
 */
final class VenueAliasIdentityContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string DIVISION = 'BEHAT NOM FBI D';

    private const string REF_MAIN = 'BNF-1';

    private const string REF_SIBLING = 'BNF-SIB';

    private const string VENUE_NAME = 'GYM ALIAS BEHAT';

    private const string VENUE_NAME_2 = 'GYM AUTRE BEHAT';

    /** Le libellé fédéral rattaché au gymnase : stocké NORMALISÉ (« salle du 8 mai »). */
    private const string ALIAS_LABEL = 'SALLE DU 8 MAI';

    /** La GRAPHIE BRUTE, telle qu'une sœur l'atteste — distincte de l'alias normalisé. */
    private const string RAW_LABEL = 'Salle du 8 Mai';

    private const string OTHER_ALIAS_LABEL = 'SALLE RAPHAEL DE BARROS';

    private string $token = '';

    private string $clubId = '';

    private string $clubName = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $venueId = '';

    private string $venueId2 = '';

    private string $matchWindowId = '';

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

        // Le samedi de la semaine ISO SUIVANTE (compté depuis le jour du club,
        // Europe/Paris) — hors de la fenêtre « FBI fait foi » : un écart s'ARBITRE au
        // lieu d'être appliqué d'office (même raison que les autres contexts d'import).
        $clubToday = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
        $mondayThisWeek = $clubToday->modify(\sprintf('-%d days', (int) $clubToday->format('N') - 1));
        $this->matchDate = $mondayThisWeek->modify('+12 days')->format('Y-m-d');
    }

    #[Given('une équipe jetable et un gymnase jetable « GYM ALIAS BEHAT » rattaché à l\'alias « SALLE DU 8 MAI »')]
    public function uneEquipeEtGymnaseAvecAlias(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'Nom FBI Jetable', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME, 'source' => 'manual'], $this->token), 'gymnase');
        $this->rattacher($this->venueId, self::ALIAS_LABEL);

        // Un accès match le jour de la rencontre, pour pouvoir placer le domicile.
        $dayOfWeek = (int) new DateTimeImmutable($this->matchDate)->format('N');
        $this->matchWindowId = $this->createdId(
            $this->apiPost('venue_match_windows', ['venueId' => $this->venueId, 'dayOfWeek' => $dayOfWeek, 'startTime' => '14:00', 'endTime' => '18:00'], $this->token),
            'accès match',
        );
    }

    #[Given('un deuxième gymnase jetable « GYM AUTRE BEHAT » rattaché à l\'alias « SALLE RAPHAEL DE BARROS »')]
    public function unDeuxiemeGymnaseAvecAlias(): void
    {
        $this->venueId2 = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME_2, 'source' => 'manual'], $this->token), 'gymnase');
        $this->rattacher($this->venueId2, self::OTHER_ALIAS_LABEL);
    }

    #[Given('une rencontre sœur atteste la graphie brute « Salle du 8 Mai » pour ce gymnase')]
    public function uneSoeurAtteste(): void
    {
        // La sœur naît AVEC le gymnase (son libellé normalisé égale l'alias), et porte
        // sa graphie BRUTE dans le libellé FBI stocké — c'est elle qu'on retrouvera.
        $this->deposit(self::REF_SIBLING, self::RAW_LABEL, [['division' => self::DIVISION, 'teamId' => $this->teamId]]);
        $this->competitionId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
            admin: true,
        );
        if (($this->fixtureJson(self::REF_SIBLING)['venueId'] ?? null) !== $this->venueId) {
            throw new RuntimeException('la sœur aurait dû être rattachée au gymnase par son alias');
        }
    }

    #[When('je dépose puis place un domicile dans « GYM ALIAS BEHAT » sous le libellé du gymnase')]
    public function jeDeposeEtPlaceSousLeNomDuGymnase(): void
    {
        $this->deposeEtPlace(self::VENUE_NAME);
    }

    #[When('je dépose puis place un domicile dans « GYM ALIAS BEHAT » sous l\'alias « Salle du 8 Mai »')]
    public function jeDeposeEtPlaceSousLAlias(): void
    {
        $this->deposeEtPlace(self::RAW_LABEL);
    }

    #[When('la ligue re-dépose exactement la même rencontre')]
    public function laLigueRedeposeIdentique(): void
    {
        $this->deposit(self::REF_MAIN, self::RAW_LABEL, null);
    }

    #[When('la ligue re-dépose la rencontre au libellé « SALLE RAPHAEL DE BARROS »')]
    public function laLigueRedeposeAutreGymnase(): void
    {
        $this->deposit(self::REF_MAIN, self::OTHER_ALIAS_LABEL, null);
    }

    #[When('la ligue re-dépose la rencontre au libellé « SALLE INCONNUE » et je garde l\'appli')]
    public function laLigueRedeposeInconnuEtJeGardeLAppli(): void
    {
        $this->deposit(self::REF_MAIN, 'SALLE INCONNUE', null);
        $result = $this->apiPost('fixtures/review/deviations', [
            'fixtureId' => $this->fixtureId(self::REF_MAIN),
            'field' => 'venue',
            'choice' => 'keep_app',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('garder l\'appli a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('aucun écart de salle n\'est ouvert et la rencontre est validée')]
    public function aucunEcartEtValidee(): void
    {
        $json = $this->fixtureJson(self::REF_MAIN);
        if ($this->hasVenueDeviation($json)) {
            throw new RuntimeException('un alias confirmé du gymnase placé ne devrait pas être un écart');
        }
        if (($json['status'] ?? null) !== 'VALIDATED') {
            throw new RuntimeException(\sprintf('attestée sur les trois champs, la rencontre aurait dû être « validée » (statut « %s »)', \is_string($json['status'] ?? null) ? $json['status'] : 'inconnu'));
        }
    }

    #[Then('un écart de salle est ouvert et la rencontre n\'est pas validée')]
    public function unEcartEtPasValidee(): void
    {
        $json = $this->fixtureJson(self::REF_MAIN);
        if (!$this->hasVenueDeviation($json)) {
            throw new RuntimeException('un alias vers un AUTRE gymnase aurait dû lever l\'écart');
        }
        if (($json['status'] ?? null) === 'VALIDATED') {
            throw new RuntimeException('une salle qui n\'est pas celle du placement n\'atteste rien : jamais validée');
        }
    }

    #[Then('le registre « à corriger dans FBI » propose de taper « Salle du 8 Mai »')]
    public function leRegistreProposeLaGraphieBrute(): void
    {
        $entry = $this->venueCorrection();
        if (($entry['venueFbiLabel'] ?? null) !== self::RAW_LABEL) {
            throw new RuntimeException(\sprintf('le registre devrait proposer la graphie brute « %s », pas « %s »', self::RAW_LABEL, \is_string($entry['venueFbiLabel'] ?? null) ? $entry['venueFbiLabel'] : 'rien'));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        foreach ([self::REF_MAIN, self::REF_SIBLING] as $ref) {
            $id = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, $ref),
                admin: true,
            );
            if ('' !== $id) {
                // Les entrées « à corriger » sont purgées avec la rencontre ; en test on
                // les efface directement (SQL admin) pour un run répétable.
                $this->dbalExec(\sprintf('DELETE FROM fbi_correction WHERE fixture_id=\'%s\'', $id), admin: true);
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        if ('' !== $this->competitionId) {
            $this->apiDelete(\sprintf('competitions/%s', $this->competitionId), $this->token);
        }
        if ('' !== $this->matchWindowId) {
            $this->apiDelete(\sprintf('venue_match_windows/%s', $this->matchWindowId), $this->token);
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

    /** Dépose la rencontre principale sous $salle, puis la place PLACED dans le gymnase à alias. */
    private function deposeEtPlace(string $salle): void
    {
        $this->deposit(self::REF_MAIN, $salle, [['division' => self::DIVISION, 'teamId' => $this->teamId]]);
        if ('' === $this->competitionId) {
            $this->competitionId = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
                admin: true,
            );
        }
        $result = $this->apiPut(\sprintf('fixtures/%s', $this->fixtureId(self::REF_MAIN)), [
            'teamId' => $this->teamId,
            'matchDate' => $this->matchDate,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire Behat',
            'venueId' => $this->venueId,
            'kickoffTime' => '15:30',
            'status' => 'PLACED',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le placement de la rencontre a répondu %d (200 attendu)', $result['status']));
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

    /** L'entrée « à corriger » de SALLE de la rencontre principale, sinon une erreur nommée. */
    private function venueCorrection(): array
    {
        $json = $this->apiGet('fixtures/fbi-corrections', $this->token)['json'];
        $rows = \is_array($json) ? ($json['corrections'] ?? []) : [];
        $fixtureId = $this->fixtureId(self::REF_MAIN);
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && 'venue' === ($row['field'] ?? null) && ($row['fixtureId'] ?? null) === $fixtureId) {
                return $row;
            }
        }

        throw new RuntimeException('aucune entrée « à corriger » de salle sur la rencontre');
    }

    /** Rattache un libellé fédéral à un gymnase (POST venues/{id}/external-labels, stocké normalisé). */
    private function rattacher(string $venueId, string $label): void
    {
        $result = $this->apiPost(\sprintf('venues/%s/external-labels', $venueId), ['label' => $label], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le rattachement du libellé a répondu %d (200 attendu)', $result['status']));
        }
    }

    /** @return array<string, mixed> */
    private function fixtureJson(string $ref): array
    {
        $json = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId($ref)), $this->token)['json'];

        return \is_array($json) ? $json : [];
    }

    private function fixtureId(string $ref): string
    {
        $id = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, $ref),
            admin: true,
        );
        if ('' === $id) {
            throw new RuntimeException(\sprintf('la rencontre « %s » est introuvable', $ref));
        }

        return $id;
    }

    /**
     * Dépose un fichier FBI d'UNE ligne (domicile, salle au choix) via multipart.
     *
     * @param list<array{division: string, teamId: string}>|null $mappings
     */
    private function deposit(string $ref, string $salle, ?array $mappings): void
    {
        $rows = [[
            self::DIVISION,
            $ref,
            $this->clubName,
            'Adversaire Behat',
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
        $path = tempnam(sys_get_temp_dir(), 'bnf') . '.xlsx';
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
