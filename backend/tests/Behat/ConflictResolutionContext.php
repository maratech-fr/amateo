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
 *
 * Lot 7, PR 4b : un statut posé sur un conflit qu'une règle neuve fait disparaître (le « hors fenêtre ligue » d'une
 * coupe) reste en base, invisible, et ne se rattache pas au conflit neuf de la coupe (« coupe hors horaires »).
 */
final class ConflictResolutionContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    /** Dans la MÊME saison que « aujourd'hui » réel (2026-2027) → socle courant en vigueur. */
    private const string PINNED_NOW = '2026-12-01T09:00:00';

    /** Catégorie DISTINCTIVE (aucune vraie équipe ne la porte) : la fenêtre de ligue du décor ne cadre qu'elle. */
    private const string CUP_CATEGORY_NAME = 'BEHAT Coupe traitée';

    private const string CUP_SATURDAY = '2027-01-16';

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

    /** Lot 7, PR 4b : la coupe du samedi, sa catégorie distinctive et l'empreinte de son ancien conflit de ligue. */
    private string $cupFixtureId = '';

    private string $cupCategoryId = '';

    /** @var array<string, mixed> le corps qui a posé la coupe, rejoué par « Modifier le match » */
    private array $cupBody = [];

    private string $staleFingerprint = '';

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

    /**
     * Lot 7, PR 4b : une équipe jetable de catégorie distinctive, niveau régional, cadrée par une fenêtre de ligue le
     * samedi de 10h00 à 10h30 (copie club posée en SQL, comme dans `ConflictTruthContext`), et sa coupe à domicile le
     * samedi 16 janv. 2027 à 18h00, sans gymnase : hors de la fenêtre de ligue, dans les horaires d'une coupe.
     */
    #[Given('une coupe à domicile d\'une équipe jetable un samedi à 18h00, hors de la fenêtre de ligue de son équipe')]
    public function uneCoupeHorsDeLaFenetreDeLigue(): void
    {
        $this->dbalExec(\sprintf('DELETE FROM club_league_window WHERE category=\'%s\'', self::CUP_CATEGORY_NAME), admin: true);
        $this->dbalExec(\sprintf('DELETE FROM sport_category WHERE club_id=\'%s\' AND name=\'%s\' AND NOT EXISTS (SELECT 1 FROM team t WHERE t.sport_category_id = sport_category.id)', $this->clubId, self::CUP_CATEGORY_NAME), admin: true);
        $clone = $this->dbalScalar(
            \sprintf('SELECT sport_category_id || \'|\' || priority_tier_id || \'|\' || COALESCE(tier_order::text, \'0\') AS behatval FROM team WHERE club_id=\'%s\' LIMIT 1', $this->clubId),
            admin: true,
        );
        $parts = '' === $clone ? [] : explode('|', $clone);
        if (3 !== \count($parts)) {
            throw new RuntimeException('impossible de cloner une catégorie/palier valide (le club a-t-il des équipes seedées ?)');
        }
        [$sportCategoryId, $priorityTierId, $tierOrder] = $parts;
        $this->teamId = $this->idOf($this->apiPost('teams', [
            'name' => 'Équipe jetable (coupe traitée)',
            'sportCategoryId' => $sportCategoryId,
            'priorityTierId' => (int) $priorityTierId,
            'tierOrder' => (int) $tierOrder,
        ], $this->token), 'équipe jetable');

        $this->cupCategoryId = $this->dbalScalar('SELECT gen_random_uuid()::text AS behatval', admin: true);
        $this->dbalExec(\sprintf(
            'INSERT INTO sport_category (id, version, created_at, updated_at, club_id, sport_id, name, is_custom, sort_order)'
            . ' SELECT \'%s\', 1, now(), now(), club_id, sport_id, \'%s\', true, 0 FROM sport_category WHERE id=\'%s\'',
            $this->cupCategoryId,
            self::CUP_CATEGORY_NAME,
            $sportCategoryId,
        ), admin: true);
        $this->dbalExec(\sprintf('UPDATE team SET sport_category_id=\'%s\', level=\'REGIONAL\' WHERE id=\'%s\'', $this->cupCategoryId, $this->teamId), admin: true);
        $seasonId = $this->dbalScalar(\sprintf('SELECT season_id AS behatval FROM team WHERE id=\'%s\'', $this->teamId), admin: true);
        $clubLeague = $this->dbalScalar(\sprintf('SELECT COALESCE(league, \'\') AS behatval FROM club WHERE id=\'%s\'', $this->clubId), admin: true);
        $hasWindows = '' !== $this->dbalScalar(\sprintf('SELECT id AS behatval FROM league_match_window WHERE league=\'%s\' LIMIT 1', $clubLeague), admin: true);
        $this->dbalExec(\sprintf(
            'INSERT INTO club_league_window (id, version, created_at, updated_at, club_id, season_id, league, category, level, gender, day_of_week, kickoff_min, kickoff_max)'
            . ' VALUES (gen_random_uuid(), 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', \'REGIONAL\', NULL, 6, \'10:00\', \'10:30\')',
            $this->clubId,
            $seasonId,
            ('' !== $clubLeague && $hasWindows) ? $clubLeague : 'AURA',
            self::CUP_CATEGORY_NAME,
        ), admin: true);

        $competitionId = $this->idOf(
            $this->apiPost('competitions', ['teamId' => $this->teamId, 'name' => 'Coupe du Rhône (coupe traitée)', 'competitionType' => 'CUP'], $this->token),
            'compétition coupe',
        );
        $this->cupBody = [
            'teamId' => $this->teamId,
            'competitionId' => $competitionId,
            'matchDate' => self::CUP_SATURDAY,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Adversaire coupe',
            'kickoffTime' => '18:00',
        ];
        $this->cupFixtureId = $this->idOf($this->apiPost('fixtures', $this->cupBody, $this->token), 'coupe du samedi');
    }

    /**
     * Le statut d'hier, quand une coupe était jugée contre la ligue : l'API refuse aujourd'hui une empreinte que le radar
     * ne porte pas, la ligne d'avant est donc posée en SQL, telle que la table la gardait.
     */
    #[Given('un statut « Dérogation demandée » posé autrefois sur le « hors fenêtre ligue » de cette coupe')]
    public function unStatutPoseAutrefois(): void
    {
        $this->staleFingerprint = 'LEAGUE_WINDOW_VIOLATION:' . $this->cupFixtureId;
        $this->dbalExec(\sprintf(
            'INSERT INTO conflict_resolution (id, version, created_at, updated_at, club_id, season_id, fingerprint, status, note, updated_by)'
            . ' SELECT gen_random_uuid(), 1, now() - interval \'30 days\', now() - interval \'30 days\', f.club_id, f.season_id, \'%s\', \'DEROGATION_REQUESTED\', \'Dérogation demandée à la ligue\', u.id'
            . ' FROM fixture f, app_user u WHERE f.id=\'%s\' AND u.email=\'%s\'',
            $this->staleFingerprint,
            $this->cupFixtureId,
            self::USER_EMAIL,
        ), admin: true);
        if ('1' !== $this->staleCount()) {
            throw new RuntimeException('le statut d\'autrefois n\'a pas été posé');
        }
    }

    #[When('je relis le radar des matchs')]
    public function jeRelisLeRadar(): void
    {
        $this->rafraichirConflits();
    }

    #[Then('le « hors fenêtre ligue » traité de la coupe n\'est plus sur le radar, et son statut reste en base')]
    public function leConflitTraiteDeLaCoupeAPeriDuRadar(): void
    {
        if (null !== $this->conflitParEmpreinte($this->staleFingerprint)) {
            throw new RuntimeException('le « hors fenêtre ligue » de la coupe est encore sur le radar : une coupe n\'est plus jugée contre la fenêtre de ligue');
        }
        foreach ($this->conflicts as $conflict) {
            $fixtureBlock = $conflict['fixture'] ?? null;
            if (\is_array($fixtureBlock) && $this->cupFixtureId === ($fixtureBlock['fixtureId'] ?? null) && null !== ($conflict['resolution'] ?? null)) {
                throw new RuntimeException(\sprintf('le statut d\'autrefois s\'est rattaché au conflit %s de la coupe', \is_string($conflict['type'] ?? null) ? $conflict['type'] : '?'));
            }
        }
        if ('1' !== $this->staleCount()) {
            throw new RuntimeException('le statut d\'autrefois a été supprimé ou réécrit : sa ligne doit rester en base, invisible');
        }
    }

    #[When('je repousse le coup d\'envoi de la coupe à 21h30')]
    public function jeRepousseLeCoupDEnvoi(): void
    {
        $response = $this->apiPut(\sprintf('fixtures/%s', $this->cupFixtureId), ['kickoffTime' => '21:30'] + $this->cupBody, $this->token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('« Modifier le match » a répondu %d (200 attendu) : %s', $response['status'], json_encode($response['json'], \JSON_UNESCAPED_UNICODE)));
        }
        $this->rafraichirConflits();
    }

    #[Then('la coupe porte « coupe hors horaires », « à traiter »')]
    public function laCoupePorteCoupeHorsHorairesATraiter(): void
    {
        $conflict = $this->conflitParEmpreinte('CUP_KICKOFF_OUT_OF_HOURS:' . $this->cupFixtureId);
        if (null === $conflict) {
            throw new RuntimeException('la coupe à 21h30 ne porte pas « coupe hors horaires » (empreinte CUP_KICKOFF_OUT_OF_HOURS:<match>)');
        }
        if (null !== ($conflict['resolution'] ?? null)) {
            throw new RuntimeException(\sprintf('le conflit neuf aurait dû naître « à traiter » (aucune résolution), il porte %s', json_encode($conflict['resolution'], \JSON_UNESCAPED_UNICODE)));
        }
        if (null !== $this->conflitParEmpreinte($this->staleFingerprint)) {
            throw new RuntimeException('le « hors fenêtre ligue » de la coupe est revenu sur le radar');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        $this->releaseClock();

        // Lot 7, PR 4b : le statut d'autrefois (introuvable par l'API, le radar ne porte plus son empreinte), la coupe,
        // puis la copie de fenêtre et la catégorie distinctives, une fois l'équipe partie (plus bas).
        if ('' !== $this->staleFingerprint) {
            $this->dbalExec(\sprintf('DELETE FROM conflict_resolution WHERE fingerprint=\'%s\'', $this->staleFingerprint), admin: true);
        }
        if ('' !== $this->cupFixtureId) {
            $this->apiDelete(\sprintf('fixtures/%s', $this->cupFixtureId), $this->token);
        }

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
        if ('' !== $this->cupCategoryId) {
            $this->dbalExec(\sprintf('DELETE FROM club_league_window WHERE category=\'%s\'', self::CUP_CATEGORY_NAME), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM sport_category WHERE id=\'%s\'', $this->cupCategoryId), admin: true);
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

    /** Le nombre de lignes du statut d'autrefois (« 1 » : posé et intact). */
    private function staleCount(): string
    {
        return $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM conflict_resolution WHERE fingerprint=\'%s\' AND status=\'DEROGATION_REQUESTED\'', $this->staleFingerprint), admin: true);
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
