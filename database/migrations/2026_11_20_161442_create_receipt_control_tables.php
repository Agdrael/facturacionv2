<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $local = fn (string $name): string => DB::getDriverName() === 'sqlite' ? $name : 'libreria.'.$name;
        Schema::create('libreria.receipt_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
        });
        Schema::create('libreria.receipt_concept_types', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_id')->primary();
            $table->string('kind', 20)->default('normal');
        });
        Schema::create('libreria.receipt_client_links', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->primary();
            $table->unsignedBigInteger('legacy_client_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('reason', 500);
            $table->timestampTz('created_at');
        });
        Schema::create('libreria.receipt_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('user_id')->index();
            $table->string('environment', 2);
            $table->json('issuer');
            $table->timestampsTz();
        });
        Schema::create('libreria.receipt_invoice_balances', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 100)->unique();
            $table->uuid('invoice_uuid')->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('remaining', 18, 2);
            $table->json('data');
            $table->timestampsTz();
        });
        Schema::create('libreria.receipt_obligations', function (Blueprint $table) use ($local) {
            $table->id();
            $table->string('source_key', 150)->unique();
            $table->foreignId('invoice_balance_id')->nullable()->constrained($local('receipt_invoice_balances'))->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('recipient_type', 20);
            $table->string('recipient_id', 60);
            $table->decimal('amount', 18, 2);
            $table->decimal('opening_remaining', 18, 2);
            $table->decimal('remaining', 18, 2);
            $table->json('data');
            $table->unsignedBigInteger('linked_by')->nullable();
            $table->string('link_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['recipient_type', 'recipient_id']);
        });
        Schema::create('libreria.receipts', function (Blueprint $table) use ($local) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('batch_id')->constrained($local('receipt_batches'))->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('operator_name');
            $table->string('employee_code', 60);
            $table->string('register', 6);
            $table->foreignId('cash_session_id')->nullable()->constrained(DB::getDriverName() === 'sqlite' ? 'caja_sesiones' : 'libreria.caja_sesiones')->restrictOnDelete();
            $table->string('recipient_type', 20);
            $table->string('recipient_id', 60);
            $table->string('recipient_name');
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->text('observation')->nullable();
            $table->decimal('total', 18, 2);
            $table->decimal('paid', 18, 2);
            $table->decimal('change', 18, 2);
            $table->string('status', 35)->default('PENDIENTE_APLICACION');
            $table->unsignedBigInteger('annulled_by')->nullable();
            $table->string('annulled_employee_code', 60)->nullable();
            $table->string('annulment_reason', 500)->nullable();
            $table->timestampTz('annulled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['batch_id', 'recipient_type', 'recipient_id']);
            $table->index(['cash_session_id', 'annulled_at']);
        });
        Schema::create('libreria.receipt_lines', function (Blueprint $table) use ($local) {
            $table->id();
            $table->foreignId('receipt_id')->constrained($local('receipts'))->restrictOnDelete();
            $table->foreignId('obligation_id')->nullable()->constrained($local('receipt_obligations'))->restrictOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('concept_id')->nullable();
            $table->string('description', 1000);
            $table->decimal('amount', 18, 2);
            $table->json('data');
        });
        Schema::create('libreria.receipt_payments', function (Blueprint $table) use ($local) {
            $table->id();
            $table->foreignId('receipt_id')->constrained($local('receipts'))->restrictOnDelete();
            $table->string('method', 2);
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable();
        });
        Schema::create('libreria.receipt_movements', function (Blueprint $table) use ($local) {
            $table->id();
            $table->foreignId('line_id')->constrained($local('receipt_lines'))->restrictOnDelete();
            $table->string('operation', 20);
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('created_at');
            $table->unique(['line_id', 'operation']);
        });
        Schema::create('libreria.receipt_integrations', function (Blueprint $table) use ($local) {
            $table->id();
            $table->foreignId('line_id')->constrained($local('receipt_lines'))->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('target', 15);
            $table->string('operation', 15);
            $table->string('status', 20)->default('PENDIENTE');
            $table->text('error')->nullable();
            $table->json('result')->nullable();
            $table->timestampsTz();
            $table->unique(['line_id', 'target', 'operation']);
        });
        Schema::create('libreria.receipt_credit_origins', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->primary();
            $table->uuid('operation_key')->unique();
            $table->json('data');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('libreria.receipt_reconciliations', function (Blueprint $table) use ($local) {
            $table->id();
            $table->foreignId('obligation_id')->constrained($local('receipt_obligations'))->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('reason', 500);
            $table->json('before');
            $table->json('after');
            $table->timestampTz('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['receipt_reconciliations', 'receipt_credit_origins', 'receipt_integrations', 'receipt_movements', 'receipt_payments', 'receipt_lines', 'receipts', 'receipt_obligations', 'receipt_invoice_balances', 'receipt_batches', 'receipt_client_links', 'receipt_concept_types', 'receipt_settings'] as $table) {
            Schema::dropIfExists('libreria.'.$table);
        }
    }
};
