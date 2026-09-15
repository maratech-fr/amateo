<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\ConflictFingerprinter;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Un conflit traité reste visible, mais décompté (P4-207), sur la stack qui tourne.
 *
 * Le gestionnaire pose un statut de traitement sur UN conflit du radar : le conflit reste rendu,
 * avec sa résolution à côté (jamais masqué) ; un autre litige reste « à traiter » (aucune ligne) ;
 * et un membre sans rôle de gestion ne peut rien poser (403). La clé = l'empreinte STABLE du
 * conflit ({@see ConflictFingerprinter}).
 *
 * Autosuffisant : une équipe JETABLE (POST API) + deux rencontres à l'extérieur SANS coup d'envoi
 * → deux conflits AWAY_NO_FOOTPRINT distincts (un par rencontre) ; pointeur de socle posé s'il
 * manque puis restauré (à NULL) ; horloge épinglée pour que les rencontres restent futures. Tout le
 * décor est retiré en fin de scénario (les lignes de résolution comprises, sinon elles resteraient
 * en orphelines — elles ne sont jamais nettoyées à la volée, par conception). La grille du club réel
 * n'est jamais touchée.
 */
final class ConflictResolutionContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    /** Dans la MÊME saison que « aujourd'hui » réel (2026-2027) → socle courant en vigueur. */
    private const string PINNED_NOW = '2026-12-01T09:00:00';

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $firstFixtureId = '';

    private string $secondFixtureId = '';

    private string $firstFingerprint = '';

    private string $secondFingerprint = '';

    private string $memberUserId = '';

    private string $memberToken = '';

    private int $memberWriteStatus = 0;

    /** @var list<array<string, mixed>> Conflits rendus par le dernier GET du radar. */
    private array $conflicts = [];

    #[Given('le club de démonstration, connecté, dont le planning de saison est en vigueur')]
    public function leClubConnecteAvecSocleEnVigueur(): void
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

    #[Given('deux litiges distincts sur le radar des matchs')]
    public function deuxLitigesDistincts(): void
    {
        // Équipe jetable (catégorie/palier clonés d'une équipe valide du club).
        $clone = $this->dbalScalar(
            \sprintf('SELECT sport_category_id || \'|\' || priority_tier_id || \'|\' || COALESCE(tier_order::text, \'0\') AS behatval FROM team WHERE club_id=\'%s\' LIMIT 1', $this->clubId),
            admin: true,
        );
        $parts = '' === $clone ? [] : explode('|', $clone);
        if (3 !== \count($parts)) {
            throw new RuntimeException('impossible de cloner une catégorie/palier valide (le club a-t-il des équipes seedées ?)');
        }
        [$sportCategoryId, $priorityTierId, $tierOrder] = $parts;

        $team = $this->apiPost('teams', [
            'name' => 'Équipe jetable (résolution conflits)',
            'sportCategoryId' => $sportCategoryId,
            'priorityTierId' => (int) $priorityTierId,
            'tierOrder' => (int) $tierOrder,
        ], $this->token);
        $this->teamId = $this->idOf($team, 'équipe jetable');

        // Deux rencontres À L'EXTÉRIEUR sans coup d'envoi → deux conflits AWAY_NO_FOOTPRINT
        // distincts (l'empreinte porte la rencontre). Dates futures (horloge épinglée).
        $this->firstFixtureId = $this->poserRencontreExterieurSansHeure('2027-01-14');
        $this->secondFixtureId = $this->poserRencontreExterieurSansHeure('2027-01-21');

        $this->rafraichirConflits();
        $this->firstFingerprint = $this->empreinteDe($this->firstFixtureId);
        $this->secondFingerprint = $this->empreinteDe($this->secondFixtureId);
    }

    #[Given('un membre du club sans rôle de gestion')]
    public function unMembreSansRoleDeGestion(): void
    {
        $this->memberUserId = $this->uuid();
        $email = 'membre-resolution-' . substr(md5(uniqid('', true)), 0, 8) . '@fonctionnel.test';
        $this->insertUser($this->memberUserId, $email);
        $this->insertMembership($this->memberUserId, $this->clubId, 'member');
        $this->memberToken = $this->mintToken($email);
    }

    #[When('le gestionnaire pose « Dérogation demandée » sur le premier litige')]
    public function leGestionnairePoseDerogation(): void
    {
        $response = $this->apiPut(
            $this->resolutionPath($this->firstFingerprint),
            ['status' => 'DEROGATION_REQUESTED', 'note' => 'Dérogation demandée à la ligue'],
            $this->token,
        );
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('poser une dérogation a répondu %d (200 attendu)', $response['status']));
        }
    }

    #[When('ce membre tente de poser « Réglé en interne » sur le premier litige')]
    public function ceMembreTenteDePoserUnStatut(): void
    {
        $response = $this->apiPut(
            $this->resolutionPath($this->firstFingerprint),
            ['status' => 'RESOLVED_INTERNALLY'],
            $this->memberToken,
        );
        $this->memberWriteStatus = $response['status'];
    }

    #[Then('le radar rend toujours ce litige, portant la résolution « Dérogation demandée »')]
    public function leRadarRendToujoursLeLitigeAvecSaResolution(): void
    {
        $this->rafraichirConflits();
        $conflict = $this->conflitParEmpreinte($this->firstFingerprint);
        if (null === $conflict) {
            throw new RuntimeException('le conflit traité a disparu du radar — un statut ne doit JAMAIS masquer le conflit');
        }
        $resolution = $conflict['resolution'] ?? null;
        $status = \is_array($resolution) ? ($resolution['status'] ?? null) : null;
        if ('DEROGATION_REQUESTED' !== $status) {
            throw new RuntimeException(\sprintf('le conflit ne porte pas la résolution « Dérogation demandée » (obtenu : %s)', \is_string($status) ? $status : 'aucune'));
        }
    }

    #[Then('l\'autre litige reste « à traiter »')]
    public function lAutreLitigeResteATraiter(): void
    {
        $conflict = $this->conflitParEmpreinte($this->secondFingerprint);
        if (null === $conflict) {
            throw new RuntimeException('l\'autre litige a disparu du radar');
        }
        if (null !== ($conflict['resolution'] ?? null)) {
            throw new RuntimeException('l\'autre litige porte une résolution alors qu\'il devrait rester « à traiter » (aucune ligne = null)');
        }
    }

    #[Then('le statut lui est refusé')]
    public function leStatutLuiEstRefuse(): void
    {
        if (403 !== $this->memberWriteStatus) {
            throw new RuntimeException(\sprintf('un membre sans rôle de gestion aurait dû se voir refuser l\'écriture (403), obtenu %d', $this->memberWriteStatus));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        $this->releaseClock();

        // Les lignes de résolution d'abord (sinon elles restent orphelines une fois les
        // rencontres supprimées — jamais nettoyées à la volée, par conception).
        foreach ([$this->firstFingerprint, $this->secondFingerprint] as $fingerprint) {
            if ('' !== $fingerprint) {
                $this->apiDelete($this->resolutionPath($fingerprint), $this->token);
            }
        }
        foreach ([$this->firstFixtureId, $this->secondFixtureId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        if ('' !== $this->teamId) {
            $this->apiDelete(\sprintf('teams/%s', $this->teamId), $this->token);
        }
        if ('' !== $this->memberUserId) {
            $this->dbalExec(\sprintf('DELETE FROM club_user WHERE user_id=\'%s\'', $this->memberUserId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->memberUserId), admin: true);
        }

        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    private function poserRencontreExterieurSansHeure(string $date): string
    {
        return $this->idOf(
            $this->apiPost('fixtures', [
                'teamId' => $this->teamId,
                'matchDate' => $date,
                'homeAway' => 'AWAY',
                'opponentLabel' => 'Adversaire sans empreinte',
            ], $this->token),
            'rencontre extérieure sans heure',
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

    /** L'empreinte du conflit AWAY_NO_FOOTPRINT porté par cette rencontre. */
    private function empreinteDe(string $fixtureId): string
    {
        foreach ($this->conflicts as $conflict) {
            $fixtureBlock = $conflict['fixture'] ?? null;
            $blockFixtureId = \is_array($fixtureBlock) ? ($fixtureBlock['fixtureId'] ?? null) : null;
            if ('AWAY_NO_FOOTPRINT' === ($conflict['type'] ?? null) && $blockFixtureId === $fixtureId) {
                $fingerprint = $conflict['fingerprint'] ?? null;
                if (\is_string($fingerprint) && '' !== $fingerprint) {
                    return $fingerprint;
                }
            }
        }

        throw new RuntimeException(\sprintf('aucun conflit AWAY_NO_FOOTPRINT ne porte la rencontre %s — le décor a-t-il bien produit un litige ?', $fixtureId));
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

    private function insertUser(string $userId, string $email): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'$2y$13$abcdefghijklmnopqrstuv\', \'Fonc\', \'Tionnel\')',
                $userId,
                $email,
            ),
            admin: true,
        );
    }

    private function insertMembership(string $userId, string $clubId, string $role): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'%s\', now(), true)',
                $this->uuid(),
                $clubId,
                $userId,
                $role,
            ),
            admin: true,
        );
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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
