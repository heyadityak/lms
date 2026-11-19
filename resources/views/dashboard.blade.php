@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Hello, {{ auth()->user()->name }}</h1>
    <p><a class="button-primary" href="{{ route('leave-requests.create') }}">Apply for leave</a></p>

    <table>
        <caption>Balances for leave year {{ $year }} ({{ $yearStart->toDateString() }} to {{ $yearEnd->toDateString() }}), in days</caption>
        <thead>
            <tr>
                <th scope="col">Leave type</th>
                <th scope="col">Entitled</th>
                <th scope="col">Used</th>
                <th scope="col">Pending</th>
                <th scope="col">Available</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($balances as $row)
                <tr>
                    <th scope="row">{{ $row['leave_type']->name }} ({{ $row['leave_type']->code }})</th>
                    <td>{{ $row['entitled'] + 0 }}</td>
                    <td>{{ $row['used'] + 0 }}</td>
                    <td>{{ $row['pending'] + 0 }}</td>
                    <td><strong>{{ $row['available'] + 0 }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="5">No leave types with balances.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
