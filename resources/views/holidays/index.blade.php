@extends('layouts.app')

@section('title', 'Holidays')

@section('content')
    <h1>Holidays {{ $year }}</h1>
    <nav aria-label="Leave years" class="pager">
        <a href="{{ route('holidays.index', ['year' => $year - 1]) }}" rel="prev">{{ $year - 1 }}</a>
        <a href="{{ route('holidays.index', ['year' => $year + 1]) }}" rel="next">{{ $year + 1 }}</a>
    </nav>
    <table>
        <caption>Holidays in leave year {{ $year }}</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Holiday</th></tr></thead>
        <tbody>
            @forelse ($holidays as $holiday)
                <tr><td><time datetime="{{ $holiday->date->toDateString() }}">{{ $holiday->date->format('D, j M Y') }}</time></td><td>{{ $holiday->name }}</td></tr>
            @empty
                <tr><td colspan="2">No holidays in this leave year.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
