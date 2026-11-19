@extends('layouts.app')

@section('title', 'Apply for leave')

@section('content')
    <h1>Apply for leave</h1>
    <p>Working days are counted for you: weekends and holidays are excluded.</p>

    <form method="POST" action="{{ route('leave-requests.store') }}" class="form" novalidate>
        @csrf
        <div class="field">
            <label for="leave_type_id">Leave type</label>
            <select id="leave_type_id" name="leave_type_id" required @error('leave_type_id') aria-invalid="true" aria-describedby="leave_type_id-error" @enderror>
                @foreach ($types as $type)
                    <option value="{{ $type->public_id }}" @selected(old('leave_type_id') === $type->public_id)>{{ $type->name }} ({{ $type->code }})</option>
                @endforeach
            </select>
            @error('leave_type_id') <p class="field-error" id="leave_type_id-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="start_date">Start date</label>
            <input type="date" id="start_date" name="start_date" value="{{ old('start_date') }}" required @error('start_date') aria-invalid="true" aria-describedby="start_date-error" @enderror>
            @error('start_date') <p class="field-error" id="start_date-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="end_date">End date</label>
            <input type="date" id="end_date" name="end_date" value="{{ old('end_date') }}" required @error('end_date') aria-invalid="true" aria-describedby="end_date-error" @enderror>
            @error('end_date') <p class="field-error" id="end_date-error">{{ $message }}</p> @enderror
        </div>
        <fieldset class="field">
            <legend>Day part</legend>
            @foreach ($dayParts as $part)
                <label><input type="radio" name="day_part" value="{{ $part->value }}" @checked(old('day_part', 'full') === $part->value)> {{ str_replace('_', ' ', ucfirst($part->value)) }}</label>
            @endforeach
            <p class="hint">Half days are only possible when start and end date are the same.</p>
            @error('day_part') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>
        <div class="field">
            <label for="reason">Reason</label>
            <textarea id="reason" name="reason" rows="3" minlength="3" maxlength="1000" required @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror>{{ old('reason') }}</textarea>
            @error('reason') <p class="field-error" id="reason-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="handover_note">Handover note (optional)</label>
            <textarea id="handover_note" name="handover_note" rows="3" maxlength="2000">{{ old('handover_note') }}</textarea>
        </div>
        <button type="submit" class="button-primary">Submit request</button>
    </form>
@endsection
