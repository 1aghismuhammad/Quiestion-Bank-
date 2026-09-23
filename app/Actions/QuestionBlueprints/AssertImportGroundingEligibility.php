<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportDocumentKind;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Enums\MaterialProfileStatus;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use App\Services\QuestionBlueprints\BlueprintImportInterpretationResultBuilder;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class AssertImportGroundingEligibility
{
    public const ERROR_OWNERSHIP = 'ownership_mismatch';

    public const ERROR_MATERIAL_MISMATCH = 'material_mismatch';

    public const ERROR_IMPORT_NOT_EXTRACTED = 'import_not_extracted';

    public const ERROR_INTERPRETATION_NOT_READY = 'interpretation_not_ready';

    public const ERROR_INTERPRETATION_INVALID = 'interpretation_invalid';

    public const ERROR_PROFILE_REQUIRED = 'profile_required';

    public const ERROR_PROFILE_NOT_READY = 'profile_not_ready';

    public const ERROR_PROFILE_OWNERSHIP = 'profile_ownership_mismatch';

    public const ERROR_FINGERPRINT_MISMATCH = 'fingerprint_mismatch';

    public function __construct(private MaterialContentHasher $hasher) {}

    /**
     * @return array{
     *     import: QuestionBlueprintImport,
     *     material: Material,
     *     profile: MaterialProfileVersion,
     *     interpretationResult: array<string, mixed>,
     *     interpretationSha256: string,
     *     fingerprint: array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}
     * }
     */
    public function handle(QuestionBlueprintImport $import, ?User $actor = null): array
    {
        $importId = (int) $import->import_id;

        $freshImport = QuestionBlueprintImport::query()->whereKey($importId)->first();

        if ($freshImport === null) {
            throw new InvalidArgumentException(self::ERROR_MATERIAL_MISMATCH);
        }

        $material = Material::query()
            ->whereKey((int) $freshImport->material_id)
            ->first();

        if (! $material instanceof Material) {
            throw new InvalidArgumentException(self::ERROR_MATERIAL_MISMATCH);
        }

        if ($actor !== null) {
            $this->assertOwner($actor, $freshImport, $material);
        }

        if ((int) $freshImport->material_id !== (int) $material->material_id) {
            throw new InvalidArgumentException(self::ERROR_MATERIAL_MISMATCH);
        }

        if ($freshImport->status !== BlueprintImportStatus::EXTRACTED) {
            throw new InvalidArgumentException(self::ERROR_IMPORT_NOT_EXTRACTED);
        }

        if ($freshImport->interpretation_status !== BlueprintImportInterpretationStatus::REVIEW_READY) {
            throw new InvalidArgumentException(self::ERROR_INTERPRETATION_NOT_READY);
        }

        $rawInterpretation = $freshImport->getRawOriginal('interpretation_result');
        $interpretationSha256 = hash('sha256', (string) $rawInterpretation);

        if (is_string($rawInterpretation) && $rawInterpretation !== '') {
            $decoded = json_decode($rawInterpretation, true);
        } else {
            $decoded = null;
        }

        if (! is_array($decoded) || ! $this->isValidInterpretation($decoded)) {
            throw new InvalidArgumentException(self::ERROR_INTERPRETATION_INVALID);
        }

        $profileVersionId = $freshImport->profile_version_id;

        if ($profileVersionId === null) {
            throw new InvalidArgumentException(self::ERROR_PROFILE_REQUIRED);
        }

        $profile = MaterialProfileVersion::query()
            ->whereKey((int) $profileVersionId)
            ->first();

        if ($profile === null) {
            throw new InvalidArgumentException(self::ERROR_PROFILE_REQUIRED);
        }

        if ($profile->status !== MaterialProfileStatus::READY) {
            throw new InvalidArgumentException(self::ERROR_PROFILE_NOT_READY);
        }

        if ((int) $profile->user_id !== (int) $freshImport->user_id
            || (int) $profile->material_id !== (int) $freshImport->material_id) {
            throw new InvalidArgumentException(self::ERROR_PROFILE_OWNERSHIP);
        }

        $importFingerprint = $this->importFingerprint($freshImport);
        $profileFingerprint = $this->profileFingerprint($profile);
        $liveFingerprint = $this->liveMaterialFingerprint($material);

        if (! $this->fingerprintsMatch($importFingerprint, $profileFingerprint)
            || ! $this->fingerprintsMatch($importFingerprint, $liveFingerprint)) {
            throw new InvalidArgumentException(self::ERROR_FINGERPRINT_MISMATCH);
        }

        return [
            'import' => $freshImport,
            'material' => $material,
            'profile' => $profile,
            'interpretationResult' => $decoded,
            'interpretationSha256' => $interpretationSha256,
            'fingerprint' => $importFingerprint,
        ];
    }

    /**
     * @return array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}
     */
    public function importFingerprint(QuestionBlueprintImport $import): array
    {
        return [
            'material_content_hash' => (string) $import->material_content_hash,
            'material_file_hash' => $import->material_file_hash,
            'extractor_implementation' => (string) $import->extractor_implementation,
        ];
    }

    /**
     * @return array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}
     */
    public function profileFingerprint(MaterialProfileVersion $profile): array
    {
        return [
            'material_content_hash' => (string) $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => (string) $profile->extractor_implementation,
        ];
    }

    /**
     * @return array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}
     */
    public function liveMaterialFingerprint(Material $material): array
    {
        return [
            'material_content_hash' => $this->hasher->hash((string) $material->content),
            'material_file_hash' => $material->file_hash,
            'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
        ];
    }

    /**
     * @param  array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}  $left
     * @param  array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}  $right
     */
    public function fingerprintsMatch(array $left, array $right): bool
    {
        return $left['material_content_hash'] === $right['material_content_hash']
            && $left['extractor_implementation'] === $right['extractor_implementation']
            && $left['material_file_hash'] === $right['material_file_hash'];
    }

    private function assertOwner(User $actor, QuestionBlueprintImport $import, Material $material): void
    {
        if ((int) $import->user_id !== (int) $actor->id
            || (int) $material->user_id !== (int) $actor->id) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isValidInterpretation(array $result): bool
    {
        if (($result['schema_version'] ?? null) !== BlueprintImportInterpretationResult::SCHEMA_VERSION) {
            return false;
        }

        $kindRaw = $result['document_kind'] ?? null;

        if (! is_string($kindRaw) || BlueprintImportDocumentKind::tryFrom($kindRaw) === null) {
            return false;
        }

        if (! array_key_exists('warnings', $result)
            || ! is_array($result['warnings'])
            || ! array_is_list($result['warnings'])) {
            return false;
        }

        foreach ($result['warnings'] as $warning) {
            if (! is_string($warning)) {
                return false;
            }
        }

        if (! array_key_exists('candidates', $result)
            || ! is_array($result['candidates'])
            || ! array_is_list($result['candidates'])) {
            return false;
        }

        $maxCandidates = max(1, (int) config('question_blueprint.max_import_candidates', 100));

        if (count($result['candidates']) > $maxCandidates) {
            return false;
        }

        if (
            in_array($kindRaw, [
                BlueprintImportDocumentKind::TaxonomyNonBlueprint->value,
                BlueprintImportDocumentKind::Empty->value,
            ], true)
            && $result['candidates'] !== []
        ) {
            return false;
        }

        foreach ($result['candidates'] as $candidate) {
            if (! $this->isValidInterpretationCandidate($candidate)) {
                return false;
            }
        }

        return true;
    }

    private function isValidInterpretationCandidate(mixed $candidate): bool
    {
        if (! is_array($candidate)) {
            return false;
        }

        foreach (['cognitive_level', 'difficulty', 'question_type', 'assessment_type', 'requested_count'] as $canonicalKey) {
            if (! array_key_exists($canonicalKey, $candidate) || $candidate[$canonicalKey] !== null) {
                return false;
            }
        }

        if (! array_key_exists('source_refs', $candidate) || ! is_array($candidate['source_refs'])) {
            return false;
        }

        if (! array_key_exists('warnings', $candidate)
            || ! is_array($candidate['warnings'])
            || ! array_is_list($candidate['warnings'])) {
            return false;
        }

        foreach ($candidate['warnings'] as $warning) {
            if (! is_string($warning)) {
                return false;
            }
        }

        if (! array_key_exists('unresolved', $candidate)
            || ! is_array($candidate['unresolved'])
            || ! array_is_list($candidate['unresolved'])) {
            return false;
        }

        foreach ($candidate['unresolved'] as $marker) {
            if (! is_string($marker)
                || ! in_array($marker, BlueprintImportInterpretationResultBuilder::SEMANTIC_KEYS, true)) {
                return false;
            }
        }

        $refCount = 0;

        foreach (BlueprintImportInterpretationResultBuilder::SEMANTIC_KEYS as $key) {
            $rawKey = 'raw_'.$key;

            if (! array_key_exists($rawKey, $candidate)) {
                return false;
            }

            $rawValue = $candidate[$rawKey];

            if ($rawValue !== null && ! is_string($rawValue)) {
                return false;
            }

            if (! array_key_exists($key, $candidate['source_refs'])) {
                return false;
            }

            $refs = $candidate['source_refs'][$key];

            if (! is_array($refs) || ! array_is_list($refs)) {
                return false;
            }

            foreach ($refs as $ref) {
                if (! $this->isValidSourceRef($ref)) {
                    return false;
                }
            }

            $refCount += count($refs);
        }

        return $refCount > 0;
    }

    private function isValidSourceRef(mixed $ref): bool
    {
        if (! is_array($ref)) {
            return false;
        }

        $kind = $ref['kind'] ?? null;

        if ($kind === 'paragraph') {
            $allowedKeys = ['kind', 'block_ordinal', 'role'];

            if (array_diff(array_keys($ref), $allowedKeys) !== []) {
                return false;
            }

            if (! array_key_exists('block_ordinal', $ref)
                || ! is_int($ref['block_ordinal'])
                || $ref['block_ordinal'] < 0) {
                return false;
            }

            return $this->roleIsAllowed($ref, ['paragraph']);
        }

        if ($kind === 'cell') {
            $allowedKeys = [
                'kind',
                'block_ordinal',
                'table_index',
                'row_index',
                'cell_index',
                'paragraph_indexes',
                'role',
            ];

            if (array_diff(array_keys($ref), $allowedKeys) !== []) {
                return false;
            }

            foreach (['block_ordinal', 'table_index', 'row_index', 'cell_index'] as $coordinate) {
                if (! array_key_exists($coordinate, $ref)
                    || ! is_int($ref[$coordinate])
                    || $ref[$coordinate] < 0) {
                    return false;
                }
            }

            if (! $this->roleIsAllowed($ref, ['cell', 'row_header', 'column_header', 'intersection'])) {
                return false;
            }

            if (array_key_exists('paragraph_indexes', $ref)
                && ! $this->isValidParagraphIndexes($ref['paragraph_indexes'])) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function roleIsAllowed(array $ref, array $allowed): bool
    {
        if (! array_key_exists('role', $ref) || $ref['role'] === null) {
            return true;
        }

        return is_string($ref['role']) && in_array($ref['role'], $allowed, true);
    }

    private function isValidParagraphIndexes(mixed $indexes): bool
    {
        if (! is_array($indexes) || ! array_is_list($indexes)) {
            return false;
        }

        $previous = null;
        $seen = [];

        foreach ($indexes as $index) {
            if (! is_int($index) || $index < 0) {
                return false;
            }

            if (isset($seen[$index])) {
                return false;
            }

            if ($previous !== null && $index <= $previous) {
                return false;
            }

            $seen[$index] = true;
            $previous = $index;
        }

        return true;
    }
}
