<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use SplFileInfo;
use UnitEnum;

/**
 * P4-88 — LE GARDE qui rend opposable la règle de `.claude/rules/frontend.md` : « le front
 * n'invente JAMAIS une règle métier ». Bidirectionnel, deux moitiés de PORTÉE VOLONTAIREMENT
 * DIFFÉRENTE (décision fondateur 2026-08-12) :
 *
 *  (i)  REGISTRE — la maison de TOUS les miroirs front DÉCLARÉS (régime 2), quelle que soit
 *       la famille : tag→équipes, applicabilité de contrainte, dédoublement de coach, orphelines,
 *       accès match. Chaque entrée nomme sa source de vérité backend ET son test de parité
 *       mécanique. Une entrée sans test de parité → ROUGE. Un module front qui se DÉCLARE au
 *       registre (mentionne cette classe) mais n'y figure pas → ROUGE (personne ne s'auto-déclare
 *       sans entrée).
 *
 *  (ii) DÉTECTEUR — attrape mécaniquement une redérivation NON déclarée, mais ÉTROITEMENT :
 *       seulement la famille CONTRAINTE (un fichier branchant sur les valeurs de `ConstraintScope`
 *       / `ConstraintRuleType` / `ConstraintFamily` pour DÉCIDER). C'est la demande littérale du
 *       fondateur (« si demain je crée une nouvelle contrainte ») — pas « si demain je code
 *       n'importe quoi ». La largeur EST un choix : un détecteur sur TOUS les enums de
 *       `TsUnionsMatchPhpEnumsTest::MIRRORED` noyait la vraie alerte sous ~13 faux positifs
 *       (tri, éditeurs de formulaire, drapeaux de statut, « trouver le coach MAIN »), ce qui
 *       aurait fait un garde-qui-ment. **Pour ÉLARGIR** : ajouter le nom d'un enum à
 *       {@see self::POLICED_ENUMS} (il doit figurer dans `MIRRORED`). Chaque faux positif restant
 *       est exempté UN PAR UN avec sa raison ({@see self::EXEMPTIONS}) — jamais en masse.
 *
 * Les deux moitiés n'ont pas la même portée, et c'est voulu : le DÉTECTEUR ne police que les
 * contraintes ; le REGISTRE tient TOUS les miroirs déclarés (coach et match y entrent à la main,
 * hors de portée du détecteur). Falsifications (brief P4-88) : un `switch` décideur sur `scope`
 * dans un fichier non déclaré → (ii) le nomme ; retirer un test de parité → (i) rougit ; casser
 * un cas de parité d'un seul côté → le test de parité lui-même rougit (pas ce garde-ci).
 *
 * Régime : garde de FRONTIÈRE statique (lit les sources front depuis PHP, comme
 * `TsUnionsMatchPhpEnumsTest`) — groupe `contract` / job `engine-semantics`, PAS un step
 * `blocking-tests` (aucun invariant runtime multi-tenant).
 */
#[Group('contract')]
final class FrontRederivationRegistryTest extends TestCase
{
    private const string FRONT = __DIR__ . '/../../../frontend/src';

    private const string FEATURES = self::FRONT . '/features';

    /** Le marqueur qu'un module front pose dans sa source pour se déclarer miroir. */
    private const string DECLARATION_MARKER = 'FrontRederivationRegistryTest';

    /**
     * Les enums que le DÉTECTEUR police (sous-ensemble VOLONTAIRE de `MIRRORED` — la famille
     * contrainte). Élargir = ajouter un nom ici ; il doit exister dans `MIRRORED`.
     *
     * @var list<string>
     */
    private const array POLICED_ENUMS = ['ConstraintScope', 'ConstraintRuleType', 'ConstraintFamily'];

    /**
     * REGISTRE — chemin front (relatif à frontend/src) => [ce qu'il décide, sa source de vérité
     * backend, le test de parité mécanique qui le garde]. Toute entrée doit avoir un test de
     * parité existant, et le module doit se déclarer (marqueur en source).
     *
     * @var array<string, array{decides: string, backendTruth: string, parityTest: string}>
     */
    private const array REGISTRY = [
        'shared/lib/tagTeamIds.ts' => [
            'decides' => 'nom de tag → équipes de la saison (foyer partagé planning+wizard)',
            'backendTruth' => 'App\\Service\\TeamTagResolver::teamIdsByTagName',
            'parityTest' => 'TagTeamIdsMirrorParityTest.php',
        ],
        'features/planning/lib/applicableConstraints.ts' => [
            'decides' => 'quelles contraintes s\'appliquent à un créneau (scope, CLUB+tag éclaté en TEAM)',
            'backendTruth' => 'App\\Service\\ScheduleConstraintBuilder (expansion du payload)',
            'parityTest' => 'TagTeamIdsMirrorParityTest.php',
        ],
        'features/wizard/steps/PeriodStructure.tsx' => [
            'decides' => 'défaut reprise/fermeture par scope + masquage CLUB+tag sans équipe active',
            'backendTruth' => 'App\\Service\\ScheduleConstraintBuilder::inheritedPermanents',
            'parityTest' => 'TagTeamIdsMirrorParityTest.php',
        ],
        'features/wizard/lib/coachDoubleBooking.ts' => [
            'decides' => 'un geste met-il un coach MAIN à deux endroits en même temps',
            'backendTruth' => 'App\\Service\\CoachDoubleBookingDetector::bookingsCollide',
            'parityTest' => 'CoachDoubleBookingMirrorParityTest.php',
        ],
        'features/wizard/lib/orphanReservations.ts' => [
            'decides' => 'réservation orpheline par triplet (étroit) ET réservation NON SERVIE (large : hors service / jour fermé / triplet ∉ grille)',
            'backendTruth' => 'App\\Service\\OrphanPinGuard::orphanTripletIds + ::unservedReservationIds',
            'parityTest' => 'OrphanReservationsMirrorParityTest.php',
        ],
        'features/matches/lib/matchAccess.ts' => [
            'decides' => 'coup d\'envoi dans une fenêtre d\'accès match de (gymnase, jour)',
            'backendTruth' => 'App\\Service\\MatchConflictDetector::kickoffInsideWindow',
            'parityTest' => 'MatchAccessMirrorParityTest.php',
        ],
        'features/cockpit/lib/holidayWorkweek.ts' => [
            'decides' => 'une semaine est-elle « de vacances » (lundi→vendredi couvert) — offerte en reprise, exclue de l\'offre fermeture ; sinon semaine de saison',
            'backendTruth' => 'App\\Service\\HolidayWorkweekRule::covers (garde du POST d\'une semaine-enfant de vacances)',
            'parityTest' => 'HolidayWorkweekMirrorParityTest.php',
        ],
    ];

    /**
     * Faux positifs LÉGITIMES du détecteur (chemin relatif à frontend/src => raison). Chacun
     * branche sur un enum de contrainte pour CHOISIR UN LIBELLÉ ou ORGANISER l'affichage —
     * jamais pour redériver un verdict que le solveur applique. Un par un, avec sa raison.
     *
     * @var array<string, string>
     */
    private const array EXEMPTIONS = [
        'features/planning/lib/describeConstraint.ts' => 'présentation : family → verbes gymnase, scope → LIBELLÉ de cible (équipe/coach/Groupe X/Toutes les équipes) — jamais un verdict d\'applicabilité (ça, c\'est applicableConstraints)',
        'features/wizard/lib/constraintOrder.ts' => 'présentation : ordonne les contraintes pour l\'affichage (tri), aucun verdict solveur',
        'features/wizard/steps/ConstraintsStep.tsx' => 'éditeur de formulaire : quels CHAMPS montrer par famille lors de la saisie — pas ce que le solveur fait',
        'features/wizard/steps/RecapStep.tsx' => 'affichage : compte les contraintes HARD pour un chiffre du récap',
        'features/planning/SlotDetail.tsx' => 'présentation : ruleType → libellé « obligatoire »/« préférence »',
        'features/matches/lib/teamLinkLabel.ts' => 'présentation : maison UNIQUE du libellé d\'intensité de passerelle (table PREFERRED/MANDATORY → « Préféré »/« Obligatoire »), consommée par les deux hôtes de la sous-ligne — aucun verdict, le solveur reste seul juge de ce qu\'une intensité FAIT',
    ];

    // ---------------------------------------------------------------- (i) REGISTRE

    public function testEveryRegisteredMirrorHasItsParityTest(): void
    {
        $missing = [];
        foreach (self::REGISTRY as $frontPath => $entry) {
            if (!is_file(self::FRONT . '/' . $frontPath)) {
                $missing[] = \sprintf('%s : le module front déclaré n\'existe plus', $frontPath);
            }
            $parity = __DIR__ . '/' . $entry['parityTest'];
            if (!is_file($parity)) {
                $missing[] = \sprintf('%s : son test de parité %s a disparu — un miroir déclaré SANS parité ment en silence', $frontPath, $entry['parityTest']);
            }
        }

        self::assertSame([], $missing, \sprintf(
            "Le registre des miroirs front est incohérent :\n  - %s\n\n"
            . 'Chaque miroir déclaré (régime 2) EXISTE et est gardé par un test de parité MÉCANIQUE. '
            . 'Rétablir le fichier, ou retirer l\'entrée du registre (et la déclaration du module).',
            implode("\n  - ", $missing),
        ));
    }

    public function testNoModuleClaimsRegistryMembershipWithoutAnEntry(): void
    {
        $orphans = [];
        foreach ($this->frontSourceFiles() as $file) {
            $relative = $this->relative($file);
            if (isset(self::REGISTRY[$relative])) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), self::DECLARATION_MARKER)) {
                // Un fichier de cas/registre/test se référence légitimement ; on ne garde que les modules front.
                $orphans[] = $relative;
            }
        }

        self::assertSame([], $orphans, \sprintf(
            "Ces modules front se DÉCLARENT au registre mais n'y ont pas d'entrée :\n  - %s\n\n"
            . 'Un miroir ne s\'auto-déclare pas sans figurer au registre (sinon il échappe à la moitié (i)). '
            . 'Ajouter son entrée dans self::REGISTRY, ou retirer le marqueur de sa source.',
            implode("\n  - ", $orphans),
        ));
    }

    // ---------------------------------------------------------------- (ii) DÉTECTEUR

    public function testNoUndeclaredConstraintRuleRederivation(): void
    {
        $values = $this->policedEnumValues();
        $violations = [];

        foreach ($this->frontSourceFiles() as $file) {
            $relative = $this->relative($file);
            if (isset(self::REGISTRY[$relative]) || isset(self::EXEMPTIONS[$relative])) {
                continue;
            }
            $hits = $this->decisionBranchValues((string) file_get_contents($file->getPathname()), $values);
            if ([] !== $hits) {
                $violations[] = \sprintf('%s (branche sur : %s)', $relative, implode(', ', $hits));
            }
        }

        self::assertSame([], $violations, \sprintf(
            "REDÉRIVATION DE RÈGLE DE CONTRAINTE NON DÉCLARÉE — ces fichiers front branchent sur les\n"
            . "valeurs d'un enum de contrainte (%s) pour DÉCIDER, sans figurer au registre ni aux exemptions :\n  - %s\n\n"
            . "Trois issues, jamais le silence :\n"
            . "  1. le front ne doit PAS décider → supprimer la redérivation, afficher ce que le backend dit ;\n"
            . "  2. c'est un miroir assumé → l'ajouter à self::REGISTRY avec sa source de vérité ET un test de parité mécanique ;\n"
            . '  3. c\'est de la présentation (un libellé, un tri) → self::EXEMPTIONS avec sa raison, une par une.',
            implode(' / ', self::POLICED_ENUMS),
            implode("\n  - ", $violations),
        ));
    }

    public function testExemptionsAreJustifiedAndStillFlagged(): void
    {
        $values = $this->policedEnumValues();
        $stale = [];
        foreach (self::EXEMPTIONS as $relative => $reason) {
            if ('' === trim($reason)) {
                $stale[] = \sprintf('%s : exemption SANS raison', $relative);

                continue;
            }
            $path = self::FRONT . '/' . $relative;
            if (!is_file($path)) {
                $stale[] = \sprintf('%s : exempté mais le fichier n\'existe plus', $relative);

                continue;
            }
            if ([] === $this->decisionBranchValues((string) file_get_contents($path), $values)) {
                $stale[] = \sprintf('%s : exempté mais ne branche plus sur un enum de contrainte — retirer l\'exemption', $relative);
            }
        }

        self::assertSame([], $stale, \sprintf(
            "Exemptions du détecteur périmées :\n  - %s\n\n"
            . 'Une exemption doit porter une raison ET rester justifiée (le fichier existe et branche encore). '
            . 'Sinon on garde une porte ouverte qui ne protège plus rien.',
            implode("\n  - ", $stale),
        ));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Les valeurs littérales des enums policés, dérivées de `MIRRORED` (maison unique).
     *
     * @return list<string>
     */
    private function policedEnumValues(): array
    {
        $values = [];
        foreach (self::POLICED_ENUMS as $enumName) {
            self::assertArrayHasKey($enumName, TsUnionsMatchPhpEnumsTest::MIRRORED, \sprintf(
                'L\'enum policé %s ne figure pas dans TsUnionsMatchPhpEnumsTest::MIRRORED — la largeur du détecteur doit rester adossée à cette liste unique.',
                $enumName,
            ));
            /** @var class-string<UnitEnum> $class */
            $class = 'App\\Enum\\' . $enumName;
            foreach (new ReflectionEnum($class)->getCases() as $case) {
                if ($case instanceof ReflectionEnumBackedCase) {
                    $values[] = (string) $case->getBackingValue();
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Les valeurs d'enum sur lesquelles la source BRANCHE POUR DÉCIDER — opérande d'une
     * égalité stricte (`=== "V"` / `"V" ===` / `!== "V"` / `"V" !==`) ou d'un `case "V":`.
     * Un objet-libellé (`{ HARD: "…" }`, clé sans guillemets) n'est PAS un branchement décideur.
     *
     * @param list<string> $values
     *
     * @return list<string> triées, sans doublon
     */
    private function decisionBranchValues(string $source, array $values): array
    {
        $found = [];
        foreach ($values as $value) {
            $v = preg_quote($value, '/');
            $pattern = \sprintf('/(?:===|!==)\s*"%s"|"%s"\s*(?:===|!==)|case\s+"%s"\s*:/', $v, $v, $v);
            if (1 === preg_match($pattern, $source)) {
                $found[] = $value;
            }
        }
        sort($found);

        return $found;
    }

    /** @return list<SplFileInfo> les modules front (.ts/.tsx), hors tests et hors JSON */
    private function frontSourceFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::FEATURES, FilesystemIterator::SKIP_DOTS));
        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!str_ends_with($name, '.ts') && !str_ends_with($name, '.tsx')) {
                continue;
            }
            if (str_contains($name, '.test.') || str_contains($name, '.spec.')) {
                continue;
            }
            $files[] = $file;
        }

        // Le foyer partagé vit hors features/ — il est déclaré au registre, on l'y ajoute.
        $shared = new SplFileInfo(self::FRONT . '/shared/lib/tagTeamIds.ts');
        if ($shared->isFile()) {
            $files[] = $shared;
        }

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(self::FRONT . '/', '', str_replace('\\', '/', $file->getPathname()));
    }
}
