@extends('layouts.app')

@section('title', 'Leave request')

@section('content')
    <h1>{{ $request->leaveType->name }}: {{ $request->start_date->toDateString() }} to {{ $request->end_date->toDateString() }}</h1>

    <dl class="details">
        <dt>Employee</dt><dd>{{ $request->user->name }}</dd>
        <dt>Status</dt><dd>@include('leave-requests._status')</dd>
        <dt>Working days</dt><dd>{{ $request->days + 0 }} ({{ str_replace('_', ' ', $request->day_part->value) }})</dd>
        <dt>Reason</dt><dd>{{ $request->reason }}</dd>
        @if ($request->handover_note)
            <dt>Handover note</dt><dd>{{ $request->handover_note }}</dd>
        @endif
        @if ($request->decider)
            <dt>Decided by</dt><dd>{{ $request->decider->name }}{{ $request->decision_comment ? ': '.$request->decision_comment : '' }}</dd>
        @endif
    </dl>

    @can('cancel', $request)
        @if ($request->status->canTransitionTo(App\Enums\LeaveStatus::Cancelled))
            <form method="POST" action="{{ route('leave-requests.cancel', $request) }}" class="form">
                @csrf
                <div class="field">
                    <label for="cancel-comment">Comment (optional)</label>
                    <input type="text" id="cancel-comment" name="comment" maxlength="1000">
                </div>
                <button type="submit" class="button-secondary">Cancel this request</button>
            </form>
        @endif
    @endcan

    <h2>History</h2>
    <ol class="history">
        @foreach ($request->actions->sortBy('id') as $action)
            <li>
                <time datetime="{{ $action->created_at->toIso8601String() }}">{{ $action->created_at->toDayDateTimeString() }}</time>:
                {{ ucfirst($action->action->value) }} by {{ $action->actor->name }}{{ $action->comment ? ' ('.$action->comment.')' : '' }}
            </li>
        @endforeach
    </ol>
@endsection
