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
 *
 * Lecture statique des fichiers suivis (un crawl réel ne tourne pas en CI) : le HTML est
 * parsé SANS exécuter ses scripts, exactement la vue d'un crawler sans JS. Le robots.txt et
 * le sitemap sont gardés à côté, par `Security/IndexationPolicyTest`.
 */
#[Group('phase1')]
final class LandingDiscoverabilityTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** La page d'accueil et les mentions légales : les deux seules pages de la vitrine. */
    private const PAGES = ['index.html', 'mentions-legales.html'];

    /** Promesse : le nom du produit ouvre chaque titre, dans le HTML servi, carte de partage comprise. */
    public function testTitlesStartWithTheConfigBrand(): void
    {
        $brand = $this->config('brand');
        $expected = [
            'index.html' => $brand . ' — Le planning d\'entraînements des clubs de basket',
            'mentions-legales.html' => $brand . ' — Mentions légales',
        ];

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

        foreach (self::PAGES as $page) {
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

    /** Promesse : aucun script ne réécrit la marque ou le titre — le HTML servi reste la seule vérité. */
    public function testNoScriptRewritesBrandOrTitle(): void
    {
        $forbidden = [
            'document.title =' => '/document\.title\s*=(?!=)/',
            'querySelectorAll(".brand")' => '/querySelectorAll\(\s*["\']\.brand["\']\s*\)/',
            'data-brand-jsonld' => '/data-brand-jsonld/',
        ];

        foreach (self::PAGES as $page) {
            foreach ($forbidden as $label => $pattern) {
                // assertSame et non assertDoesNotMatch… : l'échec nomme le motif sans recopier toute la page.
                self::assertSame(0, preg_match($pattern, $this->read('/landing/' . $page)), "{$label} dans {$page}");
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
        self::assertSame($this->config('editor'), $this->nodeOfType($decoded['@graph'], 'Organization')['name'] ?? null);
    }

    /** Promesse : chaque page désigne sa propre adresse publique comme adresse de référence. */
    public function testCanonicalMatchesSiteUrl(): void
    {
        $siteUrl = $this->config('siteUrl');
        $expected = [
            'index.html' => $siteUrl . '/',
            'mentions-legales.html' => $siteUrl . '/mentions-legales.html',
        ];

        foreach ($expected as $page => $url) {
            self::assertSame($url, $this->attribute($this->parse($page), 'link[rel="canonical"]', 'href'), "canonical de {$page}");
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
        foreach (glob(self::ROOT . '/landing/*.html') ?: [] as $path) {
            $files[] = '/landing/' . basename($path);
        }
        self::assertContains('/landing/index.html', $files, 'Le balayage ne voit plus les pages de la vitrine.');

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
        foreach (self::PAGES as $page) {
            self::assertFalse(str_contains($this->read('/landing/' . $page), 'FAQPage'), "FAQPage dans {$page}");
        }
    }

    private function parse(string $page): HTMLDocument
    {
        return HTMLDocument::createFromString($this->read('/landing/' . $page), \LIBXML_NOERROR, 'UTF-8');
    }

    /** Texte d'un élément, espaces normalisés (un h1 peut être replié sur plusieurs lignes). */
    private function text(HTMLDocument $document, string $selector): string
    {
        $element = $document->querySelector($selector);
        self::assertNotNull($element, "Élément absent : {$selector}");

        return trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
    }

    private function attribute(HTMLDocument $document, string $selector, string $attribute): string
    {
        $value = $document->querySelector($selector)?->getAttribute($attribute);
        self::assertIsString($value, "Attribut {$attribute} absent : {$selector}");

        return $value;
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

    /** Valeur d'une clé de `landing/config.js`, lue par regex : la vitrine n'a aucun build. */
    private function config(string $key): string
    {
        $matched = preg_match('/^\s*' . preg_quote($key, '/') . ':\s*"([^"]+)"/m', $this->read('/landing/config.js'), $matches);
        self::assertSame(1, $matched, "Clé {$key} introuvable dans landing/config.js");

        return $matches[1];
    }

    private function host(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        self::assertIsString($host, "URL sans hôte : {$url}");

        return $host;
    }

    private function read(string $relativePath): string
    {
        $path = self::ROOT . $relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "Fichier introuvable : {$path}");

        return $contents;
    }
}
