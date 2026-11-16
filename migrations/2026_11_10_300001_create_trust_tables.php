<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per member per day they were here: written by the read
        // request core already sends and by core's last-seen update.
        $schema->create('trust_daily', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->date('day');
            $table->unsignedInteger('posts_read')->default(0);
            $table->unsignedInteger('topics_entered')->default(0);
            $table->unsignedInteger('read_seconds')->default(0);
            $table->dateTime('last_read_at')->nullable();

            $table->primary(['user_id', 'day']);
            $table->index('day');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // The rollup: what the progress page and the dashboard read. Days older
        // than the 100-day window are folded into the base_* totals and pruned.
        $schema->create('trust_stats', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('base_days')->default(0);
            $table->unsignedInteger('base_posts_read')->default(0);
            $table->unsignedInteger('base_topics_entered')->default(0);
            $table->unsignedInteger('base_read_seconds')->default(0);
            $table->text('data')->nullable();
            $table->unsignedTinyInteger('next_level')->nullable();
            $table->unsignedTinyInteger('next_percent')->default(0);
            $table->dateTime('computed_at')->nullable();

            $table->index(['next_level', 'next_percent']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // History: level changes, locks, silences, upheld flags, suspensions.
        $schema->create('trust_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('type', 20);
            $table->unsignedTinyInteger('from_level')->nullable();
            $table->unsignedTinyInteger('to_level')->nullable();
            $table->unsignedInteger('actor_id')->nullable();
            $table->unsignedInteger('post_id')->nullable();
            $table->text('data')->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'type', 'created_at']);
            $table->index('created_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('trust_events');
        $schema->dropIfExists('trust_stats');
        $schema->dropIfExists('trust_daily');
    },
];
