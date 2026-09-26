<?php

namespace b10k\componentregression\tests\Unit;

use b10k\componentregression\services\ActivationPolicy;
use PHPUnit\Framework\TestCase;

class ActivationPolicyTest extends TestCase
{
    public function testAutoFollowsAllowAdminChanges(): void
    {
        $this->assertSame('full', ActivationPolicy::resolve('', true));
        $this->assertSame('off', ActivationPolicy::resolve('', false));
    }

    public function testExplicitModeWins(): void
    {
        // Staging/CI: admin changes off, but tests are wanted.
        $this->assertSame('full', ActivationPolicy::resolve('full', false));
        $this->assertSame('readonly', ActivationPolicy::resolve(' ReadOnly ', false));
        // A developer can switch it off locally too.
        $this->assertSame('off', ActivationPolicy::resolve('off', true));
    }

    public function testUnknownValueFallsBackToAuto(): void
    {
        $this->assertSame('off', ActivationPolicy::resolve('yes please', false));
        $this->assertSame('full', ActivationPolicy::resolve('true', true));
    }

    public function testAllows(): void
    {
        $this->assertTrue(ActivationPolicy::allows('full', 'readonly'));
        $this->assertTrue(ActivationPolicy::allows('readonly', 'readonly'));
        $this->assertFalse(ActivationPolicy::allows('readonly', 'full'));
        $this->assertFalse(ActivationPolicy::allows('off', 'readonly'));
        $this->assertFalse(ActivationPolicy::allows('full', 'nonsense'));
    }

    public function testExplainMentionsHowToEnable(): void
    {
        $this->assertStringContainsString('config/component-regression.php', ActivationPolicy::explain('', false));
        $this->assertStringContainsString('explicitly', ActivationPolicy::explain('readonly', false));
    }
}
