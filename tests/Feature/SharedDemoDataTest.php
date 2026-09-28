<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SharedDemoDataTest extends TestCase
{
    /**
     * Kiểm tra lệnh demo:verify chạy thành công và đạt tất cả tiêu chí.
     */
    public function test_demo_verify_command_passes(): void
    {
        $exitCode = Artisan::call('demo:verify');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, "Lệnh demo:verify thất bại với output:\n{$output}");
        $this->assertStringContainsString('Demo data verification passed.', $output);
    }
}
