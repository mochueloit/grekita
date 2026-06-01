<?php

namespace App\Services\Inventory;

class ProductDescriptionFormatter
{
    /**
     * Convierte texto plano limpio a HTML listo para WordPress.
     */
    public function toHtml(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $blocks = [];
        $paragraph = [];
        $listItems = [];

        $flushParagraph = function () use (&$blocks, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }

            $blocks[] = [
                'type' => 'paragraph',
                'text' => implode(' ', $paragraph),
            ];
            $paragraph = [];
        };

        $flushList = function () use (&$blocks, &$listItems): void {
            if ($listItems === []) {
                return;
            }

            $blocks[] = [
                'type' => 'list',
                'items' => $listItems,
            ];
            $listItems = [];
        };

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                $flushParagraph();
                $flushList();

                continue;
            }

            if (preg_match('/^[-•*]\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                $listItems[] = $matches[1];

                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                $listItems[] = $matches[1];

                continue;
            }

            if (preg_match('/^([A-Za-zÁÉÍÓÚáéíóúÑñ\s\/]+):\s*(.+)$/', $line, $matches) === 1
                && mb_strlen(trim($matches[1])) <= 35) {
                $flushParagraph();
                $flushList();
                $blocks[] = [
                    'type' => 'kv',
                    'key' => trim($matches[1]),
                    'value' => trim($matches[2]),
                ];

                continue;
            }

            $flushList();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushList();

        return implode("\n", array_map(fn (array $block): string => match ($block['type']) {
            'paragraph' => '<p>'.e($block['text']).'</p>',
            'list' => '<ul>'.implode('', array_map(
                fn (string $item): string => '<li>'.e($item).'</li>',
                $block['items'],
            )).'</ul>',
            'kv' => '<p><strong>'.e($block['key']).':</strong> '.e($block['value']).'</p>',
            default => '',
        }, $blocks));
    }
}
