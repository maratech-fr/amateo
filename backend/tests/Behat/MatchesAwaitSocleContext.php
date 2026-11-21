<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Promesse : tant que le planning de saison n'est pas validé, le module des matchs refuse avec son
 * motif (`SocleGuard`) l'import des rencontres et ses huit fonctions fermées (décision Q9 (a), lot 2
 * PR 5 : placer, envoyer les conflits et son aperçu, confirmer les engagements, lire et appliquer
 * les rencontres de la fédération, lire les domiciles à valider, revoir les rencontres et leurs
 * écarts) ; une fois validé, ces refus tombent — les trois gestes de la fédération ne sont pas
 * rejoués après la validation : ils appelleraient la fédération réelle. En Découverte, un placement refusé ne coûte rien, un placement abouti
 * coûte un crédit — décompté par le worker au COMPLETED (`PlaceMatchesHandler`), pas au 202.
 *
 * Décor DÉDIÉ et léger ({@see SmallClubFixtureTrait::registerSmallClub} : club neuf, offre
 * Découverte), 2 équipes, 1 gymnase de 4 créneaux d'entraînement ouvert aux matchs le samedi
 * 14h-18h ; une génération du socle. En Découverte, le placement se demande week-end par week-end
 * (le corps porte la fenêtre samedi-dimanche). Plus bas : `SocleGuardTest`, `PlaceMatchesHandler`.
 */
final class MatchesAwaitSocleContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private const string SOCLE_MESSAGE = 'Validez le planning de saison avant de créer un match ou un planning de période.';

    private string $venueId = '';

    private string $saturday = '';

    private string $fixtureId = '';

    private int $creditsBeforePlacement = 0;

    #[Given('un petit club neuf en offre Découverte, deux équipes et un gymnase ouvert aux matchs le samedi, sans planning de saison validé')]
    public function unPetitClubNeuf(): void
    {
        $this->registerSmallClub();
        $categories = $this->apiGet('sport_categories', $this->smallClubToken);
        $category = $categories['json']['member'][0]['id'] ?? null;
        if (!\is_string($category)) {
            throw new RuntimeException('aucune catégorie sportive pour le petit club');
        }
        foreach (['SM1', 'SF1'] as $name) {
            $this->smallClubTeamIds[$name] = $this->smallClubStringField($this->apiPost('teams', [
                'name' => $name, 'sportCategoryId' => $category, 'priorityTierId' => 3, 'sessionsPerWeek' => 1,
            ], $this->smallClubToken)['json'], 'id');
        }
        $this->venueId = $this->smallClubStringField($this->apiPost('venues', ['name' => 'Gymnase Centre', 'source' => 'manual'], $this->smallClubToken)['json'], 'id');
        foreach ([1, 2] as $day) {
            foreach (['19:00', '20:30'] as $start) {
                $this->smallClubStringField($this->apiPost('venue_training_slots', [
                    'venueId' => $this->venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1,
                ], $this->smallClubToken)['json'], 'id');
            }
        }
        $this->smallClubStringField($this->apiPost('venue_match_windows', [
            'venueId' => $this->venueId, 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '18:00',
        ], $this->smallClubToken)['json'], 'id');

        $paris = new DateTimeZone('Europe/Paris');
        $this->saturday = new DateTimeImmutable('now', $paris)->modify('next saturday')->format('Y-m-d');
        if ('decouverte' !== ($this->entitlements()['planCode'] ?? null)) {
            throw new RuntimeException('un club neuf devait naître en offre Découverte');
        }
    }

    #[Then('/^chacun de ces gestes m\'est refusé \(409\) : « (?P<message>.+) »$/u')]
    public function chacunDeCesGestesEstRefuse(string $message, TableNode $table): void
    {
        $failures = [];
        foreach ($table->getColumnsHash() as $row) {
            $response = $this->attempt($row['geste']);
            $served = $this->refusalText($response['json']);
            if (409 !== $response['status'] || $served !== $message) {
                $failures[] = \sprintf('« %s » : %d « %s »', $row['geste'], $response['status'], $served);
            }
        }
        if ([] !== $failures) {
            throw new RuntimeException(\sprintf("attendu 409 « %s » :\n%s", $message, implode("\n", $failures)));
        }
    }

    #[Then('ces refus n\'ont coûté aucun crédit')]
    public function cesRefusNOntCouteAucunCredit(): void
    {
        $used = $this->entitlements()['creditsUsed'] ?? null;
        if (0 !== $used) {
            throw new RuntimeException(\sprintf('les refus ont coûté %s crédit(s) (attendu 0)', json_encode($used)));
        }
    }

    #[When('je génère et valide le planning de saison')]
    public function jeGenereEtValide(): void
    {
        $this->generateAndValidateSmallClubSocle();
    }

    #[Then('aucun de ces gestes n\'est plus refusé faute de planning de saison')]
    public function aucunDeCesGestesNEstPlusRefuse(TableNode $table): void
    {
        foreach ($table->getColumnsHash() as $row) {
            $response = $this->attempt($row['geste']);
            if (self::SOCLE_MESSAGE === $this->refusalText($response['json']) || $response['status'] >= 500) {
                throw new RuntimeException(\sprintf('« %s » répond %d « %s » une fois le planning de saison validé', $row['geste'], $response['status'], $this->refusalText($response['json'])));
            }
        }
    }

    #[When('un match à domicile de la première équipe est prévu ce samedi, et que je place les matchs du week-end')]
    public function unMatchEtJePlace(): void
    {
        $competition = $this->smallClubStringField($this->apiPost('competitions', [
            'teamId' => $this->smallClubTeamIds['SM1'], 'name' => 'Championnat', 'competitionType' => 'CHAMPIONSHIP',
        ], $this->smallClubToken)['json'], 'id');
        $this->fixtureId = $this->smallClubStringField($this->apiPost('fixtures', [
            'teamId' => $this->smallClubTeamIds['SM1'], 'matchDate' => $this->saturday, 'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire du samedi', 'competitionId' => $competition,
        ], $this->smallClubToken)['json'], 'id');

        $used = $this->entitlements()['creditsUsed'] ?? null;
        $this->creditsBeforePlacement = \is_int($used) ? $used : -1;

        $placed = $this->attempt('placer les matchs du week-end');
        $runId = $placed['json']['runId'] ?? null;
        if (202 !== $placed['status'] || !\is_string($runId)) {
            throw new RuntimeException(\sprintf('le placement du week-end a répondu %d (202 attendu) : %s', $placed['status'], json_encode($placed['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->awaitRunCompleted($runId);
    }

    #[Then('le match est placé dans le gymnase, le samedi')]
    public function leMatchEstPlace(): void
    {
        $fixture = $this->apiGet(\sprintf('fixtures/%s', $this->fixtureId), $this->smallClubToken)['json'];
        $date = \is_string($fixture['matchDate'] ?? null) ? substr($fixture['matchDate'], 0, 10) : '';
        if ('PLACED' !== ($fixture['status'] ?? null) || $this->venueId !== ($fixture['venueId'] ?? null) || $this->saturday !== $date) {
            throw new RuntimeException(\sprintf('le match n\'est pas placé au gymnase le samedi : %s', json_encode($fixture, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('ce placement abouti a coûté un crédit')]
    public function cePlacementACouteUnCredit(): void
    {
        $used = $this->entitlements()['creditsUsed'] ?? null;
        if ($this->creditsBeforePlacement + 1 !== $used) {
            throw new RuntimeException(\sprintf('le placement abouti a porté le compteur de %d à %s (attendu +1)', $this->creditsBeforePlacement, json_encode($used)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @return array{status: int, json: array<mixed>} */
    private function attempt(string $gesture): array
    {
        $sunday = date('Y-m-d', (int) strtotime($this->saturday . ' +1 day'));

        return match ($gesture) {
            'importer les rencontres' => $this->apiPost('fixtures/import', [], $this->smallClubToken),
            'placer les matchs du week-end' => $this->apiPost('fixtures/place', ['from' => $this->saturday, 'to' => $sunday], $this->smallClubToken),
            'envoyer les conflits aux coachs' => $this->apiPost('fixtures/conflicts/mail-coaches', [], $this->smallClubToken),
            'prévisualiser l\'envoi des conflits aux coachs' => $this->apiGet('fixtures/conflicts/mail-coaches/preview', $this->smallClubToken),
            'confirmer les engagements de la fédération' => $this->apiPost('ffbb/engagements/confirm', [], $this->smallClubToken),
            'lire les rencontres de la fédération' => $this->apiGet('ffbb/rencontres', $this->smallClubToken),
            'appliquer les rencontres de la fédération' => $this->apiPost('ffbb/rencontres/apply', ['decisions' => [], 'creations' => []], $this->smallClubToken),
            'lire les domiciles à valider auprès de la ligue' => $this->apiGet('fixtures/league-validation', $this->smallClubToken),
            'revoir les rencontres à l\'extérieur' => $this->apiPost('fixtures/review', ['scope' => 'away'], $this->smallClubToken),
            'revoir les écarts d\'une rencontre' => $this->apiPost('fixtures/review/deviations', [], $this->smallClubToken),
            default => throw new RuntimeException(\sprintf('geste « %s » inconnu du scénario', $gesture)),
        };
    }

    /** @param array<mixed> $json */
    private function refusalText(array $json): string
    {
        foreach (['detail', 'error'] as $key) {
            if (\is_string($json[$key] ?? null) && '' !== $json[$key]) {
                return $json[$key];
            }
        }

        return '';
    }

    private function awaitRunCompleted(string $runId): void
    {
        $deadline = time() + 120;
        do {
            $run = $this->apiGet('fixtures/placement-run', $this->smallClubToken)['json']['run'] ?? null;
            if (\is_array($run) && ($run['id'] ?? null) === $runId && \in_array($run['status'] ?? null, ['COMPLETED', 'FAILED'], true)) {
                if ('COMPLETED' !== $run['status']) {
                    throw new RuntimeException('le placement du week-end a échoué (FAILED)');
                }

                return;
            }
            sleep(1);
        } while (time() < $deadline);

        throw new RuntimeException('le placement du week-end n\'a pas abouti dans le délai');
    }

    /** @return array<mixed> */
    private function entitlements(): array
    {
        $club = $this->apiGet('me', $this->smallClubToken)['json']['club'] ?? null;
        $entitlements = \is_array($club) ? ($club['entitlements'] ?? null) : null;
        if (!\is_array($entitlements)) {
            throw new RuntimeException('/api/me ne sert pas les droits de l\'offre du club');
        }

        return $entitlements;
    }
}
