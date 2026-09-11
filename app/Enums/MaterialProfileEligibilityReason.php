<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Owner-presentation reasons for whether a Material can start profile analysis.
 *
 * These values are not workflow error codes and are never written onto a
 * terminal Profile Version.
 */
enum MaterialProfileEligibilityReason: string
{
    case Eligible = 'eligible';
    case ExtractionIncomplete = 'extraction_incomplete';
    case MaterialNotReady = 'material_not_ready';
    case MaterialEmpty = 'material_empty';
    case MaterialTooLarge = 'material_too_large';
}
