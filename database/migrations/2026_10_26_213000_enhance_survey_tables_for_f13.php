<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Enhance satisfaction_surveys
        if (Schema::hasTable('satisfaction_surveys')) {
            Schema::table('satisfaction_surveys', function (Blueprint $table): void {
                if (! Schema::hasColumn('satisfaction_surveys', 'version')) {
                    $table->unsignedInteger('version')->default(1)->after('created_by');
                }
                if (! Schema::hasColumn('satisfaction_surveys', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            });

            // Compound index for manager & resident query performance
            if (! Schema::hasIndex('satisfaction_surveys', 'idx_surveys_block_active_dates')) {
                Schema::table('satisfaction_surveys', function (Blueprint $table): void {
                    $table->index(['target_block_id', 'is_active', 'start_date', 'end_date'], 'idx_surveys_block_active_dates');
                });
            }
        }

        // 2. Enhance survey_questions
        if (Schema::hasTable('survey_questions')) {
            Schema::table('survey_questions', function (Blueprint $table): void {
                if (! Schema::hasColumn('survey_questions', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            });

            if (! Schema::hasIndex('survey_questions', 'idx_survey_questions_survey_order')) {
                Schema::table('survey_questions', function (Blueprint $table): void {
                    $table->index(['survey_id', 'question_order'], 'idx_survey_questions_survey_order');
                });
            }
        }

        // 3. Enhance survey_responses (anti-duplicate unique constraint & idempotency)
        if (Schema::hasTable('survey_responses')) {
            Schema::table('survey_responses', function (Blueprint $table): void {
                if (! Schema::hasColumn('survey_responses', 'idempotency_key')) {
                    $table->string('idempotency_key', 64)->nullable()->after('apartment_id');
                }
            });

            if (! Schema::hasIndex('survey_responses', 'unique_survey_apartment')) {
                Schema::table('survey_responses', function (Blueprint $table): void {
                    $table->unique(['survey_id', 'apartment_id'], 'unique_survey_apartment');
                });
            }
            if (! Schema::hasIndex('survey_responses', 'idx_survey_responses_survey_submitted')) {
                Schema::table('survey_responses', function (Blueprint $table): void {
                    $table->index(['survey_id', 'submitted_at'], 'idx_survey_responses_survey_submitted');
                });
            }
        }

        // 4. Enhance survey_response_details (prevent duplicate answer for same question in same response)
        if (Schema::hasTable('survey_response_details')) {
            if (! Schema::hasIndex('survey_response_details', 'unique_response_question')) {
                Schema::table('survey_response_details', function (Blueprint $table): void {
                    $table->unique(['response_id', 'question_id'], 'unique_response_question');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('survey_response_details')) {
            Schema::table('survey_response_details', function (Blueprint $table): void {
                if (Schema::hasIndex('survey_response_details', 'unique_response_question')) {
                    $table->dropUnique('unique_response_question');
                }
            });
        }

        if (Schema::hasTable('survey_responses')) {
            Schema::table('survey_responses', function (Blueprint $table): void {
                if (Schema::hasIndex('survey_responses', 'unique_survey_apartment')) {
                    $table->dropUnique('unique_survey_apartment');
                }
                if (Schema::hasIndex('survey_responses', 'idx_survey_responses_survey_submitted')) {
                    $table->dropIndex('idx_survey_responses_survey_submitted');
                }
                if (Schema::hasColumn('survey_responses', 'idempotency_key')) {
                    $table->dropColumn('idempotency_key');
                }
            });
        }

        if (Schema::hasTable('survey_questions')) {
            Schema::table('survey_questions', function (Blueprint $table): void {
                if (Schema::hasIndex('survey_questions', 'idx_survey_questions_survey_order')) {
                    $table->dropIndex('idx_survey_questions_survey_order');
                }
                if (Schema::hasColumn('survey_questions', 'updated_at')) {
                    $table->dropColumn('updated_at');
                }
            });
        }

        if (Schema::hasTable('satisfaction_surveys')) {
            Schema::table('satisfaction_surveys', function (Blueprint $table): void {
                if (Schema::hasIndex('satisfaction_surveys', 'idx_surveys_block_active_dates')) {
                    $table->dropIndex('idx_surveys_block_active_dates');
                }
                if (Schema::hasColumn('satisfaction_surveys', 'version')) {
                    $table->dropColumn('version');
                }
                if (Schema::hasColumn('satisfaction_surveys', 'updated_at')) {
                    $table->dropColumn('updated_at');
                }
            });
        }
    }
};
