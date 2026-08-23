<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * **Aucun `new ValidationException(` nu hors de `AbstractStateProcessor`.** Le geste attendu est
 * `$this->refuse('…')` — la maison unique du refus de saisie 422.
 *
 * ⚠ Le pourquoi, payé une fois : `new ValidationException('chaîne')` (le constructeur chaîne
 * d'API Platform) construit une `ConstraintViolationList` VIDE ; le normalizer dérive `detail`
 * et `violations[]` EXCLUSIVEMENT de cette liste, donc un message nu rend `violations: []`,
 * `detail: ""` et le titre générique « An error occurred » — la cause n'atteint JAMAIS l'écran
 * (le front lit `detail` puis `violations[].message`). 35 refus de saisie ont vécu muets ainsi.
 * Le helper `AbstractStateProcessor::refuse()` construit une VRAIE liste ; l'idiome nu est
 * banni pour qu'une liste ad hoc ne recrée pas ailleurs une deuxième maison du même piège.
 *
 * On interroge `git grep` plutôt que de parcourir le disque : il ne voit QUE les fichiers
 * suivis, il est rapide, et la portée `src/` exclut ce test (qui cite l'idiome dans sa prose).
 *
 * Non-bloquant à dessein (tourne dans `unit-tests`, pas un step de `blocking-tests`) : le tier
 * bloquant est réservé aux invariants que le pipeline aval CONSOMME (contrat moteur, isolation
 * tenant, verrous). Ceci garde une DISCIPLINE de copie côté sortie 422 — un faux vert ici ne
 * corrompt aucun étage suivant, il rend juste un toast muet, rattrapé au gate `unit-tests`.
 */
#[Group('phase1')]
final class ValidationExceptionCarriesViolationsTest extends TestCase
{
    private const string BACKEND = __DIR__ . '/../..';

    public function testNoBareValidationExceptionOutsideAbstractProcessor(): void
    {
        // `new ValidationException(` sous `src/`, tous les fichiers suivis SAUF l'abstract où vit
        // le helper. `-F` : chaîne littérale, la parenthèse n'est pas un motif regex.
        $process = Process::fromShellCommandline(
            'git grep -n -F "new ValidationException(" -- src '
            . '":(exclude)src/State/Processor/AbstractStateProcessor.php" || true',
            self::BACKEND,
        );
        $process->run();

        $hits = trim($process->getOutput());

        self::assertSame('', $hits, \sprintf(
            "Un `new ValidationException(` nu subsiste hors de `AbstractStateProcessor` :\n%s\n\n"
            . "Le geste attendu : `\$this->refuse('votre message')`.\n"
            . 'Le pourquoi : le constructeur chaîne rend une liste de violations VIDE → 422 MUET '
            . "(`violations: []`, `detail: \"\"`, « An error occurred ») ; le message n'atteint jamais l'écran.\n"
            . 'La doctrine : `refuse()` est la maison unique — une `ConstraintViolationList` bricolée '
            . 'ailleurs recréerait le même piège muet ligne par ligne.',
            $hits,
        ));
    }
}
