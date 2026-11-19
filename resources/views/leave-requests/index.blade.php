@extends('layouts.app')

@section('title', 'My requests')

@section('content')
    <h1>My leave requests</h1>

    <form method="GET" action="{{ route('leave-requests.index') }}" class="filters">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="filter[status]">
            <option value="">All</option>
            @foreach (App\Enums\LeaveStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected($status === $case->value)>{{ ucfirst($case->value) }}</option>
            @endforeach
        </select>
        <button type="submit" class="button-secondary">Filter</button>
    </form>

    <table>
        <caption>Your requests, newest start date first</caption>
        <thead>
            <tr>
                <th scope="col">Type</th>
                <th scope="col">Dates</th>
                <th scope="col">Days</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $request)
                <tr>
                    <td>{{ $request->leaveType->code }}</td>
                    <td><a href="{{ route('leave-requests.show', $request) }}">{{ $request->start_date->toDateString() }} to {{ $request->end_date->toDateString() }}</a></td>
                    <td>{{ $request->days + 0 }}</td>
                    <td>@include('leave-requests._status')</td>
                    <td>
                        @can('cancel', $request)
                            @if ($request->status->canTransitionTo(App\Enums\LeaveStatus::Cancelled))
                                <form method="POST" action="{{ route('leave-requests.cancel', $request) }}">
                                    @csrf
                                    <button type="submit" class="button-secondary" aria-label="Cancel request {{ $request->start_date->toDateString() }} to {{ $request->end_date->toDateString() }}">Cancel</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">No requests yet. <a href="{{ route('leave-requests.create') }}">Apply for leave</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('leave-requests._pager')
@endsection
