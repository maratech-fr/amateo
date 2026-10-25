<?php

declare(strict_types=1);

namespace App\Tests\Unit\ParcImport;

use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\ParcImport\TeamLabelMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lot correctif import IA (« G2 ») PR1 — service PUR qui APPARIE un libellé d'équipe lu dans une
 * case parc-v2 aux équipes DÉJÀ saisies du club. Le fuzzy d'origine (égalité normalisée ou
 * containment, {@see VenueLabelNormalizer::fuzzyMatches}) ne rattrapait ni
 * « Arhand » (OCR de « Armand »), ni « U15M 1 » (écrit autrement que « U15-1 »), ni un nom coupé par
 * l'OCR. Ce matcher corrige le NOMMAGE sans jamais FILTRER ce qui est trouvé (cadrage G2 D5-D7,
 * consigne fondateur « correcteur jamais filtre »).
 *
 * Promesses gardées (option A — TANK écrit ROUGE, NEO implémente {@see TeamLabelMatcher}) :
 *  - D5 une distance de Levenshtein ≤ 2 à UNE seule équipe → statut « suggere » (à confirmer),
 *    JAMAIS « apparie » d'office — on ne réécrit pas le club sans l'accord du gestionnaire ;
 *  - une réécriture CANONIQUE (« U15M 1 » ≡ « U15-1 », « U18M 3 » ≡ « U18-3 » après normalisation
 *    des espaces / tirets / marqueur de genre) vise la bonne équipe — appariement confiant ;
 *  - D5 DEUX équipes dans le seuil → statut « ambigu », décoché, cibleId null — jamais deviné ;
 *  - un libellé inconnu → « aCreer » (correcteur jamais filtre : il reste proposé en création) ;
 *  - D6 recollage : deux tokens adjacents d'une case (nom coupé par l'OCR, « U18F » + « Fays »)
 *    recollés en UN libellé QUAND leur concaténation s'apparie à une équipe existante ; sinon
 *    JAMAIS (deux vraies équipes distinctes ne fusionnent pas) ;
 *  - D7 découpe « A - B » / « A + B » SEULEMENT si CHAQUE moitié s'apparie à une équipe existante ;
 *    une seule moitié appariée → pas de découpe (le libellé reste entier).
 *
 * Signatures proposées (NEO) — service PUR, candidats PASSÉS (aucune DB) :
 *   match(string $label, list<array{id:string,nom:string}> $existing):
 *       array{statut:'apparie'|'suggere'|'ambigu'|'aCreer', cibleId:?string, cibleNom:?string}
 *   split(string $label, list<array{id:string,nom:string}> $existing): ?array{0:string,1:string}
 *   recombine(list<string> $tokens, list<array{id:string,nom:string}> $existing):
 *       ?array{statut:string, cibleId:?string, cibleNom:?string, recolle:string}
 */
#[Group('phase1')]
final class TeamLabelMatcherTest extends TestCase
{
    private TeamLabelMatcher $matcher;

    public function testATypoWithinLevenshteinTwoIsSuggestedNeverMatchedOutright(): void
    {
        // « Arhand » (OCR) à distance 1 de « Armand » : SUGGÉRÉ, jamais apparié d'office.
        $existing = $this->existing('Armand', 'Jean Vilar');
        $verdict = $this->matcher->match('Arhand', $existing);

        self::assertSame('suggere', $verdict['statut'], 'un fuzzy ≤ 2 est suggéré, jamais apparié sans confirmation');
        self::assertSame($existing[0]['id'], $verdict['cibleId'], 'il suggère la bonne équipe');
        self::assertSame('Armand', $verdict['cibleNom']);
    }

    public function testACanonicalRewriteTargetsTheRightTeam(): void
    {
        // « U15M 1 » et « U18M 3 » sont les MÊMES équipes que « U15-1 » / « U18-3 » écrites
        // autrement (espace, marqueur de genre, tiret) — après normalisation canonique, la cible
        // est sans ambiguïté. L'important : viser la bonne équipe, jamais « à créer » un doublon.
        $existing = $this->existing('U15-1', 'U18-3', 'U15-2');

        $u15 = $this->matcher->match('U15M 1', $existing);
        self::assertNotSame('aCreer', $u15['statut'], '« U15M 1 » ne doit pas créer un doublon de « U15-1 »');
        self::assertSame('U15-1', $u15['cibleNom'], '« U15M 1 » vise « U15-1 »');

        $u18 = $this->matcher->match('U18M 3', $existing);
        self::assertNotSame('aCreer', $u18['statut']);
        self::assertSame('U18-3', $u18['cibleNom'], '« U18M 3 » vise « U18-3 »');
    }

    public function testTwoCandidatesWithinThresholdAreAmbiguousAndDecoched(): void
    {
        // « Armond » est à distance 1 de « Armand » ET de « Armund » : deux candidats dans le seuil
        // → ambigu, décoché, aucune cible devinée.
        $existing = $this->existing('Armand', 'Armund');
        $verdict = $this->matcher->match('Armond', $existing);

        self::assertSame('ambigu', $verdict['statut'], 'deux candidats dans le seuil = ambigu, jamais deviné');
        self::assertNull($verdict['cibleId'], 'une ambiguïté ne pointe aucune cible');
    }

    public function testAnUnknownLabelIsToCreateNeverFilteredOut(): void
    {
        // Correcteur jamais filtre : un libellé hors de tout seuil reste PROPOSÉ en création,
        // jamais écarté parce qu'il ne ressemble à rien de connu.
        $verdict = $this->matcher->match('Palais des Mille Sports', $this->existing('Armand', 'U15-1'));

        self::assertSame('aCreer', $verdict['statut']);
        self::assertNull($verdict['cibleId']);
    }

    public function testAdjacentTokensAreRecombinedWhenTheirJoinMatchesAnExistingTeam(): void
    {
        // D6 — l'OCR a coupé « U18F Fays » en deux tokens dans la case. Pris SÉPARÉMENT, « U18F »
        // seul ne s'apparie à rien ; RECOLLÉS, « U18F Fays » s'apparie à l'équipe existante.
        $existing = $this->existing('U18F Fays', 'SM1', 'SF1');

        self::assertSame('aCreer', $this->matcher->match('U18F', $existing)['statut'], 'pris seul, « U18F » ne s\'apparie pas');

        $recombined = $this->matcher->recombine(['U18F', 'Fays'], $existing);
        self::assertNotNull($recombined, 'les deux tokens recollés s\'apparient à « U18F Fays »');
        self::assertSame('U18F Fays', $recombined['cibleNom']);
        self::assertSame('U18F Fays', $recombined['recolle'], 'le libellé recollé est conservé pour l\'aperçu');
    }

    public function testTwoDistinctTeamsAreNeverRecombined(): void
    {
        // Témoin de la promesse ci-dessus (dans l'autre sens) : deux VRAIES équipes distinctes
        // posées dans la même case (mutualisation / terrain divisé) ne fusionnent JAMAIS en une.
        $existing = $this->existing('SM1', 'SF1');

        self::assertNull($this->matcher->recombine(['SM1', 'SF1'], $existing), 'deux équipes connues distinctes ne se recollent pas');
    }

    public function testAGroupedLabelIsSplitOnlyWhenBothHalvesMatch(): void
    {
        // D7 — « U21 - SENIORS » : découpé en deux SEULEMENT si CHAQUE moitié s'apparie à une
        // équipe du club. Avec les deux équipes présentes, la découpe rend deux libellés.
        $both = $this->existing('U21M1', 'Seniors M');
        $split = $this->matcher->split('U21 - SENIORS', $both);

        self::assertNotNull($split, 'les deux moitiés s\'apparient → découpe');
        self::assertCount(2, $split);
    }

    public function testAGroupedLabelIsNotSplitWhenOnlyOneHalfMatches(): void
    {
        // Témoin : si UNE SEULE moitié s'apparie, on ne découpe pas — un libellé qui « ressemble »
        // à un groupe mais dont une moitié est inconnue reste ENTIER (pas de demi-équipe inventée).
        $onlyOne = $this->existing('U21M1'); // « SENIORS » absent du club

        self::assertNull($this->matcher->split('U21 - SENIORS', $onlyOne), 'une seule moitié appariée → pas de découpe');
    }

    // ───────────────────────── PR1 — le verdict EXPOSE ses candidats (D5, aperçu refondu) ─────────────────────────
    // Avant de pouvoir TRANCHER une ambiguïté, le gestionnaire doit VOIR les équipes candidates.
    // Le matcher, correcteur jamais filtre, les PORTE donc dans son verdict (`candidats: list<{id,nom}>`),
    // d'où l'analyzer alimente le groupe « Candidats possibles » du sélecteur de la ligne (option A :
    // TANK écrit ROUGE, NEO ajoute la clé `candidats` au verdict). Jumeau côté API :
    // {@see \App\Tests\Integration\Api\ParcImportAnalyzeTest::testAnAmbiguousTeamLabelIsServedAsAmbiguWithItsCandidates}.

    public function testAnAmbiguousMatchExposesEveryCandidateToChooseFrom(): void
    {
        // « Armond » est à distance 1 de « Armand » ET de « Armund » : ambigu, aucune cible devinée,
        // MAIS les DEUX candidats sont offerts au choix (id + nom, pour l'affichage).
        $existing = $this->existing('Armand', 'Armund');
        $verdict = $this->matcher->match('Armond', $existing);

        self::assertSame('ambigu', $verdict['statut']);
        self::assertArrayHasKey('candidats', $verdict, 'le verdict porte la liste des candidats à trancher');
        $ids = array_map(static fn (array $c): string => $c['id'], $verdict['candidats']);
        self::assertEqualsCanonicalizing([$existing[0]['id'], $existing[1]['id']], $ids, 'les deux équipes dans le seuil sont offertes');
        $noms = array_map(static fn (array $c): string => $c['nom'], $verdict['candidats']);
        self::assertEqualsCanonicalizing(['Armand', 'Armund'], $noms, 'chaque candidat porte son nom pour l\'affichage');
    }

    public function testASuggestionExposesItsSingleCandidate(): void
    {
        // Un unique candidat flou (« suggere ») : la cible est posée ET le seul candidat est listé —
        // le front montre « Apparier à X (suggéré) » cohérent avec le groupe « Candidats possibles ».
        $existing = $this->existing('Armand', 'Jean Vilar');
        $verdict = $this->matcher->match('Arhand', $existing);

        self::assertSame('suggere', $verdict['statut']);
        self::assertArrayHasKey('candidats', $verdict);
        self::assertSame([['id' => $existing[0]['id'], 'nom' => 'Armand']], $verdict['candidats']);
    }

    public function testAnUnknownLabelHasNoCandidate(): void
    {
        // Correcteur jamais filtre : « à créer » n'invente aucun candidat (le sélecteur n'offre que
        // « Créer » / « Ignorer », pas de rapprochement fantôme).
        $verdict = $this->matcher->match('Palais des Mille Sports', $this->existing('Armand', 'U15-1'));

        self::assertSame('aCreer', $verdict['statut']);
        self::assertArrayHasKey('candidats', $verdict);
        self::assertSame([], $verdict['candidats'], 'rien ne ressemble → aucun candidat');
    }

    protected function setUp(): void
    {
        $this->matcher = new TeamLabelMatcher;
    }

    /** @return list<array{id: string, nom: string}> */
    private function existing(string ...$names): array
    {
        $out = [];
        foreach ($names as $i => $nom) {
            $out[] = ['id' => \sprintf('11111111-1111-4111-8111-%012d', $i + 1), 'nom' => $nom];
        }

        return $out;
    }
}
