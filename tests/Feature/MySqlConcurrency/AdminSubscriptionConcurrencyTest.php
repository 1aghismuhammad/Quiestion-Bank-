<?php

declare(strict_types=1);

namespace Tests\Feature\MySqlConcurrency;

use App\Enums\AdminSubscriptionActionType;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Models\AdminSubscriptionAction;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MySqlConcurrency\ConcurrentRunner;

#[Group('mysql-concurrency')]
class AdminSubscriptionConcurrencyTest extends MySqlConcurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
    }

    public function test_same_idempotency_key_creates_one_subscription(): void
    {
        $admin = $this->createCompleteAdmin();
        $target = $this->createCompleteUser();
        $key = '11111111-1111-4111-8111-111111111111';

        $outputs = ConcurrentRunner::run('admin-grant-subscription', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'duration_months' => 1,
            'reason' => 'Bantuan bersamaan',
            'idempotency_key' => $key,
        ]);

        $successes = 0;
        foreach ($outputs as $output) {
            $decoded = json_decode($output['output'], true);
            if (($decoded['success'] ?? false) === true) {
                $successes++;
            }
        }

        $this->assertSame(2, $successes, json_encode($outputs));
        $this->assertSame(1, Subscription::query()->where('user_id', $target->id)->count());
        $this->assertSame(1, AdminSubscriptionAction::query()->where('target_user_id', $target->id)->count());
    }

    public function test_same_key_on_two_targets_commits_only_one_grant(): void
    {
        $admin = $this->createCompleteAdmin();
        $first = $this->createCompleteUser();
        $second = $this->createCompleteUser();
        $key = '77777777-7777-4777-8777-777777777777';

        $outputs = ConcurrentRunner::runEach([
            [
                'action' => 'admin-grant-subscription',
                'args' => [
                    'admin_id' => $admin->id,
                    'target_user_id' => $first->id,
                    'duration_months' => 1,
                    'reason' => 'Satu kunci dua target',
                    'idempotency_key' => $key,
                ],
            ],
            [
                'action' => 'admin-grant-subscription',
                'args' => [
                    'admin_id' => $admin->id,
                    'target_user_id' => $second->id,
                    'duration_months' => 1,
                    'reason' => 'Satu kunci dua target',
                    'idempotency_key' => $key,
                ],
            ],
        ]);

        $successes = 0;
        foreach ($outputs as $output) {
            $decoded = json_decode($output['output'], true);
            if (($decoded['success'] ?? false) === true) {
                $successes++;
            }
        }

        $this->assertSame(1, $successes, json_encode($outputs));
        $this->assertSame(1, AdminSubscriptionAction::query()->where('idempotency_key', $key)->count());
        $this->assertSame(1, Subscription::query()->whereIn('user_id', [$first->id, $second->id])->count());

        $winnerId = (int) AdminSubscriptionAction::query()->where('idempotency_key', $key)->value('target_user_id');
        $loserId = $winnerId === (int) $first->id ? (int) $second->id : (int) $first->id;
        $this->assertSame(1, Subscription::query()->where('user_id', $winnerId)->count());
        $this->assertSame(0, Subscription::query()->where('user_id', $loserId)->count());
        $this->assertSame(0, AdminSubscriptionAction::query()->where('target_user_id', $loserId)->count());
    }

    public function test_different_keys_append_two_non_overlapping_windows(): void
    {
        $admin = $this->createCompleteAdmin();
        $target = $this->createCompleteUser();

        $outputs = ConcurrentRunner::runEach([
            [
                'action' => 'admin-grant-subscription',
                'args' => [
                    'admin_id' => $admin->id,
                    'target_user_id' => $target->id,
                    'duration_months' => 1,
                    'reason' => 'Dua kunci',
                    'idempotency_key' => '22222222-2222-4222-8222-222222222222',
                ],
            ],
            [
                'action' => 'admin-grant-subscription',
                'args' => [
                    'admin_id' => $admin->id,
                    'target_user_id' => $target->id,
                    'duration_months' => 1,
                    'reason' => 'Dua kunci',
                    'idempotency_key' => '33333333-3333-4333-8333-333333333333',
                ],
            ],
        ]);

        foreach ($outputs as $output) {
            $this->assertSame(0, $output['exitCode'], $output['output'].$output['error']);
        }

        $windows = Subscription::query()->where('user_id', $target->id)->orderBy('starts_at')->get();
        $this->assertCount(2, $windows);
        $this->assertTrue($windows[1]->starts_at->equalTo($windows[0]->ends_at));
        $this->assertSame(
            [AdminSubscriptionActionType::GRANT, AdminSubscriptionActionType::EXTEND],
            AdminSubscriptionAction::query()->where('target_user_id', $target->id)->orderBy('admin_subscription_action_id')->pluck('action')->all(),
        );
    }

    public function test_direct_grant_cannot_bypass_an_existing_pending_request(): void
    {
        $admin = $this->createCompleteAdmin();
        $target = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        SubscriptionUpgradeRequest::factory()->for($target)->for($offer)->for($offer->plan)->create([
            'status' => UpgradeRequestStatus::PENDING,
        ]);

        $outputs = ConcurrentRunner::run('admin-grant-subscription', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'duration_months' => 1,
            'reason' => 'Tidak boleh',
            'idempotency_key' => '44444444-4444-4444-8444-444444444444',
        ]);

        foreach ($outputs as $output) {
            $decoded = json_decode($output['output'], true);
            $this->assertFalse($decoded['success'] ?? true);
        }

        $this->assertSame(0, Subscription::query()->where('user_id', $target->id)->count());
        $this->assertSame(0, AdminSubscriptionAction::query()->where('target_user_id', $target->id)->count());
        $this->assertSame(UpgradeRequestStatus::PENDING, SubscriptionUpgradeRequest::query()->firstOrFail()->status);
    }

    public function test_approval_and_direct_grant_keep_a_valid_queue(): void
    {
        $admin = $this->createCompleteAdmin();
        $target = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $request = SubscriptionUpgradeRequest::factory()->for($target)->for($offer)->for($offer->plan)->create([
            'offer_code' => $offer->code,
            'offer_name' => $offer->name,
            'duration_months' => $offer->duration_months,
            'price_amount' => $offer->price_amount,
            'currency' => $offer->currency,
            'status' => UpgradeRequestStatus::PENDING,
        ]);

        $outputs = ConcurrentRunner::runEach([
            [
                'action' => 'admin-grant-subscription',
                'args' => [
                    'admin_id' => $admin->id,
                    'target_user_id' => $target->id,
                    'duration_months' => 1,
                    'reason' => 'Langsung',
                    'idempotency_key' => '55555555-5555-4555-8555-555555555555',
                ],
            ],
            [
                'action' => 'admin-approve-upgrade',
                'args' => [
                    'admin_id' => $admin->id,
                    'upgrade_request_id' => $request->upgrade_request_id,
                ],
            ],
        ]);

        $grant = [$outputs[0]];
        $approval = [$outputs[1]];

        $request->refresh();
        $windows = Subscription::query()->where('user_id', $target->id)->orderBy('starts_at')->get();

        $this->assertSame(UpgradeRequestStatus::APPROVED, $request->status);
        $this->assertNotNull($request->approved_subscription_id);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertTrue($windows->contains('subscription_id', $request->approved_subscription_id));

        if ($windows->count() === 2) {
            $this->assertTrue($windows[1]->starts_at->equalTo($windows[0]->ends_at));
            $this->assertSame(1, AdminSubscriptionAction::query()->where('target_user_id', $target->id)->count());
        } else {
            $this->assertCount(1, $windows);
            $this->assertSame(0, AdminSubscriptionAction::query()->where('target_user_id', $target->id)->count());
            $decoded = json_decode($grant[0]['output'], true);
            $this->assertFalse($decoded['success'] ?? true);
        }

        $this->assertSame(0, $approval[0]['exitCode'], $approval[0]['output'].$approval[0]['error']);
        $this->assertSame(SubscriptionStatus::ACTIVE, $windows->first()->status);
    }

    public function test_pending_creation_and_direct_grant_do_not_interleave(): void
    {
        $admin = $this->createCompleteAdmin();
        $target = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();

        $outputs = ConcurrentRunner::runEach([
            [
                'action' => 'admin-grant-subscription',
                'args' => [
                    'admin_id' => $admin->id,
                    'target_user_id' => $target->id,
                    'duration_months' => 1,
                    'reason' => 'Langsung',
                    'idempotency_key' => '66666666-6666-4666-8666-666666666666',
                ],
            ],
            [
                'action' => 'user-confirm-upgrade',
                'args' => [
                    'user_id' => $target->id,
                    'offer_id' => $offer->offer_id,
                ],
            ],
        ]);
        $grant = [$outputs[0]];
        $confirm = [$outputs[1]];

        $grantOk = (json_decode($grant[0]['output'], true)['success'] ?? false) === true;
        $confirmOk = (json_decode($confirm[0]['output'], true)['success'] ?? false) === true;
        $pending = SubscriptionUpgradeRequest::query()
            ->where('user_id', $target->id)
            ->where('status', UpgradeRequestStatus::PENDING)
            ->count();
        $subscriptions = Subscription::query()->where('user_id', $target->id)->count();

        $this->assertTrue($confirmOk, $confirm[0]['output'].$confirm[0]['error']);
        $this->assertContains($pending, [0, 1]);

        if ($grantOk) {
            $this->assertSame(1, $subscriptions);
            $this->assertSame(1, AdminSubscriptionAction::query()->where('target_user_id', $target->id)->count());
        } else {
            $this->assertSame(0, $subscriptions);
            $this->assertSame(1, $pending);
            $this->assertSame(0, AdminSubscriptionAction::query()->where('target_user_id', $target->id)->count());
        }
    }
}
