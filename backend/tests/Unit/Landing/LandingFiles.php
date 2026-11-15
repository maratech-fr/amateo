<?php

declare(strict_types=1);

namespace App\Tests\Unit\Landing;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Lecture statique des fichiers suivis de `landing/` — la vue d'un crawler sans JS : le HTML est
 * parsé SANS exécuter ses scripts. Partagée par les gardes de la vitrine (zéro build : aucune
 * autre source de vérité que les fichiers servis tels quels).
 */
trait LandingFiles
{
    /** Racine du dépôt (ce fichier vit dans `backend/tests/Unit/Landing/`). */
    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * Toutes les pages servies : `landing/*.html`, triées. Une page ajoutée entre d'office sous
     * les gardes — aucune liste à tenir à la main.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        $pages = [];
        foreach (glob(self::repositoryRoot() . '/landing/*.html') ?: [] as $path) {
            $pages[] = basename($path);
        }
        sort($pages);
        self::assertContains('index.html', $pages, 'Le balayage ne voit plus les pages de la vitrine.');

        return $pages;
    }

    /**
     * Les scripts (`js`) ou feuilles (`css`) servis sous `landing/assets/`, chemins relatifs au dépôt,
     * triés — le commun (`site.*`) et ceux d'une page (`home.*`, `offres.*`…), balayés comme les pages.
     *
     * @return list<string>
     */
    private function assetFiles(string $extension): array
    {
        $files = [];
        foreach (glob(self::repositoryRoot() . '/landing/assets/*.' . $extension) ?: [] as $path) {
            $files[] = '/landing/assets/' . basename($path);
        }
        sort($files);
        self::assertContains('/landing/assets/site.' . $extension, $files, "Le balayage ne voit plus assets/site.{$extension}.");

        return $files;
    }

    private function parse(string $page): HTMLDocument
    {
        return HTMLDocument::createFromString($this->read('/landing/' . $page), \LIBXML_NOERROR, 'UTF-8');
    }

    private function element(HTMLDocument|Element $scope, string $selector): Element
    {
        $element = $scope->querySelector($selector);
        self::assertNotNull($element, "Élément absent : {$selector}");

        return $element;
    }

    /**
     * Enfants ÉLÉMENTS directs, dans l'ordre du document (`Dom\Element::$children` n'existe pas en PHP 8.4).
     *
     * @return list<Element>
     */
    private function childElements(Element $parent): array
    {
        $children = [];
        for ($child = $parent->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
            $children[] = $child;
        }

        return $children;
    }

    /** Texte d'un élément, espaces normalisés (un h1 peut être replié sur plusieurs lignes). */
    private function text(HTMLDocument|Element $scope, string $selector): string
    {
        return $this->normalize((string) $this->element($scope, $selector)->textContent);
    }

    private function attribute(HTMLDocument|Element $scope, string $selector, string $attribute): string
    {
        $value = $scope->querySelector($selector)?->getAttribute($attribute);
        self::assertIsString($value, "Attribut {$attribute} absent : {$selector}");

        return $value;
    }

    /**
     * Texte visible du `<body>` d'une page : scripts et styles retirés, puis chaque sélecteur de
     * `$without` retiré à son tour (ex. la FAQ, qui garde les mots de l'écran).
     *
     * @param list<string> $without
     */
    private function visibleText(string $page, array $without = []): string
    {
        $document = $this->parse($page);
        $body = $document->body;
        self::assertNotNull($body, "Pas de <body> dans {$page}");
        foreach (['script', 'style', 'noscript', ...$without] as $selector) {
            foreach (iterator_to_array($body->querySelectorAll($selector)) as $node) {
                $node->remove();
            }
        }

        return $this->normalize((string) $body->textContent);
    }

    /**
     * `$needle` absent de `$haystack` — l'échec cite l'extrait fautif, jamais toute la page.
     */
    private function assertAbsent(string $needle, string $haystack, string $message): void
    {
        $at = mb_strpos($haystack, $needle);
        self::assertFalse(
            $at,
            false === $at ? $message : $message . ' — « …' . mb_substr($haystack, max(0, $at - 60), mb_strlen($needle) + 120) . '… »',
        );
    }

    /** Espaces repliés (sauts de ligne, insécables compris) — compare le TEXTE, jamais la mise en forme. */
    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
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
        $path = self::repositoryRoot() . $relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "Fichier introuvable : {$path}");

        return $contents;
    }
}
