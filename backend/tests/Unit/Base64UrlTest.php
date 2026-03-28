<?php

namespace Tests\Unit;

use App\Support\Base64Url;
use PHPUnit\Framework\TestCase;

class Base64UrlTest extends TestCase
{
    public function test_encode_matches_rfc_7515_style_base64url_without_padding(): void
    {
        $encoded = Base64Url::encode("\xfb\xef");

        $this->assertSame('--8', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    public function test_decode_reverses_encode(): void
    {
        $data = random_bytes(64);

        $encoded = Base64Url::encode($data);
        $decoded = Base64Url::decode($encoded);

        $this->assertSame($data, $decoded);
    }
}
