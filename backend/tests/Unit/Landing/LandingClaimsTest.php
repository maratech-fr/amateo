<?php

declare(strict_types=1);

namespace App\Tests\Unit\Landing;

use DateTimeImmutable;
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

    /**
     * Clubs affichés sur l'accueil (« Ils nous font confiance. »), dans l'ordre de la page : nom affiché
     * → logo sous `landing/assets/clubs/`. Liste fermée : ajouter ou retirer un club = un `<li>`, un
     * fichier et une ligne ici, ensemble.
     */
    private const array DISPLAYED_CLUBS = [
        'Villeurbanne Sharks' => 'villeurbanne-sharks.jpg',
        'Basket Rillieux Crépieux' => 'basket-rillieux-crepieux.jpg',
        'AL Antonin Perrin' => 'al-antonin-perrin.jpg',
    ];

    /**
     * Les six échéances du compte à rebours de l'accueil, dans l'ordre : libellé → date (`null` = la date
     * varie d'une année à l'autre et se vérifie par sa forme seule : réelle, entre ses voisines).
     */
    private const array COUNTDOWN_DEADLINES = [
        ['Vacances de Noël dans', '2026-12-19'],
        ['Reprise de janvier, troisième phase, dans', '2027-01-05'],
        ['Premières vacances d\'hiver dans', null],
        ['Playoffs dans', '2027-05-01'],
        ['Avant les vacances d\'été, la saison prochaine se prépare : dans', '2027-07-01'],
        ['Mi-août, la reprise approche : dans', '2027-08-16'],
    ];

    /**
     * Promesse (D3) : à côté de la définition (`section.p-intro`, juste après le héros), la carte
     * « Quatre garanties » porte les quatre garanties validées, chacune menant à sa preuve.
     */
    public function testGuaranteesBannerHoldsFourLinkedClaims(): void
    {
        $document = $this->parse('index.html');
        $intro = $this->element($document, 'section.p-intro');
        self::assertNotNull($intro->querySelector('.p-def'), 'La carte des garanties est à côté de la définition (.p-def).');
        self::assertNotNull($intro->previousElementSibling?->querySelector('h1'), 'section.p-intro suit immédiatement le héros (h1).');

        $card = $this->element($intro, 'aside.p-gar');
        $titleId = (string) $card->getAttribute('aria-labelledby');
        self::assertNotSame('', $titleId, 'aside.p-gar est nommé par son titre (aria-labelledby).');
        self::assertSame('Quatre garanties', $this->text($card, 'h2#' . $titleId));
        $banner = $this->element($card, 'ul.guarantees');

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
     * Promesse (D3, F2) : il n'y a qu'une offre, en entier pour tous les clubs — la Découverte gratuite
     * (10 crédits), puis trois paliers SANS NOM qui suivent la grille du produit (jusqu'à 20 équipes,
     * de 21 à 34, 35 et plus : `PlanEntitlements`), chacun « Tarif sur demande », sans prix affiché ni
     * palier disparu. Le curseur de l'accueil nomme les mêmes trois paliers que `offres.html` et y
     * mène. Le code n'est pas lu par ce test : la largeur est dite, la grille est recopiée.
     */
    public function testOfferCardsMatchTheLadder(): void
    {
        $tiers = ['Jusqu\'à 20 équipes', 'De 21 à 34 équipes', '35 équipes et plus'];
        $page = $this->parse('offres.html');

        $free = $this->element($page, 'article.plan.of-free');
        $freeText = $this->normalize((string) $free->textContent);
        self::assertStringContainsString('Découverte', $freeText);
        self::assertStringContainsString('10 crédits offerts', $freeText);

        $full = $this->element($page, 'article.plan.of-full');
        self::assertNotNull($full->querySelector('ul.of-all li'), 'La carte complète liste ce que fait Amateo (ul.of-all).');
        $rows = iterator_to_array($full->querySelectorAll('ol.of-tiers > li'));
        self::assertSame($tiers, array_map(fn (Element $li): string => $this->text($li, 'b'), $rows), 'Trois paliers, dans l\'ordre de la grille.');
        foreach ($rows as $index => $row) {
            self::assertSame('Tarif sur demande', $this->text($row, 'span'), $tiers[$index]);
            self::assertNotSame('', $this->text($row, 'small'), $tiers[$index] . ' : repère du palier (small).');
        }

        self::assertStringStartsWith('Une équipe = chaque groupe que vous entraînez, compétition et loisir compris.', $this->text($page, 'p.teams-note'));

        $home = $this->element($this->parse('index.html'), 'section#offres');
        $homeTiers = array_map(fn (Element $b): string => $this->normalize((string) $b->textContent), iterator_to_array($home->querySelectorAll('[data-tiers] li b')));
        self::assertSame($tiers, $homeTiers, 'Le curseur de l\'accueil nomme les paliers de offres.html.');
        $toOffers = [];
        foreach ($home->querySelectorAll('a') as $link) {
            if (\in_array($link->getAttribute('href'), ['offres.html', '/offres.html'], true)) {
                $toOffers[] = $this->normalize((string) $link->textContent);
            }
        }
        self::assertContains('Voir le détail des offres', $toOffers, 'L\'accueil mène au détail des offres.');

        foreach (['offres.html' => $this->visibleText('offres.html'), 'index.html#offres' => $this->normalize((string) $home->textContent)] as $where => $text) {
            foreach (['€', 'Essentiel', 'Grand club', '30 équipes', '10 générations'] as $stale) {
                $this->assertAbsent($stale, $text, "« {$stale} » dans {$where} : une offre, trois paliers sans nom.");
            }
        }
    }

    /**
     * Promesse : l'accueil n'affiche comme clubs qui lui font confiance QUE ceux de `DISPLAYED_CLUBS`,
     * dans cet ordre, avant les offres — chaque logo est nommé une fois aux lecteurs d'écran (`alt`),
     * la pastille visible répète le nom en `aria-hidden`, le `<li>` se focalise au clavier. Aucun logo
     * n'est servi sans son club dans la liste (fichiers de `assets/clubs/` = la liste exacte), et
     * chacun reste léger et propre : JPEG ≤ 40 Ko, ≤ 400 px, sans métadonnées.
     */
    public function testDisplayedClubsAreTheClosedList(): void
    {
        $document = $this->parse('index.html');
        $section = $this->element($document, 'section#clubs.p-clubs');
        self::assertSame('Ils nous font confiance.', $this->text($section, 'h2'));

        $items = [];
        foreach ($this->childElements($this->element($section, 'ul.clubs')) as $child) {
            self::assertSame('LI', $child->tagName, 'ul.clubs ne contient que des <li>.');
            $items[] = $child;
        }
        self::assertCount(\count(self::DISPLAYED_CLUBS), $items, 'Un <li> par club affiché, ni plus ni moins.');

        $index = 0;
        foreach (self::DISPLAYED_CLUBS as $name => $file) {
            $item = $items[$index++];
            self::assertSame('0', $item->getAttribute('tabindex'), "{$name} : le logo se focalise au clavier.");
            $images = $item->querySelectorAll('img');
            self::assertSame(1, $images->length, "{$name} : un seul logo.");
            $image = $this->element($item, 'img');
            self::assertSame('assets/clubs/' . $file, ltrim((string) $image->getAttribute('src'), '/'), "{$name} : fichier du logo.");
            self::assertSame($name, $image->getAttribute('alt'), "{$name} : alt = nom affiché.");
            foreach (['width', 'height'] as $dimension) {
                self::assertNotSame('', (string) $image->getAttribute($dimension), "{$name} : {$dimension} absent (la page saute au chargement).");
            }
            self::assertSame($name, $this->text($item, 'span[aria-hidden="true"]'), "{$name} : la pastille répète le nom, masquée aux lecteurs d'écran.");
        }

        $served = array_map('basename', glob(self::repositoryRoot() . '/landing/assets/clubs/*') ?: []);
        sort($served);
        $listed = array_values(self::DISPLAYED_CLUBS);
        sort($listed);
        self::assertSame($listed, $served, 'Les fichiers de landing/assets/clubs/ ≠ DISPLAYED_CLUBS (logo orphelin ou manquant).');

        foreach (self::DISPLAYED_CLUBS as $name => $file) {
            $path = self::repositoryRoot() . '/landing/assets/clubs/' . $file;
            self::assertLessThanOrEqual(40 * 1024, (int) filesize($path), "{$name} : logo > 40 Ko.");
            $size = getimagesize($path);
            self::assertIsArray($size, "{$name} : logo illisible.");
            self::assertSame(\IMAGETYPE_JPEG, $size[2], "{$name} : logo en JPEG.");
            self::assertLessThanOrEqual(400, max($size[0], $size[1]), "{$name} : logo > 400 px.");
            self::assertSame([], $this->embeddedMetadata((string) file_get_contents($path)), "{$name} : le logo porte des métadonnées.");
        }

        $order = array_map(static fn (Element $node): string => (string) $node->getAttribute('id'), iterator_to_array($document->querySelectorAll('#clubs, #offres')));
        self::assertSame(['clubs', 'offres'], $order, '#clubs précède #offres sur l\'accueil.');
    }

    /**
     * Promesse : la FAQ se lit sans JS — onze `<details>` natifs (aucun bouton `aria-expanded` qui
     * attendrait un script), les onze questions validées dans l'ordre et rangées en trois temps
     * (`#demarrer`, `#quotidien`, `#club`), la première ouverte ; le compteur « sur 11 » n'existe
     * qu'avec JS ; les réponses mènent aux offres, à « Vos données », aux fonctionnalités et à la démo.
     */
    public function testFaqIsElevenNativeDetails(): void
    {
        $brand = $this->config('brand');
        $expected = [
            'demarrer' => [
                'Faut-il tout ressaisir pour démarrer avec ' . $brand . ' ?',
                'C\'est fait pour un club amateur ?',
                'On peut voir avant de s\'engager ?',
                'Comment préparer le planning d\'entraînement d\'un club qui a plusieurs gymnases ?',
            ],
            'quotidien' => [
                'Comment placer les matchs à domicile depuis l\'export FBI ?',
                'Et si le planning généré ne me convient pas ?',
                'Comment adapter le planning quand un gymnase ferme ou que la mairie change les créneaux ?',
                'Les coachs doivent-ils créer un compte ?',
                'Comment les parents savent quand joue leur enfant ?',
            ],
            'club' => [
                'Combien ça coûte ?',
                'Où sont hébergées nos données ?',
            ],
        ];
        $document = $this->parse('faq.html');

        $questions = iterator_to_array($document->querySelectorAll('details.fq-item'));
        self::assertCount(11, $questions, 'Onze questions, en <details> natifs.');
        $number = 0;
        foreach ($expected as $group => $titles) {
            foreach ($titles as $title) {
                $question = $questions[$number++];
                self::assertSame('q-' . $number, $question->getAttribute('id'), "Question {$number} : id");
                self::assertSame($title, $this->text($question, 'summary .q'), "Question {$number} : titre");
                self::assertSame((string) $number, $this->text($question, 'summary i'), "Question {$number} : numéro");
                self::assertNotSame('', $this->text($question, '.a'), "Question {$number} : réponse vide.");
                self::assertSame($group, $question->closest('section')?->getAttribute('id'), "Question {$number} : rangée sous #{$group}.");
                self::assertSame(1 === $number, $question->hasAttribute('open'), "Question {$number} : seule la première est ouverte.");
            }
        }
        self::assertNull($document->querySelector('button[aria-expanded]'), 'FAQ : un bouton aria-expanded attend un script, <details> suffit.');

        $score = $this->element($document, '.fq-score');
        self::assertTrue($score->hasAttribute('data-js-only') && $score->hasAttribute('hidden'), 'Le compteur de questions lues n\'existe qu\'avec JS (data-js-only hidden).');
        self::assertStringContainsString('sur 11', $this->normalize((string) $score->textContent));
        self::assertStringContainsString('Onze questions', $this->visibleText('faq.html'));

        $links = [3 => ['data-cta', 'demo-inline'], 4 => ['href', 'fonctionnalites.html'], 10 => ['href', 'offres.html'], 11 => ['href', 'vos-donnees.html']];
        foreach ($links as $number => [$attribute, $value]) {
            $found = false;
            foreach ($questions[$number - 1]->querySelectorAll('.a a') as $link) {
                $found = $found || $value === ltrim((string) $link->getAttribute($attribute), '/');
            }
            self::assertTrue($found, "Question {$number} : la réponse mène à [{$attribute}={$value}].");
        }
    }

    /**
     * Promesse : le compte à rebours de l'accueil ne montre que des échéances bien formées — le bloc
     * n'existe qu'avec JS (`data-js-only hidden`, dans le héros), la liste reste cachée, six entrées
     * dans l'ordre de `COUNTDOWN_DEADLINES`, chacune une vraie date ISO, croissante, avec sa question.
     * « La date est à venir » n'est PAS testé (la CI tourne à date quelconque) : la doc de release
     * porte la vérification et la réécriture annuelle.
     */
    public function testCountdownDeadlinesAreWellFormed(): void
    {
        $document = $this->parse('index.html');
        $block = $this->element($document, '[data-countdown]');
        self::assertTrue($block->hasAttribute('data-js-only') && $block->hasAttribute('hidden'), 'Le compte à rebours n\'existe qu\'avec JS (data-js-only hidden).');
        self::assertNotNull($this->element($document, 'h1')->closest('section')?->querySelector('[data-countdown]'), 'Le compte à rebours vit dans le héros (la section du h1).');

        $list = $this->element($block, 'ol[data-deadlines]');
        self::assertTrue($list->hasAttribute('hidden'), 'La liste des échéances est une donnée, jamais affichée telle quelle.');
        $entries = iterator_to_array($list->querySelectorAll('li'));
        self::assertCount(\count(self::COUNTDOWN_DEADLINES), $entries, 'Six échéances.');

        $previous = null;
        foreach (self::COUNTDOWN_DEADLINES as $index => [$label, $date]) {
            $entry = $entries[$index];
            self::assertSame($this->typographyFree($label), $this->typographyFree((string) $entry->textContent), "Échéance n° {$index} : libellé");
            $actual = (string) $entry->getAttribute('data-date');
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $actual);
            self::assertTrue($parsed instanceof DateTimeImmutable && $actual === $parsed->format('Y-m-d'), "{$label} : « {$actual} » n'est pas une date ISO réelle.");
            if (null !== $date) {
                self::assertSame($date, $actual, "{$label} : date");
            }
            if (null !== $previous) {
                self::assertGreaterThan($previous, $actual, "{$label} : les échéances sont croissantes.");
            }
            $previous = $actual;
            self::assertNotSame('', trim((string) $entry->getAttribute('data-question')), "{$label} : question vide.");
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
     * que FBI ou la démo ; ni « mutualis… », ni « période ». « Verrouiller » et « régénérer » sont
     * admis (C3) : ce sont les mots des boutons que le club touche.
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

        foreach (['mutualis', 'période'] as $word) {
            $this->assertAbsent($word, $everywhere, "« {$word} » sur l'accueil");
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
        $title = 'Faut-il tout ressaisir pour démarrer avec ' . $this->config('brand') . ' ?';
        $answer = null;
        foreach ($this->parse('faq.html')->querySelectorAll('details.fq-item') as $question) {
            if ($title === $this->text($question, 'summary .q')) {
                $answer = $this->text($question, '.a');
            }
        }
        self::assertNotNull($answer, "FAQ « {$title} » absente de faq.html.");
        self::assertStringContainsString('Il reste à ajouter vos coachs et vos règles', $answer);

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
     * « micro-entreprise », ni « SIRET », pas même en commentaire de `config.js` ou d'un script de
     * `assets/`.
     */
    public function testLandingNamesNoCompany(): void
    {
        $matched = preg_match('/export const PUBLISHER_NAME = "([^"]+)"/', $this->read('/frontend/src/shared/lib/product.ts'), $matches);
        self::assertSame(1, $matched, 'PUBLISHER_NAME introuvable dans product.ts.');
        $forbidden = [$matches[1], 'micro-entreprise', 'SIRET'];

        $files = ['/landing/config.js', ...$this->assetFiles('js')];
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
     * Segments ou blocs de métadonnées d'une image JPEG/PNG (vide = image propre) — portrait et logos des clubs.
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
        self::fail('Image : format inattendu (JPEG ou PNG attendu).');
    }
}
