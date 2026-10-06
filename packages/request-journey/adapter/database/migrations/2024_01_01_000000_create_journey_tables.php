<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('request-journey.connection');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('journey_definitions')) {
            $schema->create('journey_definitions', static function (Blueprint $table): void {
                $table->string('service_code', 120);
                $table->unsignedInteger('version');
                $table->string('navigation_mode', 32);
                $table->longText('definition_json');
                $table->string('published_by', 191)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->primary(['service_code', 'version']);
            });
        }

        if (!$schema->hasTable('request_journeys')) {
            $schema->create('request_journeys', static function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_id', 191)->unique();
                $table->string('service_code', 120)->index();
                $table->unsignedInteger('journey_version');
                $table->string('last_visited_step_key', 64)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $connection = config('request-journey.connection');
        $schema = Schema::connection($connection);
        $schema->dropIfExists('request_journeys');
        $schema->dropIfExists('journey_definitions');
    }
};
