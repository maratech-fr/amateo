<?php

declare(strict_types=1);

namespace App\Seed;

use App\Command\BcclProdSeedCommand;
use InvalidArgumentException;

/**
 * P2-4 PR 2bis — l'IDENTITÉ d'un seed BCCL : le même club réaliste (équipes,
 * gymnases, créneaux, contraintes, réservations — l'état terrain), sous plusieurs
 * visages.
 *
 * AUD-SEC-29 — le dépôt est PUBLIC, il ne porte donc QUE du fictif : par défaut,
 * `dev()`/`prod()` posent un gestionnaire fictif (`dev-bccl@amateo.local`, mot de
 * passe évidemment fictif commité) et des coachs aux SURNOMS (« Coach … »). Les
 * VRAIES identités du club du fondateur vivent hors dépôt, dans un fichier local
 * gitignoré ({@see BcclSeedIdentities}) : présent → injecté (gestionnaires réels +
 * remplacement positionnel des coachs), absent (CI, autre dev) → le fictif.
 *
 * - `dev([identities])` : le club dev de `make seed-bccl`.
 * - `prod(email, password, firstName, lastName[, identities])` : le même club en PROD.
 * - `demo(password)` : le club de DÉMONSTRATION permanent — identités fictives
 *   « réalistes » ({@see FICTIONAL_COACHES}), pas de logo, flag `is_demo` posé.
 *
 * Les gymnases gardent leurs noms/ancrages réels : ce sont des bâtiments
 * publics, et l'ancre fédérale fait marcher les écrans (stats, autocomplétion).
 */
final readonly class BcclSeedProfile
{
    /**
     * Identités fictives « réalistes » pour le club de DÉMONSTRATION et les clubs de CHARGE,
     * une par coach du seed, DANS L'ORDRE de sa liste — remplacement positionnel, déterministe.
     * Depuis AUD-SEC-29 le seed par défaut est DÉJÀ fictif (surnoms « Coach … ») : cette liste
     * n'est plus l'anonymisation RGPD du dépôt, elle donne juste à la démo des noms plus crédibles
     * que les surnoms. La garde « liste au moins aussi longue que le seed » reste (le seeder lève
     * sinon). Les libellés qui citent un coach (« %s · indispo %s ») suivent automatiquement :
     * l'entité est renommée AVANT que les contraintes ne lisent son prénom.
     */
    private const array FICTIONAL_COACHES = [
        ['firstName' => 'Mathéo', 'lastName' => 'Verne'],
        ['firstName' => 'Salomé', 'lastName' => ''],
        ['firstName' => 'Edgar', 'lastName' => 'Rollin'],
        ['firstName' => 'Timo', 'lastName' => 'Grange'],
        ['firstName' => 'Ilyes', 'lastName' => ''],
        ['firstName' => 'Bastien', 'lastName' => ''],
        ['firstName' => 'Maud', 'lastName' => 'Ferrand'],
        ['firstName' => 'Côme', 'lastName' => ''],
        ['firstName' => 'Rayan', 'lastName' => ''],
        ['firstName' => 'Gaspard', 'lastName' => 'Loyer'],
        ['firstName' => 'Damien', 'lastName' => 'Vasseur'],
        ['firstName' => 'Lina', 'lastName' => ''],
        ['firstName' => 'Corentin', 'lastName' => ''],
        ['firstName' => 'Marco', 'lastName' => 'Bellini'],
        ['firstName' => 'Éva', 'lastName' => ''],
        ['firstName' => 'Fabrice', 'lastName' => ''],
        ['firstName' => 'Noah', 'lastName' => 'Carlier'],
        ['firstName' => 'Louna', 'lastName' => ''],
        ['firstName' => 'Rémi', 'lastName' => 'Deschamps'],
        ['firstName' => 'Maïwenn', 'lastName' => ''],
        ['firstName' => 'Jules', 'lastName' => ''],
        ['firstName' => 'Evan', 'lastName' => ''],
        ['firstName' => 'Assia', 'lastName' => ''],
        ['firstName' => 'Azou', 'lastName' => ''],
        ['firstName' => 'Charlie', 'lastName' => ''],
        ['firstName' => 'Jade', 'lastName' => ''],
        // 2026-08-18 — quatre identités de plus : le seed a gagné quatre coachs (relevé de la
        // base réelle du club). La liste doit rester AU MOINS aussi longue que celle du seed,
        // sinon la démo refuse de partir — garde volontaire : jamais d'anonymisation partielle.
        ['firstName' => 'Nathan', 'lastName' => 'Perrot'],
        ['firstName' => 'Soraya', 'lastName' => ''],
        ['firstName' => 'Kenza', 'lastName' => ''],
        ['firstName' => 'Victor', 'lastName' => 'Delaunay'],
    ];

    /**
     * @param list<array{firstName: string, lastName: string}>|null                             $coachNames             remplacement 1-à-1, null = noms du seed
     * @param bool                                                                              $transcribeRealSchedule P5-17 : à `true`, le plan SEASON pointe une
     *                                                                                                                  version COMPLETED transcrivant le planning réel
     *                                                                                                                  (dev SEULEMENT — la démo reste avant génération)
     * @param bool                                                                              $seedReprisePeriods     P5-13 : à `true`, le seed ajoute deux plans de
     *                                                                                                                  reprise (17 et 24 août) sous une mère « Vacances
     *                                                                                                                  d'été » (dev SEULEMENT)
     * @param bool                                                                              $seedMateoIncident      P5-13 « incident Matéo » : à `true`, le seed pose
     *                                                                                                                  l'incident de fermeture de Matéo (entrée racine +
     *                                                                                                                  datée `venue_closed`, 31/08→16/10) et son plan de
     *                                                                                                                  fermeture SUR LA RACINE, pointant une version
     *                                                                                                                  COMPLETED qui transcrit le planning d'overlay réel
     *                                                                                                                  (dev SEULEMENT)
     * @param bool                                                                              $seedWeekendMatchLayout la répartition WE des matchs du club : à `true`, le
     *                                                                                                                  seed pose les fenêtres d'accès match des gymnases,
     *                                                                                                                  les habitudes de match des équipes et les créneaux
     *                                                                                                                  de match partagés (rotations A/B) — l'état terrain
     *                                                                                                                  du week-end (dev ET prod)
     * @param bool                                                                              $seedOpponentData       l'amorçage des trois tables de référence du module
     *                                                                                                                  « adversaires » (localisations partagées, appariements
     *                                                                                                                  du club, suggestions partagées) depuis
     *                                                                                                                  {@see BcclOpponentData} — données FÉDÉRALES PUBLIQUES,
     *                                                                                                                  pour que le ré-import des matchs retrouve ses
     *                                                                                                                  localisations (dev ET prod ; false pour démo/charge)
     * @param list<array{email: string, firstName: string, lastName: string, password: string}> $additionalManagers     gestionnaires (User + ClubUser admin) EN PLUS du
     *                                                                                                                  gestionnaire principal — find-or-create par email,
     *                                                                                                                  jamais écrasés (dev/prod ; [] ailleurs)
     */
    private function __construct(
        public string $clubName,
        public string $clubSlug,
        public string $ffbbCode,
        public string $managerEmail,
        public string $managerFirstName,
        public string $managerLastName,
        public string $managerPassword,
        public bool $seedLogo,
        public bool $isDemo,
        public ?array $coachNames,
        public bool $transcribeRealSchedule,
        public bool $seedReprisePeriods,
        public bool $seedMateoIncident,
        public bool $seedWeekendMatchLayout,
        public bool $seedOpponentData,
        public array $additionalManagers,
    ) {}

    public static function dev(?BcclSeedIdentities $identities = null): self
    {
        // Dépôt public : gestionnaire FICTIF par défaut (mot de passe évidemment fictif, commité —
        // même patron que loadTest()). Les vraies identités arrivent du fichier local gitignoré via
        // $identities (absent en CI / chez un autre dev → ce gestionnaire fictif).
        $manager = $identities instanceof BcclSeedIdentities && null !== $identities->manager ? $identities->manager : [
            'email' => 'dev-bccl@amateo.local',
            'firstName' => 'Mara',
            'lastName' => 'Mb',
            'password' => 'charge-load-test-pwd',
        ];

        return new self(
            clubName: 'B CHARPENNES CROIX LUIZET',
            clubSlug: 'b-charpennes-croix-luizet',
            ffbbCode: 'ARA0069036',
            managerEmail: $manager['email'],
            managerFirstName: $manager['firstName'],
            managerLastName: $manager['lastName'],
            managerPassword: $manager['password'],
            seedLogo: true,
            isDemo: false,
            // null → surnoms fictifs par défaut ; une liste (fichier local) → remplacement positionnel.
            coachNames: self::coachNamesFrom($identities),
            transcribeRealSchedule: true,
            // P5-13 — le club dev porte, EN PLUS du planning de saison, deux plans de reprise
            // (17 et 24 août). Dev SEULEMENT.
            seedReprisePeriods: true,
            // P5-13 « incident Matéo » — le club dev porte aussi l'état d'adaptation EN COURS du
            // gestionnaire (fermeture de Matéo + son plan d'ajustement non validé). Dev SEULEMENT.
            seedMateoIncident: true,
            // Répartition WE des matchs — le club dev porte l'état terrain du week-end (fenêtres
            // d'accès match, habitudes de match des équipes, créneaux partagés A/B).
            seedWeekendMatchLayout: true,
            // Amorçage des adversaires — le club dev porte les localisations/appariements/suggestions
            // fédéraux relevés de la base réelle (le ré-import des matchs les retrouve sans re-résoudre).
            seedOpponentData: true,
            // Co-gestionnaires EN PLUS, uniquement depuis le fichier local (jamais au dépôt).
            additionalManagers: $identities instanceof BcclSeedIdentities ? $identities->additionalManagers : [],
        );
    }

    /**
     * Le club BCCL RÉEL, jouable en PROD (même état terrain que {@see dev()} : club, coachs, logo,
     * mêmes drapeaux — transcription du planning réel, reprises, incident Matéo, répartition WE,
     * amorçage des adversaires). Parité avec la base locale du fondateur.
     *
     * AUD-SEC-29 — AUCUN prénom/nom/e-mail/mot de passe en dur : le gestionnaire arrive 100 % par
     * paramètres (options CLI de {@see BcclProdSeedCommand}, mots de passe ≥ 12). Co-gestionnaires
     * et vrais noms de coachs, s'ils sont voulus, viennent du fichier local gitignoré via
     * $identities. Comptes PRÉ-VÉRIFIÉS (le rail /register est mort sans e-mail sortant en prod).
     */
    public static function prod(
        string $managerEmail,
        string $managerPassword,
        string $managerFirstName,
        string $managerLastName,
        ?BcclSeedIdentities $identities = null,
    ): self {
        return new self(
            clubName: 'B CHARPENNES CROIX LUIZET',
            clubSlug: 'b-charpennes-croix-luizet',
            ffbbCode: 'ARA0069036',
            managerEmail: $managerEmail,
            managerFirstName: $managerFirstName,
            managerLastName: $managerLastName,
            managerPassword: $managerPassword,
            seedLogo: true,
            isDemo: false,
            coachNames: self::coachNamesFrom($identities),
            transcribeRealSchedule: true,
            seedReprisePeriods: true,
            seedMateoIncident: true,
            seedWeekendMatchLayout: true,
            seedOpponentData: true,
            additionalManagers: $identities instanceof BcclSeedIdentities ? $identities->additionalManagers : [],
        );
    }

    /**
     * Profil de club JETABLE pour le harness de mesure de charge (dev-only) :
     * N clubs indépendants, chacun l'état terrain complet du BCCL sous une
     * identité fictive numérotée. Les codes FFBB vivent hors plage réelle
     * (ARA9999001..ARA9999099) et n'entrent JAMAIS en collision avec dev()
     * (ARA0069036) ni demo() (ARA9999999). Coachs fictifs (RGPD), pas de logo,
     * pas de flag démo — ce ne sont pas des clubs de démonstration, juste de la
     * charge à jeter.
     *
     * @param int $index 1..99 — l'ordinal du club dans la rafale
     */
    public static function loadTest(int $index): self
    {
        if ($index < 1 || $index > 99) {
            throw new InvalidArgumentException(\sprintf('Load-test club index must be between 1 and 99, got %d.', $index));
        }

        return new self(
            clubName: \sprintf('Club Charge %d', $index),
            clubSlug: \sprintf('club-charge-%d', $index),
            // ARA9999001..ARA9999099 : préfixe ARA (ligue/zone se résolvent) mais
            // numéro hors plage réelle, distinct du ARA9999999 de demo().
            ffbbCode: \sprintf('ARA99990%02d', $index),
            managerEmail: \sprintf('charge-%d@amateo.local', $index),
            managerFirstName: 'Charge',
            managerLastName: \sprintf('Manager %d', $index),
            managerPassword: 'charge-load-test-pwd',
            seedLogo: false,
            isDemo: false,
            coachNames: self::FICTIONAL_COACHES,
            // Charge à jeter : on mesure la GÉNÉRATION, pas un planning pré-transcrit.
            transcribeRealSchedule: false,
            // Ni plans de reprise ni gestionnaire additionnel : c'est un club de charge.
            seedReprisePeriods: false,
            // Ni incident Matéo : la charge mesure la génération, pas un état d'adaptation figé.
            seedMateoIncident: false,
            // Ni répartition WE des matchs : la charge mesure la génération d'entraînements.
            seedWeekendMatchLayout: false,
            // Ni amorçage des adversaires : la charge mesure la génération, pas le module matchs.
            seedOpponentData: false,
            additionalManagers: [],
        );
    }

    public static function demo(string $managerPassword, string $managerEmail = 'demo-bccl@amateo.fr'): self
    {
        return new self(
            clubName: 'Démo Basket Club',
            clubSlug: 'demo-basket-club',
            // Préfixe ARA conservé : la ligue (AURA) et la zone scolaire se résolvent
            // depuis le préfixe — un code fantaisiste casserait les deux écrans.
            // Le numéro est hors plage réelle : jamais un vrai club.
            ffbbCode: 'ARA9999999',
            managerEmail: $managerEmail,
            managerFirstName: 'Démo',
            managerLastName: 'Amateo',
            managerPassword: $managerPassword,
            seedLogo: false,
            isDemo: true,
            coachNames: self::FICTIONAL_COACHES,
            // La démo reste « avant première génération » : l'écran de démonstration part
            // sur le wizard/Récap, sans planning pré-pointé.
            transcribeRealSchedule: false,
            // La démo ne porte ni plan de période ni compte Nicolas (dev SEULEMENT).
            seedReprisePeriods: false,
            // La démo ne porte pas l'incident Matéo (dev SEULEMENT — elle reste vierge de calendrier).
            seedMateoIncident: false,
            // La démo ne porte pas la répartition WE des matchs (dev SEULEMENT).
            seedWeekendMatchLayout: false,
            // La démo ne porte pas d'adversaires (RGPD : elle reste vierge de calendrier de matchs).
            seedOpponentData: false,
            additionalManagers: [],
        );
    }

    /**
     * Les noms de coachs à injecter positionnellement, depuis le fichier local : `null` quand il
     * est absent ou n'en porte pas (le seed garde alors ses surnoms fictifs par défaut).
     *
     * @return list<array{firstName: string, lastName: string}>|null
     */
    private static function coachNamesFrom(?BcclSeedIdentities $identities): ?array
    {
        if (!$identities instanceof BcclSeedIdentities || [] === $identities->coachNames) {
            return null;
        }

        return $identities->coachNames;
    }
}
