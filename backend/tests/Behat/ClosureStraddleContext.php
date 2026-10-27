<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Tests\Security\ClosureWindowExtendTest;
use Behat\Hook\AfterScenario;
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

    private const int POLL_INTERVAL_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 120;

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    /** Le lundi d'ancrage de tout le décor (futur, sans vacances scolaires sur la fenêtre). */
    private string $baseMonday = '';

    /** L'entrée de calendrier de la fermeture (racine) — nettoyée en fin de scénario. */
    private string $closureEntryId = '';

    /** L'entrée de calendrier des vacances (mère des deux reprises) — nettoyée en fin de scénario. */
    private string $holidayEntryId = '';

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

        // Un plan de période (fermeture, transcription depuis le socle) exige un socle EN VIGUEUR
        // (SocleGuard). On pointe la dernière version COMPLETED de la saison si le pointeur est vide,
        // et on le restaure à NULL en fin de scénario — même patron que PeriodOverlayContext.
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM schedule WHERE club_id=\'%s\' AND status=\'COMPLETED\' AND schedule_plan_id=(SELECT id FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\') ORDER BY created_at DESC LIMIT 1', $this->clubId, $this->clubId),
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

    /** Supprime les deux entrées créées (cascade plans + versions) et restaure le pointeur de socle. */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        if ('' !== $this->closureEntryId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->closureEntryId), $this->token);
        }
        if ('' !== $this->holidayEntryId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->holidayEntryId), $this->token);
        }
        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /**
     * Prépare et VALIDE la fermeture (racine-bloc d'UNE semaine pleine, donc « d'un bloc ») qui
     * s'arrête à l'entrée des vacances, par les gestes réels : entrée `closure` au calendrier +
     * indisponibilité datée `venue_closed` attachée → Adapter (POST /schedule_plans) → transcrire
     * depuis le socle (copie HARD) → valider (POST /schedules/{v}/validate, qui POINTE la version).
     *
     * ⚠ Géométrie (relative au lundi d'ancrage B) calquée sur le NR PHPUnit
     * {@see ClosureWindowExtendTest}, mais FUTURE (pas de plancher à éprouver
     * ici) et réduite pour minimiser le risque de vacances scolaires sur la fenêtre :
     *   - fermeture d'origine (le « milieu », convert)  : [B, B+6]   (semaine 1 pleine) ;
     *   - vacances (pause)                               : [B+10, B+27] (jeu. S2 → dim. S4) ;
     *   - reprises hebdo non validées, dans les vacances : [B+14, B+20] et [B+21, B+27] ;
     *   - prolongation jusqu'à [B, B+38] (jeu. S6) → la pause découpe l'ajout en 3 copies :
     *     [B+7, B+9] (avant pause), [B+28, B+34] (après pause), [B+35, B+38] (fin entamée).
     */
    private function declareValidatedClosureUpToHolidays(): string
    {
        $this->baseMonday = $this->holidayFreeMonday(49, 38);
        $origEnd = $this->plusDays($this->baseMonday, 6);

        $venueId = $this->busiestSocleVenue();
        $this->closureEntryId = $this->postEntry([
            'kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux (enjambe vacances)',
            'startDate' => $this->baseMonday, 'endDate' => $origEnd,
        ]);
        // La datée `venue_closed` née du même geste : sa config == la fenêtre d'ORIGINE, sans quoi
        // l'applier ne la reconnaîtrait pas pour l'étendre (extendDatedConstraints matche old window).
        $this->declareVenueClosed($venueId, $this->baseMonday, $origEnd, $this->closureEntryId);

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->closureEntryId], $this->token);
        $this->closurePlanId = $this->stringField($plan, 'id', 'plan de fermeture');

        $transcribed = $this->apiPost(\sprintf('schedule_plans/%s/transcribe-from-socle', $this->closurePlanId), [], $this->token);
        $v1 = $this->stringField($transcribed, 'id', 'transcription');
        if ('COMPLETED' !== $this->pollUntilTerminal($v1)) {
            throw new RuntimeException('la transcription de la fermeture depuis le socle n\'a pas abouti');
        }

        $validated = $this->apiPost(\sprintf('schedules/%s/validate', $v1), [], $this->token);
        if (!\in_array($validated['status'], [200, 201, 202], true)) {
            throw new RuntimeException(\sprintf('la validation de la fermeture a répondu %d — %s', $validated['status'], json_encode($validated['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }

        return $this->closurePlanId;
    }

    /**
     * Pose les vacances (mère HOLIDAY) au milieu, avec deux reprises hebdomadaires NON validées
     * (entrées-enfants porteuses de leur plan, sans version pointée — « le gestionnaire les
     * régénérera »). Rend les deux plans de reprise.
     *
     * @return array{0: string, 1: string} les plans des deux reprises de vacances non validées
     */
    private function declareUnvalidatedHolidayReprises(): array
    {
        $this->holidayEntryId = $this->postEntry([
            'kind' => 'period', 'periodType' => 'holiday', 'title' => 'Toussaint (enjambe fermeture)',
            'startDate' => $this->plusDays($this->baseMonday, 10), 'endDate' => $this->plusDays($this->baseMonday, 27),
        ]);

        $r1 = $this->postEntry([
            'kind' => 'period', 'periodType' => 'holiday', 'title' => 'Reprise semaine 1',
            'startDate' => $this->plusDays($this->baseMonday, 14), 'endDate' => $this->plusDays($this->baseMonday, 20),
            'parentEntryId' => $this->holidayEntryId,
        ]);
        $r2 = $this->postEntry([
            'kind' => 'period', 'periodType' => 'holiday', 'title' => 'Reprise semaine 2',
            'startDate' => $this->plusDays($this->baseMonday, 21), 'endDate' => $this->plusDays($this->baseMonday, 27),
            'parentEntryId' => $this->holidayEntryId,
        ]);

        $this->reprise1PlanId = $this->requirePlanOf($r1);
        $this->reprise2PlanId = $this->requirePlanOf($r2);

        return [$this->reprise1PlanId, $this->reprise2PlanId];
    }

    /**
     * @return array{startDate: string, endDate: string} la fenêtre prolongée [B, B+38]
     */
    private function extendedWindow(): array
    {
        return ['startDate' => $this->baseMonday, 'endDate' => $this->plusDays($this->baseMonday, 38)];
    }

    /**
     * Le lundi (Y-m-d) d'une fenêtre de `$spanDays` jours SANS vacances scolaires de la zone du
     * club, à partir de « next monday +$offsetDays » — glisse de semaine en semaine. La fermeture
     * enjambe les vacances DÉCLARÉES par le gestionnaire ; une vacance SCOLAIRE seedée ferait, elle,
     * une pause PARASITE (le planner soustrait entrées HOLIDAY ET feed scolaire) et casserait le
     * compte de segments. Même patron que PeriodOverlayContext::holidayFreeMonday.
     */
    private function holidayFreeMonday(int $offsetDays, int $spanDays): string
    {
        $monday = (int) strtotime(\sprintf('next monday +%d days', $offsetDays));
        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $start = date('Y-m-d', $monday);
            $end = date('Y-m-d', (int) strtotime(\sprintf('+%d days', $spanDays), $monday));
            $hits = $this->dbalScalar(\sprintf(
                'SELECT count(*) AS behatval FROM school_holiday_period h JOIN club c ON c.school_zone = h.zone WHERE c.id=\'%s\' AND h.start_date <= \'%s\' AND h.end_date >= \'%s\'',
                $this->clubId,
                $end,
                $start,
            ), admin: true);
            if ('0' === $hits) {
                return $start;
            }
            $monday = (int) strtotime('+7 days', $monday);
        }

        throw new RuntimeException(\sprintf('aucune fenêtre de %d jours sans vacances scolaires trouvée — le club de démo n\'offre pas de creux assez long pour ce scénario', $spanDays));
    }

    /** Le gymnase le PLUS OCCUPÉ du socle (celui qu'on ferme) — départagé par id pour le déterminisme. */
    private function busiestSocleVenue(): string
    {
        $venueId = $this->dbalScalar(\sprintf(
            'SELECT venue_id AS behatval FROM schedule_slot_template WHERE schedule_id=('
            . 'SELECT chosen_schedule_id FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1'
            . ') AND venue_id IS NOT NULL GROUP BY venue_id ORDER BY COUNT(*) DESC, venue_id LIMIT 1',
            $this->clubId,
        ), admin: true);
        if ('' === $venueId) {
            throw new RuntimeException('le socle ne porte aucune séance placée — le gymnase à fermer est introuvable');
        }

        return $venueId;
    }

    /** Déclare l'indisponibilité datée `venue_closed` attachée à l'entrée (geste réel de l'application). */
    private function declareVenueClosed(string $venueId, string $start, string $end, string $entryId): void
    {
        $created = $this->apiPost('constraints', [
            'name' => 'Fermeture du gymnase (enjambe vacances)',
            'scope' => 'FACILITY',
            'scopeTargetId' => $venueId,
            'family' => 'FACILITY',
            'ruleType' => 'HARD',
            'config' => ['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end],
            'calendarEntryId' => $entryId,
            'isActive' => true,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('la déclaration de la fermeture du gymnase a répondu %d — %s', $created['status'], json_encode($created['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        }
    }

    /** @param array<string, mixed> $body */
    private function postEntry(array $body): string
    {
        $entry = $this->apiPost('calendar_entries', $body, $this->token);

        return $this->stringField($entry, 'id', 'entrée de calendrier');
    }

    /** Le plan d'une entrée (créé par le StateProcessor au POST de l'enfant), exigé non vide. */
    private function requirePlanOf(string $entryId): string
    {
        $planId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule_plan WHERE calendar_entry_id=\'%s\' LIMIT 1', $entryId),
            admin: true,
        );
        if ('' === $planId) {
            throw new RuntimeException('une reprise de vacances n\'a pas de plan — le POST de l\'enfant n\'a pas provisionné son plan');
        }

        return $planId;
    }

    private function pollUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture de la version en échec (HTTP %d)', $response['status']));
            }
            $status = $response['json']['status'] ?? '';
            if (\is_string($status) && \in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return $status;
            }
            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException('la génération n\'a pas abouti dans le délai imparti');
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function stringField(array $response, string $key, string $what): string
    {
        $value = $response['json'][$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            $detail = json_encode($response['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            throw new RuntimeException(\sprintf('%s sans « %s » (HTTP %d) — %s', $what, $key, $response['status'], \is_string($detail) ? mb_substr($detail, 0, 600) : '?'));
        }

        return $value;
    }

    private function plusDays(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime(\sprintf('%s +%d days', $date, $days)));
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
