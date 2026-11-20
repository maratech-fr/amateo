<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Promesse : en offre Découverte, chaque sortie (planning en tableur, matchs en PDF) coûte un
 * crédit du pool du club, consulter ne coûte rien, à zéro la sortie est refusée avec son motif ;
 * un club de démonstration n'est jamais décompté (`CreditBudgetSubscriber`, `PlanEntitlements`).
 *
 * Décor DÉDIÉ : un club inscrit par l'API ({@see SmallClubFixtureTrait::registerSmallClub}) naît en
 * Découverte (`club.plan_id` NULL). Aucune génération : le planning exporté est un brouillon vide.
 * Le compteur se lit comme le lit l'écran, sur `/api/me` (`club.entitlements`). Passer le club en
 * démonstration est un pointage SQL (`club.is_demo`), patron de `DemoRetainedContext`.
 * Le placement (abouti : un crédit, refusé : aucun) : {@see MatchesAwaitSocleContext}.
 * Volontairement non écrits (le lot 2 en change la règle) : crédit du PDF planning, du comblement,
 * et le moment où la génération prend le sien.
 */
final class DiscoveryCreditContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $scheduleId = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $lastExport = ['status' => 0, 'json' => []];

    #[Given('un club inscrit en offre Découverte, avec 10 crédits et aucun d\'utilisé')]
    public function unClubInscritEnDecouverte(): void
    {
        $this->registerSmallClub();
        $entitlements = $this->entitlements();
        if ('decouverte' !== ($entitlements['planCode'] ?? null) || 10 !== ($entitlements['creditsMax'] ?? null) || 0 !== ($entitlements['creditsUsed'] ?? null)) {
            throw new RuntimeException(\sprintf('un club inscrit devait naître en Découverte, 0 crédit utilisé sur 10 : %s', json_encode($entitlements, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Given('un planning de saison en brouillon')]
    public function unPlanningEnBrouillon(): void
    {
        $created = $this->apiPost('schedules', ['name' => 'Brouillon', 'status' => 'DRAFT'], $this->smallClubToken);
        $this->scheduleId = $this->smallClubStringField($created['json'], 'id');
    }

    #[Given('le club passe en démonstration')]
    public function leClubPasseEnDemonstration(): void
    {
        $this->dbalExec(\sprintf('UPDATE club SET is_demo = true WHERE id=\'%s\'', $this->smallClubId), admin: true);
    }

    #[When('je consulte mes plannings, leurs séances et mes matchs')]
    public function jeConsulte(): void
    {
        foreach (['schedules', \sprintf('schedules/%s', $this->scheduleId), \sprintf('schedule_slot_templates?scheduleId=%s', $this->scheduleId), 'fixtures', 'me'] as $path) {
            $read = $this->apiGet($path, $this->smallClubToken);
            if (200 !== $read['status']) {
                throw new RuntimeException(\sprintf('la lecture de %s a répondu %d', $path, $read['status']));
            }
        }
    }

    #[When('j\'exporte le planning en tableur')]
    public function jExporteLePlanning(): void
    {
        $this->lastExport = $this->exportXlsx();
    }

    #[When('/^j\'exporte le planning en tableur (?P<times>\d+) fois$/u')]
    public function jExporteLePlanningNFois(int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->lastExport = $this->exportXlsx();
            if (200 !== $this->lastExport['status']) {
                throw new RuntimeException(\sprintf('l\'export n° %d a répondu %d (200 attendu)', $i + 1, $this->lastExport['status']));
            }
        }
    }

    #[When('j\'exporte les matchs de la semaine en PDF')]
    public function jExporteLesMatchs(): void
    {
        $monday = date('Y-m-d', (int) strtotime('next monday'));
        $response = $this->client->request('POST', 'fixtures/export-pdf', [
            'headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken],
            'json' => ['from' => $monday, 'to' => date('Y-m-d', (int) strtotime($monday . ' +6 days')), 'teamId' => null],
            'timeout' => 60,
        ]);
        $bytes = $response->getContent(false);
        if (200 !== $response->getStatusCode() || !str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException(\sprintf('l\'export des matchs en PDF a répondu %d sans PDF', $response->getStatusCode()));
        }
    }

    #[Then('/^j\'ai utilisé (?P<used>\d+) crédits? sur (?P<max>\d+)$/u')]
    public function jAiUtilise(int $used, int $max): void
    {
        $entitlements = $this->entitlements();
        if ($used !== ($entitlements['creditsUsed'] ?? null) || $max !== ($entitlements['creditsMax'] ?? null)) {
            throw new RuntimeException(\sprintf('le club a utilisé %s crédit(s) sur %s (attendu %d sur %d)', json_encode($entitlements['creditsUsed'] ?? null), json_encode($entitlements['creditsMax'] ?? null), $used, $max));
        }
    }

    #[Then('/^exporter le planning en tableur m\'est refusé \(403\) : « (?P<message>.+) »$/u')]
    public function exporterMEstRefuse(string $message): void
    {
        $this->assertRefused($this->exportXlsx(), $message, 'exporter le planning');
    }

    #[Then('/^générer le planning m\'est refusé \(403\) : « (?P<message>.+) »$/u')]
    public function genererMEstRefuse(string $message): void
    {
        $this->assertRefused($this->apiPost(\sprintf('schedules/%s/generate', $this->scheduleId), [], $this->smallClubToken), $message, 'générer le planning');
        $this->jAiUtilise(10, 10);
    }

    #[Then('l\'export est servi')]
    public function lExportEstServi(): void
    {
        if (200 !== $this->lastExport['status']) {
            throw new RuntimeException(\sprintf('l\'export du club de démonstration a répondu %d (200 attendu)', $this->lastExport['status']));
        }
    }

    #[Then('le club n\'a utilisé aucun crédit')]
    public function leClubNAUtiliseAucunCredit(): void
    {
        $entitlements = $this->entitlements();
        if (0 !== ($entitlements['creditsUsed'] ?? null) || !\array_key_exists('creditsMax', $entitlements) || null !== $entitlements['creditsMax']) {
            throw new RuntimeException(\sprintf('un club de démonstration a été décompté ou borné : %s', json_encode($entitlements, \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    /** @return array{status: int, json: array<mixed>} */
    private function exportXlsx(): array
    {
        $response = $this->client->request('POST', \sprintf('schedules/%s/export-xlsx', $this->scheduleId), [
            'headers' => ['Authorization' => 'Bearer ' . $this->smallClubToken],
            'json' => (object) [],
        ]);
        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $json = 200 === $status ? [] : json_decode($raw, true);

        return ['status' => $status, 'json' => \is_array($json) ? $json : []];
    }

    /** @param array{status: int, json: array<mixed>} $response */
    private function assertRefused(array $response, string $message, string $gesture): void
    {
        $served = $response['json']['detail'] ?? ($response['json']['error'] ?? '');
        if (403 !== $response['status'] || $served !== $message) {
            throw new RuntimeException(\sprintf('%s à crédits épuisés : %d « %s » (attendu 403 « %s »)', $gesture, $response['status'], \is_string($served) ? $served : '', $message));
        }
    }

    /** @return array<mixed> */
    private function entitlements(): array
    {
        $me = $this->apiGet('me', $this->smallClubToken);
        $club = $me['json']['club'] ?? null;
        $entitlements = \is_array($club) ? ($club['entitlements'] ?? null) : null;
        if (!\is_array($entitlements)) {
            throw new RuntimeException('/api/me ne sert pas les droits de l\'offre du club');
        }

        return $entitlements;
    }
}
