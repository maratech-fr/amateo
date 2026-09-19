<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RGPD — portabilité (art. 20) : export machine-readable des données.
 *
 * Deux périmètres :
 * - exportUser : les données du COMPTE (responsable de traitement = nous) —
 *   identité + memberships. JAMAIS le hash de mot de passe.
 * - exportClub : le WORKSPACE complet du club (nous = sous-traitant, le club
 *   exerce la portabilité pour ses propres données) — lignes brutes par table,
 *   sélectionnées par club_id/season_id sous le GUC RLS du club courant (posé
 *   par le TenantFilterListener) : la frontière tenant est garantie par la DB
 *   même si une requête oubliait son WHERE.
 *
 * Le format est volontairement BRUT (une clé par table, lignes associatives) :
 * complet, stable, réimportable — pas une projection d'API qui rotirait.
 */
final class RgpdExportService
{
    /**
     * Tables club-scoped traitées AILLEURS dans exportClub() — elles sortent de la
     * boucle générique, pas de l'export.
     *
     * @var array<string, string> table => où elle est traitée
     */
    private const HANDLED_APART = [
        'schedule' => 'exporté sans son blob interne (snapshot_data)',
        'club_user' => 'exporté sous la clé `members`, jointe à app_user pour l\'email',
    ];

    /**
     * Tables club-scoped VOLONTAIREMENT hors de l'export, chacune avec sa raison.
     *
     * ⚠ Une exclusion se justifie, elle ne se constate pas : le test
     * `RgpdExportCompletenessTest` exige que toute entité
     * tenant soit exportée OU listée ici. Ajouter une entité sans y penser fait rougir.
     *
     * @var array<string, string> table => pourquoi
     */
    private const EXCLUDED_FROM_EXPORT = [
        // SECRET EN CLAIR (décision fondateur 2026-07-26, cf. Entity\CoachWishToken) : le
        // token EST l'identité de la page publique du coach. Le verser dans un fichier
        // téléchargeable transformerait l'export de portabilité en fuite de credentials —
        // quiconque obtient le JSON peut écrire des souhaits au nom de n'importe quel coach.
        // Les souhaits eux-mêmes (coach_wish) sont exportés : c'est LA donnée de l'art. 20.
        'coach_wish_token' => 'secret en clair — l\'exporter serait une fuite de credentials',
        // Base légale DIFFÉRENTE : le journal relève de l'accountability (art. 5.2, intérêt
        // légitime), pas du contrat ; la portabilité de l'art. 20 ne couvre que les données
        // fournies par la personne sur base contrat/consentement. Il est de surcroît
        // append-only par construction et sans PII (« ids uniquement » — docs/security/rgpd.md).
        'audit_log' => 'accountability art. 5.2, hors périmètre art. 20 ; append-only, sans PII',
        // Donnée d'ÉTABLISSEMENT recomputable, aucune PII : un temps de trajet dérivé du
        // siège du club et de coordonnées de gymnases. Hors art. 20 (données fournies par la
        // personne) ; l'exporter verserait de surcroît les coordonnées du siège dans un
        // fichier utilisateur. Purgée par club à l'effacement (ErasedClubPurger).
        'club_travel_cache' => 'donnée d\'établissement recomputable, sans PII — hors art. 20',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantContext,
    ) {}

    /** @return array<string, mixed> */
    public function exportUser(User $user): array
    {
        // Art. 15 : l'export d'un USER liste TOUTES ses memberships, pas celle du club
        // que la requête a résolu. SEC-12 ayant scopé le SELECT de club_user dès que le
        // GUC est posé, cette lecture cross-tenant volontaire sort explicitement du
        // contexte tenant — sans quoi l'export d'un user multi-club serait silencieusement
        // amputé aux lignes du club courant.
        $memberships = $this->tenantContext->runWithoutTenant(
            fn (): array => $this->connection()->fetchAllAssociative(
                'SELECT cu.club_id, c.name AS club_name, cu.role, cu.is_active, cu.joined_at, cu.created_at
                 FROM club_user cu JOIN club c ON c.id = cu.club_id
                 WHERE cu.user_id = :uid',
                ['uid' => $user->getId()],
            ),
        );

        return [
            'exportedAt' => date('c'),
            'kind' => 'user',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'createdAt' => $user->getCreatedAt()->format('c'),
                'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format('c'),
                // Complétude art. 15 : TOUT ce que l'app sait du compte —
                // preuve de consentement et traces d'activité comprises.
                'termsAcceptedAt' => $user->getTermsAcceptedAt()?->format('c'),
                'termsVersion' => $user->getTermsVersion(),
                'lastLoginAt' => $user->getLastLoginAt()?->format('c'),
            ],
            'memberships' => $memberships,
        ];
    }

    /** @return array<string, mixed> */
    public function exportClub(string $clubId): array
    {
        $connection = $this->connection();
        $data = [
            'exportedAt' => date('c'),
            'kind' => 'club',
            'club' => $connection->fetchAssociative('SELECT * FROM club WHERE id = :cid', ['cid' => $clubId]) ?: null,
        ];

        foreach (self::clubScopedTables() as $table) {
            // Noms de table issus des métadonnées Doctrine (jamais d'input) — le
            // quote gère le mot réservé `constraint`.
            $quoted = '"' . $table . '"';
            $data[$table] = $connection->fetchAllAssociative(
                \sprintf('SELECT * FROM %s WHERE club_id = :cid', $quoted),
                ['cid' => $clubId],
            );
        }

        // schedule sans son blob interne : SELECT * puis unset — une liste de
        // colonnes rotirait à chaque migration (revue PR-2), le blob exclu est
        // le seul invariant (snapshot_data = duplicat technique de l'export).
        $data['schedule'] = array_map(
            static function (array $row): array {
                unset($row['snapshot_data']);

                return $row;
            },
            $connection->fetchAllAssociative('SELECT * FROM schedule WHERE club_id = :cid', ['cid' => $clubId]),
        );

        // constraint_conflict n'a pas de club_id : jointure par schedule.
        $data['constraint_conflict'] = $connection->fetchAllAssociative(
            'SELECT cc.* FROM constraint_conflict cc
             JOIN schedule s ON s.id = cc.schedule_id WHERE s.club_id = :cid',
            ['cid' => $clubId],
        );

        // (team_tag_assignment passait ici par une jointure saison « faute de club_id » —
        // elle en a un depuis BCK-11, 2026-08-07 : la boucle générique la sert désormais,
        // et le contournement est retiré avec son commentaire devenu faux.)

        // Memberships du club (qui a accès) — sans données de compte au-delà
        // de l'email (les comptes appartiennent à leurs titulaires).
        $data['members'] = $connection->fetchAllAssociative(
            'SELECT cu.user_id, u.email, cu.role, cu.is_active, cu.joined_at
             FROM club_user cu JOIN app_user u ON u.id = cu.user_id
             WHERE cu.club_id = :cid',
            ['cid' => $clubId],
        );

        return $data;
    }

    /**
     * Les tables à exporter en bloc : TOUTES les entités tenant, moins celles traitées
     * à part et celles exclues nommément.
     *
     * ⚑ DÉRIVÉE, jamais recopiée (audit D-01, 2026-08-08). La liste vivait en dur et
     * avait dérivé : 9 tables tenant manquaient, dont `coach_wish` qui porte le
     * commentaire libre du coach et ses indisponibilités. L'omission était INVISIBLE —
     * `GET /api/club/export` rendait 200, un JSON valide, la clé simplement absente.
     * Le marqueur `TenantOwnedInterface` est déjà prouvé équivalent à la colonne
     * `club_id` par `TenantOwnedInterfaceCompletenessTest` :
     * on s'appuie sur une source déjà gardée plutôt que d'en maintenir une seconde.
     *
     * @return list<string>
     */
    public function clubScopedTables(): array
    {
        $tables = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!is_a($metadata->getName(), TenantOwnedInterface::class, true)) {
                continue;
            }

            $table = $metadata->getTableName();
            if (isset(self::HANDLED_APART[$table]) || isset(self::EXCLUDED_FROM_EXPORT[$table])) {
                continue;
            }

            $tables[] = $table;
        }

        sort($tables); // ordre stable d'un export à l'autre (diffable)

        return $tables;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
