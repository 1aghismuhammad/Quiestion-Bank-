@php
    /** @var list<\App\Models\MaterialProfileElement> $items */
    $items = $items ?? [];
    $originLabel = $originLabel ?? '';
    $originClass = $originClass ?? 'status status-muted';
    $withEvidence = $withEvidence ?? false;
@endphp

@if (count($items) > 0)
    <h4 style="margin-bottom: 8px;">{{ $originLabel }} ({{ count($items) }})</h4>
    <ul style="margin-top: 0; padding-left: 20px;">
        @foreach ($items as $element)
            <li style="margin-bottom: 12px;">
                <span class="{{ $originClass }}">{{ $originLabel }}</span>
                {{ $element->text }}

                @if ($withEvidence && filled($element->evidence_excerpt))
                    <blockquote style="margin: 8px 0 0; padding: 10px 12px; border-left: 3px solid #bac5d6; background: #f4f7fb; border-radius: 0 9px 9px 0;">
                        <p style="margin: 0;">&ldquo;{{ $element->evidence_excerpt }}&rdquo;</p>
                        @if ($element->char_start !== null && $element->char_end !== null)
                            <p class="muted" style="margin: 6px 0 0; font-size: 13px;">
                                Sumber: karakter {{ $element->char_start }}&ndash;{{ $element->char_end }} pada materi
                            </p>
                        @endif
                    </blockquote>
                @endif
            </li>
        @endforeach
    </ul>
@endif
