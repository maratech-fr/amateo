<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidRichDocException;

/**
 * Le corps riche d'une note du journal de nouveautés (JSON ProseMirror produit par l'éditeur de la
 * console) — liste blanche en FORME FERMÉE, parce que la note est rendue chez TOUS les clubs.
 *
 * Accepté : `doc` (blocs non vides) ; blocs `paragraph`, `heading` (niveau 3 exactement),
 * `bulletList`/`orderedList` (≤ 3 niveaux, `start` entier 1–9999, `type` seulement à null et jamais
 * stocké) ; `listItem` (un paragraphe d'abord, puis paragraphes ou sous-listes — jamais un titre) ;
 * en ligne `text` (non vide) et `hardBreak` ; marques `bold`, `italic`, `noteColor` (5 couleurs
 * nommées). Toute autre clé, tout autre type, une marque en double, une liste qui n'en est pas une →
 * refus. La valeur rendue est RECONSTRUITE des seules clés connues (les marques d'un `hardBreak`
 * sont retirées). Bornes : 500 nœuds, 10 000 points de code de texte cumulé, caractères de
 * contrôle et bidi refusés (`TextSanity`).
 *
 * Le schéma de l'éditeur (`frontend/src/features/admin/releaseNoteEditor/editorExtensions.ts`) ne
 * produit que cette forme : fixtures croisées `CrossStack/ReleaseNoteRichDocEditorFixturesTest`.
 */
final readonly class ReleaseNoteRichDoc
{
    public const array NODE_TYPES = ['doc', 'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'text', 'hardBreak'];

    public const array MARK_TYPES = ['bold', 'italic', 'noteColor'];

    public const array COLORS = ['rouge', 'orange', 'vert', 'bleu', 'gris'];

    public const int HEADING_LEVEL = 3;

    public const int MAX_LIST_DEPTH = 3;

    public const int MAX_NODES = 500;

    public const int MAX_TEXT = 10000;

    public const int MAX_RAW_BYTES = 65536;

    private const string INVALID = 'Le contenu mis en forme est invalide.';

    private const string TOO_DEEP = 'Les listes ne peuvent pas dépasser 3 niveaux.';

    private const string TOO_MANY_NODES = 'Le contenu contient trop d\'éléments (500 au plus).';

    private const string TOO_LONG = 'Le contenu dépasse 10 000 caractères.';

    private const string FORBIDDEN_CHARS = 'Le contenu contient des caractères non autorisés.';

    private const array TOP_BLOCKS = ['paragraph', 'heading', 'bulletList', 'orderedList'];

    private const array ITEM_FOLLOWERS = ['paragraph', 'bulletList', 'orderedList'];

    private const int MAX_ORDERED_START = 9999;

    public function __construct(private TextSanity $sanity = new TextSanity) {}

    /**
     * @throws InvalidRichDocException message lisible par la superadmin
     *
     * @return array<string, mixed> le document reconstruit des seules clés connues
     */
    public function validate(mixed $doc): array
    {
        $tally = ['nodes' => 0, 'text' => 0];
        $node = $this->node($doc, ['doc'], ['type', 'content'], ['type', 'content'], $tally);

        return ['type' => 'doc', 'content' => $this->blocks($node['content'], self::TOP_BLOCKS, 0, $tally, true)];
    }

    /**
     * Miroir texte brut (`body`, lu par un ancien bundle) : titres et paragraphes sur leur ligne,
     * saut de ligne → `\n`, puce `- `, numéro `N. ` (depuis `start`), sous-liste indentée de 2 espaces.
     *
     * @param array<string, mixed> $doc un document déjà validé
     */
    public function toPlainText(array $doc): string
    {
        $lines = [];
        foreach ($this->children($doc) as $block) {
            $this->blockLines($block, 0, $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string>                 $types    types acceptés à cette place
     * @param list<string>                 $allowed  clés permises
     * @param list<string>                 $required clés obligatoires
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function node(mixed $raw, array $types, array $allowed, array $required, array &$tally): array
    {
        if (!$this->isObject($raw) || !\is_string($raw['type'] ?? null) || !\in_array($raw['type'], $types, true)) {
            throw new InvalidRichDocException(self::INVALID);
        }
        foreach (array_keys($raw) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new InvalidRichDocException(self::INVALID);
            }
        }
        foreach ($required as $key) {
            if (!\array_key_exists($key, $raw)) {
                throw new InvalidRichDocException(self::INVALID);
            }
        }
        if (++$tally['nodes'] > self::MAX_NODES) {
            throw new InvalidRichDocException(self::TOO_MANY_NODES);
        }

        return $raw;
    }

    /**
     * @param list<string>                 $types
     * @param array{nodes: int, text: int} $tally
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(mixed $content, array $types, int $listDepth, array &$tally, bool $nonEmpty): array
    {
        $out = [];
        foreach ($this->list($content, $nonEmpty) as $raw) {
            $out[] = $this->block($raw, $types, $listDepth, $tally);
        }

        return $out;
    }

    /**
     * @param list<string>                 $types
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function block(mixed $raw, array $types, int $listDepth, array &$tally): array
    {
        $type = $this->isObject($raw) && \is_string($raw['type'] ?? null) ? $raw['type'] : null;

        return match ($type) {
            'paragraph' => $this->textBlock($this->node($raw, $types, ['type', 'content'], ['type'], $tally), $tally),
            'heading' => $this->heading($this->node($raw, $types, ['type', 'attrs', 'content'], ['type', 'attrs'], $tally), $tally),
            'bulletList', 'orderedList' => $this->listBlock($raw, (string) $type, $types, $listDepth, $tally),
            default => throw new InvalidRichDocException(self::INVALID),
        };
    }

    /**
     * @param array<string, mixed>         $node
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function heading(array $node, array &$tally): array
    {
        $attrs = $node['attrs'];
        if (!$this->isObject($attrs) || ['level'] !== array_keys($attrs) || self::HEADING_LEVEL !== $attrs['level']) {
            throw new InvalidRichDocException(self::INVALID);
        }

        return ['type' => 'heading', 'attrs' => ['level' => self::HEADING_LEVEL], ...$this->inlineContent($node, $tally)];
    }

    /**
     * @param array<string, mixed>         $node
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function textBlock(array $node, array &$tally): array
    {
        return ['type' => 'paragraph', ...$this->inlineContent($node, $tally)];
    }

    /**
     * @param list<string>                 $types
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function listBlock(mixed $raw, string $type, array $types, int $listDepth, array &$tally): array
    {
        $depth = $listDepth + 1;
        if ($depth > self::MAX_LIST_DEPTH) {
            throw new InvalidRichDocException(self::TOO_DEEP);
        }
        $ordered = 'orderedList' === $type;
        $node = $this->node($raw, $types, $ordered ? ['type', 'attrs', 'content'] : ['type', 'content'], ['type', 'content'], $tally);

        $items = [];
        foreach ($this->list($node['content'], true) as $item) {
            $items[] = $this->listItem($item, $depth, $tally);
        }
        if (!$ordered) {
            return ['type' => 'bulletList', 'content' => $items];
        }

        return ['type' => 'orderedList', 'attrs' => ['start' => $this->orderedStart($node['attrs'] ?? [])], 'content' => $items];
    }

    /** `start` entier 1–9999 (défaut 1) ; une clé `type` n'est admise qu'à null (forme de l'éditeur). */
    private function orderedStart(mixed $attrs): int
    {
        if (!$this->isObject($attrs) || [] !== array_diff(array_keys($attrs), ['start', 'type'])) {
            throw new InvalidRichDocException(self::INVALID);
        }
        if (\array_key_exists('type', $attrs) && null !== $attrs['type']) {
            throw new InvalidRichDocException(self::INVALID);
        }
        $start = $attrs['start'] ?? 1;
        if (!\is_int($start) || $start < 1 || $start > self::MAX_ORDERED_START) {
            throw new InvalidRichDocException(self::INVALID);
        }

        return $start;
    }

    /**
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function listItem(mixed $raw, int $listDepth, array &$tally): array
    {
        $node = $this->node($raw, ['listItem'], ['type', 'content'], ['type', 'content'], $tally);
        $children = $this->list($node['content'], true);

        $content = [$this->block(array_shift($children), ['paragraph'], $listDepth, $tally)];
        foreach ($children as $child) {
            $content[] = $this->block($child, self::ITEM_FOLLOWERS, $listDepth, $tally);
        }

        return ['type' => 'listItem', 'content' => $content];
    }

    /**
     * Le contenu en ligne d'un paragraphe ou d'un titre, omis quand il est vide.
     *
     * @param array<string, mixed>         $node
     * @param array{nodes: int, text: int} $tally
     *
     * @return array{content?: list<array<string, mixed>>}
     */
    private function inlineContent(array $node, array &$tally): array
    {
        if (!\array_key_exists('content', $node)) {
            return [];
        }
        $inline = [];
        foreach ($this->list($node['content'], false) as $raw) {
            $inline[] = $this->inline($raw, $tally);
        }

        return [] === $inline ? [] : ['content' => $inline];
    }

    /**
     * @param array{nodes: int, text: int} $tally
     *
     * @return array<string, mixed>
     */
    private function inline(mixed $raw, array &$tally): array
    {
        $type = $this->isObject($raw) && \is_string($raw['type'] ?? null) ? $raw['type'] : null;
        if ('hardBreak' === $type) {
            $node = $this->node($raw, ['hardBreak'], ['type', 'marks'], ['type'], $tally);
            // Les marques d'un saut de ligne sont validées puis RETIRÉES : il ne s'affiche pas.
            if (\array_key_exists('marks', $node)) {
                $this->marks($node['marks']);
            }

            return ['type' => 'hardBreak'];
        }

        $node = $this->node($raw, ['text'], ['type', 'text', 'marks'], ['type', 'text'], $tally);
        $text = $node['text'];
        if (!\is_string($text) || '' === $text) {
            throw new InvalidRichDocException(self::INVALID);
        }
        if ($this->sanity->hasForbiddenChars($text)) {
            throw new InvalidRichDocException(self::FORBIDDEN_CHARS);
        }
        $tally['text'] += mb_strlen($text);
        if ($tally['text'] > self::MAX_TEXT) {
            throw new InvalidRichDocException(self::TOO_LONG);
        }
        $marks = \array_key_exists('marks', $node) ? $this->marks($node['marks']) : [];

        return [] === $marks ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /** @return list<array<string, mixed>> */
    private function marks(mixed $raw): array
    {
        $out = [];
        $seen = [];
        foreach ($this->list($raw, false) as $mark) {
            if (!$this->isObject($mark) || !\is_string($mark['type'] ?? null) || \in_array($mark['type'], $seen, true)) {
                throw new InvalidRichDocException(self::INVALID);
            }
            $seen[] = $mark['type'];
            $out[] = match ($mark['type']) {
                'bold', 'italic' => ['type' => ['type'] === array_keys($mark) ? $mark['type'] : throw new InvalidRichDocException(self::INVALID)],
                'noteColor' => ['type' => 'noteColor', 'attrs' => ['name' => $this->colorName($mark)]],
                default => throw new InvalidRichDocException(self::INVALID),
            };
        }

        return $out;
    }

    /** @param array<string, mixed> $mark */
    private function colorName(array $mark): string
    {
        $attrs = $mark['attrs'] ?? null;
        // Clés comparées en ENSEMBLE : JSONB (et tout client) peut les ordonner autrement.
        if (2 !== \count($mark) || !$this->isObject($attrs) || ['name'] !== array_keys($attrs)) {
            throw new InvalidRichDocException(self::INVALID);
        }
        $name = $attrs['name'];
        if (!\is_string($name) || !\in_array($name, self::COLORS, true)) {
            throw new InvalidRichDocException(self::INVALID);
        }

        return $name;
    }

    /** @return list<mixed> */
    private function list(mixed $raw, bool $nonEmpty): array
    {
        if (!\is_array($raw) || !array_is_list($raw) || ($nonEmpty && [] === $raw)) {
            throw new InvalidRichDocException(self::INVALID);
        }

        return $raw;
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private function isObject(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string>         $lines
     */
    private function blockLines(array $block, int $level, array &$lines): void
    {
        $type = $block['type'] ?? null;
        if ('bulletList' === $type || 'orderedList' === $type) {
            $this->listLines($block, $level, $lines);

            return;
        }
        $lines[] = str_repeat('  ', $level) . $this->inlineText($block);
    }

    /**
     * @param array<string, mixed> $list
     * @param list<string>         $lines
     */
    private function listLines(array $list, int $level, array &$lines): void
    {
        $ordered = 'orderedList' === ($list['type'] ?? null);
        $attrs = $list['attrs'] ?? [];
        $number = $ordered && \is_array($attrs) && \is_int($attrs['start'] ?? null) ? $attrs['start'] : 1;
        $indent = str_repeat('  ', $level);

        foreach ($this->children($list) as $item) {
            $marker = $ordered ? $number++ . '. ' : '- ';
            foreach ($this->children($item) as $index => $child) {
                if (0 === $index) {
                    $lines[] = $indent . $marker . $this->inlineText($child);
                } else {
                    $this->blockLines($child, $level + 1, $lines);
                }
            }
        }
    }

    /** @param array<string, mixed> $block */
    private function inlineText(array $block): string
    {
        $text = '';
        foreach ($this->children($block) as $inline) {
            $text .= 'hardBreak' === ($inline['type'] ?? null) ? "\n" : (\is_string($inline['text'] ?? null) ? $inline['text'] : '');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    private function children(array $node): array
    {
        $content = $node['content'] ?? [];

        return \is_array($content) ? array_values(array_filter($content, \is_array(...))) : [];
    }
}
