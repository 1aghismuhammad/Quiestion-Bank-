<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

use RuntimeException;

class BlueprintCandidateValidationException extends RuntimeException
{
    public const REASON_UNSUPPORTED_ENUM = 'unsupported_enum';
    public const REASON_MISSING_CONTEXT = 'missing_context';
    public const REASON_UNKNOWN_CONTEXT = 'unknown_context';
    public const REASON_EVIDENCE_NOT_FOUND = 'evidence_not_found';
    public const REASON_EVIDENCE_AMBIGUOUS = 'evidence_ambiguous';
    public const REASON_CONTEXT_BOUNDARY_CROSSED = 'context_boundary_crossed';
    public const REASON_SHAPE_INVALID = 'shape_invalid';
    public const REASON_TOTAL_MISMATCH = 'total_mismatch';
    public const REASON_TYPE_COMPOSITION_MISMATCH = 'type_composition_mismatch';
    public const REASON_SERVER_OWNED_FIELD = 'server_owned_field';
    public const REASON_QUESTION_TYPE_INVALID = 'question_type_invalid';
    public const REASON_TEXT_INVALID = 'text_invalid';
    public const REASON_FOREIGN_PROFILE_ELEMENT = 'foreign_profile_element';
    public const REASON_FOREIGN_PROFILE_CHUNK = 'foreign_profile_chunk';
    public const REASON_MIXED_PROFILE_REFERENCES = 'mixed_profile_references';
    public const REASON_DUPLICATE_CONTEXT_REF = 'duplicate_context_ref';
    public const REASON_MISSING_PROFILE_REFERENCE = 'missing_profile_reference';

    public function __construct(
        string $message = 'The blueprint provider candidates are invalid.',
        public readonly ?string $internalReason = null,
    ) {
        parent::__construct($message);
    }
}
