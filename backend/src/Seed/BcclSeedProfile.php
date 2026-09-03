<?php

declare(strict_types=1);

namespace App\Seed;

use InvalidArgumentException;

/**
 * P2-4 PR 2bis — l'IDENTITÉ d'un seed BCCL : le même club réaliste (équipes,
 * gymnases, créneaux, contraintes, réservations — l'état terrain), sous deux
 * visages.
 *
 * - `dev()` : le BCCL réel (logo compris) — le club dev de `make seed-bccl`.
 * - `demo(password)` : le club de DÉMONSTRATION permanent — noms de club et de
 *   coachs FICTIFS (RGPD : l'écran part en rendez-vous), pas de logo BCCL, flag
 *   `is_demo` posé.
 *
 * Les gymnases gardent leurs noms/ancrages réels : ce sont des bâtiments
 * publics, et l'ancre fédérale fait marcher les écrans (stats, autocomplétion).
 */
final readonly class BcclSeedProfile
{
    /**
     * 26 identités fictives, une par coach du seed, DANS L'ORDRE de sa liste —
     * le remplacement est positionnel, donc déterministe d'un reset à l'autre.
     * Les libellés qui citent un coach (« %s - Indisponible mercredi ») suivent
     * automatiquement : l'entité est renommée AVANT que les contraintes ne
     * lisent son prénom.
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
     *                                                                                                                  du week-end (dev SEULEMENT)
     * @param list<array{email: string, firstName: string, lastName: string, password: string}> $additionalManagers     gestionnaires (User + ClubUser admin) EN PLUS du
     *                                                                                                                  gestionnaire principal — find-or-create par email,
     *                                                                                                                  jamais écrasés (dev SEULEMENT ; [] ailleurs)
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
        public array $additionalManagers,
    ) {}

    public static function dev(): self
    {
        return new self(
            clubName: 'B CHARPENNES CROIX LUIZET',
            clubSlug: 'b-charpennes-croix-luizet',
            ffbbCode: 'ARA0069036',
            managerEmail: 'mara.mb@bccl.fr',
            managerFirstName: 'Mara',
            managerLastName: 'Mb',
            managerPassword: 'maraboubccl',
            seedLogo: true,
            isDemo: false,
            coachNames: null,
            transcribeRealSchedule: true,
            // P5-13 — le club dev porte, EN PLUS du planning de saison, deux plans de reprise
            // (17 et 24 août) et le compte gestionnaire Nicolas. Dev SEULEMENT.
            seedReprisePeriods: true,
            // P5-13 « incident Matéo » — le club dev porte aussi l'état d'adaptation EN COURS du
            // gestionnaire (fermeture de Matéo + son plan d'ajustement non validé). Dev SEULEMENT.
            seedMateoIncident: true,
            // Répartition WE des matchs — le club dev porte l'état terrain du week-end (fenêtres
            // d'accès match, habitudes de match des équipes, créneaux partagés A/B). Dev SEULEMENT.
            seedWeekendMatchLayout: true,
            additionalManagers: [
                // Mot de passe EN CLAIR, hashé au seed (patron du gestionnaire principal
                // ci-dessus). Find-or-create par email, jamais écrasé s'il existe déjà.
                ['email' => 'nicolas.barilleau@bccl.fr', 'firstName' => 'Nicolas', 'lastName' => 'Barilleau', 'password' => 'NicolasB'],
            ],
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
            additionalManagers: [],
        );
    }
}
