<?php

namespace Tests\Unit;

use App\Services\Inventory\BrandExtractor;
use App\Services\Inventory\ProductDescriptionCleaner;
use App\Services\Inventory\ProductDescriptionFormatter;
use PHPUnit\Framework\TestCase;

class ProductDescriptionCleanerTest extends TestCase
{
    public function test_it_removes_mercadolibre_footer_from_first_asterisk_block(): void
    {
        $cleaner = new ProductDescriptionCleaner;

        $raw = "Licra Short con almohadilla unisex\nMarca: Rockbros\n\n*******************************************************************************************************\nUBICACIÓN\nLECHERÍA";

        $this->assertSame("Licra Short con almohadilla unisex\nMarca: Rockbros", $cleaner->clean($raw));
    }

    public function test_it_strips_html_tags(): void
    {
        $cleaner = new ProductDescriptionCleaner;

        $this->assertSame('Texto limpio', $cleaner->clean('<p>Texto <strong>limpio</strong></p>'));
    }

    public function test_brand_extractor_reads_brand_attribute(): void
    {
        $extractor = new BrandExtractor;

        $brand = $extractor->extract(
            ['BRAND' => 'Rockbros', 'MODEL' => 'RK1008BL'],
            'BRAND: Rockbros; MODEL: RK1008BL',
        );

        $this->assertSame('Rockbros', $brand);
    }

    public function test_description_formatter_builds_html_lists_and_paragraphs(): void
    {
        $formatter = new ProductDescriptionFormatter;

        $html = $formatter->toHtml("Intro del producto\n\nMarca: Rockbros\n- Item uno\n- Item dos");

        $this->assertStringContainsString('<p>Intro del producto</p>', $html);
        $this->assertStringContainsString('<strong>Marca:</strong> Rockbros', $html);
        $this->assertStringContainsString('<ul><li>Item uno</li><li>Item dos</li></ul>', $html);
    }
}
