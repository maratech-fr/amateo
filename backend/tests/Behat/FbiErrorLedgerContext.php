<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTimeImmutable;
use RuntimeException;

/**
 * « Erreur FBI » alimente le registre « à corriger dans FBI » (lot N), sur la stack qui tourne.
 *
 * Le gestionnaire pose « erreur FBI » sur une COLLISION DE GYMNASE : le conflit reste rendu avec sa
 * résolution, ET une entrée du registre « à corriger dans FBI » apparaît pour la rencontre + le champ
 * fautifs, valeur cible VIDE (l'appli a importé l'erreur, elle ne l'invente pas). Un seul appel, un
 * seul enregistrement.
 *
 * Autosuffisant : deux équipes + un gymnase JETABLES (POST API), puis deux matchs à domicile qui se
 * chevauchent (30 min d'écart, même gymnase) → une collision de gymnase (VENUE_OVERLAP) ; pointeur de
 * socle posé s'il manque puis restauré ; horloge épinglée pour que les matchs restent futurs. Tout le
 * décor est retiré en fin de scénario (résolution + entrée de registre comprises). La grille du club
 * réel n'est jamais touchée.
 */
final class FbiErrorLedgerContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    /** Dans la MÊME saison que « aujourd'hui » réel (2026-2027) → socle courant en vigueur. */
    private const string PINNED_NOW = '2026-12-01T09:00:00';

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $teamAId = '';

    private string $teamBId = '';

    private string $venueId = '';

    private string $fixtureAId = '';

    private string $fixtureBId = '';

    private string $fingerprint = '';

    /** @var list<array<string, mixed>> Conflits rendus par le dernier GET du radar. */
    private array $conflicts = [];

    #[Given('un club de démonstration prêt à traiter une collision de gymnase')]
    public function unClubPretPourCollision(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);
        $this->pinClock(self::PINNED_NOW);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // Le radar exige que le socle pointe une version (comme le module matchs).
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

    #[Given('deux matchs à domicile qui se chevauchent dans le même gymnase')]
    public function deuxMatchsQuiSeChevauchent(): void
    {
        // Deux équipes JETABLES (catégorie/palier clonés d'une équipe valide du club).
        $clone = $this->dbalScalar(
            \sprintf('SELECT sport_category_id || \'|\' || priority_tier_id || \'|\' || COALESCE(tier_order::text, \'0\') AS behatval FROM team WHERE club_id=\'%s\' LIMIT 1', $this->clubId),
            admin: true,
        );
        $parts = '' === $clone ? [] : explode('|', $clone);
        if (3 !== \count($parts)) {
            throw new RuntimeException('impossible de cloner une catégorie/palier valide (le club a-t-il des équipes seedées ?)');
        }
        [$sportCategoryId, $priorityTierId, $tierOrder] = $parts;

        $this->teamAId = $this->idOf($this->apiPost('teams', [
            'name' => 'Équipe jetable A (erreur FBI)',
            'sportCategoryId' => $sportCategoryId,
            'priorityTierId' => (int) $priorityTierId,
            'tierOrder' => (int) $tierOrder,
        ], $this->token), 'équipe jetable A');
        $this->teamBId = $this->idOf($this->apiPost('teams', [
            'name' => 'Équipe jetable B (erreur FBI)',
            'sportCategoryId' => $sportCategoryId,
            'priorityTierId' => (int) $priorityTierId,
            'tierOrder' => (int) $tierOrder,
        ], $this->token), 'équipe jetable B');

        $this->venueId = $this->idOf($this->apiPost('venues', [
            'name' => 'Gymnase jetable (erreur FBI)',
            'source' => 'manual',
        ], $this->token), 'gymnase jetable');

        // Samedi À VENIR relatif à l'horloge épinglée → futur. 18:45 puis 19:15 (30 min
        // d'écart), MÊME gymnase : les fenêtres SALLE se chevauchent → collision de gymnase.
        $saturday = new DateTimeImmutable(self::PINNED_NOW)->modify('next saturday')->format('Y-m-d');
        $this->fixtureAId = $this->poserMatchDomicile($this->teamAId, $saturday, '18:45');
        $this->fixtureBId = $this->poserMatchDomicile($this->teamBId, $saturday, '19:15');

        $this->rafraichirConflits();
        $this->fingerprint = $this->empreinteDeLaCollision();
    }

    #[When('le gestionnaire déclare « erreur FBI » sur la salle du premier match')]
    public function declareErreurFbi(): void
    {
        $response = $this->apiPut(
            $this->resolutionPath($this->fingerprint),
            ['status' => 'FBI_ERROR', 'fbiCorrection' => ['fixtureId' => $this->fixtureAId, 'field' => 'venue']],
            $this->token,
        );
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('déclarer une erreur FBI a répondu %d (200 attendu) : %s', $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }
    }

    #[Then('ce conflit de gymnase porte la résolution « erreur FBI »')]
    public function leConflitPorteLaResolution(): void
    {
        $this->rafraichirConflits();
        $conflict = $this->conflitParEmpreinte($this->fingerprint);
        if (null === $conflict) {
            throw new RuntimeException('la collision de gymnase a disparu du radar — un statut ne doit JAMAIS masquer le conflit');
        }
        $resolution = $conflict['resolution'] ?? null;
        $status = \is_array($resolution) ? ($resolution['status'] ?? null) : null;
        if ('FBI_ERROR' !== $status) {
            throw new RuntimeException(\sprintf('le conflit ne porte pas la résolution « erreur FBI » (obtenu : %s)', \is_string($status) ? $status : 'aucune'));
        }
    }

    #[Then('le registre « à corriger dans FBI » gagne une entrée « salle à vérifier » pour ce match')]
    public function leRegistreGagneUneEntree(): void
    {
        $response = $this->apiGet('fixtures/fbi-corrections', $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('GET /api/fixtures/fbi-corrections a répondu %d (200 attendu)', $response['status']));
        }
        $corrections = $response['json']['corrections'] ?? null;
        $rows = array_values(array_filter(\is_array($corrections) ? $corrections : [], 'is_array'));

        $entry = null;
        foreach ($rows as $row) {
            if (($row['fixtureId'] ?? null) === $this->fixtureAId && 'venue' === ($row['field'] ?? null)) {
                $entry = $row;
                break;
            }
        }
        if (null === $entry) {
            throw new RuntimeException('aucune entrée « à corriger dans FBI » pour la salle de ce match — la déclaration n\'a pas alimenté le registre');
        }
        // Valeur cible VIDE : l'appli a importé l'erreur, elle ne l'invente pas (« à vérifier »).
        $appValue = $entry['appValue'] ?? null;
        if (null !== $appValue && '' !== $appValue) {
            throw new RuntimeException(\sprintf('la valeur cible devrait rester vide (« à vérifier »), obtenu « %s »', (string) $appValue));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        $this->releaseClock();

        // La ligne de résolution d'abord (orpheline sinon — jamais nettoyée à la volée).
        if ('' !== $this->fingerprint) {
            $this->apiDelete($this->resolutionPath($this->fingerprint), $this->token);
        }
        // L'entrée de registre ouverte par la déclaration (pas de FK sur la rencontre).
        foreach ([$this->fixtureAId, $this->fixtureBId] as $id) {
            if ('' !== $id) {
                $this->dbalExec(\sprintf('DELETE FROM fbi_correction WHERE fixture_id=\'%s\'', $id), admin: true);
            }
        }
        foreach ([$this->fixtureAId, $this->fixtureBId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        foreach ([$this->teamAId, $this->teamBId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('teams/%s', $id), $this->token);
            }
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

    private function poserMatchDomicile(string $teamId, string $date, string $kickoff): string
    {
        return $this->idOf(
            $this->apiPost('fixtures', [
                'teamId' => $teamId,
                'matchDate' => $date,
                'homeAway' => 'HOME',
                'opponentLabel' => 'Adversaire jetable',
                'venueId' => $this->venueId,
                'kickoffTime' => $kickoff,
            ], $this->token),
            'match à domicile jetable',
        );
    }

    private function rafraichirConflits(): void
    {
        $response = $this->apiGet('fixtures/conflicts', $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('GET /api/fixtures/conflicts a répondu %d (200 attendu)', $response['status']));
        }
        $conflicts = $response['json']['conflicts'] ?? null;
        $this->conflicts = array_values(array_filter(\is_array($conflicts) ? $conflicts : [], 'is_array'));
    }

    /** L'empreinte de la COLLISION DE GYMNASE portée par les deux matchs jetables. */
    private function empreinteDeLaCollision(): string
    {
        $mine = [$this->fixtureAId, $this->fixtureBId];
        foreach ($this->conflicts as $conflict) {
            if ('VENUE_OVERLAP' !== ($conflict['type'] ?? null)) {
                continue;
            }
            $left = \is_array($conflict['left'] ?? null) ? ($conflict['left']['fixtureId'] ?? null) : null;
            $right = \is_array($conflict['right'] ?? null) ? ($conflict['right']['fixtureId'] ?? null) : null;
            if (\in_array($left, $mine, true) && \in_array($right, $mine, true)) {
                $fingerprint = $conflict['fingerprint'] ?? null;
                if (\is_string($fingerprint) && '' !== $fingerprint) {
                    return $fingerprint;
                }
            }
        }

        throw new RuntimeException('aucune collision de gymnase entre les deux matchs jetables — le décor a-t-il bien produit un chevauchement ?');
    }

    /** @return array<string, mixed>|null */
    private function conflitParEmpreinte(string $fingerprint): ?array
    {
        foreach ($this->conflicts as $conflict) {
            if (($conflict['fingerprint'] ?? null) === $fingerprint) {
                return $conflict;
            }
        }

        return null;
    }

    private function resolutionPath(string $fingerprint): string
    {
        return \sprintf('fixtures/conflicts/%s/resolution', rawurlencode($fingerprint));
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function idOf(array $response, string $what): string
    {
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour (HTTP %d) : %s', $what, $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }

        return $id;
    }

    /** Épingle l'horloge de l'app (POST /api/dev/clock, dev-only, honoré app-wide). */
    private function pinClock(string $iso): void
    {
        $response = $this->apiPost('dev/clock', ['at' => $iso], $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('impossible d\'épingler l\'horloge de dev (HTTP %d) — la stack tourne-t-elle bien en dev ?', $response['status']));
        }
    }

    /** Relâche l'horloge → temps réel. Ne lève pas : le nettoyage doit toujours continuer. */
    private function releaseClock(): void
    {
        $this->apiPost('dev/clock', ['at' => null], $this->token);
    }
}
