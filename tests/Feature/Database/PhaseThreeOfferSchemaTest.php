<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\UpgradeRequestStatus;
use App\Models\PlanOffer;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseThreeOfferSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_and_upgrade_request_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('plan_offers'));
        $this->assertTrue(Schema::hasTable('subscription_upgrade_requests'));
        $this->assertTrue(Schema::hasColumns('plan_offers', [
            'offer_id',
            'plan_id',
            'code',
            'name',
            'duration_months',
            'price_amount',
            'currency',
            'status',
            'sort_order',
        ]));
        $this->assertTrue(Schema::hasColumns('subscription_upgrade_requests', [
            'upgrade_request_id',
            'user_id',
            'offer_id',
            'plan_id',
            'reference_code',
            'status',
            'offer_code',
            'offer_name',
            'duration_months',
            'price_amount',
            'currency',
            'requested_at',
            'reviewed_at',
            'reviewed_by',
            'rejection_reason',
            'approved_subscription_id',
        ]));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'payment_status'));
    }

    public function test_approved_subscription_unique_and_pending_user_status_is_not_unique(): void
    {
        $this->assertTrue($this->hasIndex('subscription_upgrade_requests', ['approved_subscription_id'], unique: true));
        $this->assertTrue($this->hasIndex('subscription_upgrade_requests', ['reference_code'], unique: true));
        $this->assertTrue($this->hasIndex('subscription_upgrade_requests', ['user_id', 'status'], unique: false));
    }

    public function test_explicit_index_names_are_mysql_safe(): void
    {
        $names = collect(Schema::getIndexes('subscription_upgrade_requests'))->pluck('name');

        $this->assertTrue($names->contains('upgrade_req_approved_sub_unique'));
        $this->assertTrue($names->contains('upgrade_req_reference_unique'));
        $this->assertTrue($names->contains('upgrade_req_user_status_idx'));

        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }

        foreach (Schema::getForeignKeys('subscription_upgrade_requests') as $foreignKey) {
            if (! isset($foreignKey['name']) || $foreignKey['name'] === '') {
                continue;
            }

            $this->assertLessThanOrEqual(64, strlen($foreignKey['name']));
        }

        $this->assertSame('plan_offers', $this->foreignTable('subscription_upgrade_requests', 'offer_id'));
        $this->assertSame('subscriptions', $this->foreignTable('subscription_upgrade_requests', 'approved_subscription_id'));
        $this->assertSame('users', $this->foreignTable('subscription_upgrade_requests', 'reviewed_by'));
    }

    public function test_multiple_null_approved_subscription_ids_are_allowed(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();

        SubscriptionUpgradeRequest::factory()->for(User::factory())->for($offer)->for($offer->plan)->create([
            'status' => UpgradeRequestStatus::PENDING,
            'approved_subscription_id' => null,
        ]);
        SubscriptionUpgradeRequest::factory()->for(User::factory())->for($offer)->for($offer->plan)->create([
            'status' => UpgradeRequestStatus::REJECTED,
            'approved_subscription_id' => null,
        ]);

        $this->assertSame(2, SubscriptionUpgradeRequest::query()->whereNull('approved_subscription_id')->count());
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

    private function foreignTable(string $table, string $column): string
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === [$column]);

        $this->assertIsArray($foreignKey, "Expected foreign key on {$table}.{$column}");

        return $foreignKey['foreign_table'];
    }
}
