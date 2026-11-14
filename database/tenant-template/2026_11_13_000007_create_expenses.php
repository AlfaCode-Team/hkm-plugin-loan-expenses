<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * What a branch spent, and who approved it.
 */
return new class implements MigrationInterface {

    public function up($schema): void
    {
        $schema->create('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();          // client-minted
            $table->unsignedBigInteger('branch_id')->nullable();

            // Stored as a PLAIN STRING, not an enum or a foreign key. The
            // category list grows (28 today, grouped road / people / office /
            // money and the law), and summaries group by whatever is on the
            // record — so adding one never touches an expense already recorded.
            $table->string('category', 120);

            $table->unsignedBigInteger('amount');
            $table->string('description', 500)->nullable();
            $table->string('payee', 240)->nullable();
            $table->string('payment_method', 32)->default('cash');
            $table->string('reference', 120)->nullable();
            $table->date('spent_on');
            $table->string('receipt_path', 255)->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->string('recorded_by_name', 240)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_expenses_rev');
            $table->index(['spent_on'], 'idx_expenses_date');
            $table->index(['category'], 'idx_expenses_category');
            $table->index(['branch_id'], 'idx_expenses_branch');
            $table->index(['recorded_by'], 'idx_expenses_by');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('expenses');
    }
};
