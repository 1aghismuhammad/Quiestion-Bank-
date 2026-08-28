<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\PlanCode;
use App\Enums\PlanOfferStatus;
use App\Enums\PlanStatus;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use App\Services\Subscriptions\QrisPublicAsset;
use App\Services\Subscriptions\WhatsAppConfirmationUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ConfirmSubscriptionUpgrade
{
    private const MAX_REFERENCE_ATTEMPTS = 8;

    public function __construct(
        private QrisPublicAsset $qris,
        private WhatsAppConfirmationUrl $whatsApp,
    ) {}

    public function handle(User $user, ?int $offerId): SubscriptionUpgradeRequest
    {
        return DB::transaction(function () use ($user, $offerId): SubscriptionUpgradeRequest {
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $pending = SubscriptionUpgradeRequest::query()
                ->where('user_id', $owner->id)
                ->where('status', UpgradeRequestStatus::PENDING)
                ->orderBy('upgrade_request_id')
                ->get();

            if ($pending->count() > 1) {
                throw new AmbiguousUpgradeRequestsException(
                    'The upgrade request cannot be resolved.',
                    $owner->id,
                    $pending->count(),
                );
            }

            if ($pending->count() === 1) {
                return $pending->first();
            }

            $this->assertCheckoutReady();

            return $this->createPending($owner, $this->validatedOffer($offerId));
        });
    }

    private function assertCheckoutReady(): void
    {
        if (! $this->whatsApp->isConfigured()) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Konfirmasi WhatsApp belum dikonfigurasi.',
            ]);
        }

        if (! $this->qris->exists()) {
            throw ValidationException::withMessages([
                'qris' => 'QRIS belum dikonfigurasi.',
            ]);
        }
    }

    private function validatedOffer(?int $offerId): PlanOffer
    {
        if ($offerId === null) {
            throw ValidationException::withMessages([
                'offer_id' => 'Pilih paket berlangganan.',
            ]);
        }

        $offer = PlanOffer::query()->with('plan')->whereKey($offerId)->first();

        if (
            $offer === null
            || $offer->status !== PlanOfferStatus::ACTIVE
            || $offer->plan === null
            || $offer->plan->code !== PlanCode::PRO
            || $offer->plan->status !== PlanStatus::ACTIVE
            || $offer->duration_months < 1
            || $offer->price_amount <= 0
            || $offer->currency !== PlanOffer::CURRENCY_IDR
        ) {
            throw ValidationException::withMessages([
                'offer_id' => 'Paket berlangganan tidak tersedia.',
            ]);
        }

        $canonicalPro = Plan::query()->where('code', PlanCode::PRO)->first();

        if ($canonicalPro === null || (int) $offer->plan_id !== (int) $canonicalPro->plan_id) {
            throw ValidationException::withMessages([
                'offer_id' => 'Paket berlangganan tidak tersedia.',
            ]);
        }

        return $offer;
    }

    private function createPending(User $owner, PlanOffer $offer): SubscriptionUpgradeRequest
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $reference = $this->newReference();

            try {
                return DB::transaction(function () use ($owner, $offer, $reference): SubscriptionUpgradeRequest {
                    return $owner->subscriptionUpgradeRequests()->create([
                        'offer_id' => $offer->offer_id,
                        'plan_id' => $offer->plan_id,
                        'reference_code' => $reference,
                        'status' => UpgradeRequestStatus::PENDING,
                        'offer_code' => $offer->code,
                        'offer_name' => $offer->name,
                        'duration_months' => $offer->duration_months,
                        'price_amount' => $offer->price_amount,
                        'currency' => $offer->currency,
                        'requested_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                // Database UNIQUE(reference_code) is authoritative; retry a new token.
            }
        }

        throw new RuntimeException('Unable to allocate an upgrade request reference.');
    }

    private function newReference(): string
    {
        return sprintf('UP-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6)));
    }
}
