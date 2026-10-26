<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('log-insight.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('log_insight_clusters', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('type', 20);
            $table->string('level', 20);
            $table->string('exception_class')->nullable();
            $table->text('message');
            $table->text('sample_message');
            $table->string('file', 1024)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('app_frame', 1024)->nullable();
            $table->longText('stack_trace')->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });

        $schema->create('log_insight_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cluster_id')->constrained('log_insight_clusters')->cascadeOnDelete();
            $table->string('hash', 40)->unique();
            $table->timestamp('occurred_at');
            $table->string('level', 20);
            $table->string('channel');
            $table->text('message');
            $table->timestamp('created_at')->nullable();

            $table->index(['cluster_id', 'occurred_at']);
        });

        $schema->create('log_insight_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cluster_id')->constrained('log_insight_clusters')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->text('root_cause');
            $table->text('suggested_fix');
            $table->json('affected_files');
            $table->string('severity', 20);
            $table->decimal('confidence', 3, 2);
            $table->json('raw_response')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('log_insight_analyses');
        $schema->dropIfExists('log_insight_occurrences');
        $schema->dropIfExists('log_insight_clusters');
    }
};
