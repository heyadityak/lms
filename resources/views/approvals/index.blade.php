@extends('layouts.app')

@section('title', 'Approvals')

@section('content')
    <h1>Approval inbox</h1>

    @forelse ($requests as $request)
        <article class="card" aria-labelledby="req-{{ $request->public_id }}">
            <h2 id="req-{{ $request->public_id }}">{{ $request->user->name }}: {{ $request->leaveType->code }}, {{ $request->start_date->toDateString() }} to {{ $request->end_date->toDateString() }} ({{ $request->days + 0 }} days)</h2>
            <p>{{ $request->reason }} <a href="{{ route('leave-requests.show', $request) }}">Details</a></p>
            <div class="decisions">
                <form method="POST" action="{{ route('leave-requests.approve', $request) }}">
                    @csrf
                    <label for="approve-{{ $request->public_id }}">Comment (optional)</label>
                    <input type="text" id="approve-{{ $request->public_id }}" name="comment" maxlength="1000">
                    <button type="submit" class="button-primary">Approve</button>
                </form>
                <form method="POST" action="{{ route('leave-requests.reject', $request) }}">
                    @csrf
                    <label for="reject-{{ $request->public_id }}">Reason for rejection (required)</label>
                    <input type="text" id="reject-{{ $request->public_id }}" name="comment" minlength="3" maxlength="1000" required>
                    <button type="submit" class="button-secondary">Reject</button>
                </form>
            </div>
        </article>
    @empty
        <p>Nothing to approve.</p>
    @endforelse
    @include('leave-requests._pager')
@endsection
