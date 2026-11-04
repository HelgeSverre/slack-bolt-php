<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit\Internal;

use Thunder\Bolt\BlockKit\Block;

/** @internal Shared constraints for nested blocks and their enclosing surfaces. */
final class BlockRules
{
    public static function text(string $value, int $maximum, string $field): void
    {
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException("{$field} needs between 1 and {$maximum} characters.");
        }
    }

    /** @param list<string> $allowed */
    public static function choice(string $value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid {$field}: {$value}.");
        }
    }

    /**
     * @param list<Block> $blocks
     * @param list<string> $allowed
     */
    public static function children(array $blocks, array $allowed): void
    {
        foreach ($blocks as $block) {
            $data = $block->toArray();
            if (!in_array($data['type'] ?? null, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported child block type.');
            }
            self::walk($data, 1);
        }
    }

    /**
     * @param list<array<array-key, mixed>> $blocks
     * @return list<array<array-key, mixed>>
     */
    public static function flatten(array $blocks): array
    {
        $result = [];
        foreach ($blocks as $block) {
            $result = [...$result, ...self::walk($block, 0)];
        }
        return $result;
    }

    /** @param list<array<array-key, mixed>> $blocks */
    public static function message(array $blocks, bool $countBlocks = true): void
    {
        $flat = self::flatten($blocks);
        if ($countBlocks && count($flat) > 50) {
            throw new \InvalidArgumentException('A message accepts at most 50 blocks including nested blocks.');
        }
        $charts = 0;
        $characters = 0;
        foreach ($flat as $block) {
            if (($block['type'] ?? null) === 'data_visualization') {
                $charts++;
            }
            if (($block['type'] ?? null) === 'data_table' && is_array($block['rows'] ?? null)) {
                foreach ($block['rows'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ($row as $cell) {
                        if (is_array($cell)) {
                            $characters += self::cellCharacters($cell);
                        }
                    }
                }
            }
        }
        if ($charts > 2 || $characters > 20000) {
            throw new \InvalidArgumentException(
                'A message supports at most 2 charts and 20000 data-table cell characters.',
            );
        }
    }

    /** @param array<array-key, mixed> $cell */
    public static function cellCharacters(array $cell): int
    {
        if (($cell['type'] ?? null) === 'action_cell') {
            $element = $cell['element'] ?? [];
            $text = is_array($element) ? $element['text'] ?? [] : [];
            return is_array($text) && is_string($text['text'] ?? null) ? mb_strlen($text['text']) : 0;
        }
        $count = 0;
        foreach ($cell as $key => $value) {
            if ($key === 'text' && is_string($value)) {
                $count += mb_strlen($value);
            } elseif (is_array($value)) {
                $count += self::cellCharacters($value);
            }
        }
        return $count;
    }

    /**
     * @param array<array-key, mixed> $block
     * @return list<array<array-key, mixed>>
     */
    private static function walk(array $block, int $depth): array
    {
        $type = $block['type'] ?? null;
        if ($type !== 'container' && $type !== 'layout') {
            return [$block];
        }
        if ($depth >= 2) {
            throw new \InvalidArgumentException('Layout/container nesting is limited to two levels.');
        }
        $children = [];
        if ($type === 'container' && is_array($block['child_blocks'] ?? null)) {
            $children = $block['child_blocks'];
        } elseif ($type === 'layout' && is_array($block['rows'] ?? null)) {
            foreach ($block['rows'] as $row) {
                if (!is_array($row) || !is_array($row['items'] ?? null)) {
                    continue;
                }
                foreach ($row['items'] as $item) {
                    if (is_array($item) && is_array($item['block'] ?? null)) {
                        $children[] = $item['block'];
                    }
                }
            }
        }
        $result = [$block];
        foreach ($children as $child) {
            if (is_array($child)) {
                $result = [...$result, ...self::walk($child, $depth + 1)];
            }
        }
        return $result;
    }
}
