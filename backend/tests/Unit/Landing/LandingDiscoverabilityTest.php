<?php

declare(strict_types=1);

namespace App\Tests\Unit\Landing;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-391 — promesse : la vitrine se présente sous son vrai nom et son vrai sujet à qui ne
 * lance pas JavaScript (crawler de moteur, aperçu de messagerie, IA) : tout ce qu'un
 * moteur doit lire est ÉCRIT dans le HTML servi, et reste cohérent avec `landing/config.js`.
 * P4-522 — la vitrine a six pages qui partagent `assets/site.css` et `assets/site.js` : chaque
 * page (`landing/*.html`, balayée — aucune liste à tenir) est sous ces gardes, ses liens
 * internes aboutissent, ses boutons d'appel marchent sans JS (P4-407).
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
            'fonctionnalites.html' => $brand . ' — Ce qu\'' . $brand . ' fait, et ce qu\'il ne fait pas',
            'index.html' => $brand . ' — Planning d\'entraînement et matchs FBI des clubs de basket',
            'mentions-legales.html' => $brand . ' — Mentions légales',
            'qui-fait.html' => $brand . ' — Qui fait ' . $brand,
            'vos-donnees.html' => $brand . ' — Vos données',
        ];
        self::assertSame(array_keys($expected), $this->pages(), 'Les pages servies ≠ les six pages validées (une page sans titre validé, ou une page manquante).');

        foreach ($expected as $page => $title) {
            $document = $this->parse($page);
            self::assertSame($title, $this->text($document, 'title'), "<title> de {$page}");
            self::assertSame($title, $this->attribute($document, 'meta[property="og:title"]', 'content'), "og:title de {$page}");
        }
    }

    /** Promesse : le titre visible nomme le basket, dans la phrase validée par le fondateur. */
    public function testHeroHeadlineIsTheFounderSentence(): void
    {
        self::assertSame(
            'Le planning de votre club de basket, sans le casse-tête.',
            $this->text($this->parse('index.html'), 'h1'),
        );
    }

    /** Promesse : la description lue par les moteurs porte les mots que cherche un club, partage compris. */
    public function testMetaDescriptionNamesBasketAmateurAndFbi(): void
    {
        $document = $this->parse('index.html');
        $description = $this->attribute($document, 'meta[name="description"]', 'content');

        foreach (['basket', 'amateur', 'FBI', 'FFBB'] as $word) {
            self::assertStringContainsString($word, $description, 'Mot attendu dans la meta description.');
        }
        self::assertSame($description, $this->attribute($document, 'meta[property="og:description"]', 'content'));
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
     * Le script partagé `assets/site.js` est lu au même titre que chaque page.
     */
    public function testNoScriptRewritesBrandOrTitle(): void
    {
        $forbidden = [
            'document.title =' => '/document\.title\s*=(?!=)/',
            'querySelectorAll(".brand")' => '/querySelectorAll\(\s*["\']\.brand["\']\s*\)/',
            'data-brand-jsonld' => '/data-brand-jsonld/',
        ];

        $files = ['/landing/assets/site.js'];
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
     * (schema.org, sitemaps.org) — un domaine périmé ou un brouillon ne part pas aux moteurs.
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

        $files = ['/landing/robots.txt', '/landing/sitemap.xml'];
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

    /** Promesse : pas de balisage FAQPage — Google ne l'affiche plus pour un site comme le nôtre, il encombrerait la page. */
    public function testNoFaqPageMarkup(): void
    {
        $pages = $this->pages();
        self::assertGreaterThanOrEqual(6, \count($pages), 'Témoin : le balayage doit voir les six pages de la vitrine.');
        foreach ($pages as $page) {
            self::assertFalse(str_contains($this->read('/landing/' . $page), 'FAQPage'), "FAQPage dans {$page}");
        }
    }

    /**
     * Promesse (D1) : un visiteur de retour ne mélange jamais un ancien fichier partagé avec une page
     * neuve — `config.js`, `assets/site.css` et `assets/site.js` sont chargés par CHAQUE page avec
     * le MÊME `?v=` (Caddy n'envoie pas de Cache-Control), le script partagé APRÈS `config.js`, en
     * fin de `<body>`.
     */
    public function testEveryPageSharesOneCacheBusterForConfigStylesheetAndScript(): void
    {
        $versions = [];
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            $config = $this->versionedAsset($document, 'script[src]', 'src', 'config.js', $page);
            $stylesheet = $this->versionedAsset($document, 'link[rel="stylesheet"][href]', 'href', 'assets/site.css', $page);
            $script = $this->versionedAsset($document, 'script[src]', 'src', 'assets/site.js', $page);
            self::assertSame([$config], array_values(array_unique([$config, $stylesheet, $script])), "{$page} : config.js, site.css et site.js doivent porter le même ?v=.");
            $versions[$page] = $config;

            $source = $this->read('/landing/' . $page);
            $configAt = strpos($source, 'config.js?v=');
            $scriptAt = strpos($source, 'assets/site.js?v=');
            self::assertIsInt($configAt);
            self::assertIsInt($scriptAt);
            self::assertLessThan($scriptAt, $configAt, "{$page} : site.js doit être chargé APRÈS config.js (il le lit).");

            $scriptElement = $this->element($document, 'script[src*="assets/site.js?v="]');
            self::assertSame('BODY', $scriptElement->parentElement?->tagName, "{$page} : site.js se charge en fin de <body>.");
        }

        self::assertCount(1, array_unique($versions), 'Un seul ?v= pour toute la vitrine : ' . json_encode($versions, \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Promesse (D1) : le thème choisi s'applique AVANT le premier rendu (aucun flash clair) — le
     * mini-script anti-flash reste inline dans `<head>`, avant la feuille partagée ; c'est le SEUL
     * script inline d'une page (tout le reste vit une fois, dans `assets/site.js`).
     */
    public function testThemeAntiFlashStaysInlineBeforeTheStylesheet(): void
    {
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            $head = $document->head;
            self::assertNotNull($head, "Pas de <head> dans {$page}");

            $antiFlashAt = null;
            $stylesheetAt = null;
            $position = 0;
            foreach ($this->childElements($head) as $child) {
                ++$position;
                if ('SCRIPT' === $child->tagName && !$child->hasAttribute('src') && str_contains((string) $child->textContent, 'data-theme')) {
                    $antiFlashAt ??= $position;
                }
                if ('LINK' === $child->tagName && str_contains((string) $child->getAttribute('href'), 'assets/site.css')) {
                    $stylesheetAt ??= $position;
                }
            }
            self::assertNotNull($antiFlashAt, "{$page} : script anti-flash de thème absent du <head>.");
            self::assertNotNull($stylesheetAt, "{$page} : <link> vers assets/site.css absent du <head>.");
            self::assertLessThan($stylesheetAt, $antiFlashAt, "{$page} : l'anti-flash doit précéder la feuille partagée.");

            $inline = 0;
            foreach ($document->querySelectorAll('script:not([src])') as $script) {
                if ('application/ld+json' !== $script->getAttribute('type')) {
                    ++$inline;
                }
            }
            self::assertSame(1, $inline, "{$page} : seul l'anti-flash reste inline ; le reste vit dans assets/site.js.");
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
     * Promesse (D2) : le pied de page est le même sur les six pages — on retrouve partout Produit,
     * À propos et Compte, avec les mêmes liens.
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
            'Offres' => '/#offres',
            'Questions fréquentes' => '/#faq',
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
     * Promesse (D2) : le logotype et « Accueil » ramènent à l'adresse publique de l'accueil (`/`),
     * jamais à `index.html` (une seconde URL du même contenu) ; les pages sœurs gardent la bascule de
     * thème, sans burger.
     */
    public function testHomeLinksUseTheRootPath(): void
    {
        foreach ($this->pages() as $page) {
            $document = $this->parse($page);
            self::assertSame('/', $this->attribute($document, 'a.logotype', 'href'), "logotype de {$page}");
            foreach ($document->querySelectorAll('a[href]') as $link) {
                self::assertFalse(str_starts_with((string) $link->getAttribute('href'), 'index.html'), "{$page} : lien vers index.html (« / » attendu).");
            }
            self::assertNotNull($document->querySelector('[data-theme-toggle]'), "{$page} : bascule de thème absente.");
            if ('index.html' === $page) {
                continue;
            }

            $homeLinks = [];
            foreach ($this->element($document, 'header.site')->querySelectorAll('a') as $link) {
                if ('Accueil' === $this->normalize((string) $link->textContent)) {
                    $homeLinks[] = $link->getAttribute('href');
                }
            }
            self::assertSame(['/'], $homeLinks, "{$page} : un lien « Accueil » vers / attendu dans l'en-tête.");
            self::assertNull($document->querySelector('details.nav-menu'), "{$page} : une page sœur n'a pas de burger.");
        }
    }

    /** Promesse (D2) : la barre de l'accueil et son panneau mobile proposent les six mêmes entrées, dans l'ordre validé. */
    public function testHomeNavigationNamesTheSixEntries(): void
    {
        $expected = ['Comment ça marche', 'Fonctionnalités', 'Offres', 'FAQ', 'Se connecter', 'Essayer gratuitement'];
        $document = $this->parse('index.html');

        foreach (['nav.top > a', '.nav-panel > a'] as $selector) {
            $labels = [];
            foreach ($document->querySelectorAll($selector) as $link) {
                $labels[] = $this->normalize((string) $link->textContent);
            }
            self::assertSame($expected, $labels, "Entrées de « {$selector} »");
        }
        foreach ($document->querySelectorAll('nav.top a') as $link) {
            if ('Fonctionnalités' === $this->normalize((string) $link->textContent)) {
                self::assertContains($link->getAttribute('href'), ['fonctionnalites.html', '/fonctionnalites.html']);
            }
        }
    }

    /**
     * Promesse (P4-407) : un visiteur sans JS peut s'inscrire, se connecter, demander une démo et lire
     * la politique de confidentialité — chaque `[data-cta]` porte son `href` EN CLAIR dans le HTML,
     * égal à ce que `config.js` donne (aucun `href="#"` qui attendrait un script).
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
