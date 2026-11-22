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
 * Promesse : l'offre d'une saison ne dépend que de ce qui a été réglé POUR ELLE, par le geste
 * « Offre » du support (`app:clubs:set-plan`) ; régler la saison suivante ne touche pas la saison
 * en cours, repasser en Découverte ne retire pas la saison suivante réglée, un club jamais réglé
 * est en Découverte, un club de démonstration n'est jamais bridé ; le geste « Marquer la saison
 * suivante payée » n'existe plus.
 *
 * Décor JETABLE par scénario : un club inscrit par l'API ({@see SmallClubFixtureTrait::registerSmallClub})
 * et un planning en brouillon (aucun solve). Le geste du support est la VRAIE commande lancée par
 * la console (`BaseContext::runConsole`) ; seul le drapeau de démonstration est un pointage SQL
 * (patron {@see DiscoveryCreditContext}). L'offre se lit comme la lit l'écran, sur `/api/me`
 * (`club.entitlements`), pour la saison en cours ou, par l'en-tête `X-Season-Id`, pour la saison
 * suivante une fois préparée. La sortie qui décompte est l'export en tableur (synchrone, réglé à la
 * réponse). La date de préparation : horloge de dev globale, relâchée en fin de scénario.
 *
 * Plus bas : `Integration/Command/SetClubPlanCommandTest` (les deux années d'un geste, club resté
 * sur sa saison passée, horloge de démonstration) ; la bascule elle-même :
 * `la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`.
 */
final class SubscriptionBySeasonContext extends BaseContext
{
    use SeasonCalendarDayTrait;
    use SmallClubFixtureTrait;

    private string $scheduleId = '';

    private string $currentSeasonId = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $prepared = ['status' => 0, 'json' => []];

    #[Given('un club inscrit par l\'API, avec un planning de saison en brouillon')]
    public function unClubInscritAvecUnBrouillon(): void
    {
        $this->registerSmallClub();
        // Une horloge de dev laissée figée par un run interrompu fausserait « saison en cours ».
        $this->releaseDevClock($this->smallClubToken);
        $this->currentSeasonId = $this->dbalScalar(\sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        if ('' === $this->currentSeasonId) {
            throw new RuntimeException('le club inscrit n\'a pas de saison en cours');
        }
        $created = $this->apiPost('schedules', ['name' => 'Brouillon', 'status' => 'DRAFT'], $this->smallClubToken);
        $this->scheduleId = $this->smallClubStringField($created['json'], 'id');
    }

    #[Given('aucun geste du support')]
    public function aucunGesteDuSupport(): void
    {
        // Témoin : l'inscription seule ne règle rien.
    }

    #[Given('/^le support règle la bêta pour la saison (?P<season>en cours|suivante seulement)$/u')]
    public function leSupportRegleLaBeta(string $season): void
    {
        $this->runConsole(['php', 'bin/console', 'app:clubs:set-plan', '--club=' . $this->smallClubId, '--plan=beta', '--paid-season=' . ('en cours' === $season ? 'current' : 'next')]);
    }

    #[Given('le club passe en démonstration, sans aucun geste du support')]
    public function leClubPasseEnDemonstration(): void
    {
        $this->dbalExec(\sprintf('UPDATE club SET is_demo = true WHERE id=\'%s\'', $this->smallClubId), admin: true);
    }

    #[When('le support repasse le club en Découverte')]
    public function leSupportRepasseEnDecouverte(): void
    {
        $this->runConsole(['php', 'bin/console', 'app:clubs:set-plan', '--club=' . $this->smallClubId, '--plan=decouverte']);
    }

    #[When('j\'exporte le planning en tableur')]
    public function jExporteLePlanning(): void
    {
        $response = $this->client->request('POST', \sprintf('schedules/%s/export-xlsx', $this->scheduleId), [
            'headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken],
            'json' => (object) [],
        ]);
        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('l\'export en tableur a répondu %d (200 attendu)', $response->getStatusCode()));
        }
    }

    #[Then('/^sur la saison en cours, mon offre est « (?P<offer>[^»]+) » et (?P<credits>.+)$/u')]
    public function surLaSaisonEnCours(string $offer, string $credits): void
    {
        $entitlements = $this->entitlements(null);
        $this->assertOffer($entitlements, $offer, 'la saison en cours');
        if (1 === preg_match('/^j\'ai utilisé (?P<used>\d+) crédits? sur (?P<max>\d+)$/u', $credits, $m)) {
            if ((int) $m['used'] !== ($entitlements['creditsUsed'] ?? null) || (int) $m['max'] !== ($entitlements['creditsMax'] ?? null)) {
                throw new RuntimeException(\sprintf('la saison en cours devait être bridée (%d crédit(s) utilisé(s) sur %d) : %s', $m['used'], $m['max'], $this->encoded($entitlements)));
            }

            return;
        }
        if ('aucun crédit n\'est décompté, sans plafond' === $credits) {
            if (0 !== ($entitlements['creditsUsed'] ?? null) || !\array_key_exists('creditsMax', $entitlements) || null !== $entitlements['creditsMax']) {
                throw new RuntimeException(\sprintf('la saison en cours ne devait être ni décomptée ni plafonnée : %s', $this->encoded($entitlements)));
            }

            return;
        }

        throw new RuntimeException(\sprintf('crédits « %s » illisibles', $credits));
    }

    #[When('/^nous sommes le (?P<day>.+)$/u')]
    public function nousSommesLe(string $day): void
    {
        $this->pinDevClock($this->seasonDay($day), $this->smallClubToken);
    }

    #[When('je prépare la saison suivante')]
    public function jePrepareLaSaisonSuivante(): void
    {
        $this->prepared = $this->apiPost(\sprintf('seasons/%s/transition', $this->currentSeasonId), [], $this->smallClubToken);
    }

    #[Then('la saison suivante naît')]
    public function laSaisonSuivanteNait(): void
    {
        $startDate = $this->prepared['json']['startDate'] ?? null;
        if (201 !== $this->prepared['status'] || !\is_string($this->prepared['json']['seasonId'] ?? null) || !\is_string($startDate)) {
            throw new RuntimeException(\sprintf('préparer la saison suivante a répondu %d (201 attendu : la saison suivante est réglée) : %s', $this->prepared['status'], $this->encoded($this->prepared['json'])));
        }
        $expected = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1;
        if (SeasonResolver::seasonYear(new DateTimeImmutable($startDate)) !== $expected) {
            throw new RuntimeException(\sprintf('la saison née commence le %s (saison %d attendue)', $startDate, $expected));
        }
    }

    #[Then('/^sur la saison suivante, mon offre est « (?P<offer>[^»]+) », sans plafond de crédits$/u')]
    public function surLaSaisonSuivante(string $offer): void
    {
        $seasonId = $this->prepared['json']['seasonId'] ?? null;
        if (!\is_string($seasonId)) {
            throw new RuntimeException('aucune saison suivante préparée à lire');
        }
        $entitlements = $this->entitlements($seasonId);
        $this->assertOffer($entitlements, $offer, 'la saison suivante');
        if (!\array_key_exists('creditsMax', $entitlements) || null !== $entitlements['creditsMax']) {
            throw new RuntimeException(\sprintf('la saison suivante ne devait pas être plafonnée : %s', $this->encoded($entitlements)));
        }
    }

    /** La commande retirée n'est plus enregistrée : la console répond « not defined », rien ne s'écrit. */
    #[Then('le support n\'a plus de geste « Marquer la saison suivante payée »')]
    public function leSupportNAPlusDeGesteMarquer(): void
    {
        try {
            $this->runConsole(['php', 'bin/console', 'app:clubs:mark-next-season-paid', '--club=' . $this->smallClubId]);
        } catch (RuntimeException $refused) {
            if (str_contains($refused->getMessage(), 'is not defined')) {
                return;
            }

            throw new RuntimeException(\sprintf('la commande retirée devait être inconnue de la console, elle a échoué autrement : %s', $refused->getMessage()), 0, $refused);
        }

        throw new RuntimeException('« app:clubs:mark-next-season-paid » existe encore : le geste retiré (décision du 2026-11-22) a tourné');
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->releaseDevClock($this->smallClubToken);
        $this->teardownSmallClub();
    }

    /** @param array<mixed> $entitlements */
    private function assertOffer(array $entitlements, string $offer, string $which): void
    {
        if ($offer !== ($entitlements['planName'] ?? null)) {
            throw new RuntimeException(\sprintf('sur %s, l\'offre servie est %s (« %s » attendue) : %s', $which, json_encode($entitlements['planName'] ?? null, \JSON_UNESCAPED_UNICODE), $offer, $this->encoded($entitlements)));
        }
    }

    /** @return array<mixed> */
    private function entitlements(?string $seasonId): array
    {
        $me = $this->apiGet('me', $this->smallClubToken, null === $seasonId ? [] : ['X-Season-Id' => $seasonId]);
        $club = $me['json']['club'] ?? null;
        $entitlements = \is_array($club) ? ($club['entitlements'] ?? null) : null;
        if (200 !== $me['status'] || !\is_array($entitlements)) {
            throw new RuntimeException(\sprintf('/api/me (HTTP %d) ne sert pas les droits de l\'offre du club', $me['status']));
        }

        return $entitlements;
    }

    /** @param array<mixed> $value */
    private function encoded(array $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
