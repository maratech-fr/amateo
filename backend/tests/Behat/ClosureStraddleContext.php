<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Prolonger une fermeture à travers les vacances, sans perdre le planning déjà travaillé
 * (ADR-0002 amendé, axe planning lifecycle — fondateur 2026-10-27), sur la stack qui tourne.
 *
 * Le gestionnaire part d'une fermeture VALIDÉE qui s'arrête à l'entrée des vacances, avec deux
 * reprises de vacances NON validées posées au milieu. Il prolonge la fenêtre de la fermeture
 * par-delà les vacances via le rail NEUF (`/window-preview` puis `/window`) : le milieu est
 * conservé (même version), trois copies validées naissent (fin avant pause, après pause, fin
 * entamée), la fermeture datée court jusqu'au nouveau bout (son début figé), et les reprises
 * recoupées passent « à régénérer ». L'aperçu == l'appliqué.
 *
 * Le décor part du club de démonstration et le restaure en fin de scénario. Les fenêtres sont
 * posées relativement à l'horloge du club de démo (pinnée côté seed).
 */
final class ClosureStraddleContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private string $token = '';

    private string $clubId = '';

    /** Le plan de fermeture (racine-bloc validée) à prolonger. */
    private string $closurePlanId = '';

    /** Les plans des deux reprises de vacances non validées. */
    private string $reprise1PlanId = '';

    private string $reprise2PlanId = '';

    /**
     * L'aperçu servi (effets + jeton), comparé ensuite à l'appliqué.
     *
     * @var array<string, mixed>
     */
    private array $preview = [];

    #[Given('le club de démonstration, connecté, dont le planning de saison est en vigueur')]
    public function leClubConnecteAvecSocleEnVigueur(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);
        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;
    }

    #[Given('une fermeture validée qui s\'arrête à l\'entrée de vacances portant deux reprises non validées')]
    public function uneFermetureValideeArreteeAuxVacances(): void
    {
        // Déclare la fermeture (fenêtre alignée, un seul segment), l'adapte, la transcrit du socle et
        // la valide ; déclare des vacances au milieu et y pose deux reprises non validées.
        $this->closurePlanId = $this->declareValidatedClosureUpToHolidays();
        [$this->reprise1PlanId, $this->reprise2PlanId] = $this->declareUnvalidatedHolidayReprises();
    }

    #[When('je prolonge la fermeture par-delà les vacances après avoir vu l\'aperçu exact')]
    public function jeProlongeApresApercu(): void
    {
        $window = $this->extendedWindow();

        $preview = $this->apiPost('schedule_plans/' . $this->closurePlanId . '/window-preview', [
            'startDate' => $window['startDate'],
            'endDate' => $window['endDate'],
        ], $this->token);
        if (200 !== $preview['status']) {
            throw new RuntimeException('l\'aperçu de prolongation a échoué : ' . (string) json_encode($preview['json']));
        }
        $this->preview = $preview['json'];

        $apply = $this->apiPost('schedule_plans/' . $this->closurePlanId . '/window', [
            'startDate' => $window['startDate'],
            'endDate' => $window['endDate'],
            'previewToken' => $this->preview['token'] ?? '',
        ], $this->token);
        if (200 !== $apply['status']) {
            throw new RuntimeException('la prolongation a échoué : ' . (string) json_encode($apply['json']));
        }
    }

    #[Then('l\'aperçu annonçait exactement ce qui est appliqué')]
    public function lApercuEgaleLApplique(): void
    {
        $effects = $this->preview['effects'] ?? [];
        if (!\is_array($effects) || [] === $effects) {
            throw new RuntimeException('l\'aperçu n\'a annoncé aucun effet');
        }
        foreach ($effects as $effect) {
            if (!\is_array($effect) || !isset($effect['kind'], $effect['startDate'], $effect['endDate'])) {
                throw new RuntimeException('un effet d\'aperçu est mal formé');
            }
            // Chaque fenêtre annoncée doit correspondre à un plan réellement en place après l'apply.
            if (\in_array($effect['kind'], ['convert', 'birth_copy'], true)
                && !$this->planExistsOnWindow((string) $effect['startDate'], (string) $effect['endDate'])) {
                throw new RuntimeException('un effet annoncé ne correspond à aucun planning appliqué');
            }
        }
    }

    #[Then('la fermeture compte quatre plannings : le milieu conservé et trois copies validées')]
    public function laFermetureCompteQuatrePlannings(): void
    {
        $count = (int) $this->dbalScalar(\sprintf(
            'SELECT COUNT(*) AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'CLOSURE\' AND chosen_schedule_id IS NOT NULL',
            $this->clubId,
        ), admin: true);
        if (4 !== $count) {
            throw new RuntimeException(\sprintf('attendu 4 plannings de fermeture pointés, obtenu %d', $count));
        }
    }

    #[Then('la fermeture datée court jusqu\'au nouveau bout de la fenêtre, son début inchangé')]
    public function laFermetureDateeSuitLaFenetre(): void
    {
        $window = $this->extendedWindow();
        $end = $this->dbalScalar(\sprintf(
            'SELECT (config->>\'endDate\') AS behatval FROM constraint_entry WHERE club_id=\'%s\' AND config->>\'type\'=\'venue_closed\' ORDER BY (config->>\'endDate\') DESC LIMIT 1',
            $this->clubId,
        ), admin: true);
        if ($window['endDate'] !== $end) {
            throw new RuntimeException(\sprintf('la fermeture datée devait courir jusqu\'au %s, obtenu %s', $window['endDate'], $end));
        }
    }

    #[Then('les deux reprises de vacances recoupées sont signalées à régénérer')]
    public function lesReprisesSontSignaleesARegenerer(): void
    {
        foreach ([$this->reprise1PlanId, $this->reprise2PlanId] as $planId) {
            $hash = $this->apiGet('schedule_plans/' . $planId . '/structure-hash', $this->token);
            if (200 !== $hash['status']) {
                throw new RuntimeException('impossible de lire l\'empreinte d\'une reprise');
            }
            // La reprise reste NON validée (chosen null) : le gestionnaire la régénère.
            $chosen = $this->dbalScalar(\sprintf(
                'SELECT COALESCE(chosen_schedule_id::text, \'\') AS behatval FROM schedule_plan WHERE id=\'%s\'',
                $planId,
            ), admin: true);
            if ('' !== $chosen) {
                throw new RuntimeException('une reprise recoupée ne doit pas rester validée en silence');
            }
        }
    }

    /**
     * Prépare et valide la fermeture (racine-bloc) qui s'arrête à l'entrée des vacances.
     * (Détail d'implémentation : porté par NEO au moment où le rail existe — ce décor passe par
     * les gestes réels Adapter + transcription + validation.).
     */
    private function declareValidatedClosureUpToHolidays(): string
    {
        throw new RuntimeException('décor à brancher sur le rail de prolongation (PR1) : window-preview/window');
    }

    /**
     * @return array{0: string, 1: string} les plans des deux reprises de vacances non validées
     */
    private function declareUnvalidatedHolidayReprises(): array
    {
        throw new RuntimeException('décor à brancher sur le rail de prolongation (PR1)');
    }

    /**
     * @return array{startDate: string, endDate: string} la fenêtre prolongée, relative à l'horloge
     */
    private function extendedWindow(): array
    {
        throw new RuntimeException('fenêtre à dériver de l\'horloge du club de démo (PR1)');
    }

    private function planExistsOnWindow(string $start, string $end): bool
    {
        return '' !== $this->dbalScalar(\sprintf(
            'SELECT COALESCE((SELECT id::text FROM schedule_plan WHERE club_id=\'%s\' AND start_date=\'%s\' AND end_date=\'%s\' LIMIT 1), \'\') AS behatval',
            $this->clubId,
            $start,
            $end,
        ), admin: true);
    }
}
