<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\SeasonResolver;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use RuntimeException;

/**
 * Promesse : la saison suivante se prépare à partir du 1er mai, date tenue par le SERVEUR —
 * avant, un appel direct est refusé (409, message exact) et aucune saison ne naît ; un club
 * de démonstration suit la même date à son horloge simulée ; un club qui n'a rien préparé
 * avant le 15 juillet attend le 1er mai suivant ; `/api/me` annonce la date d'ouverture.
 *
 * Un décor JETABLE par scénario ({@see SmallClubFixtureTrait::registerSmallClub}, inscription
 * seule : la bascule ne lit que la saison), jamais le club de démonstration partagé. Le club
 * réel sort de Découverte par le geste support réel `app:clubs:set-plan --plan=essentiel
 * --paid-season=next` (patron {@see SeasonTransitionContext}) ; la saison réglée d'un club en
 * retard (deux saisons plus loin, ce que la commande ne sait pas dire) et le drapeau démo sont
 * des POINTAGES SQL. La date : horloge de dev globale pour un club réel, horloge simulée du
 * club (`POST /api/club/clock`) pour un club de démonstration.
 *
 * Plus bas : `Integration/Api/SeasonTransitionApiTest`, `Integration/Service/SeasonTransitionServiceTest`
 * (la copie, à une date figée dans la fenêtre).
 */
final class SeasonPreparationDateContext extends BaseContext
{
    use SeasonCalendarDayTrait;
    use SmallClubFixtureTrait;

    private bool $demo = false;

    private string $currentSeasonId = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $prepared = ['status' => 0, 'json' => []];

    #[Given('/^un petit club (?P<kind>réel qui a réglé la saison suivante|de démonstration|réel en retard, qui a réglé la saison d\'après la suivante)$/u')]
    public function unPetitClub(string $kind): void
    {
        $this->registerSmallClub();
        $this->currentSeasonId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ('' === $this->currentSeasonId) {
            throw new RuntimeException('le petit club n\'a pas de saison en cours');
        }
        // Une horloge de dev laissée figée par un run interrompu masquerait l'horloge du club.
        $this->releaseDevClock($this->smallClubToken);

        if ('de démonstration' === $kind) {
            $this->demo = true;
            $this->dbalExec(\sprintf('UPDATE club SET is_demo = true WHERE id=\'%s\'', $this->smallClubId), admin: true);

            return;
        }
        $this->runConsole(['php', 'bin/console', 'app:clubs:set-plan', '--club=' . $this->smallClubId, '--plan=essentiel', '--paid-season=next']);
        if (str_starts_with($kind, 'réel en retard')) {
            $paid = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 2;
            $this->dbalExec(\sprintf('UPDATE club SET paid_season_year = %d WHERE id=\'%s\'', $paid, $this->smallClubId), admin: true);
        }
    }

    #[Given('/^nous sommes le (?P<day>.+)$/u')]
    public function nousSommesLe(string $day): void
    {
        $iso = $this->seasonDay($day);
        if (!$this->demo) {
            $this->pinDevClock($iso, $this->smallClubToken);

            return;
        }
        $clock = $this->apiPost('club/clock', ['date' => $iso], $this->smallClubToken);
        if (200 !== $clock['status']) {
            throw new RuntimeException(\sprintf('l\'horloge simulée du club de démonstration au %s a répondu %d : %s', $iso, $clock['status'], json_encode($clock['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[When('je prépare la saison suivante')]
    public function jePrepareLaSaisonSuivante(): void
    {
        $this->prepared = $this->apiPost(\sprintf('seasons/%s/transition', $this->currentSeasonId), [], $this->smallClubToken);
    }

    #[Then('/^c\'est refusé : « (?P<message>.+) »$/u')]
    public function cEstRefuse(string $message): void
    {
        $served = $this->prepared['json']['error'] ?? '';
        if (409 !== $this->prepared['status'] || $served !== $message) {
            throw new RuntimeException(\sprintf('préparer la saison suivante a répondu %d « %s » (attendu 409 « %s »)', $this->prepared['status'], \is_string($served) ? $served : json_encode($this->prepared['json'], \JSON_UNESCAPED_UNICODE), $message));
        }
    }

    #[Then('le club n\'a toujours qu\'une saison')]
    public function leClubNATouJoursQuUneSaison(): void
    {
        $count = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ('1' !== $count) {
            throw new RuntimeException(\sprintf('le club porte %s saisons après le refus (attendu 1 : aucune saison ne naît)', $count));
        }
    }

    #[Then('/^mon espace annonce l\'ouverture le (?P<day>.+)$/u')]
    public function monEspaceAnnonceLOuverture(string $day): void
    {
        $expected = $this->seasonDay($day);
        $me = $this->apiGet('me', $this->smallClubToken);
        $served = $me['json']['seasonPreparationOpensOn'] ?? null;
        if (200 !== $me['status'] || $served !== $expected) {
            throw new RuntimeException(\sprintf('/api/me annonce l\'ouverture au %s (attendu %s)', json_encode($served), $expected));
        }
    }

    #[Then('/^la saison (?P<which>suivante|d\'après la saison suivante) naît$/u')]
    public function laSaisonNait(string $which): void
    {
        $seasonId = $this->prepared['json']['seasonId'] ?? null;
        $startDate = $this->prepared['json']['startDate'] ?? null;
        if (201 !== $this->prepared['status'] || !\is_string($seasonId) || !\is_string($startDate)) {
            throw new RuntimeException(\sprintf('préparer la saison suivante a répondu %d (201 attendu) : %s', $this->prepared['status'], json_encode($this->prepared['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $expectedYear = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + ('suivante' === $which ? 1 : 2);
        $bornYear = SeasonResolver::seasonYear(new DateTimeImmutable($startDate));
        if ($bornYear !== $expectedYear) {
            throw new RuntimeException(\sprintf('la saison née commence en %d (saison %d attendue)', $bornYear, $expectedYear));
        }
        $count = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ('2' !== $count) {
            throw new RuntimeException(\sprintf('le club porte %s saisons (attendu 2)', $count));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->releaseDevClock($this->smallClubToken);
        $this->teardownSmallClub();
    }
}
