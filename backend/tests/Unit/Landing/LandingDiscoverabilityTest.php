<?php

declare(strict_types=1);

namespace App\Tests\Unit\Landing;

use Dom\Element;
use Dom\HTMLDocument;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P4-391 — promesse : la vitrine se présente sous son vrai nom et son vrai sujet à qui ne
 * lance pas JavaScript (crawler de moteur, aperçu de messagerie, IA) : tout ce qu'un
 * moteur doit lire est ÉCRIT dans le HTML servi, et reste cohérent avec `landing/config.js`.
 * P4-522 — la vitrine a huit pages qui partagent `assets/site.css`, `assets/site.js`, un en-tête et
 * un pied identiques : chaque page (`landing/*.html`, balayée — aucune liste à tenir) est sous ces
 * gardes, ses liens internes aboutissent, ses boutons d'appel marchent sans JS (P4-407). Les
 * scripts et feuilles propres à une page (`assets/*.js`, `assets/*.css`) sont lus au même titre
 * que les pages : aucun ne réécrit la marque, n'appelle un tiers ni ne ramène un thème sombre.
 *
 * Lecture statique des fichiers suivis (un crawl réel ne tourne pas en CI) : le HTML est
 * parsé SANS exécuter ses scripts, exactement la vue d'un crawler sans JS. Le robots.txt et
 * le sitemap sont gardés à côté, par `Security/IndexationPolicyTest` ; ce que la vitrine
 * AFFIRME (garanties, offres, délais), par `LandingClaimsTest` ; le journal, par
 * `LandingChangelogTest`.
 */
#[Group('phase1')]
final class LandingDiscoverabilityTest extends TestCase
{
    use LandingFiles;

    /**
     * Promesse : chaque page porte le titre validé par le fondateur, ouvert par la marque, carte de
     * partage comprise — et aucune page n'existe sans titre validé (clés == pages servies).
     */
    public function testEveryPageHasItsFounderTitleAndOgTitleMatches(): void
    {
        $brand = $this->config('brand');
        $expected = [
            'evolutions.html' => $brand . ' — Journal des évolutions',
            'faq.html' => $brand . ' — Questions fréquentes',
            'fonctionnalites.html' => $brand . ' — Ce qu\'' . $brand . ' fait, et ce qu\'il ne fait pas',
            'index.html' => $brand . ' — Planning d\'entraînement et matchs FBI des clubs de basket',
            'mentions-legales.html' => $brand . ' — Mentions légales',
            'offres.html' => $brand . ' — Les offres',
            'qui-fait.html' => $brand . ' — Qui fait ' . $brand,
            'vos-donnees.html' => $brand . ' — Vos données',
        ];
        self::assertSame(array_keys($expected), $this->pages(), 'Les pages servies ≠ les huit pages validées (une page sans titre validé, ou une page manquante).');

        foreach ($expected as $page => $title) {
            $document = $this->parse($page);
            self::assertSame($title, $this->text($document, 'title'), "<title> de {$page}");
            self::assertSame($title, $this->attribute($document, 'meta[property="og:title"]', 'content'), "og:title de {$page}");
        }
    }

    /**
     * Promesse : le titre visible nomme le basket, dans la phrase validée par le fondateur — un moteur
     * lit « sans le casse-tête », jamais le « avec » barré, qui n'est qu'un décor CSS hors du DOM.
     */
    public function testHeroHeadlineIsTheFounderSentence(): void
    {
        $headline = $this->text($this->parse('index.html'), 'h1');
        self::assertSame('Le planning de votre club de basket, sans le casse-tête.', $headline);
        self::assertStringNotContainsString('avec', $headline, 'Le « avec » barré vit en CSS (pseudo-élément), jamais dans le texte du h1.');
    }

    /**
     * Promesse : la description lue par les moteurs porte les mots que cherche un club, partage compris,
     * et c'est la définition affichée sur la page (`.p-defq` puis `.p-defs`) — un moteur et un visiteur
     * lisent la même phrase.
     */
    public function testMetaDescriptionNamesBasketAmateurAndFbi(): void
    {
        $document = $this->parse('index.html');
        $description = $this->attribute($document, 'meta[name="description"]', 'content');

        foreach (['basket', 'amateur', 'FBI', 'FFBB'] as $word) {
            self::assertStringContainsString($word, $description, 'Mot attendu dans la meta description.');
        }
        self::assertSame($description, $this->attribute($document, 'meta[property="og:description"]', 'content'));

        $definition = $this->element($document, 'section.p-intro .p-def');
        self::assertSame(
            $this->text($definition, 'p.p-defq') . ' ' . $this->text($definition, 'p.p-defs'),
            $description,
            'La meta description ≠ la définition affichée (.p-defq + .p-defs).',
        );
    }

    /** Promesse : aucun emplacement de marque n'attend un script — sans JS, la page dit son nom, jamais « … ». */
    public function testEveryBrandSlotHoldsTheConfigBrand(): void
    {
        $brand = $this->config('brand');

        foreach ($this->pages() as $page) {
            $document = $this->parse($page);

            $slots = $document->querySelectorAll('.brand');
            self::assertGreaterThan(0, $slots->length, "Aucun .brand dans {$page} : le sélecteur ne lit plus rien.");
            foreach ($slots as $slot) {
                self::assertSame($brand, trim((string) $slot->textContent), ".brand de {$page}");
            }

            $labels = $document->querySelectorAll('[data-brand-label]');
            self::assertGreaterThan(0, $labels->length, "Aucun [data-brand-label] dans {$page} : le sélecteur ne lit plus rien.");
            foreach ($labels as $label) {
                self::assertSame($brand, $label->getAttribute('aria-label'), "aria-label de [data-brand-label] dans {$page}");
            }
        }
    }

    /**
     * Promesse : aucun script ne réécrit la marque ou le titre — le HTML servi reste la seule vérité.
     * Chaque script de `assets/` (le partagé `site.js` et ceux d'une page) est lu au même titre que
     * chaque page.
     */
    public function testNoScriptRewritesBrandOrTitle(): void
    {
        $forbidden = [
            'document.title =' => '/document\.title\s*=(?!=)/',
            'querySelectorAll(".brand")' => '/querySelectorAll\(\s*["\']\.brand["\']\s*\)/',
            'data-brand-jsonld' => '/data-brand-jsonld/',
        ];

        $files = $this->assetFiles('js');
        foreach ($this->pages() as $page) {
            $files[] = '/landing/' . $page;
        }
        foreach ($files as $file) {
            foreach ($forbidden as $label => $pattern) {
                // assertSame et non assertDoesNotMatch… : l'échec nomme le motif sans recopier toute la page.
                self::assertSame(0, preg_match($pattern, $this->read($file)), "{$label} dans {$file}");
            }
        }
    }

    /** Promesse : le balisage schema.org est lisible sans JS, valide, et dit la même chose que la page et config.js. */
    public function testJsonLdIsStaticValidAndCoherent(): void
    {
        $document = $this->parse('index.html');
        $json = $this->text($document, 'script[type="application/ld+json"]');
        self::assertNotSame('', $json, 'Le bloc JSON-LD est vide dans le HTML servi.');

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['@graph'] ?? null, 'Le JSON-LD doit porter un @graph.');

        $application = $this->nodeOfType($decoded['@graph'], 'SoftwareApplication');
        self::assertSame($this->config('brand'), $application['name'] ?? null);
        self::assertSame(
            $this->attribute($document, 'meta[name="description"]', 'content'),
            $application['description'] ?? null,
        );
    }

    /**
     * Promesse (D9, F9) : le balisage dit qui édite Amateo — une PERSONNE, celle de `config.js`
     * `editor`, et non une société qui n'existe pas encore ; le produit pointe vers elle.
     */
    public function testJsonLdPublisherIsThePersonOfConfig(): void
    {
        $decoded = json_decode($this->text($this->parse('index.html'), 'script[type="application/ld+json"]'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['@graph'] ?? null, 'Le JSON-LD doit porter un @graph.');

        $person = $this->nodeOfType($decoded['@graph'], 'Person');
        self::assertSame($this->config('editor'), $person['name'] ?? null, 'Person.name ≠ config.js editor.');
        self::assertSame($this->config('siteUrl') . '/qui-fait.html', $person['url'] ?? null, 'Person.url doit pointer « Qui fait ».');
        self::assertArrayNotHasKey('logo', $person, 'Une personne n\'a pas de logo (schema.org).');

        $application = $this->nodeOfType($decoded['@graph'], 'SoftwareApplication');
        self::assertIsArray($application['publisher'] ?? null);
        self::assertSame($person['@id'] ?? null, $application['publisher']['@id'] ?? null, 'publisher doit pointer le nœud Person.');

        foreach ($decoded['@graph'] as $node) {
            self::assertNotSame('Organization', \is_array($node) ? ($node['@type'] ?? null) : null, 'Aucun nœud Organization : aucune société n\'édite Amateo à ce jour.');
        }
    }

    /**
     * Promesse : chaque page désigne sa propre adresse publique comme adresse de référence, carte de
     * partage comprise, et porte une description (identique sur la carte de partage) — dérivées du
     * nom de fichier, jamais recopiées d'une autre page.
     */
    public function testCanonicalAndOgUrlAreDerivedFromFilename(): void
    {
        $siteUrl = $this->config('siteUrl');

        foreach ($this->pages() as $page) {
            $url = 'index.html' === $page ? $siteUrl . '/' : $siteUrl . '/' . $page;
            $document = $this->parse($page);
            self::assertSame($url, $this->attribute($document, 'link[rel="canonical"]', 'href'), "canonical de {$page}");
            self::assertSame($url, $this->attribute($document, 'meta[property="og:url"]', 'content'), "og:url de {$page}");

            $description = $this->attribute($document, 'meta[name="description"]', 'content');
            self::assertNotSame('', trim($description), "meta description vide dans {$page}");
            self::assertSame($description, $this->attribute($document, 'meta[property="og:description"]', 'content'), "og:description de {$page}");
        }
    }

    /**
     * Promesse : une adresse en dur ne pointe que chez nous ou vers un vocabulaire
     * (schema.org, sitemaps.org) — un domaine périmé ou un brouillon ne part pas aux moteurs, et
     * aucune feuille ni aucun script de `assets/` ne fait charger au visiteur une ressource tierce
     * (police, bibliothèque, image) qui recevrait son adresse IP.
     * `www.sitemaps.org` est la forme que porte l'espace de noms officiel d'un sitemap.
     */
    public function testAbsoluteUrlsStayOnOurHosts(): void
    {
        $allowed = [
            $this->host($this->config('siteUrl')),
            $this->host($this->config('appUrl')),
            $this->host($this->config('scriptUrl')),
            'schema.org',
            'sitemaps.org',
            'www.sitemaps.org',
        ];

        $files = ['/landing/robots.txt', '/landing/sitemap.xml', ...$this->assetFiles('css'), ...$this->assetFiles('js')];
        foreach ($this->pages() as $page) {
            $files[] = '/landing/' . $page;
        }

        $seen = 0;
        foreach ($files as $file) {
            preg_match_all('#https://[^\s"\'<>)\\\\]+#', $this->read($file), $matches);
            foreach ($matches[0] as $url) {
                ++$seen;
                self::assertContains($this->host($url), $allowed, "{$url} dans {$file}");
            }
        }
        self::assertGreaterThan(0, $seen, 'Aucune URL trouvée : le motif ne lit plus rien.');
    }

    /**
     * Promesse : les polices de la vitrine sont servies par nous et sous licence — chaque `@font-face`
     * de `assets/*.css` pointe un `.woff2` présent sous `assets/fonts/`, chaque `.woff2` a sa licence
     * OFL à côté, et aucune feuille ne charge rien d'ailleurs (`@import`, `url()` absolue ou `//hôte`) :
     * un visiteur ne livre jamais son IP à un service de polices.
     */
    public function testFontsAreSelfHostedWithTheirLicence(): void
    {
        $fontsDir = self::repositoryRoot() . '/landing/assets/fonts/';
        $referenced = [];
        foreach ($this->assetFiles('css') as $file) {
            $css = $this->read($file);
            self::assertSame(0, preg_match('/@import\b/i', $css), "{$file} : @import interdit (une feuille, un fichier).");

            preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css, $urls);
            foreach ($urls[1] as $url) {
                self::assertSame(0, preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url), "{$file} : url({$url}) ne pointe pas un fichier de la vitrine.");
            }

            preg_match_all('/@font-face\s*\{([^}]*)\}/i', $css, $faces);
            foreach ($faces[1] as $face) {
                self::assertSame(1, preg_match('/src:\s*url\(\s*["\']?fonts\/([a-z0-9-]+\.woff2)["\']?\s*\)\s*format\(\s*["\']woff2["\']\s*\)\s*(?:;|$)/i', $face, $source), "{$file} : un @font-face sans src unique vers fonts/*.woff2 — {$face}");
                self::assertFileExists($fontsDir . $source[1], "{$file} : police absente de assets/fonts/.");
                $referenced[] = $source[1];
            }
        }
        foreach (['archivo-latin.woff2', 'caveat-latin.woff2', 'bricolage-latin.woff2'] as $font) {
            self::assertContains($font, $referenced, "Police attendue non déclarée par un @font-face : {$font}.");
        }

        $fonts = glob($fontsDir . '*.woff2') ?: [];
        self::assertNotSame([], $fonts, 'Témoin : aucune police sous assets/fonts/.');
        foreach ($fonts as $font) {
            $family = explode('-', basename($font, '.woff2'))[0];
            self::assertNotSame([], glob($fontsDir . 'OFL-' . $family . '*.txt') ?: [], basename($font) . " : licence OFL-{$family}….txt absente à côté de la police.");
        }
    }

    /** Promesse : pas de balisage FAQPage — Google ne l'affiche plus pour un site comme le nôtre, il encombrerait la page. */
    public function testNoFaqPageMarkup(): void
    {
        $pages = $this->pages();
        self::assertGreaterThanOrEqual(6, \count($pages), 'Témoin : le balayage doit voir les pages de la vitrine.');
        foreach ($pages as $page) {
            self::assertFalse(str_contains($this->read('/landing/' . $page), 'FAQPage'), "FAQPage dans {$page}");
        }
    }

    /**
     * Promesse (D1) : un visiteur de retour ne mélange jamais un ancien fichier avec une page neuve —
     * TOUTE ressource locale (`script[src]`, `link[rel=stylesheet]`) de CHAQUE page porte le MÊME
     * `?v=` (Caddy n'envoie pas de Cache-Control) ; les scripts se chargent en fin de `<body>`, dans
     * l'ordre `config.js` → `assets/site.js` (qui le lit) → script de la page ; une page interactive
     * charge son propre script et sa propre feuille (`index.html` → `home`, `offres.html` → `offres`,
     * `faq.html` → `faq`).
     */
    public function testEveryPageSharesOneCacheBusterForEveryLocalScriptAndStylesheet(): void
    {
        $pageAssets = ['index.html' => 'home', 'offres.html' => 'offres', 'faq.html' => 'faq'];
        $versions = [];
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            $version = $this->versionedAsset($document, 'script[src]', 'src', 'config.js', $page);
            $this->versionedAsset($document, 'link[rel="stylesheet"][href]', 'href', 'assets/site.css', $page);
            $this->versionedAsset($document, 'script[src]', 'src', 'assets/site.js', $page);
            $versions[$page] = $version;

            $scripts = [];
            foreach (['script[src]' => 'src', 'link[rel="stylesheet"][href]' => 'href'] as $selector => $attribute) {
                foreach ($document->querySelectorAll($selector) as $element) {
                    $reference = (string) $element->getAttribute($attribute);
                    if (1 === preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $reference)) {
                        continue; // Hôte extérieur : gardé par testAbsoluteUrlsStayOnOurHosts.
                    }
                    self::assertSame(1, preg_match('#^/?([^?]+)\?v=([^&"\s]+)$#', $reference, $parts), "{$page} : {$reference} sans ?v=.");
                    self::assertSame($version, $parts[2], "{$page} : {$reference} ne porte pas le ?v= commun ({$version}).");
                    if ('src' === $attribute) {
                        self::assertSame('BODY', $element->parentElement?->tagName, "{$page} : {$reference} se charge en fin de <body>.");
                        $scripts[] = $parts[1];
                    }
                }
            }
            self::assertSame(['config.js', 'assets/site.js'], \array_slice($scripts, 0, 2), "{$page} : config.js puis assets/site.js, avant tout script de page.");

            if (isset($pageAssets[$page])) {
                $name = $pageAssets[$page];
                $this->versionedAsset($document, 'script[src]', 'src', "assets/{$name}.js", $page);
                $this->versionedAsset($document, 'link[rel="stylesheet"][href]', 'href', "assets/{$name}.css", $page);
            }
        }

        self::assertCount(1, array_unique($versions), 'Un seul ?v= pour toute la vitrine : ' . json_encode($versions, \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Promesse : la vitrine n'a qu'un thème, clair — aucune page ni aucun fichier de `assets/` ne pose
     * ou ne lit un thème (`data-theme`, bascule `[data-theme-toggle]`, `localStorage`,
     * `prefers-color-scheme`), et aucune variante `*-dark.*` ne reste servie sous `assets/`.
     */
    public function testNoDarkThemeAnywhere(): void
    {
        $files = [...$this->assetFiles('js'), ...$this->assetFiles('css')];
        foreach ($this->pages() as $page) {
            $files[] = '/landing/' . $page;
        }
        foreach ($files as $file) {
            $contents = $this->read($file);
            foreach (['data-theme', 'localStorage', 'prefers-color-scheme'] as $needle) {
                $this->assertAbsent($needle, $contents, "« {$needle} » dans {$file} : la vitrine n'a qu'un thème.");
            }
        }

        $dark = [];
        $assets = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::repositoryRoot() . '/landing/assets', FilesystemIterator::SKIP_DOTS));
        foreach ($assets as $asset) {
            if ($asset instanceof SplFileInfo && 1 === preg_match('/-dark\./', $asset->getFilename())) {
                $dark[] = $asset->getFilename();
            }
        }
        self::assertSame([], $dark, 'Variantes sombres encore servies sous landing/assets.');
    }

    /**
     * Promesse : aucune page ne porte de script ni de style inline (hors JSON-LD, qui est une donnée) —
     * tout vit dans `assets/*.js` et `assets/*.css`, chargés avec le `?v=` commun.
     */
    public function testNoInlineScriptOrStyle(): void
    {
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            foreach ($document->querySelectorAll('script:not([src])') as $script) {
                self::assertSame('application/ld+json', $script->getAttribute('type'), "{$page} : script inline (seul le JSON-LD reste dans la page).");
            }
            self::assertSame(0, $document->querySelectorAll('style')->length, "{$page} : <style> inline (les styles vivent dans assets/*.css).");
        }
    }

    /**
     * Promesse (D2) : aucun lien interne de la vitrine ne mène à une page ou une ancre absente — chaque
     * `href`/`src` relatif aboutit à un fichier de `landing/`, et chaque ancre à un `id` de sa page.
     */
    public function testInternalLinksResolveToTrackedFiles(): void
    {
        $seen = 0;
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            foreach (['a[href]' => 'href', 'link[href]' => 'href', 'img[src]' => 'src', 'script[src]' => 'src'] as $selector => $attribute) {
                foreach ($document->querySelectorAll($selector) as $element) {
                    $reference = (string) $element->getAttribute($attribute);
                    if ('' === $reference || '#' === $reference || 1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference)) {
                        continue; // https:, mailto: — gardés ailleurs (hôtes, CTA) ; « # » seul = haut de page.
                    }
                    ++$seen;
                    [$path, $fragment] = array_pad(explode('#', (string) preg_replace('/\?[^#]*/', '', $reference), 2), 2, '');
                    $target = match (true) {
                        '' === $path => $page,
                        '/' === $path => 'index.html',
                        default => ltrim($path, '/'),
                    };
                    self::assertFileExists(self::repositoryRoot() . '/landing/' . $target, "{$reference} dans {$page}");
                    if ('' !== $fragment && str_ends_with($target, '.html')) {
                        self::assertNotNull(
                            $this->parse($target)->getElementById($fragment),
                            "{$reference} dans {$page} : aucun id « {$fragment} » dans {$target}",
                        );
                    }
                }
            }
        }
        self::assertGreaterThan(20, $seen, 'Témoin : le balayage ne lit presque plus aucun lien interne.');
    }

    /**
     * Promesse (D2) : le pied de page est le même sur toutes les pages — on retrouve partout Produit,
     * À propos et Compte, avec les mêmes liens ; Offres et Questions fréquentes mènent à leurs pages.
     */
    public function testFooterIsIdenticalAcrossPages(): void
    {
        $brand = $this->config('brand');
        $home = $this->parse('index.html');
        $footer = $this->element($home, 'footer.site');
        $reference = $this->normalize((string) $footer->innerHTML);

        $text = $this->normalize((string) $footer->textContent);
        self::assertStringContainsString($brand . ' — le planning des clubs de basket. Actuellement en bêta avec des clubs pilotes.', $text);
        foreach (['Produit', 'À propos', 'Compte'] as $column) {
            self::assertStringContainsString($column, $text, "Colonne « {$column} » absente du pied.");
        }

        $links = [];
        foreach ($footer->querySelectorAll('a') as $link) {
            $links[$this->normalize((string) $link->textContent)] = $link;
        }
        $expected = [
            'Fonctionnalités' => 'fonctionnalites.html',
            'Offres' => 'offres.html',
            'Questions fréquentes' => 'faq.html',
            'Journal des évolutions' => 'evolutions.html',
            'Qui fait ' . $brand => 'qui-fait.html',
            'Vos données' => 'vos-donnees.html',
            'Mentions légales' => 'mentions-legales.html',
        ];
        foreach ($expected as $label => $href) {
            self::assertArrayHasKey($label, $links, "Lien « {$label} » absent du pied.");
            self::assertContains($links[$label]->getAttribute('href'), [$href, '/' . ltrim($href, '/')], "href de « {$label} » dans le pied");
        }
        foreach (['Contact' => 'demo-inline', 'Se connecter' => 'login', 'Essayer gratuitement' => 'register'] as $label => $cta) {
            self::assertArrayHasKey($label, $links, "Lien « {$label} » absent du pied.");
            self::assertSame($cta, $links[$label]->getAttribute('data-cta'), "« {$label} » du pied doit être un [data-cta={$cta}].");
        }

        foreach ($this->pages() as $page) {
            self::assertSame($reference, $this->normalize((string) $this->element($this->parse($page), 'footer.site')->innerHTML), "Le pied de {$page} diffère de celui de l'accueil.");
        }
    }

    /**
     * Promesse (D2) : le logotype ramène à l'adresse publique de l'accueil (`/`), et aucun lien ne
     * pointe `index.html` (une seconde URL du même contenu).
     */
    public function testHomeLinksUseTheRootPath(): void
    {
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            self::assertSame('/', $this->attribute($document, 'a.logotype', 'href'), "logotype de {$page}");
            foreach ($document->querySelectorAll('a[href]') as $link) {
                self::assertFalse(str_starts_with(ltrim((string) $link->getAttribute('href'), '/'), 'index.html'), "{$page} : lien vers index.html (« / » attendu).");
            }
        }
    }

    /**
     * Promesse (D2, C1) : où qu'il arrive, le visiteur retrouve le même en-tête — logotype vers `/`,
     * la barre et le burger natif (`details.nav-menu`, ouvrable sans JS) proposent les six mêmes
     * entrées dans l'ordre validé, chacune vers sa page ; seul `aria-current="page"` change d'une
     * page à l'autre. Le logotype remplace « Accueil ».
     */
    public function testHeaderIsIdenticalAndNamesTheSixEntries(): void
    {
        $expected = [
            'Comment ça marche' => ['href', '/#fonctionnement'],
            'Fonctionnalités' => ['href', 'fonctionnalites.html'],
            'Offres' => ['href', 'offres.html'],
            'FAQ' => ['href', 'faq.html'],
            'Se connecter' => ['data-cta', 'login'],
            'Essayer gratuitement' => ['data-cta', 'register'],
        ];
        $home = $this->element($this->parse('index.html'), 'header.site');
        $reference = $this->headerWithoutCurrentPage($home);

        foreach ($this->pages() as $page) {
            $header = $this->element($this->parse($page), 'header.site');
            self::assertNotNull($header->querySelector('a.logotype[href="/"]'), "{$page} : logotype vers / absent de l'en-tête.");
            self::assertNotNull($header->querySelector('nav.top details.nav-menu'), "{$page} : burger natif absent de l'en-tête.");

            foreach (['nav.top > a', 'details.nav-menu .nav-panel > a'] as $selector) {
                $links = iterator_to_array($header->querySelectorAll($selector));
                $labels = array_map(fn (Element $link): string => $this->normalize((string) $link->textContent), $links);
                self::assertSame(array_keys($expected), $labels, "{$page} : entrées de « {$selector} »");
                foreach ($links as $index => $link) {
                    [$attribute, $value] = $expected[$labels[$index]];
                    $actual = (string) $link->getAttribute($attribute);
                    self::assertSame($value, 'href' === $attribute && !str_starts_with($value, '/') ? ltrim($actual, '/') : $actual, "{$page} : {$attribute} de « {$labels[$index]} » ({$selector})");
                }
            }
            foreach ($header->querySelectorAll('a') as $link) {
                self::assertNotSame('Accueil', $this->normalize((string) $link->textContent), "{$page} : le logotype remplace « Accueil ».");
            }

            self::assertSame($reference, $this->headerWithoutCurrentPage($header), "L'en-tête de {$page} diffère de celui de l'accueil (seul aria-current peut changer).");
        }
    }

    /**
     * Promesse (P4-407) : un visiteur sans JS peut s'inscrire, se connecter, demander une démo et lire
     * la politique de confidentialité — chaque `[data-cta]` porte son `href` EN CLAIR dans le HTML,
     * égal à ce que `config.js` donne (aucun `href="#"` qui attendrait un script), et aucun bouton de
     * maquette (`[data-mock]`, qui n'ouvre rien) ne reste sur une page servie.
     */
    public function testCtaHrefsAreWrittenInClearAndMatchConfig(): void
    {
        $appUrl = $this->config('appUrl');
        $brand = $this->config('brand');
        $mailto = 'mailto:' . $this->config('contactEmail')
            . '?subject=' . $this->encodeUriComponent('Demande de demo - ' . $brand)
            . '&body=' . $this->encodeUriComponent("Bonjour,\n\nJe souhaite voir " . $brand . " en demo pour mon club.\n\nClub : \nNombre d'equipes (environ) : \nDisponibilites : ");
        $expected = [
            'register' => $appUrl . '/register',
            'login' => $appUrl . '/login',
            'privacy' => $appUrl . '/confidentialite',
            'demo' => $mailto,
            'demo-inline' => $mailto,
            'home' => '/',
        ];

        $seen = [];
        foreach ($this->pages() as $page) {
            self::assertNull($this->parse($page)->querySelector('[data-mock]'), "{$page} : un bouton de maquette [data-mock] reste sur la page.");
            foreach ($this->parse($page)->querySelectorAll('[data-cta]') as $cta) {
                $kind = (string) $cta->getAttribute('data-cta');
                self::assertArrayHasKey($kind, $expected, "{$page} : data-cta « {$kind} » inconnu.");
                self::assertSame($expected[$kind], $cta->getAttribute('href'), "{$page} : href de [data-cta={$kind}]");
                $seen[$kind] = true;
            }
        }
        foreach (['register', 'login', 'privacy', 'demo', 'demo-inline'] as $kind) {
            self::assertArrayHasKey($kind, $seen, "Témoin : aucun [data-cta={$kind}] lu sur la vitrine.");
        }
    }

    /**
     * Promesse (P4-276, D1) : la mesure d'audience ne part que de la vraie vitrine — l'injection Umami
     * vit UNE fois, dans `assets/site.js` (chargé par chaque page), sous la double garde : clés
     * `analytics` non vides ET hôte courant = hôte de `siteUrl`. Aucune page n'en porte une copie.
     */
    public function testEveryPageInjectsAnalyticsUnderTheDoubleGuard(): void
    {
        $script = $this->read('/landing/assets/site.js');
        foreach (['analytics', 'scriptUrl', 'websiteId', 'data-website-id', 'location.hostname', 'siteUrl', 'new URL('] as $needle) {
            self::assertStringContainsString($needle, $script, "assets/site.js : « {$needle} » absent de l'injection Umami gardée.");
        }

        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            self::assertNotNull($document->querySelector('script[src*="assets/site.js?v="]'), "{$page} ne charge pas assets/site.js : pas de mesure d'audience.");
            self::assertFalse(str_contains($this->read('/landing/' . $page), 'data-website-id'), "{$page} : l'injection Umami ne vit que dans assets/site.js.");
        }
    }

    /**
     * Promesse (corrections SMITH 3) : un `config.js` absent (404) ou périmé en cache ne casse ni le
     * thème, ni le burger, ni le glissement d'ancre — `assets/site.js` lit `window.LANDING_CONFIG`
     * toujours sous garde (`|| {}`) et isole chacun de ses blocs dans son propre try/catch.
     * Garde STATIQUE (aucun moteur JS en CI pour `landing/`) : elle tient la forme, la preuve de
     * comportement est le banc navigateur « config.js coupé » de la passe visuelle.
     */
    public function testSharedScriptSurvivesAMissingConfig(): void
    {
        $script = $this->read('/landing/assets/site.js');
        self::assertGreaterThan(0, substr_count($script, 'LANDING_CONFIG'), 'Témoin : assets/site.js ne lit plus LANDING_CONFIG.');
        self::assertSame(
            0,
            preg_match_all('/window\.LANDING_CONFIG(?!\s*\|\|)/', $script),
            'assets/site.js lit window.LANDING_CONFIG sans garde : écrire « window.LANDING_CONFIG || {} ».',
        );
        self::assertGreaterThanOrEqual(
            4,
            preg_match_all('/\btry\s*\{/', $script),
            'assets/site.js : un try/catch par bloc (injection, thème, burger, ancres) — un bloc qui casse n\'emporte pas les autres.',
        );
    }

    /**
     * Promesse (C8) : un visiteur qui demande moins de mouvement l'obtient — chaque script de
     * `assets/` qui anime (`requestAnimationFrame`, `setInterval`, `.animate(`, `IntersectionObserver`)
     * lit `prefers-reduced-motion` ; le script de l'accueil (compte à rebours, scène) en fait partie.
     * Garde de FORME : le comportement (compte à rebours figé, scène sans transition) se prouve par
     * les captures « mouvement réduit » de la passe visuelle.
     */
    public function testAnimatedScriptsHonourReducedMotion(): void
    {
        $animated = [];
        foreach ($this->assetFiles('js') as $file) {
            $script = $this->read($file);
            if (1 !== preg_match('/requestAnimationFrame|setInterval|\.animate\(|IntersectionObserver/', $script)) {
                continue;
            }
            $animated[] = $file;
            self::assertStringContainsString('prefers-reduced-motion', $script, "{$file} anime sans lire prefers-reduced-motion.");
        }
        self::assertContains('/landing/assets/home.js', $animated, 'Témoin : le script de l\'accueil (compte à rebours, scène) n\'est pas vu comme animé.');
    }

    /**
     * Le `?v=` d'une ressource partagée chargée par la page — exactement une occurrence.
     */
    private function versionedAsset(HTMLDocument $document, string $selector, string $attribute, string $file, string $page): string
    {
        $versions = [];
        foreach ($document->querySelectorAll($selector) as $element) {
            if (1 === preg_match('#^/?' . preg_quote($file, '#') . '\?v=([^&"\s]+)$#', (string) $element->getAttribute($attribute), $matches)) {
                $versions[] = $matches[1];
            }
        }
        self::assertCount(1, $versions, "{$page} doit charger {$file}?v=… exactement une fois.");

        return $versions[0];
    }

    /** Balisage de l'en-tête, `aria-current` retiré (le seul écart admis d'une page à l'autre), espaces repliés. */
    private function headerWithoutCurrentPage(Element $header): string
    {
        $copy = $header->cloneNode(true);
        self::assertInstanceOf(Element::class, $copy);
        foreach ($copy->querySelectorAll('[aria-current]') as $current) {
            $current->removeAttribute('aria-current');
        }

        return $this->normalize((string) $copy->innerHTML);
    }

    /** Équivalent PHP d'`encodeURIComponent` (JS ne code pas ! ' ( ) *). */
    private function encodeUriComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%27' => '\'', '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    /**
     * @param array<mixed> $graph
     *
     * @return array<mixed>
     */
    private function nodeOfType(array $graph, string $type): array
    {
        foreach ($graph as $node) {
            if (\is_array($node) && $type === ($node['@type'] ?? null)) {
                return $node;
            }
        }
        self::fail("Aucun nœud {$type} dans le @graph.");
    }
}
