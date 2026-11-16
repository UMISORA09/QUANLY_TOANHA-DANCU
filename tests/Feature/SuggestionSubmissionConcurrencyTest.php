<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\SatisfactionSurvey;
use App\Models\Suggestion;
use App\Models\SuggestionBox;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SuggestionSubmissionConcurrencyTest extends TestCase
{
    public function test_two_workers_do_not_duplicate_anonymous_identified_or_survey_submissions(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $user = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('SUPER_ADMIN');
        $block = (string) Str::uuid();
        $floor = (string) Str::uuid();
        $apartment = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $block, 'block_code' => 'QA-'.Str::random(8), 'block_name' => 'Concurrency QA']);
        DB::table('floors')->insert(['id' => $floor, 'block_id' => $block, 'floor_number' => 1, 'floor_code' => 'QA1', 'floor_name' => 'QA']);
        DB::table('apartments')->insert(['id' => $apartment, 'block_id' => $block, 'floor_id' => $floor,
            'apartment_number' => 'QA-'.Str::random(8), 'gross_floor_area_sqm' => 80, 'net_usable_area_sqm' => 70]);
        Resident::factory()->create(['user_id' => $user->id, 'apartment_id' => $apartment]);
        $box = SuggestionBox::create(['box_name' => 'QA concurrency', 'block_id' => $block, 'is_open' => true]);
        $survey = SatisfactionSurvey::create(['survey_code' => 'QA-'.Str::random(12), 'title' => 'Concurrent responses',
            'target_block_id' => $block, 'start_date' => today(), 'end_date' => today()->addDay(), 'is_active' => true, 'created_by' => $manager->id]);
        $question = SurveyQuestion::create(['survey_id' => $survey->id, 'question_order' => 1, 'question_text' => 'Rate',
            'question_type' => SurveyQuestion::TYPE_RATING_1_TO_5, 'is_required' => true]);
        try {
            foreach ([false, true] as $anonymous) {
                $payload = ['box_id' => $box->id, 'topic_category' => 'VE_SINH', 'title' => 'Concurrent submission',
                    'content_text' => 'Two workers submit exactly the same content.', 'is_anonymous' => $anonymous, 'idempotency_key' => (string) Str::uuid()];
                $results = $this->workers($user->id, 'suggestion', $payload);
                $this->assertSame([200, 200], array_column($results, 'status'));
                $this->assertSame($results[0]['id'], $results[1]['id']);
                $this->assertSame(1, count(array_filter(array_column($results, 'replayed'))));
                $id = $results[0]['id'];
                $this->assertSame(1, DB::table('audit_logs')->where('record_id', $id)->count());
                $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $manager->id)->where('deep_link_url', 'like', '%'.$id.'%')->count());
                if ($anonymous) {
                    $this->assertNull(Suggestion::findOrFail($id)->resident_user_id);
                    $this->assertNull(Suggestion::findOrFail($id)->apartment_id);
                }
            }
            $results = $this->workers($user->id, 'survey', ['survey_id' => $survey->id, 'answers' => [$question->id => 5]]);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(1, $survey->responses()->count());
            $this->assertSame(1, DB::table('survey_response_details')->where('question_id', $question->id)->count());
        } finally {
            $ids = Suggestion::where('box_id', $box->id)->pluck('id');
            DB::table('audit_logs')->whereIn('record_id', $ids)->delete();
            foreach ($ids as $id) {
                DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$id.'%')->delete();
            }
            Suggestion::where('box_id', $box->id)->delete();
            $box->delete();
            DB::table('survey_response_details')->where('question_id', $question->id)->delete();
            $survey->responses()->delete();
            $survey->questions()->delete();
            $survey->delete();
            DB::table('residents')->where('user_id', $user->id)->delete();
            DB::table('apartments')->where('id', $apartment)->delete();
            DB::table('floors')->where('id', $floor)->delete();
            DB::table('blocks')->where('id', $block)->delete();
            $manager->roles()->detach();
            $user->forceDelete();
            $manager->forceDelete();
        }
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function workers(string $userId, string $action, array $payload): array
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'DB_URL' => false, 'DATABASE_URL' => false, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $barrier = tempnam(sys_get_temp_dir(), 'submission-race-');
        unlink($barrier);
        $workers = [];
        try {
            foreach ([0, 1] as $number) {
                $workers[] = new Process([PHP_BINARY, '-r', <<<'PHP'
                    require "vendor/autoload.php";
                    $app = require "bootstrap/app.php";
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    config(["services.gemini.key" => null, "services.openai.key" => null]);
                    Illuminate\Support\Facades\Http::preventStrayRequests();
                    $user = App\Models\User::findOrFail($argv[1]);
                    $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
                    file_put_contents($argv[4].".ready.".$argv[5], "ready");
                    $deadline = microtime(true) + 25;
                    while (!is_file($argv[4])) {
                        if (microtime(true) > $deadline) { exit(2); }
                        usleep(10000);
                    }
                    try {
                        if ($argv[2] === "suggestion") {
                            $result = app(App\Services\ResidentSuggestionService::class)->submit($user, $data);
                            echo json_encode(["status" => 200, "id" => $result["suggestion"]->id, "replayed" => $result["replayed"]]);
                        } else {
                            $survey = App\Models\SatisfactionSurvey::findOrFail($data["survey_id"]);
                            app(App\Services\ResidentSurveyService::class)->submitResponse($survey, $user, $data["answers"]);
                            echo json_encode(["status" => 200]);
                        }
                    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                        echo json_encode(["status" => $exception->getStatusCode()]);
                    } catch (Throwable $exception) {
                        fwrite(STDERR, get_class($exception).": ".$exception->getMessage()); exit(1);
                    }
                    PHP, $userId, $action, json_encode($payload, JSON_THROW_ON_ERROR), $barrier, (string) $number,
                ], base_path(), $environment, timeout: 45);
            }
            foreach ($workers as $worker) {
                $worker->start();
            }
            $deadline = microtime(true) + 25;
            while ((! is_file($barrier.'.ready.0') || ! is_file($barrier.'.ready.1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.ready.0');
            $this->assertFileExists($barrier.'.ready.1');
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
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
        }
    }
}
