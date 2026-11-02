<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embedding vectors for similar clusters. Published separately with
 * --tag=log-insight-similarity-migrations, and only needed when
 * log-insight.similarity is enabled.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('log-insight.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('log_insight_cluster_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cluster_id')->constrained('log_insight_clusters')->cascadeOnDelete();
            $table->string('model', 100);
            $table->unsignedSmallInteger('dimensions');
            $table->json('vector');
            $table->timestamps();

            $table->unique(['cluster_id', 'model']);
            $table->index(['model', 'dimensions']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('log_insight_cluster_embeddings');
    }
};
