<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Env;
use PHPUnit\Framework\TestCase;

class EnvCandidatesTest extends TestCase
{
    public function testLoadParsesEnvFileCorrectly(): void
    {
        $tempEnv = sys_get_temp_dir() . '/test_' . uniqid() . '.env';
        file_put_contents($tempEnv, "TEST_WAYKI_KEY=secure_value_123\nTEST_PORT=9999\n");

        Env::load($tempEnv);
        unlink($tempEnv);

        $this->assertSame('secure_value_123', Env::get('TEST_WAYKI_KEY'));
        $this->assertSame('9999', Env::get('TEST_PORT'));
    }

    public function testCandidatePriorityResolvesFirstExisting(): void
    {
        $tempOuter = sys_get_temp_dir() . '/outer_' . uniqid() . '.env';
        $tempInner = sys_get_temp_dir() . '/inner_' . uniqid() . '.env';

        file_put_contents($tempOuter, "ENV_PRIORITY_TEST=outer_cpanel\n");
        file_put_contents($tempInner, "ENV_PRIORITY_TEST=inner_public\n");

        $candidates = [
            sys_get_temp_dir() . '/non_existent_' . uniqid() . '.env',
            $tempOuter,
            $tempInner,
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                Env::load($candidate);
                break;
            }
        }

        unlink($tempOuter);
        unlink($tempInner);

        $this->assertSame('outer_cpanel', Env::get('ENV_PRIORITY_TEST'));
    }
}
