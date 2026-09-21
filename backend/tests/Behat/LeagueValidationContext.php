<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Controller\CompetitionEntryDeadlinesController;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * Le geste « validé ligue » en lot piloté par l'ÉCHÉANCE du championnat (lot O), de bout
 * en bout sur la stack qui tourne : un club en cours de saison importe des domiciles déjà
 * datés (heure + gymnase), apparie le libellé de salle, PUIS l'échéance de saisie du
 * championnat pilote la validation — passée, on bascule ; non passée, rien n'est proposé.
 * Un domicile sans heure n'est pas validé mais il est nommé à traiter, et rejouer ne
 * bascule plus rien. On possède TOUTES les ressources (équipe + gymnase + division
 * jetables) et on restaure en fin de scénario.
 *
 * ⚠ La division jetable N'EST PAS appariée à la fédération : poser son échéance n'écrit
 * QUE la valeur club (`Competition.entryDeadline`), jamais la table partagée entre tous
 * les clubs ({@see CompetitionEntryDeadlinesController}).
 */
final class LeagueValidationContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string DIVISION = 'BEHAT LEAGUE D';

    private const string REF_DATED = 'BHL-2001';

    private const string REF_NO_HOUR = 'BHL-2002';

    private const string VENUE_NAME = 'GYM BEHAT';

    private string $token = '';

    private string $clubId = '';

    private string $clubName = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $venueId = '';

    private string $competitionId = '';

    private string $matchWindowId = '';

    private string $matchDate = '';

    private int $lastConfirmed = -1;

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

        // Un samedi FUTUR (14 mars 2099) : « validé ligue » n'a AUCUNE condition de
        // date — un domicile futur portant heure + gymnase est enregistré côté
        // fédération tout autant. Un samedi lève l'ambiguïté de l'accès match posé plus bas.
        $this->matchDate = '2099-03-14';
    }

    #[Given('une équipe jetable et un gymnase jetable « GYM BEHAT »')]
    public function uneEquipeEtGymnaseJetables(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'League Jetable', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe');
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

    #[When('je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure')]
    public function jeDeposeUnFichierDateEtSansHeure(): void
    {
        // Deux domiciles au même gymnase : l'un porte une heure (15:30), l'autre non.
        $this->deposit([
            [self::DIVISION, self::REF_DATED, $this->clubName, 'Adversaire A', $this->fbiDate($this->matchDate), '15:30', self::VENUE_NAME],
            [self::DIVISION, self::REF_NO_HOUR, $this->clubName, 'Adversaire B', $this->fbiDate($this->matchDate), '', self::VENUE_NAME],
        ], [['division' => self::DIVISION, 'teamId' => $this->teamId]]);

        $this->competitionId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM competition WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::DIVISION),
            admin: true,
        );
    }

    #[When('j\'apparie le libellé « GYM BEHAT » au gymnase jetable')]
    public function jApparieLeLibelle(): void
    {
        // Backfill : les domiciles NON PLACÉS de ce libellé reçoivent le gymnase, tout
        // en RESTANT à placer (UNPLACED) — c'est ce qui les rend « validables ligue ».
        $result = $this->apiPost(\sprintf('venues/%s/external-labels', $this->venueId), ['label' => self::VENUE_NAME], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('l\'appariement du libellé a répondu %d (200 attendu)', $result['status']));
        }
    }

    #[Given('l\'échéance de saisie du championnat est déjà passée')]
    public function lEcheanceDejaPassee(): void
    {
        // Une échéance BIEN dans le passé : le championnat est échu, la validation ouverte.
        $this->setDeadline('2020-09-10');
    }

    #[Given('l\'échéance de saisie du championnat n\'est pas encore passée')]
    public function lEcheancePasEncorePassee(): void
    {
        // Une échéance loin dans le futur : le championnat n'est pas échu, rien n'est proposé.
        $this->setDeadline('2099-12-31');
    }

    #[Then('1 rencontre est validable « validé ligue »')]
    public function uneRencontreEstValidable(): void
    {
        $this->assertCount(1);
    }

    #[Then('le domicile sans heure est nommé parmi les rencontres à traiter')]
    public function leDomicileSansHeureEstNomme(): void
    {
        $noHourId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, self::REF_NO_HOUR),
            admin: true,
        );
        $result = $this->apiGet('fixtures/league-validation', $this->token);
        $toTreat = \is_array($result['json']['toTreat'] ?? null) ? $result['json']['toTreat'] : [];
        foreach ($toTreat as $entry) {
            if (\is_array($entry) && ($entry['fixtureId'] ?? null) === $noHourId) {
                if ('NO_KICKOFF' !== ($entry['reason'] ?? null)) {
                    throw new RuntimeException(\sprintf('le domicile sans heure est nommé avec la raison « %s » au lieu de NO_KICKOFF', (string) ($entry['reason'] ?? '')));
                }

                return;
            }
        }
        throw new RuntimeException('le domicile sans heure n\'est PAS nommé parmi les rencontres à traiter');
    }

    #[Then('plus aucune rencontre n\'est validable « validé ligue »')]
    #[Then('aucune rencontre n\'est validable « validé ligue »')]
    public function plusAucuneRencontreValidable(): void
    {
        $this->assertCount(0);
    }

    #[When('je valide les placements côté ligue')]
    public function jeValideLesPlacements(): void
    {
        $result = $this->apiPost('fixtures/league-validation', [], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('valider les placements côté ligue a répondu %d (200 attendu)', $result['status']));
        }
        $this->lastConfirmed = \is_int($result['json']['confirmed'] ?? null) ? $result['json']['confirmed'] : -1;
    }

    #[Then('1 rencontre a basculé « validé ligue »')]
    public function uneRencontreABascule(): void
    {
        if (1 !== $this->lastConfirmed) {
            throw new RuntimeException(\sprintf('%d rencontre(s) basculée(s) au lieu de 1', $this->lastConfirmed));
        }
    }

    #[Then('la rencontre datée est « validé ligue »')]
    public function laRencontreDateeEstValidee(): void
    {
        $this->assertStatusOf(self::REF_DATED, 'VALIDATED');
    }

    #[Then('le domicile sans heure est resté « à placer »')]
    public function leDomicileSansHeureEstReste(): void
    {
        $this->assertStatusOf(self::REF_NO_HOUR, 'UNPLACED');
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        foreach ([self::REF_DATED, self::REF_NO_HOUR] as $ref) {
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
        if ('' !== $this->matchWindowId) {
            $this->apiDelete(\sprintf('venue_match_windows/%s', $this->matchWindowId), $this->token);
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

    private function setDeadline(string $date): void
    {
        if ('' === $this->competitionId) {
            throw new RuntimeException('aucune compétition à échéancer — le dépôt FBI a-t-il eu lieu ?');
        }
        // Division NON appariée → écrit seulement la valeur CLUB, jamais la table partagée.
        $result = $this->apiPost('competitions/entry-deadlines', ['competitionIds' => [$this->competitionId], 'deadline' => $date], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('poser l\'échéance de saisie a répondu %d (200 attendu)', $result['status']));
        }
    }

    private function assertCount(int $expected): void
    {
        $result = $this->apiGet('fixtures/league-validation', $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('la lecture des validables ligue a répondu %d (200 attendu)', $result['status']));
        }
        $count = $result['json']['totalValidatable'] ?? null;
        if ($count !== $expected) {
            throw new RuntimeException(\sprintf('%s rencontre(s) validable(s) au lieu de %d', \is_int($count) ? (string) $count : 'inconnu', $expected));
        }
    }

    private function assertStatusOf(string $ref, string $expected): void
    {
        $status = $this->dbalScalar(
            \sprintf('SELECT status AS behatval FROM fixture WHERE club_id=\'%s\' AND external_ref=\'%s\' LIMIT 1', $this->clubId, $ref),
            admin: true,
        );
        if ($status !== $expected) {
            throw new RuntimeException(\sprintf('statut « %s » au lieu de « %s » pour la rencontre %s', '' === $status ? 'inconnu' : $status, $expected, $ref));
        }
    }

    private function fbiDate(string $iso): string
    {
        return date('d/m/Y', (int) strtotime($iso));
    }

    /**
     * Dépose un fichier FBI multipart, exactement comme le dialog d'import.
     *
     * @param list<list<string>>                                 $rows     lignes de données (sans en-tête)
     * @param list<array{division: string, teamId: string}>|null $mappings
     */
    private function deposit(array $rows, ?array $mappings): void
    {
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
        $path = tempnam(sys_get_temp_dir(), 'bhl') . '.xlsx';
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
