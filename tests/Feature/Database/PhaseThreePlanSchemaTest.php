<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\GenerationResetStrategy;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseThreePlanSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_and_subscription_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('plans'));
        $this->assertTrue(Schema::hasTable('subscriptions'));
    }

    public function test_plans_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('plans', [
            'plan_id',
            'code',
            'name',
            'storage_limit_bytes',
            'generation_limit',
            'generation_reset_strategy',
            'status',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_subscriptions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('subscriptions', [
            'subscription_id',
            'user_id',
            'plan_id',
            'starts_at',
            'ends_at',
            'status',
            'cancelled_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_plans_and_subscriptions_do_not_have_obsolete_commercial_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('plans', 'description'));
        $this->assertFalse(Schema::hasColumn('plans', 'price'));
        $this->assertFalse(Schema::hasColumn('plans', 'currency'));
        $this->assertFalse(Schema::hasColumn('plans', 'billing_period'));
        $this->assertFalse(Schema::hasColumn('plans', 'storage_limit_mb'));
        $this->assertFalse(Schema::hasColumn('plans', 'deleted_at'));

        $this->assertFalse(Schema::hasColumn('subscriptions', 'subscription_code'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'start_date'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'end_date'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'payment_status'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'approved_by'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'approved_at'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'deleted_at'));
    }

    public function test_plan_code_is_unique(): void
    {
        Plan::query()->create([
            'code' => PlanCode::FREE,
            'name' => 'Free',
            'storage_limit_bytes' => 52_428_800,
            'generation_limit' => 2,
            'generation_reset_strategy' => GenerationResetStrategy::LIFETIME,
            'status' => PlanStatus::ACTIVE,
        ]);

        $this->expectException(QueryException::class);

        Plan::query()->create([
            'code' => PlanCode::FREE,
            'name' => 'Free duplicate',
            'storage_limit_bytes' => 52_428_800,
            'generation_limit' => 2,
            'generation_reset_strategy' => GenerationResetStrategy::LIFETIME,
            'status' => PlanStatus::ACTIVE,
        ]);
    }

    public function test_plans_have_unique_code_index(): void
    {
        $this->assertTrue($this->hasIndex('plans', ['code'], unique: true));
    }

    public function test_subscriptions_have_user_status_index(): void
    {
        $this->assertTrue($this->hasIndex('subscriptions', ['user_id', 'status'], unique: false));
    }

    public function test_subscription_user_foreign_key_restricts_delete(): void
    {
        $foreignKey = $this->foreignKey('subscriptions', 'user_id');

        $this->assertSame('users', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertContains($foreignKey['on_delete'], ['restrict', 'no action']);
    }

    public function test_subscription_plan_foreign_key_restricts_delete(): void
    {
        $foreignKey = $this->foreignKey('subscriptions', 'plan_id');

        $this->assertSame('plans', $foreignKey['foreign_table']);
        $this->assertSame(['plan_id'], $foreignKey['foreign_columns']);
        $this->assertContains($foreignKey['on_delete'], ['restrict', 'no action']);
    }

    public function test_starts_at_is_required(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();

        $this->expectException(QueryException::class);

        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => null,
        ]);
    }

    public function test_ends_at_is_required(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();

        $this->expectException(QueryException::class);

        Subscription::factory()->for($user)->for($pro)->create([
            'ends_at' => null,
        ]);
    }

    public function test_cancelled_at_may_be_null(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();

        $subscription = Subscription::factory()->for($user)->for($pro)->create([
            'cancelled_at' => null,
        ]);

        $this->assertNull($subscription->cancelled_at);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
    }

    public function test_deleting_a_user_with_subscription_history_is_restricted(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        Subscription::factory()->for($user)->for($pro)->create();

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_deleting_a_plan_with_subscription_history_is_restricted(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        Subscription::factory()->for($user)->for($pro)->create();

        $this->expectException(QueryException::class);

        $pro->delete();
    }

    public function test_phase_three_index_and_foreign_key_names_fit_mysql_identifier_limit(): void
    {
        foreach (['plans', 'subscriptions'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($index['name']),
                    "Index {$index['name']} on {$table} exceeds MySQL's 64-character identifier limit.",
                );
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (! isset($foreignKey['name']) || $foreignKey['name'] === '') {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    64,
                    strlen($foreignKey['name']),
                    "Foreign key {$foreignKey['name']} on {$table} exceeds MySQL's 64-character identifier limit.",
                );
            }
        }
    }

    public function test_rolling_back_phase_three_drops_only_plan_and_subscription_tables(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_28_100002_create_subscriptions_table.php',
        ])->assertSuccessful();
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_28_100001_create_plans_table.php',
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('subscriptions'));
        $this->assertFalse(Schema::hasTable('plans'));
        $this->assertTrue(Schema::hasTable('material_topics'));
        $this->assertTrue(Schema::hasTable('materials'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('role_user'));
        $this->assertTrue(Schema::hasTable('whatsapp_contacts'));
    }

    /**
     * @return array{foreign_table: string, foreign_columns: list<string>, on_delete: string, name?: string}
     */
    private function foreignKey(string $table, string $column): array
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === [$column]);

        $this->assertIsArray($foreignKey, "Expected foreign key on {$table}.{$column}");

        return $foreignKey;
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(string $table, array $columns, bool $unique): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => $index['columns'] === $columns && $index['unique'] === $unique,
        );
    }
}
