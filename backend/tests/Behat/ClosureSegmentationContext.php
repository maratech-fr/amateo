<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Le découpage d'une indisponibilité est début · milieu · fin (axe planning lifecycle, décision
 * fondateur 2026-09-05), sur la stack qui tourne.
 *
 * Trois promesses, en HTTP contre la vraie API :
 *   - une semaine complète ISOLÉE au milieu est refusée : les semaines pleines forment UN SEUL plan
 *     (`CalendarEntryStateProcessor`, message « un seul plan ») ;
 *   - « d'un bloc » (adapter la racine, POST /schedule_plans) est refusé quand la fermeture a une
 *     SEMAINE ENTAMÉE (`SchedulePlanStateProcessor`, message « semaine entamée ») ;
 *   - la même fermeture se découpe en DÉBUT · MILIEU · FIN : trois enfants (POST /calendar_entries
 *     avec parentEntryId), chacun naît AVEC son plan.
 *
 * Autosuffisant : une fermeture JETABLE sur des semaines de novembre CLAIRES de toute vacance
 * (hors Toussaint) et de toute période semée ; la suppression de la racine cascade ses enfants et
 * leurs plans (vérifié), quoi qu'il arrive. Le socle en vigueur n'est jamais touché (naître un plan
 * de période ne l'exige pas).
 */
final class ClosureSegmentationContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $token = '';

    private string $motherId = '';

    private int $refusalStatus = 0;

    private string $refusalMessage = '';

    /** @var list<string> Enfants (début · milieu · fin) créés par le découpage. */
    private array $childIds = [];

    #[Given('le club de démonstration et son gestionnaire connecté')]
    public function leClubEtSonGestionnaireConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
    }

    #[Given('une indisponibilité dont le milieu couvre plusieurs semaines pleines')]
    public function uneIndisponibiliteAMilieuMultiSemaines(): void
    {
        // Lun 09/11 → dim 29/11 : trois semaines pleines contiguës = un seul « milieu ».
        $this->motherId = $this->createClosure('Fermeture milieu (fonctionnel)', '2026-11-09', '2026-11-29');
    }

    #[Given('une indisponibilité qui a une semaine entamée')]
    public function uneIndisponibiliteASemaineEntamee(): void
    {
        // Mer 11/11 → mar 24/11 : début entamé (09→15), milieu plein (16→22), fin entamée (23→29).
        $this->motherId = $this->createClosure('Fermeture à semaine entamée (fonctionnel)', '2026-11-11', '2026-11-24');
    }

    #[When('je tente d\'en détacher une seule semaine complète au milieu')]
    public function jeDetacheUneSemaineIsolee(): void
    {
        // La semaine du 16/11 (lun→dim) prise seule, au cœur d'un milieu de trois semaines.
        $result = $this->postChild('Semaine isolée', '2026-11-16', '2026-11-22');
        $this->captureRefusal($result);
    }

    #[When('je tente de l\'adapter d\'un bloc')]
    public function jAdapteDunBloc(): void
    {
        $result = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->motherId], $this->token);
        $this->captureRefusal($result);
    }

    #[When('je la découpe en début, milieu et fin')]
    public function jeDecoupeEnDebutMilieuFin(): void
    {
        $segments = [
            ['Début', '2026-11-09', '2026-11-15'],
            ['Milieu', '2026-11-16', '2026-11-22'],
            ['Fin', '2026-11-23', '2026-11-29'],
        ];
        foreach ($segments as [$title, $start, $end]) {
            $result = $this->postChild($title, $start, $end);
            if (201 !== $result['status']) {
                throw new RuntimeException(\sprintf('le segment « %s » aurait dû être accepté (201), obtenu %d', $title, $result['status']));
            }
            $id = $result['json']['id'] ?? null;
            if (!\is_string($id) || '' === $id) {
                throw new RuntimeException(\sprintf('le segment « %s » a été créé sans identifiant en retour', $title));
            }
            $this->childIds[] = $id;
        }
    }

    #[Then('le découpage est refusé car les semaines pleines forment un seul plan')]
    public function leDecoupageEstRefuseUnSeulPlan(): void
    {
        $this->assertRefused('un seul plan');
    }

    #[Then('l\'adaptation est refusée car la fermeture a une semaine entamée')]
    public function ladaptationEstRefuseeSemaineEntamee(): void
    {
        $this->assertRefused('semaine entamée');
    }

    #[Then('les trois segments sont acceptés et chacun porte son plan')]
    public function lesTroisSegmentsPortentLeurPlan(): void
    {
        if (3 !== \count($this->childIds)) {
            throw new RuntimeException(\sprintf('trois segments étaient attendus, %d créé(s)', \count($this->childIds)));
        }
        foreach ($this->childIds as $childId) {
            $count = $this->dbalScalar(
                \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $childId),
                admin: true,
            );
            if ('1' !== $count) {
                throw new RuntimeException('chaque segment aurait dû naître avec son plan (rail 1 entrée = 1 plan)');
            }
        }
    }

    /**
     * Supprime la fermeture racine : sa suppression cascade ses enfants et leurs plans (vérifié).
     * Quoi qu'il arrive. DELETE tolérant si la racine a déjà disparu.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token || '' === $this->motherId) {
            return;
        }

        $this->apiDelete(\sprintf('calendar_entries/%s', $this->motherId), $this->token);

        // Ceinture et bretelles : la cascade emporte enfants + plans, mais on ne laisse rien traîner
        // si un enfant a échappé à la cascade (DELETE en base tolérant).
        foreach ($this->childIds as $childId) {
            $this->dbalExec(\sprintf('DELETE FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $childId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE id=\'%s\'', $childId), admin: true);
        }
    }

    private function createClosure(string $title, string $start, string $end): string
    {
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $id = $entry['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création de la fermeture jetable sans identifiant (HTTP %d)', $entry['status']));
        }

        return $id;
    }

    /**
     * @return array{status: int, json: array<mixed>}
     */
    private function postChild(string $title, string $start, string $end): array
    {
        return $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'parentEntryId' => $this->motherId,
        ], $this->token);
    }

    /**
     * @param array{status: int, json: array<mixed>} $result
     */
    private function captureRefusal(array $result): void
    {
        $this->refusalStatus = $result['status'];
        foreach (['hydra:description', 'detail', 'error'] as $key) {
            if (\is_string($result['json'][$key] ?? null)) {
                $this->refusalMessage = $result['json'][$key];

                break;
            }
        }
    }

    private function assertRefused(string $expectedFragment): void
    {
        if (422 !== $this->refusalStatus) {
            throw new RuntimeException(\sprintf('le geste aurait dû être refusé (422), obtenu %d', $this->refusalStatus));
        }
        if (!str_contains($this->refusalMessage, $expectedFragment)) {
            throw new RuntimeException(\sprintf('le refus aurait dû mentionner « %s », message obtenu « %s »', $expectedFragment, $this->refusalMessage));
        }
    }
}
