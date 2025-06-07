<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

class SaveImageTest extends TestCase {
    public function testRejectsInvalidBase64() {
        $res = anp_save_image('invalid');
        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('Invalid image format', $res->get_error_message());
    }

    public function testRejectsOversizeImage() {
        $data = random_bytes(6 * 1024 * 1024); // 6 MB
        $b64  = 'data:image/png;base64,' . base64_encode($data);
        $res = anp_save_image($b64);
        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('Image exceeds 5 MB limit', $res->get_error_message());
    }
}
