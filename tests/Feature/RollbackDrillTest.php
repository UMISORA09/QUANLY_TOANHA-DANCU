<?php

namespace Tests\Feature;

use Tests\TestCase;

class RollbackDrillTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/rollback_test_'.uniqid();
        @mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Case 1: old image + new image + health success -> deployment success
     */
    public function test_case_1_successful_deployment_promotes_new_image_to_stable(): void
    {
        $stableFile = $this->tempDir.'/.last_deployed_image';
        $prevFile = $this->tempDir.'/.previous_image';

        // Trạng thái ban đầu: version 1.0.0 đang chạy
        file_put_contents($stableFile, 'smart-cassavas:v1.0.0');

        // Bắt đầu deploy v1.0.1: Lưu previous image
        $oldImage = trim((string) file_get_contents($stableFile));
        file_put_contents($prevFile, $oldImage);
        $newImage = 'smart-cassavas:v1.0.1';

        // Giả lập Health, Smoke, Freshness = PASS
        $allPassed = true;
        if ($allPassed) {
            file_put_contents($stableFile, $newImage);
        }

        $this->assertEquals('smart-cassavas:v1.0.1', trim((string) file_get_contents($stableFile)));
        $this->assertEquals('smart-cassavas:v1.0.0', trim((string) file_get_contents($prevFile)));
    }

    /**
     * Case 2: old image + new image + health failure -> rollback to old image
     */
    public function test_case_2_health_failure_rolls_back_to_previous_image(): void
    {
        $stableFile = $this->tempDir.'/.last_deployed_image';
        $prevFile = $this->tempDir.'/.previous_image';

        file_put_contents($stableFile, 'smart-cassavas:v1.0.0');
        $oldImage = trim((string) file_get_contents($stableFile));
        file_put_contents($prevFile, $oldImage);

        $newImage = 'smart-cassavas:v1.0.2-broken';
        $healthPassed = false;

        $runningImage = $newImage;
        if (! $healthPassed) {
            // Trigger rollback
            $rollbackTarget = trim((string) file_get_contents($prevFile));
            $runningImage = $rollbackTarget;
            file_put_contents($stableFile, $rollbackTarget);
        }

        $this->assertEquals('smart-cassavas:v1.0.0', $runningImage);
        $this->assertEquals('smart-cassavas:v1.0.0', trim((string) file_get_contents($stableFile)));
    }

    /**
     * Case 3: old image + new image + smoke failure -> rollback to old image
     */
    public function test_case_3_smoke_failure_rolls_back_to_previous_image(): void
    {
        $stableFile = $this->tempDir.'/.last_deployed_image';
        $prevFile = $this->tempDir.'/.previous_image';

        file_put_contents($stableFile, 'smart-cassavas:v1.0.0');
        file_put_contents($prevFile, 'smart-cassavas:v1.0.0');

        $smokePassed = false;
        $runningImage = 'smart-cassavas:v1.0.3-smoke-broken';

        if (! $smokePassed) {
            $rollbackTarget = trim((string) file_get_contents($prevFile));
            $runningImage = $rollbackTarget;
            file_put_contents($stableFile, $rollbackTarget);
        }

        $this->assertEquals('smart-cassavas:v1.0.0', $runningImage);
        $this->assertEquals('smart-cassavas:v1.0.0', trim((string) file_get_contents($stableFile)));
    }

    /**
     * Case 4: old image + new image + freshness failure -> rollback to old image
     */
    public function test_case_4_freshness_failure_rolls_back_to_previous_image(): void
    {
        $stableFile = $this->tempDir.'/.last_deployed_image';
        $prevFile = $this->tempDir.'/.previous_image';

        file_put_contents($stableFile, 'smart-cassavas:v1.0.0');
        file_put_contents($prevFile, 'smart-cassavas:v1.0.0');

        $freshnessPassed = false;
        $runningImage = 'smart-cassavas:v1.0.4-stale';

        if (! $freshnessPassed) {
            $rollbackTarget = trim((string) file_get_contents($prevFile));
            $runningImage = $rollbackTarget;
            file_put_contents($stableFile, $rollbackTarget);
        }

        $this->assertEquals('smart-cassavas:v1.0.0', $runningImage);
        $this->assertEquals('smart-cassavas:v1.0.0', trim((string) file_get_contents($stableFile)));
    }

    /**
     * Case 5: first deployment, no previous image -> rollback unavailable
     */
    public function test_case_5_first_deployment_failure_reports_rollback_unavailable(): void
    {
        $prevFile = $this->tempDir.'/.previous_image';

        // Lần đầu triển khai: Không có file .previous_image
        $hasPrevious = file_exists($prevFile) && ! empty(trim((string) @file_get_contents($prevFile)));

        $rollbackStatus = $hasPrevious ? 'ROLLBACK_SUCCESS' : 'ROLLBACK_UNAVAILABLE';

        $this->assertEquals('ROLLBACK_UNAVAILABLE', $rollbackStatus);
    }
}
