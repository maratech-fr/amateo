<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PurgeExportsCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-52 — les quatre verdicts de la purge des rendus.
 *
 * Les PDF s'écrivaient dans `public/exports` et **ne repartaient jamais**. Le rendu étant
 * servi publiquement par design (SEC-14 : « proxy PUBLIC par design »), la rétention borne
 * deux choses d'un coup : le disque, et la durée pendant laquelle un vieux rendu reste
 * atteignable par son URL.
 *
 * ⚑ **Pourquoi la règle est testée ici et non à travers la commande** : la purge est
 * transverse, donc elle lit par la connexion `admin` — la seule qui contourne RLS. Or la
 * transaction d'un test d'intégration ne traverse pas cette connexion : le test devenait un
 * combat contre le harnais au lieu d'une preuve de la règle. Extraite, elle se falsifie cas
 * par cas. Même leçon que `retryTarget.ts`, le même jour.
 *
 * ⚠ Le cas qui compte est l'ÉPINGLÉ : sans lui, la purge effacerait le PDF de la saison en
 * vigueur — le seul qu'un gestionnaire ait une raison de rouvrir des mois plus tard.
 */
#[Group('phase1')]
final class PurgeExportsVerdictTest extends TestCase
{
    private const string LIVE = '11111111-1111-4111-8111-111111111111';

    private const string GONE = '22222222-2222-4222-8222-222222222222';

    private const int CUTOFF = 1_000_000;

    private const string MATCHES_RENDER = 'matchs-' . self::LIVE . '-' . self::GONE . '.pdf';

    public function testAnOrphanRenderIsRemovedEvenWhenRecent(): void
    {
        self::assertSame('orphan', $this->verdict(self::GONE, modifiedAt: self::CUTOFF + 5_000));
    }

    public function testALivingRecentRenderIsKept(): void
    {
        self::assertSame('keep', $this->verdict(self::LIVE, modifiedAt: self::CUTOFF + 5_000));
    }

    public function testALivingButExpiredRenderIsRemoved(): void
    {
        self::assertSame('expired', $this->verdict(self::LIVE, modifiedAt: self::CUTOFF - 5_000));
    }

    /**
     * L'épinglage n'est PAS une colonne `is_pinned` : c'est `Season.exportPdfUrl`, qui existe
     * déjà. Une colonne dédiée aurait supposé un geste d'épinglage qu'aucun écran n'offre —
     * on n'ajoute pas une capacité que personne ne peut atteindre.
     */
    public function testAPinnedRenderSurvivesBothAgeAndOrphanhood(): void
    {
        $name = $this->renderName(self::GONE);

        self::assertSame('keep', PurgeExportsCommand::verdictFor(
            $name,
            [self::LIVE => true],
            ['/exports/' . $name => true],
            self::CUTOFF - 999_999,
            self::CUTOFF,
        ), 'un export épinglé ne part jamais : ni vieux, ni orphelin');
    }

    /**
     * P4-357 — l'épinglage se fait désormais par le BASENAME du rendu, pas par l'URL entière. Une
     * saison dont `exportPdfUrl` porte la NOUVELLE forme authentifiée
     * (`/api/schedules/{id}/export-pdf/schedule-{id}-{scope}.pdf`) épingle le même fichier que
     * l'ancienne forme statique (`/exports/schedule-{id}-{scope}.pdf`, gardée par le test ci-dessus).
     * Sans ça, le basculement de l'URL de production ferait repartir à la purge le SEUL rendu qu'un
     * gestionnaire ait une raison de rouvrir des mois plus tard — alors qu'il est épinglé.
     *
     * Contrat proposé à NEO (option A) : `verdictFor` compare le `basename` de chaque URL épinglée
     * à `$name` ; le paramètre `$pinnedUrls` reste une map d'URL (n'importe quelle forme).
     */
    public function testAPinnedRenderSurvivesWhenTheSeasonHoldsTheNewAuthenticatedUrl(): void
    {
        $name = $this->renderName(self::GONE);

        self::assertSame('keep', PurgeExportsCommand::verdictFor(
            $name,
            [self::LIVE => true],
            ['/api/schedules/' . self::GONE . '/export-pdf/' . $name => true],
            self::CUTOFF - 999_999,
            self::CUTOFF,
        ), 'un rendu épinglé par la nouvelle URL authentifiée ne part jamais : ni vieux, ni orphelin');
    }

    /**
     * Semgrep signale tout `unlink()` à chemin non littéral, et il a raison de le faire.
     * L'alerte est ici un faux positif — mais on ne le déclare pas sur parole : le motif
     * lui-même refuse les séparateurs, et c'est ce que ce test épingle. Un `.+` aurait
     * laissé passer `../../etc/passwd.pdf`, et le `nosemgrep` posé à côté serait devenu un
     * mensonge signé.
     */
    public function testANameCarryingATraversalIsNotEvenARender(): void
    {
        self::assertNull(PurgeExportsCommand::verdictFor(
            'schedule-' . self::GONE . '-../../etc/passwd.pdf',
            [],
            [],
            0,
            self::CUTOFF,
        ), 'un nom porteur de traversée ne doit même pas être reconnu comme un rendu');
    }

    public function testANonRenderFileIsLeftAlone(): void
    {
        self::assertNull(PurgeExportsCommand::verdictFor('.gitignore', [], [], 0, self::CUTOFF));
        self::assertNull(PurgeExportsCommand::verdictFor('rapport-2026.pdf', [], [], 0, self::CUTOFF));
    }

    /**
     * Export des matchs : le fichier se supprime dès la réponse, un survivant = un crash entre
     * l'écriture et la réponse. Il ne reste pas 90 jours : passé 1 h il part, quel que soit le planning.
     * Le 6ᵉ argument est la borne « 1 h » propre à ces fichiers.
     */
    public function testAnOrphanMatchesRenderExpiresAfterOneHour(): void
    {
        self::assertSame('expired', PurgeExportsCommand::verdictFor(self::MATCHES_RENDER, [], [], self::CUTOFF - 1, 0, self::CUTOFF));
    }

    public function testARecentMatchesRenderIsKept(): void
    {
        self::assertSame('keep', PurgeExportsCommand::verdictFor(self::MATCHES_RENDER, [], [], self::CUTOFF + 1, 0, self::CUTOFF));
    }

    /** Même défense que pour les rendus de planning : le motif refuse tout séparateur. */
    public function testAMatchesNameCarryingATraversalIsNotARender(): void
    {
        self::assertNull(PurgeExportsCommand::verdictFor('matchs-' . self::GONE . '-../../etc/passwd.pdf', [], [], 0, 0, self::CUTOFF));
    }

    /** La borne d'1 h ne touche pas les rendus de planning : un rendu vivant vieux d'1 h reste. */
    public function testTheOneHourBoundLeavesScheduleRendersAlone(): void
    {
        self::assertSame('keep', PurgeExportsCommand::verdictFor($this->renderName(self::LIVE), [self::LIVE => true], [], self::CUTOFF + 5_000, self::CUTOFF, self::CUTOFF + 9_000));
    }

    private function verdict(string $scheduleId, int $modifiedAt): ?string
    {
        return PurgeExportsCommand::verdictFor(
            $this->renderName($scheduleId),
            [self::LIVE => true],
            [],
            $modifiedAt,
            self::CUTOFF,
        );
    }

    private function renderName(string $scheduleId): string
    {
        return \sprintf('schedule-%s-all.pdf', $scheduleId);
    }
}
