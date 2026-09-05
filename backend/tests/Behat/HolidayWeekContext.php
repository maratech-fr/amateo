<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Une semaine ne relève des vacances que si lundi→vendredi y est entièrement couvert (axe planning
 * lifecycle, décision fondateur 2026-09-04), sur la stack qui tourne.
 *
 * Deux promesses, en HTTP contre la vraie API, sous une mère VACANCES jetable finissant un lundi :
 *   - la semaine qui commence ce dernier lundi n'a que son lundi en vacances (mar→ven en saison) →
 *     refusée (`CalendarEntryStateProcessor`, message « pas entièrement en vacances ») ;
 *   - la semaine entièrement comprise dans les vacances est acceptée et naît avec son plan.
 *
 * Autosuffisant : une mère VACANCES jetable sur des semaines de novembre CLAIRES de toute vacance
 * scolaire semée et de toute période ; la suppression de la mère cascade l'enfant et son plan, quoi
 * qu'il arrive. Le socle en vigueur n'est jamais touché.
 */
final class HolidayWeekContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $token = '';

    private string $motherId = '';

    private string $childId = '';

    private int $refusalStatus = 0;

    private string $refusalMessage = '';

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

    #[Given('des vacances jetables qui se terminent un lundi')]
    public function desVacancesFinissantUnLundi(): void
    {
        // Lun 23/11 → lun 30/11 : la vacance couvre 23→30. La semaine du 23 (lun→ven ⊂ 23→30) est
        // pleinement en vacances ; celle du 30 n'a que son lundi couvert (mar 01/12 est hors vacances).
        $this->motherId = $this->createHoliday('Vacances de test (fonctionnel)', '2026-11-23', '2026-11-30');
    }

    #[When('je tente d\'en détacher la semaine qui commence ce dernier lundi')]
    public function jeDetacheLaSemainePartielle(): void
    {
        // Lun 30/11 → dim 06/12 : seul le lundi 30/11 est en vacances.
        $result = $this->postChild('Semaine du 30 novembre', '2026-11-30', '2026-12-06');
        $this->refusalStatus = $result['status'];
        foreach (['hydra:description', 'detail', 'error'] as $key) {
            if (\is_string($result['json'][$key] ?? null)) {
                $this->refusalMessage = $result['json'][$key];

                break;
            }
        }
    }

    #[When('je détache une semaine entièrement comprise dans ces vacances')]
    public function jeDetacheLaSemainePleine(): void
    {
        // Lun 23/11 → dim 29/11 : lun→ven (23→27) ⊂ vacances (23→30).
        $result = $this->postChild('Semaine du 23 novembre', '2026-11-23', '2026-11-29');
        if (201 !== $result['status']) {
            throw new RuntimeException(\sprintf('la semaine pleinement en vacances aurait dû être acceptée (201), obtenu %d', $result['status']));
        }
        $id = $result['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('la semaine acceptée a été créée sans identifiant en retour');
        }
        $this->childId = $id;
    }

    #[Then('la semaine est refusée car pas entièrement en vacances')]
    public function laSemaineEstRefusee(): void
    {
        if (422 !== $this->refusalStatus) {
            throw new RuntimeException(\sprintf('la semaine partielle aurait dû être refusée (422), obtenu %d', $this->refusalStatus));
        }
        if (!str_contains($this->refusalMessage, 'pas entièrement en vacances')) {
            throw new RuntimeException(\sprintf('le refus aurait dû mentionner « pas entièrement en vacances », message obtenu « %s »', $this->refusalMessage));
        }
    }

    #[Then('la semaine est acceptée et porte son plan')]
    public function laSemainePorteSonPlan(): void
    {
        if ('' === $this->childId) {
            throw new RuntimeException('aucune semaine acceptée à vérifier');
        }
        $count = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $this->childId),
            admin: true,
        );
        if ('1' !== $count) {
            throw new RuntimeException('la semaine acceptée aurait dû naître avec son plan (rail 1 entrée = 1 plan)');
        }
    }

    /**
     * Supprime la mère vacances : sa suppression cascade l'enfant et son plan. Quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token || '' === $this->motherId) {
            return;
        }

        $this->apiDelete(\sprintf('calendar_entries/%s', $this->motherId), $this->token);

        if ('' !== $this->childId) {
            $this->dbalExec(\sprintf('DELETE FROM schedule_plan WHERE calendar_entry_id=\'%s\'', $this->childId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM calendar_entry WHERE id=\'%s\'', $this->childId), admin: true);
        }
    }

    private function createHoliday(string $title, string $start, string $end): string
    {
        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'holiday',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);
        $id = $entry['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création des vacances jetables sans identifiant (HTTP %d)', $entry['status']));
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
            'periodType' => 'holiday',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'parentEntryId' => $this->motherId,
        ], $this->token);
    }
}
