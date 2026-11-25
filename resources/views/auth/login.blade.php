@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<div class="cardx guest-card">
    <div class="text-center mb-4">
        <span class="brand-mark mx-auto mb-3" style="width:48px;height:48px;font-size:1.4rem"><i class="bi bi-envelope-paper-fill"></i></span>
        <h1 class="h4 fw-semibold mb-1">Welcome back</h1>
        <p class="text-muted mb-0">Sign in to the {{ config('app.name') }} admin panel</p>
    </div>
    <form method="POST" action="{{ route('login') }}" data-no-loading>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input id="password" type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label" for="remember">Keep me signed in</label>
        </div>
        <button class="btn btn-primary w-100 py-2" type="submit">Sign in</button>
    </form>
</div>
@endsection
