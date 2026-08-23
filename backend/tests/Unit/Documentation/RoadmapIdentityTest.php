<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * UN IDENTIFIANT DE ROADMAP EST UNE CLÉ — il désigne un item, et un seul.
 *
 * ⚑ Ce test existe parce que la convention a cédé en silence : constaté le 2026-08-22,
 * **`P4-119` et `P5-19` désignaient chacun DEUX items différents**. Or `P4-119` est cité dans
 * une vingtaine d'endroits du code (`frontend/src/features/planning/*`, `frontend-spec.md`) :
 * un lecteur qui suit la référence depuis `api.ts` tombe sur une ligne de roadmap qui parle
 * d'autre chose. La référence ne pointe plus rien, sans que rien ne rougisse.
 *
 * Le mécanisme de la dérive est banal et se reproduira : deux passes rapprochées lisent le même
 * « dernier numéro », et l'attribuent toutes les deux. C'est précisément ce qu'une machine
 * détecte mieux qu'une relecture.
 *
 * Le second invariant — le compteur du titre — est l'exception fondateur du 2026-08-04 à la règle
 * anti-décompte (skill `documentation-update`) : « Roadmap (N) » est le seul décompte que le
 * dépôt s'autorise, parce qu'il répond d'un coup d'œil à « combien reste-t-il ». Un décompte faux
 * est pire que pas de décompte : il se lit sans être vérifié.
 */
#[Group('phase1')]
final class RoadmapIdentityTest extends TestCase
{
    private const ROADMAP = 'specs/evolution/roadmap.md';

    /**
     * Restaurations ASSUMÉES — un id retiré qui a le droit de revivre, nommé avec sa raison.
     * Vide par construction : le seul cas rencontré (P2-42, rebase #661) était un accident.
     *
     * @var array<string, string>
     */
    private const RESURRECTIONS_ASSUMEES = [];

    /**
     * Une ligne d'item : `| P4-119 | **…** | … |`. Le tableau porte aussi des lignes sans id
     * (en-têtes, séparateurs), et le compteur du titre ne doit compter que les items.
     *
     * ⚠ Le **suffixe littéral est signifiant** et fait partie de la clé : `P5-4b` est un
     * sous-item dérivé de `P5-4`, pas une coquille. L'oublier était mon premier essai — le
     * compteur du titre m'a alors accusé d'un écart qui venait de MA lecture, pas du fichier.
     */
    private const ITEM_LINE = '/^\| ([A-Z]+\d*-\d+[a-z]?) \|/';

    public function testEveryIdentifierDesignatesExactlyOneItem(): void
    {
        $seen = [];
        $duplicates = [];
        foreach ($this->itemLines() as $lineNumber => $id) {
            if (isset($seen[$id])) {
                $duplicates[] = \sprintf('%s : lignes %d et %d', $id, $seen[$id], $lineNumber);

                continue;
            }
            $seen[$id] = $lineNumber;
        }

        self::assertSame([], $duplicates, <<<'TXT'
            Ces identifiants désignent plusieurs items — ils ne sont donc plus des clés.

            Un identifiant est cité depuis le CODE, les commits et les PR : le réattribuer casse
            silencieusement toutes ces références. Renumérotez l'item qui n'est cité NULLE PART
            (`grep -rn "<id>" --include="*.php" --include="*.ts" --include="*.tsx" --include="*.py" --include="*.md" .`),
            en prenant le successeur du plus grand numéro de sa famille.

            Un trou de numérotation signifie « livré », par convention — il n'y a donc jamais lieu
            de recycler un numéro libre.
            TXT);
    }

    public function testTheHeaderCountMatchesWhatTheTableHolds(): void
    {
        if (1 !== preg_match('/^# Roadmap \((\d+)\)/m', $this->roadmap(), $matches)) {
            self::fail('le titre doit annoncer le nombre d\'items ouverts : « # Roadmap (N) — … »');
        }

        $announced = (int) $matches[1];
        $actual = \count($this->itemLines());

        self::assertSame($announced, $actual, \sprintf(<<<'TXT'
            Le titre annonce %d items ouverts, le tableau en contient %d.

            « Roadmap (N) » est le seul décompte que ce dépôt s'autorise, et il se lit sans être
            vérifié : chaque ligne supprimée le décrémente, chaque ligne ajoutée l'incrémente.
            TXT, $announced, $actual));
    }

    /**
     * UN IDENTIFIANT RETIRÉ NE RESSUSCITE JAMAIS (P4-125).
     *
     * Le test d'unicité ci-dessus ne voit que les lignes PRÉSENTES : un identifiant dont la ligne
     * a été supprimée (item livré — « un trou signifie livré ») lui est invisible, et rien
     * n'empêchait sa résurrection. Ce n'est pas théorique : le REJEU qui suit, écrit le
     * 2026-08-23, a trouvé son premier cas AVANT d'exister — `P2-42`, livrée par #659 (sa ligne
     * retirée), ressuscitée le même jour par la résolution de rebase de #661, et la roadmap a
     * annoncé « à cadrer » pendant trois jours pour une règle déjà livrée.
     *
     * La vérité « ce numéro a déjà servi » ne vit nulle part dans le fichier courant : on la lit
     * dans GIT, qui est déjà le registre (pas de second fichier à tenir — il aurait exigé git de
     * toute façon pour se vérifier lui-même). Un seul `git log --reverse --follow -p` rejoue
     * l'histoire : un id dont une ligne est retirée SANS être ré-ajoutée dans le même commit
     * (une édition retire ET rajoute — ce n'est pas un retrait) entre au cimetière ; à l'arrivée,
     * aucun id présent ne doit y figurer.
     *
     * ⚠ Sur un clone SHALLOW l'histoire est tronquée : le rejeu serait un mensonge rassurant, on
     * SKIPPE en le disant. Le job CI `unit-tests` clone en `fetch-depth: 0` (comme l'exige déjà
     * `DocStampFreshnessTest`) : là où le gate compte, l'histoire est entière.
     */
    public function testNoRetiredIdentifierIsResurrected(): void
    {
        $repoRoot = \dirname(__DIR__, 4);
        if (is_file($repoRoot . '/.git/shallow')) {
            self::markTestSkipped('clone shallow : l\'histoire de la roadmap est tronquée, le rejeu ne prouverait rien — CI clone en fetch-depth: 0.');
        }

        exec(\sprintf('git -C %s log --reverse --follow -p --format=%%H -- %s 2>/dev/null', escapeshellarg($repoRoot), escapeshellarg(self::ROADMAP)), $output, $exitCode);
        self::assertSame(0, $exitCode, 'git doit pouvoir rejouer l\'histoire de la roadmap');
        self::assertNotSame([], $output, 'une histoire vide signifierait un --follow cassé, pas une roadmap sans passé');

        // On COMPTE au lieu d'ensembliser : l'histoire contient une ère où deux lignes portaient
        // le même id (les doublons que le test d'unicité a fait naître ce garde) — en retirer UNE
        // n'enterre pas l'id tant que l'autre vit. Un id n'entre au cimetière que quand son compte
        // tombe à zéro.
        /** @var array<string, string> $assumed — le littéral vide se lit sinon comme `array{}`, et PHPStan déclarerait la garde impossible */
        $assumed = self::RESURRECTIONS_ASSUMEES;
        $alive = [];
        $retired = [];
        $violations = [];
        $added = [];
        $removed = [];
        $flush = function () use ($assumed, &$alive, &$added, &$removed, &$retired, &$violations): void {
            // Une ÉDITION retire et rajoute le même id dans le même commit : ni retrait ni
            // résurrection. Seuls les mouvements NETS comptent.
            foreach (array_diff_key($added, $removed) as $id => $line) {
                if (isset($retired[$id]) && !\array_key_exists($id, $assumed)) {
                    $violations[$id] = $line;
                }
                unset($retired[$id]);
                $alive[$id] = ($alive[$id] ?? 0) + 1;
            }
            foreach (array_keys(array_diff_key($removed, $added)) as $id) {
                $alive[$id] = max(0, ($alive[$id] ?? 0) - 1);
                if (0 === $alive[$id]) {
                    $retired[$id] = true;
                }
            }
            $added = [];
            $removed = [];
        };

        foreach ($output as $line) {
            if (1 === preg_match('/^[0-9a-f]{40}$/', $line)) {
                $flush();

                continue;
            }
            if (1 === preg_match('/^\+\| ([A-Z]+\d*-\d+[a-z]?) \|/', $line, $m)) {
                $added[$m[1]] = $line;
            } elseif (1 === preg_match('/^-\| ([A-Z]+\d*-\d+[a-z]?) \|/', $line, $m)) {
                $removed[$m[1]] = true;
            }
        }
        $flush();

        // Deux façons de ressusciter, TOUTES DEUX visées : la résurrection déjà COMMITÉE (elle vit
        // dans $violations — l'id a été ré-ajouté par un commit après son retrait) et celle de la
        // COPIE DE TRAVAIL (l'id est encore au cimetière à la fin du rejeu, mais le FICHIER le
        // porte — le fichier est lu tel quel, pas depuis git). La première falsification de ce
        // test n'avait visé que la première : le fantôme non commité passait vert.
        $present = array_flip($this->itemLines());
        $graveyard = $violations + array_map(static fn (): string => 'ré-ajouté dans la copie de travail', $retired);
        $resurrectedNow = array_intersect_key($graveyard, $present);

        self::assertSame([], array_keys($resurrectedNow), <<<'TXT'
            Ces identifiants ont déjà été RETIRÉS de la roadmap — un retrait signifie « livré », et
            un numéro livré ne ressert jamais (la ligne qui revient annonce comme ouvert un travail
            déjà fait, et fausse le compteur en silence).

            Si c'est une résolution de rebase qui a ramené la ligne : supprimez-la et décrémentez le
            compteur. Si c'est un item NOUVEAU : donnez-lui le prochain numéro libre de sa famille —
            le successeur du plus grand JAMAIS attribué, à lire dans l'histoire :
            `git log -p --follow specs/evolution/roadmap.md | grep -oE '^\+\| P4-[0-9]+' | sort -V | tail -1`.
            Une restauration réellement légitime se déclare NOMMÉMENT dans RESURRECTIONS_ASSUMEES,
            avec sa raison — jamais par un contournement.
            TXT);
    }

    /** @return array<int, string> numéro de ligne (1-indexé) => identifiant */
    private function itemLines(): array
    {
        $items = [];
        foreach (explode("\n", $this->roadmap()) as $index => $line) {
            if (1 === preg_match(self::ITEM_LINE, $line, $matches)) {
                $items[$index + 1] = $matches[1];
            }
        }

        return $items;
    }

    private function roadmap(): string
    {
        $path = \dirname(__DIR__, 3) . '/../' . self::ROADMAP;
        $content = file_get_contents($path);
        self::assertIsString($content, self::ROADMAP . ' doit être lisible');

        return $content;
    }
}
