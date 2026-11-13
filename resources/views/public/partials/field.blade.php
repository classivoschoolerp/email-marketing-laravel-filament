@php
    /** @var \App\Models\ListField $field */
    $type = $field->type;
    $value = old($field->tag, $values[$field->tag] ?? null);
    $inputClasses = 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500';
@endphp

@if ($type === \App\Enums\ListFieldType::Hidden)
    <input type="hidden" name="{{ $field->tag }}" value="{{ $value }}">
@else
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700" for="field-{{ $field->tag }}">
            {{ $field->label }}@if ($field->required)<span class="text-red-600">*</span>@endif
        </label>

        @if ($field->help_text)
            <p class="text-xs text-gray-500">{{ $field->help_text }}</p>
        @endif

        @switch($type)
            @case(\App\Enums\ListFieldType::Textarea)
                <textarea id="field-{{ $field->tag }}" name="{{ $field->tag }}" rows="3"
                          class="{{ $inputClasses }}" @required($field->required)>{{ $value }}</textarea>
                @break

            @case(\App\Enums\ListFieldType::Dropdown)
                <select id="field-{{ $field->tag }}" name="{{ $field->tag }}" class="{{ $inputClasses }}" @required($field->required)>
                    <option value="">{{ __('lists.embed.select_placeholder') }}</option>
                    @foreach ($field->options as $option)
                        <option value="{{ $option->value }}" @selected($value === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </select>
                @break

            @case(\App\Enums\ListFieldType::Multiselect)
                <select id="field-{{ $field->tag }}" name="{{ $field->tag }}[]" multiple class="{{ $inputClasses }}">
                    @foreach ($field->options as $option)
                        <option value="{{ $option->value }}" @selected(in_array($option->value, (array) $value, true))>{{ $option->label }}</option>
                    @endforeach
                </select>
                @break

            @case(\App\Enums\ListFieldType::CheckboxList)
                @foreach ($field->options as $option)
                    <label class="mt-1 flex items-center gap-2 text-sm">
                        <input type="checkbox" name="{{ $field->tag }}[]" value="{{ $option->value }}"
                               @checked(in_array($option->value, (array) $value, true))
                               class="rounded border-gray-300 text-emerald-600">
                        {{ $option->label }}
                    </label>
                @endforeach
                @break

            @case(\App\Enums\ListFieldType::Radio)
                @foreach ($field->options as $option)
                    <label class="mt-1 flex items-center gap-2 text-sm">
                        <input type="radio" name="{{ $field->tag }}" value="{{ $option->value }}"
                               @checked($value === $option->value) class="border-gray-300 text-emerald-600">
                        {{ $option->label }}
                    </label>
                @endforeach
                @break

            @case(\App\Enums\ListFieldType::Checkbox)
            @case(\App\Enums\ListFieldType::Consent)
                <label class="mt-1 flex items-center gap-2 text-sm">
                    <input type="checkbox" id="field-{{ $field->tag }}" name="{{ $field->tag }}" value="1"
                           @checked((bool) $value) @required($field->required)
                           class="rounded border-gray-300 text-emerald-600">
                    {{ $type === \App\Enums\ListFieldType::Consent ? ($field->help_text ?: $field->label) : $field->label }}
                </label>
                @break

            @case(\App\Enums\ListFieldType::Country)
                <select id="field-{{ $field->tag }}" name="{{ $field->tag }}" class="{{ $inputClasses }}" @required($field->required)>
                    <option value="">{{ __('lists.embed.select_placeholder') }}</option>
                    @foreach (\App\Support\Geo\Countries::all() as $code => $name)
                        <option value="{{ $code }}" @selected($value === $code)>{{ $name }}</option>
                    @endforeach
                </select>
                @break

            @case(\App\Enums\ListFieldType::Date)
                <input type="date" id="field-{{ $field->tag }}" name="{{ $field->tag }}" value="{{ $value }}"
                       class="{{ $inputClasses }}" @required($field->required)>
                @break

            @case(\App\Enums\ListFieldType::Datetime)
                <input type="datetime-local" id="field-{{ $field->tag }}" name="{{ $field->tag }}"
                       value="{{ is_string($value) ? str_replace(' ', 'T', $value) : $value }}"
                       class="{{ $inputClasses }}" @required($field->required)>
                @break

            @case(\App\Enums\ListFieldType::Number)
            @case(\App\Enums\ListFieldType::Rating)
                <input type="number" id="field-{{ $field->tag }}" name="{{ $field->tag }}" value="{{ $value }}"
                       class="{{ $inputClasses }}" @required($field->required)>
                @break

            @case(\App\Enums\ListFieldType::Email)
                <input type="email" id="field-{{ $field->tag }}" name="{{ $field->tag }}" value="{{ $value }}"
                       class="{{ $inputClasses }}" @required($field->required)>
                @break

            @case(\App\Enums\ListFieldType::Url)
                <input type="url" id="field-{{ $field->tag }}" name="{{ $field->tag }}" value="{{ $value }}"
                       class="{{ $inputClasses }}" @required($field->required)>
                @break

            {{-- F-2.2.9-001 file upload: the stored value is a disk path, so an
                 existing file is shown as a link and re-uploading replaces it. --}}
            @case(\App\Enums\ListFieldType::File)
                <input type="file" id="field-{{ $field->tag }}" name="{{ $field->tag }}"
                       class="{{ $inputClasses }}" @required($field->required && ! $value)>
                @if ($value)
                    <a class="text-xs text-emerald-700 underline" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url((string) $value) }}"
                       target="_blank" rel="noopener">{{ __('lists.fields.current_file') }}</a>
                @endif
                @break

            @default
                <input type="text" id="field-{{ $field->tag }}" name="{{ $field->tag }}" value="{{ $value }}"
                       class="{{ $inputClasses }}" @required($field->required)>
        @endswitch

        @error($field->tag)
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
@endif
