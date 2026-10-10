<?php

namespace Tests\Unit;

use App\Services\LoginClientContext;
use PHPUnit\Framework\TestCase;

class LoginClientContextTest extends TestCase
{
    public function test_it_describes_common_browser_and_operating_system(): void
    {
        $context = new LoginClientContext;

        $this->assertSame(
            'Chrome 152 on Windows 10 or later',
            $context->describeAgent('Mozilla/5.0 (Windows NT 10.0) Chrome/152.0.0.0'),
        );
    }
}
