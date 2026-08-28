<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProductIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P5-15 — le nom produit a UNE maison : `ProductIdentity`, alimenté par
 * `APP_PRODUCT_NAME`/`APP_PUBLISHER_NAME`.
 *
 * Le test qui compte est le second : il interdit que l'ancien nom commercial
 * (« ClubScheduler ») revienne EN DUR dans `src/` et `config/`. C'est
 * précisément ce qui a rendu le renommage coûteux — des libellés à retrouver un
 * par un, dont plusieurs au milieu d'un contrôleur de 900 lignes. Un
 * « ClubScheduler » littéral rouvre la chasse : il échoue ici, en nommant le
 * fichier et la ligne.
 *
 * P4-142 — les DERNIERS littéraux d'infrastructure `clubscheduler` sous
 * `src/`/`config/` ont disparu : le rôle SQL owner `clubscheduler` → `amateo_owner`,
 * la clé du verrou `clubscheduler.admin_job` → `amateo.admin_job`, le préfixe des
 * dumps `clubscheduler-*.dump` → `amateo-*`, la base de restauration
 * `clubscheduler_restore_*` → `amateo_restore_*` (et les bases `clubscheduler_*` →
 * `amateo_*` en PR-1). Conséquence : les DEUX allowlists ci-dessous se VIDENT — le
 * balayage n'a plus rien à excuser et devient d'autant plus strict.
 *
 * Les quelques `clubscheduler` techniques encore vivants habitent tous HORS de la
 * racine balayée ici, jamais à tolérer sous `src/`/`config/` : `clubscheduler-slot:`
 * (graine UUIDv5 des créneaux) et le paquet `clubscheduler-engine` dans `engine/`,
 * `clubscheduler:wish-draft:` dans `frontend/`, l'e-mail de compte
 * `e2e-admin@clubscheduler.test`. Cités pour mémoire.
 */
#[Group('phase1')]
final class ProductIdentityTest extends TestCase
{
    /**
     * Sous-chaînes techniques tolérées : VIDE depuis P4-142 (cf. docblock de
     * classe) — plus aucun littéral d'infrastructure `clubscheduler` ne subsiste
     * sous `src/`/`config/`. Une ré-exclusion sans raison écrite serait une
     * régression déguisée.
     *
     * @var list<string>
     */
    private const array TECHNICAL_EXCEPTIONS = [];

    /**
     * Fichiers de CONFIGURATION exclus par FICHIER : VIDE depuis P4-142 — les
     * commentaires de `doctrine.yaml`/`doctrine_migrations.yaml` nomment désormais
     * le rôle admin `amateo_owner`, plus `clubscheduler`, donc rien à exclure ; le
     * balayage les couvre maintenant comme n'importe quel autre YAML de `config/`.
     *
     * @var list<string>
     */
    private const array INFRA_CONFIG_FILES = [];

    public function testIdentityCarriesTheConfiguredNames(): void
    {
        $identity = new ProductIdentity('Marque', 'Éditeur');

        self::assertSame('Marque', $identity->name());
        self::assertSame('Éditeur', $identity->publisher());
    }

    public function testOldProductNameIsNotHardCodedInSource(): void
    {
        $offenders = [];
        $backend = \dirname(__DIR__, 3);

        // `src/` ET `config/` : le titre exposé par `/api/docs` vivait dans
        // `config/packages/api_platform.yaml` et a survécu au renommage parce que
        // le balayage s'arrêtait à `src/`. Un angle mort de garde EST une régression
        // en attente — le texte lu par un humain ne vit pas que dans du PHP.
        foreach ([$backend . '/src', $backend . '/config'] as $root) {
            /** @var iterable<string, SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $path => $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['php', 'yaml', 'yml'], true)) {
                    continue;
                }

                $relative = str_replace($backend . '/', '', $path);
                if (\in_array($relative, self::INFRA_CONFIG_FILES, true)) {
                    continue;
                }

                $contents = file_get_contents($path);
                self::assertIsString($contents);

                foreach (explode("\n", $contents) as $number => $line) {
                    if (false === stripos($line, 'clubscheduler')) {
                        continue;
                    }
                    foreach (self::TECHNICAL_EXCEPTIONS as $allowed) {
                        if (str_contains($line, $allowed)) {
                            continue 2;
                        }
                    }
                    $offenders[] = \sprintf('%s:%d', str_replace($backend . '/', '', $path), $number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Ancien nom produit codé en dur : passer par ProductIdentity (APP_PRODUCT_NAME/APP_PUBLISHER_NAME) pour le texte lu par un humain.');
    }
}
