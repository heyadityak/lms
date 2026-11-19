<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · @endif{{ $brand->name }}</title>
    <link rel="icon" href="{{ $brand->faviconUrl }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    {{-- Hex values are validated on write and on read (Brand::fromSettings), so nothing else can reach this block (D-16). --}}
    <style>:root{--brand-primary:{{ $brand->primary }};--brand-accent:{{ $brand->accent }};--brand-primary-contrast:{{ $brand->contrast }};}</style>
</head>
<body>
    <a class="skip-link" href="#main">Skip to main content</a>
    <header class="site-header">
        <a class="brand" href="{{ url('/') }}">
            <img src="{{ $brand->logoUrl }}" alt="" height="32">
            <span>{{ $brand->name }}</span>
        </a>
        @auth
            {{-- Admin links (with @can) arrive with their routes in phases 8 and 9. --}}
            <nav aria-label="Main">
                <ul>
                    <li><a href="{{ url('/') }}" @if (request()->is('/')) aria-current="page" @endif>Dashboard</a></li>
                    <li><a href="{{ route('leave-requests.create') }}" @if (request()->routeIs('leave-requests.create')) aria-current="page" @endif>Apply</a></li>
                    <li><a href="{{ route('leave-requests.index') }}" @if (request()->routeIs('leave-requests.index')) aria-current="page" @endif>My requests</a></li>
                    @can('viewApprovals', App\Models\LeaveRequest::class)
                        <li><a href="{{ route('approvals.index') }}" @if (request()->routeIs('approvals.index')) aria-current="page" @endif>Approvals</a></li>
                    @endcan
                    @module('holidays')
                        <li><a href="{{ url('/holidays') }}" @if (request()->is('holidays')) aria-current="page" @endif>Holidays</a></li>
                    @endmodule
                </ul>
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="logout">
                @csrf
                <button type="submit">Log out</button>
            </form>
        @else
            <a class="button" href="{{ route('login') }}">Log in</a>
        @endauth
    </header>
    <main id="main" tabindex="-1">
        @if (session('status'))
            <p class="notice" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="errors" role="alert">
                <h2>Please fix the following</h2>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
