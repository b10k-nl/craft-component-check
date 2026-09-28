<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\services\MarkerToken;
use PHPUnit\Framework\TestCase;

class MarkerTokenTest extends TestCase
{
    private const KEY = 'test-security-key';

    public function testRoundTrip(): void
    {
        $token = MarkerToken::create(self::KEY, 1000, 60);
        $this->assertTrue(MarkerToken::verify($token, self::KEY, 1000));
        $this->assertTrue(MarkerToken::verify($token, self::KEY, 1060));
    }

    public function testExpires(): void
    {
        $token = MarkerToken::create(self::KEY, 1000, 60);
        $this->assertFalse(MarkerToken::verify($token, self::KEY, 1061));
    }

    public function testWrongKeyOrTamperingFails(): void
    {
        $token = MarkerToken::create(self::KEY, 1000, 60);
        $this->assertFalse(MarkerToken::verify($token, 'other-key', 1000));

        // Pushing the expiry forward invalidates the signature.
        [, $sig] = explode('.', $token, 2);
        $this->assertFalse(MarkerToken::verify('99999999.' . $sig, self::KEY, 1000));
    }

    public function testGarbageIsRejected(): void
    {
        foreach ([null, '', 'abc', '123', 'x.y', '.', '1000.'] as $bad) {
            $this->assertFalse(MarkerToken::verify($bad, self::KEY, 0), var_export($bad, true));
        }
    }

    public function testEmptyKeyNeverVerifies(): void
    {
        $token = MarkerToken::create(self::KEY, 1000, 60);
        $this->assertFalse(MarkerToken::verify($token, '', 1000));

        $this->expectException(\InvalidArgumentException::class);
        MarkerToken::create('', 1000);
    }
}
