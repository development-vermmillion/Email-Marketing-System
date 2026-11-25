@extends('layouts.guest')
@section('title', 'Email preferences')
@section('content')
<div class="cardx" style="max-width:480px;width:100%"><div class="cardx-body text-center p-4 p-md-5">
    @if ($justChanged === 'unsubscribed' || ($unsubscribed && ! $justChanged))
        <div class="display-5 text-success mb-2"><i class="bi bi-check-circle"></i></div>
        <h1 class="h4">You're unsubscribed</h1>
        <p class="text-muted">{{ $notice }}</p>
        <p class="small text-muted">{{ $maskedEmail }}</p>
        <form method="POST" action="{{ route('unsubscribe.resubscribe', $token) }}" class="mt-4">@csrf
            <p class="small text-muted mb-2">Changed your mind?</p><button class="btn btn-outline-primary btn-sm">Subscribe again</button></form>
    @elseif ($justChanged === 'resubscribed')
        <div class="display-5 text-primary mb-2"><i class="bi bi-envelope-heart"></i></div>
        <h1 class="h4">Welcome back</h1>
        <p class="text-muted">You will receive our emails again at {{ $maskedEmail }}.</p>
    @elseif ($justChanged === 'blocked')
        <div class="display-5 text-warning mb-2"><i class="bi bi-exclamation-triangle"></i></div>
        <h1 class="h4">We can't resubscribe this address</h1>
        <p class="text-muted">This address is blocked because of an earlier bounce or complaint. Please contact {{ $company }} if you think this is a mistake.</p>
    @else
        <div class="display-5 text-primary mb-2"><i class="bi bi-envelope-slash"></i></div>
        <h1 class="h4">Unsubscribe from {{ $company }}?</h1>
        <p class="text-muted">{{ $maskedEmail }} will stop receiving our marketing emails.</p>
        <form method="POST" action="{{ route('unsubscribe.store', $token) }}" class="mt-4">@csrf <button class="btn btn-primary px-4">Yes, unsubscribe me</button></form>
        <p class="small text-muted mt-3 mb-0">Not you? Close this page; nothing changes until you confirm.</p>
    @endif
</div></div>
@endsection
