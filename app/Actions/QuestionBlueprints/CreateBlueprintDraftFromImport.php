<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Actions\MaterialProfiles\AssertMaterialEligibleForProfileAnalysis;
use App\Actions\Subscriptions\ResolveActivePro;
use App\Data\QuestionBlueprints\BlueprintImportGroundingResult;
use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintImportGroundingFieldStatus;
use App\Enums\BlueprintImportGroundingStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\BlueprintRowOrigin;
use App\Enums\BlueprintSource;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintImport;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateBlueprintDraftFromImport
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private AssertImportGroundingEligibility $eligibility,
        private AssertBlueprintShape $assertShape,
        private ResolveBlueprintRowContexts $resolveContexts,
        private PersistBlueprintRows $persistRows,
        private ResolveActivePro $resolveActivePro,
    ) {}

    /**
     * @param  list<int>  $selectedIndexes
     * @param  list<array<string, mixed>>  $ownerRows
     */
    public function handle(
        User $actor,
        Material $material,
        QuestionBlueprintImport $import,
        string $title,
        AssessmentType $assessmentType,
        BlueprintMode $mode,
        array $selectedIndexes,
        array $ownerRows,
    ): QuestionBlueprint {
        $title = trim($title);
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);

        if ($title === '' || mb_strlen($title, 'UTF-8') > $maxTitle) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return DB::transaction(function () use (
            $actor,
            $material,
            $import,
            $title,
            $assessmentType,
            $mode,
            $selectedIndexes,
            $ownerRows,
        ): QuestionBlueprint {
            $lockedMaterial = $this->lockUserAndMaterial((int) $actor->id, (int) $material->material_id);

            $lockedImport = QuestionBlueprintImport::query()
                ->whereKey((int) $import->import_id)
                ->lockForUpdate()
                ->first();

            if ($lockedImport === null
                || (int) $lockedImport->material_id !== (int) $lockedMaterial->material_id
                || (int) $lockedImport->user_id !== (int) $actor->id) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            if ($lockedImport->created_blueprint_id !== null) {
                return $this->existingBlueprint($lockedImport, $lockedMaterial, $actor);
            }

            try {
                $this->assertEligible->handle($lockedMaterial);
            } catch (MaterialProfileRejectedException) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            try {
                $eligible = $this->eligibility->handle($lockedImport, $actor);
            } catch (AuthorizationException $exception) {
                throw $exception;
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'import' => $this->eligibilityMessage($exception->getMessage()),
                ]);
            }

            $profile = $eligible['profile'];
            $this->lockProfileVersion((int) $profile->profile_version_id);
            $grounding = $this->readyGrounding($lockedImport, (int) $profile->profile_version_id);
            $ownerByIndex = $this->ownerRowsByIndex($ownerRows);
            $payloads = [];

            foreach ($selectedIndexes as $index) {
                if (! is_int($index)) {
                    throw ValidationException::withMessages([
                        'selected_indexes' => 'Indeks kandidat tidak valid.',
                    ]);
                }

                $candidate = $grounding['by_index'][$index] ?? null;

                if (! is_array($candidate)) {
                    throw ValidationException::withMessages([
                        'selected_indexes' => 'Kandidat yang dipilih tidak ada.',
                    ]);
                }

                $elementIds = $this->elementIds($candidate);
                $owner = $ownerByIndex[$index] ?? null;

                if ($owner === null) {
                    throw ValidationException::withMessages([
                        'rows' => 'Setiap kandidat yang dipilih membutuhkan isian kanonis.',
                    ]);
                }

                $fields = $candidate['fields'];
                $payloads[] = [
                    'objective' => trim((string) $fields['objective']['claim_raw']),
                    'topic' => trim((string) $fields['topic']['claim_raw']),
                    'indicator' => trim((string) $fields['indicator']['claim_raw']),
                    'cognitive_level' => CognitiveLevel::from((string) $owner['cognitive_level']),
                    'difficulty' => DifficultyLevel::from((string) $owner['difficulty']),
                    'question_type' => QuestionType::from((string) $owner['question_type']),
                    'requested_count' => (int) $owner['requested_count'],
                    'sources' => array_map(
                        static fn (int $id): string => 'element:'.$id,
                        $elementIds,
                    ),
                ];
            }

            if ($mode === BlueprintMode::Advanced && ! $this->resolveActivePro->handle($actor)) {
                throw new BlueprintRejectedException(BlueprintErrorCode::AdvancedRequiresPro);
            }

            $this->assertShape->handle($payloads, $mode);
            $payloads = $this->resolveContexts->attach($lockedMaterial, $profile, $payloads);
            $fingerprint = $eligible['fingerprint'];

            $series = QuestionBlueprintSeries::query()->create([
                'user_id' => $lockedMaterial->user_id,
                'material_id' => $lockedMaterial->material_id,
            ]);

            $blueprint = QuestionBlueprint::query()->create([
                'blueprint_series_id' => $series->blueprint_series_id,
                'user_id' => $lockedMaterial->user_id,
                'material_id' => $lockedMaterial->material_id,
                'profile_version_id' => $profile->profile_version_id,
                'version' => 1,
                'lifecycle_status' => BlueprintLifecycleStatus::Draft,
                'source' => BlueprintSource::Manual,
                'ai_fill_status' => BlueprintAiFillStatus::None,
                'mode' => $mode,
                'assessment_type' => $assessmentType,
                'title' => $title,
                'material_content_hash' => $fingerprint['material_content_hash'],
                'material_file_hash' => $fingerprint['material_file_hash'],
                'extractor_implementation' => $fingerprint['extractor_implementation'],
            ]);

            $this->persistRows->replace($blueprint, $payloads, BlueprintRowOrigin::Extracted);

            $updated = QuestionBlueprintImport::query()
                ->whereKey((int) $lockedImport->import_id)
                ->whereNull('created_blueprint_id')
                ->update(['created_blueprint_id' => $blueprint->blueprint_id]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'import' => 'Konversi impor ini tidak dapat disimpan.',
                ]);
            }

            return $blueprint->refresh()->load(['rows.contexts', 'series']);
        });
    }

    /**
     * @return list<int>
     */
    public function convertibleIndexes(QuestionBlueprintImport $import): array
    {
        try {
            $grounding = $this->readyGrounding($import, (int) $import->profile_version_id);
        } catch (ValidationException) {
            return [];
        }

        $indexes = [];

        foreach ($grounding['by_index'] as $index => $candidate) {
            try {
                $this->elementIds($candidate);
                $indexes[] = $index;
            } catch (ValidationException) {
                continue;
            }
        }

        return $indexes;
    }

    private function existingBlueprint(
        QuestionBlueprintImport $import,
        Material $material,
        User $actor,
    ): QuestionBlueprint {
        $blueprint = QuestionBlueprint::query()
            ->whereKey((int) $import->created_blueprint_id)
            ->first();

        if ($blueprint === null
            || (int) $blueprint->user_id !== (int) $actor->id
            || (int) $blueprint->material_id !== (int) $material->material_id) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        return $blueprint->load(['rows.contexts', 'series']);
    }

    /**
     * @return array{by_index: array<int, array<string, mixed>>}
     */
    private function readyGrounding(QuestionBlueprintImport $import, int $profileVersionId): array
    {
        if ($import->grounding_status !== BlueprintImportGroundingStatus::READY) {
            throw ValidationException::withMessages([
                'import' => 'Grounding impor belum siap.',
            ]);
        }

        $result = $import->grounding_result;

        if (! is_array($result)
            || ($result['schema_version'] ?? null) !== BlueprintImportGroundingResult::SCHEMA_VERSION
            || (int) ($result['grounded_profile_version_id'] ?? 0) !== $profileVersionId) {
            throw ValidationException::withMessages([
                'import' => 'Hasil grounding tidak valid untuk profil impor ini.',
            ]);
        }

        $rawInterpretation = $import->getRawOriginal('interpretation_result');
        $sha = hash('sha256', (string) $rawInterpretation);

        if (($result['interpretation_result_sha256'] ?? null) !== $sha) {
            throw ValidationException::withMessages([
                'import' => 'Hasil grounding tidak lagi cocok dengan interpretasi.',
            ]);
        }

        $candidates = $result['candidates'] ?? null;

        if (! is_array($candidates) || ! array_is_list($candidates)) {
            throw ValidationException::withMessages([
                'import' => 'Hasil grounding tidak valid.',
            ]);
        }

        $byIndex = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! is_int($candidate['index'] ?? null)) {
                throw ValidationException::withMessages([
                    'import' => 'Hasil grounding tidak valid.',
                ]);
            }

            $index = $candidate['index'];

            if (isset($byIndex[$index])) {
                throw ValidationException::withMessages([
                    'import' => 'Hasil grounding tidak valid.',
                ]);
            }

            $byIndex[$index] = $candidate;
        }

        return ['by_index' => $byIndex];
    }

    /**
     * @param  list<array<string, mixed>>  $ownerRows
     * @return array<int, array<string, mixed>>
     */
    private function ownerRowsByIndex(array $ownerRows): array
    {
        $byIndex = [];

        foreach ($ownerRows as $row) {
            if (! is_array($row) || ! is_int($row['index'] ?? null)) {
                throw ValidationException::withMessages([
                    'rows' => 'Isian kandidat tidak valid.',
                ]);
            }

            if (isset($byIndex[$row['index']])) {
                throw ValidationException::withMessages([
                    'rows' => 'Indeks kandidat terduplikasi.',
                ]);
            }

            $byIndex[$row['index']] = $row;
        }

        return $byIndex;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<int>
     */
    private function elementIds(array $candidate): array
    {
        $fields = $candidate['fields'] ?? null;

        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'selected_indexes' => 'Kandidat tidak dapat dikonversi.',
            ]);
        }

        $ids = [];
        $maxText = (int) config('question_blueprint.row_text_max_chars', 500);

        foreach (['objective', 'topic', 'indicator'] as $field) {
            $payload = $fields[$field] ?? null;

            if (! is_array($payload)
                || ($payload['status'] ?? null) !== BlueprintImportGroundingFieldStatus::GROUNDED->value) {
                throw ValidationException::withMessages([
                    'selected_indexes' => 'Kandidat tidak memenuhi syarat grounding.',
                ]);
            }

            $claim = $payload['claim_raw'] ?? null;
            $claim = is_string($claim) ? trim($claim) : '';

            if ($claim === '' || mb_strlen($claim, 'UTF-8') > $maxText) {
                throw ValidationException::withMessages([
                    'selected_indexes' => 'Teks kandidat kosong atau terlalu panjang.',
                ]);
            }

            $evidence = $payload['material_evidence'] ?? null;

            if (! is_array($evidence) || $evidence === []) {
                throw ValidationException::withMessages([
                    'selected_indexes' => 'Kandidat tidak memiliki bukti materi yang valid.',
                ]);
            }

            foreach ($evidence as $item) {
                $id = is_array($item) ? ($item['profile_element_id'] ?? null) : null;

                if (! is_int($id)) {
                    throw ValidationException::withMessages([
                        'selected_indexes' => 'Bukti materi kandidat tidak valid.',
                    ]);
                }

                $ids[] = $id;
            }
        }

        $unique = array_values(array_unique($ids));
        sort($unique, SORT_NUMERIC);
        $max = (int) config('question_blueprint.max_contexts_per_row', 4);

        if ($unique === [] || count($unique) > $max) {
            throw ValidationException::withMessages([
                'selected_indexes' => 'Jumlah konteks kandidat melebihi batas atau kosong.',
            ]);
        }

        return $unique;
    }

    private function eligibilityMessage(string $code): string
    {
        return match ($code) {
            AssertImportGroundingEligibility::ERROR_PROFILE_REQUIRED => 'Profil impor tidak ditemukan.',
            AssertImportGroundingEligibility::ERROR_PROFILE_NOT_READY => 'Profil impor belum siap.',
            AssertImportGroundingEligibility::ERROR_PROFILE_OWNERSHIP,
            AssertImportGroundingEligibility::ERROR_OWNERSHIP,
            AssertImportGroundingEligibility::ERROR_MATERIAL_MISMATCH => 'Impor tidak sesuai dengan materi pemilik.',
            AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH => 'Sidik jari materi tidak lagi cocok dengan impor.',
            AssertImportGroundingEligibility::ERROR_INTERPRETATION_NOT_READY,
            AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID,
            AssertImportGroundingEligibility::ERROR_IMPORT_NOT_EXTRACTED => 'Impor belum siap dikonversi.',
            default => 'Impor tidak dapat dikonversi.',
        };
    }
}
