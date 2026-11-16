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
        if (Schema::hasTable('payments')) {
            try {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropUnique('idempotency_key');
                });
            } catch (Throwable) {
                // Ignore if index name is different or does not exist
            }

            if (! Schema::hasIndex('payments', 'payments_idempotency_invoice_unique')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->unique(['idempotency_key', 'invoice_id'], 'payments_idempotency_invoice_unique');
                });
            }
        }

        if (Schema::hasTable('payment_receipts')) {
            $hasReceiptIndex = Schema::hasIndex('payment_receipts', 'receipt_number')
                || Schema::hasIndex('payment_receipts', 'payment_receipts_receipt_number_unique');

            if (! $hasReceiptIndex) {
                Schema::table('payment_receipts', function (Blueprint $table) {
                    $table->unique('receipt_number', 'payment_receipts_receipt_number_unique');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            if (Schema::hasIndex('payments', 'payments_idempotency_invoice_unique')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropUnique('payments_idempotency_invoice_unique');
                });
            }

            if (! Schema::hasIndex('payments', 'idempotency_key')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->unique('idempotency_key');
                });
            }
        }

        if (Schema::hasTable('payment_receipts')) {
            if (Schema::hasIndex('payment_receipts', 'payment_receipts_receipt_number_unique')) {
                Schema::table('payment_receipts', function (Blueprint $table) {
                    $table->dropUnique('payment_receipts_receipt_number_unique');
                });
            }
        }
    }
};
