<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintImportDocumentKind: string
{
    case BlueprintLike = 'blueprint_like';
    case MatrixIncomplete = 'matrix_incomplete';
    case TaxonomyNonBlueprint = 'taxonomy_non_blueprint';
    case Ambiguous = 'ambiguous';
    case Empty = 'empty';
}
