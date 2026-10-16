<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D-10 — le topic Mercure ne se reconstruit pas à la main.
 *
 * Le format `/clubs/{id}/schedules/{id}` (ex-`club:{id}:schedule:{id}` avant le cutover
 * Mercure 1.0, lot 4 PR C) était bâti par SIX `sprintf` dispersés face à UN sélecteur
 * d'abonnement. Extraire `MercureTopic` supprime les six copies — mais n'empêche
 * pas d'en réécrire une septième, et c'est ce que ce test ferme.
 *
 * ⚠ **La divergence est parfaitement silencieuse** : un publieur qui dérive émet sur un topic
 * auquel personne n'est abonné. Le hub ne matche rien, l'événement n'arrive nulle part — et
 * le front **dégrade en polling par conception**, donc l'écran continue de se mettre à jour,
 * en retard. Aucune erreur, aucun log, aucun test rouge. On perd le temps réel sans l'apprendre.
 */
#[Group('phase1')]
final class MercureTopicIsNotRebuiltTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../src';

    public function testNothingBuildsTheTopicOutsideItsHome(): void
    {
        $offenders = [];
        foreach (glob(self::SRC . '/{,*/,*/*/}*.php', \GLOB_BRACE) ?: [] as $file) {
            if (str_ends_with($file, 'Mercure/MercureTopic.php')) {
                continue; // le foyer lui-même
            }

            $source = file_get_contents($file);
            if (!\is_string($source)) {
                continue;
            }

            // Le format littéral, sous ses deux formes : `sprintf` et concaténation.
            if (1 === preg_match('#\'/clubs/%s/schedules|\'/clubs/\' \\. #', $source)) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces fichiers reconstruisent le topic Mercure au lieu d'appeler MercureTopic : %s.\n"
            . "Une divergence y est INVISIBLE : le hub ne matche rien, l'événement n'arrive à\n"
            . 'personne, et le front dégrade en polling — l\'écran marche, en retard, sans erreur.',
            implode(', ', $offenders),
        ));
    }
}
