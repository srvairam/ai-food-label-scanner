<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

class OpenAIExtractTest extends TestCase {
    public function testReturnsDefaultsWithoutApiKey() {
        $out = anp_openai_extract_after_clean('nutrition text');
        $this->assertSame([
            'product_name'=> null,
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => '',
            'alternative' => '',
        ], $out);
    }
}
