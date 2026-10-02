@php
    /** @var list<\App\Models\MaterialProfileElement> $items */
    $items = $items ?? [];
    $originLabel = $originLabel ?? '';
    $originClass = $originClass ?? 'status status-muted';
    $withEvidence = $withEvidence ?? false;
@endphp

@if (count($items) > 0)
    <h4 class="material-profile-origin">{{ $originLabel }} ({{ count($items) }})</h4>
    <div class="material-profile-items">
        @foreach ($items as $element)
            <article class="material-profile-item">
                <span class="{{ $originClass }}">{{ $originLabel }}</span>
                <p>{{ $element->text }}</p>

                @if ($withEvidence && filled($element->evidence_excerpt))
                    <blockquote class="material-profile-evidence">
                        <p>&ldquo;{{ $element->evidence_excerpt }}&rdquo;</p>
                        @if ($element->char_start !== null && $element->char_end !== null)
                            <p class="muted">
                                Sumber: karakter {{ $element->char_start }}&ndash;{{ $element->char_end }} pada materi
                            </p>
                        @endif
                    </blockquote>
                @endif
            </article>
        @endforeach
    </div>
@endif
