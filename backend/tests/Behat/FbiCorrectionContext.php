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
 * Le registre « à corriger dans FBI » de bout en bout, sur la stack qui tourne :
 * quand le gestionnaire GARDE l'appli sur un écart, FBI est en retard → une entrée
 * ouverte dit quoi taper dans FBI. Un re-dépôt de la même valeur ne re-crée pas
 * d'écart (juste « vu dans FBI ») ; un dépôt montrant FBI corrigé ferme l'entrée ; et
 * le gestionnaire peut la cocher « corrigé » à la main.
 *
 * On possède toutes les ressources jetables et on restaure en fin de scénario.
 */
final class FbiCorrectionContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string DIVISION = 'BEHAT FBI CORR D';

    private const string EXTERNAL_REF = 'BFC-1';

    private const string VENUE_NAME = 'GYM BEHAT';

    private string $token = '';

    private string $clubId = '';

    private string $clubName = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $venueId = '';

    private string $fixtureId = '';

    private string $competitionId = '';

    private string $matchWindowId = '';

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

        // Le samedi de la semaine ISO SUIVANTE (compté depuis le jour du club, Europe/Paris) :
        // hors de la fenêtre « FBI fait foi », donc un écart s'ARBITRE au lieu d'être appliqué
        // d'office — condition pour que « garder l'appli » ait un sens.
        $clubToday = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
        $mondayThisWeek = $clubToday->modify(\sprintf('-%d days', (int) $clubToday->format('N') - 1));
        $this->matchDate = $mondayThisWeek->modify('+12 days')->format('Y-m-d');
    }

    #[Given('une équipe jetable et un gymnase jetable « GYM BEHAT »')]
    public function uneEquipeEtGymnaseJetables(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'FBI Corr Jetable', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => self::VENUE_NAME, 'source' => 'manual'], $this->token), 'gymnase');
    }

    #[Given('un accès match le samedi sur « GYM BEHAT »')]
    public function unAccesMatchSurGymBehat(): void
    {
        $dayOfWeek = (int) new DateTimeImmutable($this->matchDate)->format('N');
        $this->matchWindowId = $this->createdId(
            $this->apiPost('venue_match_windows', ['venueId' => $this->venueId, 'dayOfWeek' => $dayOfWeek, 'startTime' => '14:00', 'endTime' => '18:00'], $this->token),
            'accès match',
        );
    }

    #[When('je dépose puis place un domicile au gymnase « GYM BEHAT » à 15h30')]
    public function jeDeposeEtPlace(): void
    {
        $this->deposit('15:30', [['division' => self::DIVISION, 'teamId' => $this->teamId]]);
        $this->fixtureId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, self::EXTERNAL_REF),
            admin: true,
        );
        if ('' === $this->fixtureId) {
            throw new RuntimeException('la rencontre importée est introuvable après le dépôt');
        }
        $this->competitionId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
            admin: true,
        );

        $result = $this->apiPut(\sprintf('fixtures/%s', $this->fixtureId), [
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

    #[When('FBI affiche 17h00 et je garde l\'appli')]
    public function fbiAffiche17hEtJeGardeLAppli(): void
    {
        // Un dépôt divergent (17:00) ouvre un écart d'heure ; on garde l'appli.
        $this->deposit('17:00', null);
        $result = $this->apiPost('fixtures/review/deviations', [
            'fixtureId' => $this->fixtureId,
            'field' => 'kickoff',
            'choice' => 'keep_app',
        ], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('garder l\'appli a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('une entrée « à corriger dans FBI » est ouverte : taper 15:30, FBI affiche 17:00')]
    public function uneEntreeEstOuverte(): void
    {
        $entry = $this->onlyCorrection();
        if (($entry['field'] ?? null) !== 'kickoff') {
            throw new RuntimeException('l\'entrée « à corriger » devrait porter sur l\'heure');
        }
        if (($entry['appValue'] ?? null) !== '15:30' || ($entry['fbiValue'] ?? null) !== '17:00') {
            throw new RuntimeException('l\'entrée devrait dire : taper 15:30, FBI affiche 17:00');
        }
    }

    #[When('FBI affiche toujours 17h00 au dépôt suivant')]
    public function fbiAfficheToujours17h(): void
    {
        $this->deposit('17:00', null);
    }

    #[Then('aucun écart n\'est à traiter et l\'entrée dit « vu dans FBI »')]
    public function aucunEcartEtVuDansFbi(): void
    {
        $state = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->token)['json']['reviewState'] ?? null;
        if ('REVIEWED' !== $state) {
            throw new RuntimeException('le re-dépôt de la même valeur ne devrait PAS re-créer d\'écart à traiter');
        }
        $entry = $this->onlyCorrection();
        if (null === ($entry['lastSeenInFbiAt'] ?? null)) {
            throw new RuntimeException('l\'entrée devrait porter un « vu dans FBI »');
        }
    }

    #[When('FBI est corrigé et affiche de nouveau 15h30')]
    public function fbiEstCorrige(): void
    {
        $this->deposit('15:30', null);
    }

    #[When('je marque la correction faite dans FBI')]
    public function jeMarqueLaCorrectionFaite(): void
    {
        $entry = $this->onlyCorrection();
        $id = $entry['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('l\'entrée à fermer n\'a pas d\'identifiant');
        }
        $result = $this->apiPost(\sprintf('fixtures/fbi-corrections/%s/close', $id), [], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('marquer corrigé a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Then('le registre « à corriger dans FBI » est vide')]
    public function leRegistreEstVide(): void
    {
        $corrections = $this->corrections();
        if ([] !== $corrections) {
            throw new RuntimeException(\sprintf('le registre devrait être vide (reste %d entrée(s))', \count($corrections)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        if ('' !== $this->fixtureId) {
            $this->apiDelete(\sprintf('fixtures/%s', $this->fixtureId), $this->token);
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
        if ('' !== $this->venueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->venueId), $this->token);
        }
        // Les entrées « à corriger » de cette rencontre sont purgées avec la saison ;
        // en test on les efface directement (SQL admin) pour un run répétable.
        if ('' !== $this->fixtureId) {
            $this->dbalExec(
                \sprintf('DELETE FROM fbi_correction WHERE fixture_id=\'%s\'', $this->fixtureId),
                admin: true,
            );
        }
        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /**
     * L'unique entrée « à corriger » ouverte, sinon une erreur nommée.
     *
     * @return array<string, mixed>
     */
    private function onlyCorrection(): array
    {
        $corrections = $this->corrections();
        if (1 !== \count($corrections)) {
            throw new RuntimeException(\sprintf('exactement une entrée « à corriger » attendue, %d trouvée(s)', \count($corrections)));
        }

        return $corrections[0];
    }

    /**
     * Les entrées OUVERTES du registre « à corriger dans FBI » du club.
     *
     * @return list<array<string, mixed>>
     */
    private function corrections(): array
    {
        $json = $this->apiGet('fixtures/fbi-corrections', $this->token)['json'];
        $rows = $json['corrections'] ?? [];

        return array_values(array_filter(\is_array($rows) ? $rows : [], 'is_array'));
    }

    /**
     * Dépose un fichier FBI d'UNE ligne (domicile GYM BEHAT, heure au choix) via multipart.
     *
     * @param list<array{division: string, teamId: string}>|null $mappings
     */
    private function deposit(string $heure, ?array $mappings): void
    {
        $rows = [[
            self::DIVISION,
            self::EXTERNAL_REF,
            $this->clubName,
            'Adversaire Behat',
            date('d/m/Y', (int) strtotime($this->matchDate)),
            $heure,
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
        $path = tempnam(sys_get_temp_dir(), 'bfc') . '.xlsx';
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
