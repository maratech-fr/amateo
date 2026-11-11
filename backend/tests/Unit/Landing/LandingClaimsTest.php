<?php

declare(strict_types=1);

namespace App\Tests\Unit\Landing;

use Dom\Element;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-522 — promesse : ce que la vitrine AFFIRME à un club est vrai et reste celui que le fondateur
 * a validé — garanties, offres, délais d'effacement, ce qu'Amateo ne fait pas, qui l'édite. Une
 * affirmation trop forte (« 60 jours au plus », « sauvegardées chaque jour »), une société qui
 * n'existe pas, un écart de délai entre la vitrine et l'application rougissent ici.
 *
 * Lecture statique des fichiers suivis (`LandingFiles`), HTML parsé sans exécuter ses scripts. Les
 * gardes de référencement et de liens vivent dans `LandingDiscoverabilityTest`, le journal dans
 * `LandingChangelogTest`.
 */
#[Group('phase1')]
final class LandingClaimsTest extends TestCase
{
    use LandingFiles;

    /**
     * Phrase d'effacement de l'espace du club (F5, rédaction SMITH validée le 2026-11-11), commune
     * à `vos-donnees.html` et à `PrivacyPage.tsx`. Le titre « Supprimer l'espace du club. » ne
     * figure que sur la vitrine (côté app, la phrase suit l'intitulé « Effacement » d'une puce).
     */
    private const string ERASURE_TITLE = 'Supprimer l\'espace du club.';
    private const string ERASURE_SENTENCE = 'Quand le dernier gestionnaire supprime son compte, l\'espace du club est effacé 30 jours plus tard ; pendant ces 30 jours, un gestionnaire peut reprendre la main et tout annuler. Ses données sortent ensuite des sauvegardes à mesure qu\'elles sont remplacées par de plus récentes ; la copie conservée aux Pays-Bas est effacée au bout de 30 jours. Nous ne restaurons jamais une sauvegarde pour récupérer des données supprimées.';

    private const string PRIVACY_PAGE = '/frontend/src/features/legal/PrivacyPage.tsx';

    /** Promesse (D3) : le bandeau de l'accueil porte les quatre garanties validées, chacune menant à sa preuve. */
    public function testGuaranteesBannerHoldsFourLinkedClaims(): void
    {
        $document = $this->parse('index.html');
        $banner = $this->element($document, 'ul.guarantees');
        self::assertSame('Nos garanties', $banner->getAttribute('aria-label'));

        $expected = [
            ['Hébergé en France (Paris), copie de secours aux Pays-Bas.', 'vos-donnees.html', null],
            ['Vos données vous appartiennent : export complet et suppression à la demande.', 'vos-donnees.html', null],
            ['Découverte gratuite, sans carte bancaire.', null, 'register'],
            ['Ne remplace pas FBI et n\'y écrit rien.', 'fonctionnalites.html#ne-fait-pas', null],
        ];
        $items = [];
        foreach ($this->childElements($banner) as $child) {
            if ('LI' === $child->tagName) {
                $items[] = $child;
            }
        }
        self::assertCount(4, $items, 'Quatre garanties, ni plus ni moins.');

        foreach ($expected as $index => [$claim, $href, $cta]) {
            $item = $items[$index];
            $rank = 'Garantie n° ' . ($index + 1);
            self::assertSame($claim, $this->normalize((string) $item->textContent), $rank);
            $link = $this->element($item, 'a');
            if (null !== $href) {
                self::assertContains($link->getAttribute('href'), [$href, '/' . $href], $rank . ' : lien');
            }
            if (null !== $cta) {
                self::assertSame($cta, $link->getAttribute('data-cta'), $rank . ' : mène à l\'inscription');
            }
        }
    }

    /**
     * Promesse (D3, F10) : juste après la section du bureau, l'accueil dit d'où vient Amateo — sans
     * AUCUN chiffre (les chiffres vivent sur « Qui fait ») — et mène à la page « Qui fait ».
     */
    public function testOriginBlockFollowsTheBoardSectionAndLinksToQuiFait(): void
    {
        $brand = $this->config('brand');
        $document = $this->parse('index.html');
        $origin = $this->element($document, 'section#origine');

        self::assertSame('transparence', $origin->previousElementSibling?->getAttribute('id'), '#origine suit immédiatement la section du bureau (#transparence).');
        self::assertSame('Créé par un joueur de basket pour son club amateur.', $this->text($origin, 'h2'));

        $text = $this->normalize((string) $origin->textContent);
        self::assertStringContainsString('Là-bas, refaire le planning à chaque changement de coach ou de créneau prenait des heures. ' . $brand . ' est né pour que ça n\'arrive plus.', $text);
        self::assertSame(0, preg_match('/\d/', $text), 'Le bloc d\'origine ne porte aucun chiffre.');

        $toQuiFait = null;
        foreach ($origin->querySelectorAll('a') as $link) {
            if (\in_array($link->getAttribute('href'), ['qui-fait.html', '/qui-fait.html'], true)) {
                $toQuiFait = $link;
            }
        }
        self::assertNotNull($toQuiFait, 'Le bloc d\'origine mène à qui-fait.html.');
        self::assertSame('Qui fait ' . $brand . ' →', $this->normalize((string) $toQuiFait->textContent));
    }

    /**
     * Promesse (D3, F2) : les cartes d'offres disent la grille du produit — Essentiel jusqu'à 20
     * équipes, Club de 21 à 34, Grand club à partir de 35 (`PlanEntitlements`) — sans prix affiché
     * et sans palier disparu (« 30 équipes », « 40-50 », « Tout, sans limite »). Le code n'est pas
     * lu par ce test : la largeur est dite, la grille est recopiée.
     */
    public function testOfferCardsMatchTheLadder(): void
    {
        $offers = $this->element($this->parse('index.html'), 'section#offres');
        $cards = iterator_to_array($offers->querySelectorAll('.plans article.plan'));
        self::assertCount(4, $cards, 'Quatre cartes : Découverte, Essentiel, Club, Grand club.');

        $names = array_map(fn (Element $card): string => $this->text($card, '.name'), $cards);
        self::assertSame(['Découverte', 'Essentiel', 'Club', 'Grand club'], $names);

        self::assertSame('Une équipe = chaque groupe que vous entraînez, compétition et loisir compris.', $this->text($offers, '.teams-note'));

        $items = static fn (Element $card): array => array_map(
            static fn (Element $li): string => trim((string) preg_replace('/\s+/u', ' ', (string) $li->textContent)),
            iterator_to_array($card->querySelectorAll('li')),
        );
        self::assertContains('10 crédits offerts : chaque planning généré, placement de matchs ou export en utilise un', $items($cards[0]));

        self::assertSame('Jusqu\'à 20 équipes', $this->text($cards[1], '.cap'));
        self::assertContains('Plannings de vacances et de fermetures', $items($cards[1]));
        self::assertSame('De 21 à 34 équipes', $this->text($cards[2], '.cap'));
        self::assertSame('À partir de 35 équipes', $this->text($cards[3], '.cap'));
        foreach ([2, 3] as $index) {
            self::assertSame(['Tout Essentiel', 'Même offre complète, pour plus d\'équipes'], $items($cards[$index]), $names[$index]);
        }
        foreach ([1, 2, 3] as $index) {
            self::assertStringStartsWith('Sur demande', $this->text($cards[$index], '.price'), $names[$index]);
            self::assertStringContainsString('Tarif par saison', $this->text($cards[$index], '.price'), $names[$index]);
        }

        $text = $this->normalize((string) $offers->textContent);
        foreach (['€', '30 équipes', '40-50', 'Tout, sans limite', '10 générations'] as $stale) {
            $this->assertAbsent($stale, $text, "« {$stale} » ne figure plus dans les offres.");
        }
    }

    /**
     * Promesse (D4, D5, D12, décisions du 2026-11-11) : la vitrine n'affirme rien de plus que ce qui
     * est vrai — ni sauvegarde quotidienne, ni effacement « sous 60 jours au plus », ni coachs qui
     * « reçoivent leur planning » d'Amateo, ni e-mails limités aux rappels, ni « n°1 ».
     */
    public function testNoOverclaimPhrase(): void
    {
        $forbidden = [
            'sauvegardées chaque jour', '20 minutes', 'n°1', 'numéro 1', '10 générations',
            '60 jours', 'au plus', 'panne à Paris', 'conservées 30 jours',
            'reçoivent leur planning', 'se limitent aux rappels',
        ];
        foreach ($this->pages() as $page) {
            $text = mb_strtolower($this->visibleText($page) . ' ' . $this->attribute($this->parse($page), 'meta[name="description"]', 'content'));
            foreach ($forbidden as $phrase) {
                $this->assertAbsent(mb_strtolower($phrase), $text, "« {$phrase} » dans {$page}");
            }
        }
    }

    /**
     * Promesse (F5, D12) : un club lit le MÊME délai d'effacement sur la vitrine et dans
     * l'application — la phrase validée figure, texte normalisé (balises retirées, espaces repliés),
     * dans `vos-donnees.html` et dans `PrivacyPage.tsx` ; l'ancienne « rotation (30 jours) » et les
     * « 60 jours » ont disparu de l'app. Angle jumeau côté rendu :
     * `frontend/src/features/legal/PrivacyPage.test.tsx` (« annonce l'effacement de l'espace du club
     * avec la phrase de la vitrine ») — doublon assumé, domaine RGPD/effacement.
     */
    public function testErasureDelayIsTheSameOnTheSiteAndInTheApp(): void
    {
        $site = $this->typographyFree($this->visibleText('vos-donnees.html'));
        self::assertStringContainsString(self::ERASURE_TITLE . ' ' . self::ERASURE_SENTENCE, $site, 'vos-donnees.html ne porte pas la phrase d\'effacement validée.');

        $app = $this->typographyFree($this->tsxText($this->read(self::PRIVACY_PAGE)));
        self::assertStringContainsString(self::ERASURE_SENTENCE, $app, 'PrivacyPage.tsx ne porte pas la phrase d\'effacement de la vitrine.');
        foreach (['rotation (30 jours)', '60 jours', 'conservées 30 jours'] as $stale) {
            $this->assertAbsent($stale, $app, "« {$stale} » dans PrivacyPage.tsx");
        }
    }

    /**
     * Promesse (D3, termes validés groupes 1 et 2) : l'accueil parle la langue d'un club, pas celle du
     * moteur — ni « solveur », ni « optimis… », ni « radar », ni « l'app » ; « l'outil » ne désigne
     * que FBI ou la démo ; hors FAQ (qui garde les mots de l'écran), ni « mutualis… », ni
     * « verrouill… », ni « régénér… », ni « période ».
     */
    public function testLandingAvoidsEngineVocabulary(): void
    {
        $everywhere = mb_strtolower($this->visibleText('index.html'));
        foreach (['solveur', 'moteur d\'optimisation', 'optimis', 'radar', 'à la volée'] as $word) {
            $this->assertAbsent($word, $everywhere, "« {$word} » sur l'accueil");
        }
        self::assertSame(0, preg_match('/\bl[\'’]app\b/u', $everywhere), '« l\'app » sur l\'accueil : écrire « Amateo ».');

        $withoutAllowedTool = str_replace(
            ['l\'outil de gestion de la ffbb', 'l\'outil de la ffbb', 'montrons l\'outil'],
            '',
            $everywhere,
        );
        $this->assertAbsent('l\'outil', $withoutAllowedTool, '« l\'outil » sur l\'accueil hors FBI et la démo : écrire « Amateo ».');

        $outsideFaq = mb_strtolower($this->visibleText('index.html', ['#faq']));
        foreach (['mutualis', 'verrouill', 'régénér', 'période'] as $word) {
            $this->assertAbsent($word, $outsideFaq, "« {$word} » sur l'accueil hors FAQ");
        }
    }

    /**
     * Promesse (D6) : la page Fonctionnalités décrit les huit modules en prose (aucune puce) et dit
     * franchement, en six lignes, ce qu'Amateo ne fait pas — dont les deux corrigées le 2026-11-11.
     */
    public function testFeaturesPageNamesWhatTheToolDoesNot(): void
    {
        $document = $this->parse('fonctionnalites.html');
        self::assertSame('Ce qu\'' . $this->config('brand') . ' fait, et ce qu\'il ne fait pas', $this->text($document, 'h1'));

        $modules = [];
        foreach ($document->querySelectorAll('article.module') as $module) {
            $modules[] = $this->text($module, 'h3');
        }
        self::assertSame([
            'Planning de saison', 'Les imprévus', 'Les vacances', 'Les contraintes, faciles à gérer',
            'L\'aide au placement', 'La détection des erreurs FBI', 'Les conflits de coachs vus en amont', 'Les déplacements extérieurs',
        ], $modules);
        self::assertNull($document->querySelector('main ul, main ol'), 'Fonctionnalités : aucune puce, de la prose.');

        $lines = iterator_to_array($this->element($document, 'section#ne-fait-pas')->querySelectorAll('p'));
        self::assertCount(6, $lines, 'Six lignes « ce qu\'Amateo ne fait pas ».');
        $statements = [];
        $explanations = [];
        foreach ($lines as $line) {
            $statements[] = $this->text($line, 'strong');
            $explanations[] = $this->text($line, 'strong + span');
        }
        self::assertSame([
            'Il n\'écrit rien dans FBI.',
            'Il ne gère ni paiements, ni licences, ni cotisations.',
            'Il ne fabrique pas le calendrier des championnats.',
            'Il ne remplace pas votre jugement.',
            'Il n\'envoie ni convocations ni messages aux joueurs ou aux familles.',
            'Il est fait pour le basket amateur affilié FFBB, et pour lui seul aujourd\'hui.',
        ], $statements);
        self::assertStringContainsString('dégrossit le travail, à vous les finitions', $explanations[3]);
        self::assertSame('Vous transmettez le planning aux coachs en PDF ou en Excel ; ils déposent leurs indisponibilités par un lien personnel, sans compte.', $explanations[4]);

        $text = $this->visibleText('fonctionnalites.html');
        self::assertStringContainsString('30, 14 et 7 jours avant chaque période de vacances scolaires', $text, 'Rappels des vacances scolaires (J-30/J-14/J-7).');
        self::assertStringContainsString('14, 7 et 3 jours avant une période que vous avez posée vous-même', $text, 'Rappels des périodes posées (J-14/J-7/J-3).');
    }

    /**
     * Promesse (`landing.md` : la vitrine s'adosse à `modules-produit.md`) : chaque limite affichée
     * et chaque famille de rappels a sa ligne dans la carte produit — une affirmation de la vitrine
     * sans source dans la spec rougit ici.
     */
    public function testFeaturesClaimsExistInModulesProduit(): void
    {
        $spec = $this->read('/specs/courantes/modules-produit.md');
        $heading = '## Ce qu\'' . $this->config('brand') . ' ne fait pas';
        $start = strpos($spec, $heading);
        self::assertIsInt($start, "modules-produit.md : section « {$heading} » absente.");
        $section = substr($spec, $start + \strlen($heading));
        $parts = preg_split('/^(?:## |---)/m', $section);
        self::assertIsArray($parts);
        $section = $parts[0];
        self::assertSame(6, preg_match_all('/^- /m', $section), 'modules-produit.md : six limites, une par ligne.');
        $section = $this->normalize($section);

        $page = $this->element($this->parse('fonctionnalites.html'), 'section#ne-fait-pas');
        $statements = $this->normalize((string) $page->textContent);
        foreach ([
            'n\'écrit rien dans FBI',
            'ni paiements, ni licences, ni cotisations',
            'ne fabrique pas le calendrier des championnats',
            'ne remplace pas',
            'n\'envoie ni convocations ni messages aux joueurs ou aux familles',
            'basket amateur affilié FFBB',
        ] as $anchor) {
            self::assertStringContainsString($anchor, $statements, "Vitrine : « {$anchor} » absent de #ne-fait-pas.");
            self::assertStringContainsString($anchor, $section, "modules-produit.md : « {$anchor} » absent de « Ce qu'Amateo ne fait pas ».");
        }

        $wholeSpec = $this->normalize($spec);
        self::assertStringContainsString('J-14 / J-7 / J-3', $wholeSpec, 'modules-produit.md : rappels des périodes posées.');
        self::assertStringContainsString('J-30 / J-14 / J-7', $wholeSpec, 'modules-produit.md : rappels des vacances scolaires.');
        self::assertStringContainsString('jamais les coachs ni les contraintes', $wholeSpec, 'modules-produit.md : ce que l\'import par IA ne crée pas.');
    }

    /**
     * Promesse (F1) : l'import par IA n'est jamais vendu pour plus qu'il ne fait — la FAQ, le journal
     * (v1.3.0) et Fonctionnalités disent tous trois qu'il reste à saisir les coachs et les règles
     * (`ParcImportExecutor` ne crée ni coachs ni contraintes).
     */
    public function testAiImportClaimsLeaveCoachesAndRulesToTheManager(): void
    {
        $answer = null;
        foreach ($this->parse('index.html')->querySelectorAll('#faq details') as $question) {
            if (str_starts_with($this->text($question, 'summary'), 'Il faut tout ressaisir à la main ?')) {
                $answer = $this->text($question, '.a');
            }
        }
        self::assertNotNull($answer, 'FAQ « Il faut tout ressaisir à la main ? » absente.');
        self::assertStringContainsString('il reste à ajouter vos coachs et vos règles', $answer);

        self::assertStringContainsString('il ne reste qu\'à ajouter vos coachs et vos règles', $this->text($this->parse('evolutions.html'), 'article#v1-3-0'));
        self::assertStringContainsString('Les coachs et les contraintes se saisissent ensuite', $this->visibleText('fonctionnalites.html'));
    }

    /**
     * Promesse (D7, F14) : le gain de temps n'est jamais promis — « moins de deux heures » est
     * présenté comme « notre but », et sur « Qui fait » seulement.
     */
    public function testOutcomeFiguresStayOnQuiFaitAsAGoal(): void
    {
        self::assertMatchesRegularExpression('/notre but[^.]*moins de deux heures/u', $this->visibleText('qui-fait.html'));
        foreach ($this->pages() as $page) {
            if ('qui-fait.html' !== $page) {
                $this->assertAbsent('deux heures', $this->visibleText($page), "« deux heures » hors de qui-fait.html ({$page})");
            }
        }
    }

    /**
     * Promesse (D7, F15) : la photo du fondateur ne part jamais avec ses métadonnées (lieu, appareil,
     * date) ni hors format — le fichier `assets/qui-fait/fondateur.*` et son `<img>` dans
     * `figure.portrait` vont ensemble (ni l'un sans l'autre) ; présents, l'image fait ≤ 800 px, sans
     * Exif/XMP/IPTC (JPEG APP1/APP13) ni `eXIf`/`tEXt`/`iTXt`/`zTXt` (PNG), et son `alt` est
     * `config.js` `editor`. Vert tant qu'aucun des deux n'existe (photo à venir, suivi Light).
     */
    public function testPortraitIsCoupledToThePageAndCarriesNoMetadata(): void
    {
        $files = glob(self::repositoryRoot() . '/landing/assets/qui-fait/fondateur.*') ?: [];
        $page = self::repositoryRoot() . '/landing/qui-fait.html';
        $image = is_file($page) ? $this->parse('qui-fait.html')->querySelector('figure.portrait img') : null;

        if ([] === $files && !$image instanceof Element) {
            $this->addToAssertionCount(1); // Ni photo ni <img> : état attendu tant que la photo n'est pas fournie.

            return;
        }
        self::assertCount(1, $files, 'Un <img> de portrait sans son fichier assets/qui-fait/fondateur.* (ou plusieurs fichiers).');
        self::assertNotNull($image, 'Un fichier assets/qui-fait/fondateur.* sans son <figure class="portrait"><img> dans qui-fait.html.');
        self::assertSame('assets/qui-fait/' . basename($files[0]), ltrim((string) $image->getAttribute('src'), '/'), 'Le <img> du portrait pointe un autre fichier.');
        self::assertSame($this->config('editor'), $image->getAttribute('alt'), 'alt du portrait = config.js editor.');

        $size = getimagesize($files[0]);
        self::assertIsArray($size, 'Le portrait n\'est pas une image lisible.');
        self::assertLessThanOrEqual(800, max($size[0], $size[1]), 'Portrait ≤ 800 px.');
        self::assertSame([], $this->embeddedMetadata((string) file_get_contents($files[0])), 'Le portrait porte des métadonnées.');
    }

    /**
     * Promesse (D9, corrections SMITH 6) : les mentions légales nomment l'éditeur, son statut et
     * l'hébergeur EN CLAIR dans le HTML (l'obligation LCEN ne dépend pas d'un script), avec les
     * valeurs de `config.js` ; « Qui fait » dit le même éditeur ; `assets/site.js` n'injecte plus ces
     * valeurs.
     */
    public function testPublisherIsWrittenInClearAndMatchesConfig(): void
    {
        $brand = $this->config('brand');
        $editor = $this->config('editor');
        $status = $this->config('editorStatus');
        $legal = $this->parse('mentions-legales.html');

        $editors = iterator_to_array($legal->querySelectorAll('[data-legal="editor"]'));
        self::assertGreaterThanOrEqual(2, \count($editors), 'Éditeur ET responsable de publication nommés.');
        foreach ($editors as $node) {
            self::assertSame($editor, $this->normalize((string) $node->textContent));
        }
        self::assertSame($status, $this->text($legal, '[data-legal="editorStatus"]'));
        foreach (['hostName' => 'name', 'hostAddress' => 'address', 'hostRcs' => 'rcs', 'hostPhone' => 'phone'] as $slot => $key) {
            self::assertSame($this->config($key), $this->text($legal, "[data-legal=\"{$slot}\"]"), "Hébergeur ({$slot}) en clair = config.js legal.host.{$key}");
        }

        $legalText = $this->visibleText('mentions-legales.html');
        $this->assertAbsent('…', $legalText, 'Un emplacement des mentions légales attend encore un script.');
        self::assertStringContainsString('Le responsable de la publication est ' . $editor . ', fondateur, joignable à l\'adresse de contact ci-dessus.', $legalText);

        self::assertStringContainsString($brand . ' est édité par ' . $editor . ', ' . $status . '.', $this->visibleText('qui-fait.html'));

        $script = $this->read('/landing/assets/site.js');
        foreach (['data-legal="editor"', 'editorStatus', 'hostName', 'hostAddress', 'hostRcs', 'hostPhone'] as $injected) {
            $this->assertAbsent($injected, $script, "assets/site.js injecte encore « {$injected} » : la valeur est écrite en clair.");
        }
    }

    /**
     * Promesse (D9) : la vitrine ne nomme aucune société — aucune n'est immatriculée à ce jour : ni le
     * nom d'éditeur que l'application porte encore (`PUBLISHER_NAME`, `product.ts`, hors lot F7), ni
     * « micro-entreprise », ni « SIRET », pas même en commentaire de `config.js` ou de `site.js`.
     */
    public function testLandingNamesNoCompany(): void
    {
        $matched = preg_match('/export const PUBLISHER_NAME = "([^"]+)"/', $this->read('/frontend/src/shared/lib/product.ts'), $matches);
        self::assertSame(1, $matched, 'PUBLISHER_NAME introuvable dans product.ts.');
        $forbidden = [$matches[1], 'micro-entreprise', 'SIRET'];

        $files = ['/landing/config.js', '/landing/assets/site.js'];
        foreach ($this->pages() as $page) {
            $files[] = '/landing/' . $page;
        }
        foreach ($files as $file) {
            $contents = $this->read($file);
            foreach ($forbidden as $word) {
                $this->assertAbsent(mb_strtolower($word), mb_strtolower($contents), "« {$word} » dans {$file}");
            }
        }
    }

    /** Texte d'un composant TSX tel que l'affiche React : balises retirées, `{" "}` rendus, espaces repliés. */
    private function tsxText(string $source): string
    {
        $text = str_replace(['{" "}', '{\' \'}'], ' ', $source);
        $text = (string) preg_replace('/<[^>]+>/', ' ', $text);

        return $this->normalize(html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /** Apostrophe typographique ramenée à l'apostrophe droite, espaces repliés : on compare des mots. */
    private function typographyFree(string $text): string
    {
        return $this->normalize(str_replace('’', '\'', $text));
    }

    /**
     * Segments ou blocs de métadonnées d'une image JPEG/PNG (vide = image propre).
     *
     * @return list<string>
     */
    private function embeddedMetadata(string $bytes): array
    {
        $found = [];
        if (str_starts_with($bytes, "\xFF\xD8")) {
            $offset = 2;
            $length = \strlen($bytes);
            while ($offset + 4 <= $length && "\xFF" === $bytes[$offset]) {
                $marker = \ord($bytes[$offset + 1]);
                if (0xDA === $marker) {
                    break; // Début des données d'image : plus aucun segment d'en-tête.
                }
                $size = unpack('n', substr($bytes, $offset + 2, 2));
                self::assertIsArray($size);
                if (0xE1 === $marker) {
                    $found[] = 'APP1 (Exif/XMP)';
                }
                if (0xED === $marker) {
                    $found[] = 'APP13 (IPTC)';
                }
                $offset += 2 + (int) $size[1];
            }

            return $found;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            $offset = 8;
            while ($offset + 8 <= \strlen($bytes)) {
                $header = unpack('Nlength/a4type', substr($bytes, $offset, 8));
                self::assertIsArray($header);
                if (\in_array($header['type'], ['eXIf', 'tEXt', 'iTXt', 'zTXt'], true)) {
                    $found[] = (string) $header['type'];
                }
                $offset += 12 + (int) $header['length'];
            }

            return $found;
        }
        self::fail('Portrait : format inattendu (JPEG ou PNG attendu).');
    }
}
