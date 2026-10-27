<?php

namespace Tests\Feature;

use App\Models\SatisfactionSurvey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SatisfactionSurveyConcurrencyTest extends TestCase
{
    public function test_two_workers_with_same_version_commit_one_update_and_one_audit(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires real MySQL row locks.');
        }
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(str_contains($database, '_test') || str_contains($database, '_qa'), 'Requires an isolated QA database.');
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');
        $survey = SatisfactionSurvey::create([
            'survey_code' => 'QA-CONCURRENCY-'.Str::random(10), 'title' => 'Before concurrent update',
            'start_date' => today()->toDateString(), 'end_date' => today()->addDay()->toDateString(),
            'is_active' => true, 'created_by' => $user->id, 'version' => 1,
        ]);
        $barrier = tempnam(sys_get_temp_dir(), 'survey-occ-');
        unlink($barrier);
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'DB_URL' => false, 'DATABASE_URL' => false, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ];
        $workers = [];
        try {
            foreach ([0, 1] as $number) {
                $workers[] = new Process([PHP_BINARY, '-r', <<<'PHP'
                    require "vendor/autoload.php";
                    $app = require "bootstrap/app.php";
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    $survey = App\Models\SatisfactionSurvey::findOrFail($argv[1]);
                    $user = App\Models\User::findOrFail($argv[2]);
                    file_put_contents($argv[3].".ready.".$argv[4], "ready");
                    $deadline = microtime(true) + 20;
                    while (!is_file($argv[3])) {
                        if (microtime(true) > $deadline) { fwrite(STDERR, "Barrier timeout"); exit(1); }
                        usleep(10000);
                    }
                    try {
                        app(App\Services\SatisfactionSurveyService::class)->updateSurvey($survey,
                            ["version" => 1, "title" => "Worker ".$argv[4]], $user);
                        echo json_encode(["status" => 200, "title" => "Worker ".$argv[4]]);
                    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                        echo json_encode(["status" => $exception->getStatusCode()]);
                    } catch (Throwable $exception) {
                        fwrite(STDERR, get_class($exception)); exit(1);
                    }
                    PHP,
                    $survey->id, $user->id, $barrier, (string) $number,
                ], base_path(), $environment, timeout: 30);
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $deadline = microtime(true) + 20;
            while ((! is_file($barrier.'.ready.0') || ! is_file($barrier.'.ready.1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.ready.0', 'First worker must preload version 1.');
            $this->assertFileExists($barrier.'.ready.1', 'Second worker must preload version 1.');
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(2, $survey->fresh()->version);
            $winner = collect($results)->firstWhere('status', 200);
            $this->assertSame($winner['title'], $survey->fresh()->title);
            $this->assertSame(1, DB::table('audit_logs')->where('record_id', $survey->id)->where('action', 'SURVEY_UPDATED')->count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$barrier, $barrier.'.ready.0', $barrier.'.ready.1'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            DB::table('audit_logs')->where('record_id', $survey->id)->delete();
            $survey->delete();
            $user->roles()->detach();
            $user->forceDelete();
        }
    }
}
