<?php

namespace Tests\Unit;

use App\Services\Export\ProductXmlFlatFields;
use PHPUnit\Framework\TestCase;

class ProductXmlFlatFieldsTest extends TestCase
{
    public function test_categories_joined_by_comma(): void
    {
        $text = (new ProductXmlFlatFields)->categoriesText([
            ['path' => 'Hogar > Cerraduras', 'segments' => [], 'depth' => 2],
            ['path' => 'Herramientas > Llaves', 'segments' => [], 'depth' => 2],
        ]);

        $this->assertSame('Hogar > Cerraduras, Herramientas > Llaves', $text);
    }

    public function test_dimensions_extracted_from_attribute_codes(): void
    {
        $dimensions = (new ProductXmlFlatFields)->dimensions([
            ['code' => 'WIDTH', 'label' => 'Ancho', 'value' => '18 cm'],
            ['code' => 'HEIGHT', 'label' => 'Alto', 'value' => '28 cm'],
            ['code' => 'WEIGHT', 'label' => 'Peso', 'value' => '350 g'],
            ['code' => 'BRAND', 'label' => 'Marca', 'value' => 'Test'],
        ]);

        $this->assertSame('18 cm', $dimensions['width']);
        $this->assertSame('28 cm', $dimensions['height']);
        $this->assertSame('350 g', $dimensions['weight']);
    }

    public function test_dimension_fields_removed_from_attributes_xml_list(): void
    {
        $filtered = (new ProductXmlFlatFields)->attributesForXml([
            ['code' => 'WIDTH', 'label' => 'Ancho', 'value' => '10 cm'],
            ['code' => 'BRAND', 'label' => 'Marca', 'value' => 'Greka'],
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('BRAND', $filtered[0]['code']);
    }
}
