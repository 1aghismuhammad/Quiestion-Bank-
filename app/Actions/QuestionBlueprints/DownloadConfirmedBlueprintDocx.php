<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprint;
use App\Models\User;
use App\Services\QuestionBlueprints\BlueprintDocxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadConfirmedBlueprintDocx
{
    public function __construct(
        private AssertReadyMatchingProfile $assertProfile,
        private BlueprintDocxWriter $writer,
    ) {}

    public function handle(User $actor, QuestionBlueprint $blueprint): BinaryFileResponse
    {
        if ((int) $blueprint->user_id !== (int) $actor->id) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        if ($blueprint->lifecycle_status !== BlueprintLifecycleStatus::Confirmed) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        $material = $blueprint->material()->firstOrFail();
        $matching = $this->assertProfile->matchingReady($material);
        $historical = $matching === null
            || (int) $matching->profile_version_id !== (int) $blueprint->profile_version_id
            || (string) $blueprint->material_content_hash !== $this->assertProfile->fingerprint($material)['material_content_hash'];

        $blueprint->loadMissing(['rows.contexts', 'material']);

        return $this->writer->download($blueprint, $historical);
    }
}
