<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use RuntimeException;

/**
 * La MAISON UNIQUE du décor des parcours de bout en bout : un PETIT CLUB RÉALISTE,
 * bâti PAR L'API (le geste réel du gestionnaire), jetable, nettoyé en fin de scénario.
 *
 * « Travailler avec une équipe n'a aucun sens — un petit club, c'est 10-12 équipes. »
 * Le décor : **11 équipes** (catégories/rangs variés, dont un rang S), **3 gymnases**
 * avec assez de créneaux, des coachs (dont un sur DEUX équipes). Complexité solveur =
 * équipes × gymnases = 11 × 3 = 33 ≤ 50 → **palier 60 s** (`engine/app/main.py::_adaptive_timeout`),
 * là où le club de démonstration complet (BCCL, ~50 équipes) tient le palier 600 s : c'est
 * précisément cette lenteur qu'on retire des parcours.
 *
 * Préparation PAR L'API (register → verify → approve → saisie → génération → validation) ;
 * le SQL direct n'est utilisé que pour le NETTOYAGE (teardown via la purge canonique
 * `app:clubs:purge-erased`, gardée par PurgeCompletenessTest) et le POINTAGE (lecture des
 * tables pour les assertions). Un context qui `use` ce trait DOIT étendre {@see BaseContext}
 * (pour `apiPost`/`publicPost`/`httpGet`/`mintToken`/`dbalExec`/`runConsole`) et appeler
 * `teardownSmallClub()` depuis son propre `#[AfterScenario]`.
 *
 * @phpstan-require-extends BaseContext
 */
trait SmallClubFixtureTrait
{
    private string $smallClubToken = '';

    private string $smallClubId = '';

    private string $smallClubUserId = '';

    private string $smallClubAra = '';

    /** @var array<string, string> nom d'équipe → id (pour cibler SM1, U13M1… dans les enchaînements) */
    private array $smallClubTeamIds = [];

    /** @var array<string, string> nom de gymnase → id */
    private array $smallClubVenueIds = [];

    /** @var array<string, string> prénom de coach → id */
    private array $smallClubCoachIds = [];

    /** Durée (s) du dernier solve de saison, mesurée autour du poll generate→terminal. */
    private float $smallClubLastSolveSeconds = 0.0;

    /**
     * Le décor des 11 équipes : nom → [rang (priorityTierId), index de catégorie].
     * Rang S = priorityTierId 1 (label « S », Elite) ; deux équipes séniors le portent
     * (SM1/SF1) pour les parcours qui exigent « le rang S garde son jour ».
     *
     * @return array<string, array{tier: int, categoryIndex: int, sessionsPerWeek: int}>
     */
    private function smallClubTeamPlan(): array
    {
        return [
            'SM1' => ['tier' => 1, 'categoryIndex' => 0, 'sessionsPerWeek' => 2],
            'SF1' => ['tier' => 1, 'categoryIndex' => 1, 'sessionsPerWeek' => 2],
            'U20M1' => ['tier' => 2, 'categoryIndex' => 2, 'sessionsPerWeek' => 2],
            'U17M1' => ['tier' => 2, 'categoryIndex' => 3, 'sessionsPerWeek' => 2],
            'U17F1' => ['tier' => 3, 'categoryIndex' => 4, 'sessionsPerWeek' => 2],
            'U15M1' => ['tier' => 3, 'categoryIndex' => 5, 'sessionsPerWeek' => 2],
            'U15F1' => ['tier' => 3, 'categoryIndex' => 6, 'sessionsPerWeek' => 2],
            'U13M1' => ['tier' => 4, 'categoryIndex' => 7, 'sessionsPerWeek' => 2],
            'U13F1' => ['tier' => 4, 'categoryIndex' => 8, 'sessionsPerWeek' => 1],
            'U11M1' => ['tier' => 4, 'categoryIndex' => 9, 'sessionsPerWeek' => 1],
            'Loisir' => ['tier' => 5, 'categoryIndex' => 10, 'sessionsPerWeek' => 1],
        ];
    }

    /**
     * Bâtit le petit club complet, prêt à générer : club + saison (register/verify/approve),
     * 11 équipes, 3 gymnases avec créneaux, coachs (un sur deux équipes). Ne génère PAS encore.
     */
    private function buildSmallClub(): void
    {
        $this->registerSmallClub();
        $this->seedSmallClubCategoriesTeamsVenuesCoaches();
    }

    private function registerSmallClub(): void
    {
        $ara = 'SMC' . time() . random_int(100, 999);
        $this->smallClubAra = $ara;
        $email = 'small-' . $ara . '@smoke.fr';

        $registered = $this->publicPost('register', [
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'Petit',
            'lastName' => 'Club',
            'ara' => $ara,
            'club_name' => 'Petit club réaliste ' . $ara,
            'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('inscription du petit club refusée (HTTP %d)', $registered['status']));
        }

        $rawToken = $this->pullSmallClubVerificationToken($email);
        $verified = $this->publicPost('register/verify', ['token' => $rawToken]);
        $this->smallClubToken = $this->extractSmallClubBearerCookie($verified['headers']);

        $approved = $this->apiPost('dev/approve-club-request', [], $this->smallClubToken);
        if (200 !== $approved['status']) {
            throw new RuntimeException(\sprintf('approbation du petit club refusée (HTTP %d)', $approved['status']));
        }

        $me = $this->apiGet('me', $this->smallClubToken);
        $club = $me['json']['club'] ?? null;
        $this->smallClubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : '';
        $this->smallClubUserId = \is_string($me['json']['id'] ?? null) ? (string) $me['json']['id'] : '';
        if ('' === $this->smallClubId) {
            throw new RuntimeException('le petit club n\'a pas d\'id sur /api/me après approbation');
        }
    }

    private function seedSmallClubCategoriesTeamsVenuesCoaches(): void
    {
        $categoriesResponse = $this->apiGet('sport_categories', $this->smallClubToken);
        $categories = array_values(array_filter(
            \is_array($categoriesResponse['json']['member'] ?? $categoriesResponse['json']) ? ($categoriesResponse['json']['member'] ?? $categoriesResponse['json']) : [],
            'is_array',
        ));
        $categoryIds = [];
        foreach ($categories as $category) {
            if (\is_string($category['id'] ?? null) && '' !== $category['id']) {
                $categoryIds[] = $category['id'];
            }
        }
        if ([] === $categoryIds) {
            throw new RuntimeException('un club neuf devrait porter des catégories sportives seedées — aucune trouvée');
        }

        // 11 équipes, rangs variés dont un rang S (tier 1). Catégorie prise dans la liste
        // seedée (cyclée si moins de 11 catégories) — le NOM porte l'intention métier.
        foreach ($this->smallClubTeamPlan() as $name => $plan) {
            $categoryId = $categoryIds[$plan['categoryIndex'] % \count($categoryIds)];
            $created = $this->apiPost('teams', [
                'name' => $name,
                'sportCategoryId' => $categoryId,
                'priorityTierId' => $plan['tier'],
                'sessionsPerWeek' => $plan['sessionsPerWeek'],
            ], $this->smallClubToken);
            if (!\in_array($created['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('création de l\'équipe « %s » refusée (HTTP %d)', $name, $created['status']));
            }
            $this->smallClubTeamIds[$name] = $this->smallClubStringField($created['json'], 'id');
        }

        // 3 gymnases, chacun ouvert lun→jeu à 18:00 / 19:30 / 21:00 (12 créneaux/gymnase,
        // 36 au total) : large de quoi placer ~20 séances en laissant respirer le solveur.
        foreach (['Gymnase Centre', 'Gymnase Nord', 'Gymnase Sud'] as $venueName) {
            $venue = $this->apiPost('venues', ['name' => $venueName, 'source' => 'manual'], $this->smallClubToken);
            if (!\in_array($venue['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('création du gymnase « %s » refusée (HTTP %d)', $venueName, $venue['status']));
            }
            $venueId = $this->smallClubStringField($venue['json'], 'id');
            $this->smallClubVenueIds[$venueName] = $venueId;

            foreach ([1, 2, 3, 4] as $dayOfWeek) {
                foreach (['18:00', '19:30', '21:00'] as $startTime) {
                    $slot = $this->apiPost('venue_training_slots', [
                        'venueId' => $venueId,
                        'dayOfWeek' => $dayOfWeek,
                        'startTime' => $startTime,
                        'durationMinutes' => 90,
                        'capacity' => 1,
                    ], $this->smallClubToken);
                    if (!\in_array($slot['status'], [200, 201], true)) {
                        throw new RuntimeException(\sprintf('création d\'un créneau de « %s » refusée (HTTP %d)', $venueName, $slot['status']));
                    }
                }
            }
        }

        // 4 coachs ; Camille coache DEUX équipes (SM1 + U13M1) — « un coach sur 2 équipes = OK »
        // (même club, mutualisation possible). Les autres coachent une équipe chacun.
        foreach (['Camille', 'Dominique', 'Sacha', 'Alex'] as $firstName) {
            $coach = $this->apiPost('coaches', ['firstName' => $firstName], $this->smallClubToken);
            if (!\in_array($coach['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('création du coach « %s » refusée (HTTP %d)', $firstName, $coach['status']));
            }
            $this->smallClubCoachIds[$firstName] = $this->smallClubStringField($coach['json'], 'id');
        }
        $this->assignSmallClubCoach('Camille', 'SM1');
        $this->assignSmallClubCoach('Camille', 'U13M1');
        $this->assignSmallClubCoach('Dominique', 'SF1');
        $this->assignSmallClubCoach('Sacha', 'U17M1');
        $this->assignSmallClubCoach('Alex', 'U15M1');
    }

    private function assignSmallClubCoach(string $coachFirstName, string $teamName): void
    {
        $assigned = $this->apiPost('team_coaches', [
            'teamId' => $this->smallClubTeamIds[$teamName],
            'coachId' => $this->smallClubCoachIds[$coachFirstName],
            'role' => 'MAIN',
        ], $this->smallClubToken);
        if (!\in_array($assigned['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('affectation de « %s » à « %s » refusée (HTTP %d)', $coachFirstName, $teamName, $assigned['status']));
        }
    }

    /**
     * Génère une version de saison, attend le statut terminal (mesure la durée du solve),
     * et la VALIDE (socle en vigueur). Rend l'id de la version.
     */
    private function generateAndValidateSmallClubSocle(): string
    {
        $scheduleId = $this->generateSmallClubSeason();
        $this->validateSmallClubSchedule($scheduleId);

        return $scheduleId;
    }

    /** Génère une version de saison et rend son id ; `$this->smallClubLastSolveSeconds` est posé. */
    private function generateSmallClubSeason(): string
    {
        $created = $this->apiPost('schedules', ['name' => 'Socle ' . date('H:i:s'), 'status' => 'DRAFT'], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du planning refusée (HTTP %d)', $created['status']));
        }
        $scheduleId = $this->smallClubStringField($created['json'], 'id');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $scheduleId), [], $this->smallClubToken);
        if (!\in_array($launched['status'], [200, 202], true)) {
            throw new RuntimeException(\sprintf('déclenchement de la génération refusé (HTTP %d)', $launched['status']));
        }

        $start = microtime(true);
        $status = $this->pollSmallClubUntilTerminal($scheduleId);
        $this->smallClubLastSolveSeconds = microtime(true) - $start;
        fwrite(\STDERR, \sprintf("\n[SmallClubFixture] solve %s en %.1fs\n", $status, $this->smallClubLastSolveSeconds));

        if ('COMPLETED' !== $status) {
            throw new RuntimeException(\sprintf('la génération du socle aurait dû aboutir (COMPLETED), obtenu « %s »', $status));
        }

        return $scheduleId;
    }

    private function validateSmallClubSchedule(string $scheduleId): void
    {
        $validated = $this->apiPost(\sprintf('schedules/%s/validate', $scheduleId), [], $this->smallClubToken);
        if (!\in_array($validated['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('validation du socle refusée (HTTP %d)', $validated['status']));
        }
    }

    /**
     * Rouvre le socle : on DÉPOINTE la version en vigueur (pointage SQL — une version choisie est
     * en lecture seule et non régénérable). C'est le geste « je rouvre mon planning pour y
     * retravailler » ; le socle sera repointé à la version régénérée par le parcours lui-même.
     */
    private function reopenSmallClubSocle(): void
    {
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->smallClubId),
            admin: true,
        );
    }

    /** Vrai si le socle de saison pointe bien la version donnée (socle en vigueur). */
    private function smallClubSocleIsChosen(string $scheduleId): bool
    {
        $count = $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id=\'%s\'',
                $this->smallClubId,
                $scheduleId,
            ),
            admin: true,
        );

        return '1' === $count;
    }

    /**
     * Nettoyage : purge canonique du workspace (maison unique `app:clubs:purge-erased`,
     * gardée par PurgeCompletenessTest), puis retrait du compte et de la coquille FFBB
     * résiduelle. Tolérant : un échec de teardown ne doit pas faire rougir un scénario vert.
     */
    private function teardownSmallClub(): void
    {
        if ('' === $this->smallClubId) {
            return;
        }

        // 1. Plus aucun membre actif → la purge ne s'auto-annule pas (revalidation du cron).
        $this->dbalExec(\sprintf('UPDATE club_user SET is_active=false WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        // 2. Échéance d'effacement dans le passé → éligible à la purge RGPD.
        $this->dbalExec(\sprintf('UPDATE club SET erasure_scheduled_at = now() - interval \'1 day\' WHERE id=\'%s\'', $this->smallClubId), admin: true);
        // 3. Purge canonique (vide toutes les tables tenant du workspace ; la coquille FFBB survit).
        $this->runConsole(['php', 'bin/console', 'app:clubs:purge-erased']);
        // 4. Retrait des résidus : coquille FFBB + audit + compte gestionnaire (hors purge RGPD).
        $this->dbalExec(\sprintf('DELETE FROM club_user WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM audit_log WHERE club_id=\'%s\'', $this->smallClubId), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM club WHERE id=\'%s\'', $this->smallClubId), admin: true);
        if ('' !== $this->smallClubUserId) {
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->smallClubUserId), admin: true);
        }
    }

    private function pullSmallClubVerificationToken(string $email): string
    {
        $mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $messages = $search['json']['messages'] ?? [];
            $messageId = \is_array($messages) && isset($messages[0]['ID']) && \is_string($messages[0]['ID']) ? $messages[0]['ID'] : '';
            if ('' !== $messageId) {
                $message = $this->httpGet($mailpitBase . '/api/v1/message/' . $messageId);
                $text = $message['json']['Text'] ?? '';
                if (\is_string($text) && 1 === preg_match('#verify-email/([a-f0-9]{64})#', $text, $m)) {
                    return $m[1];
                }
            }
            sleep(1);
        }

        throw new RuntimeException('aucun e-mail de vérification du petit club trouvé dans le webmail — la file est-elle consommée ?');
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function extractSmallClubBearerCookie(array $headers): string
    {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            if (1 === preg_match('/^BEARER=([^;]+)/', $cookie, $m)) {
                return $m[1];
            }
        }

        throw new RuntimeException('la vérification du petit club n\'a pas posé le cookie de session');
    }

    private function pollSmallClubUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + 300;
        $status = '';
        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->smallClubToken);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture du planning en échec (HTTP %d)', $response['status']));
            }
            $status = $this->smallClubStringField($response['json'], 'status');
            if (\in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return $status;
            }
            sleep(5);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('la génération du socle n\'a pas abouti dans le délai (dernier statut « %s »)', $status));
    }

    /**
     * @param array<mixed> $json
     */
    private function smallClubStringField(array $json, string $field): string
    {
        $value = $json[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new RuntimeException(\sprintf('champ « %s » absent de la réponse de l\'API', $field));
        }

        return $value;
    }
}
