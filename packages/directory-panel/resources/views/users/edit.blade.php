@extends('directory-panel::layouts.app')

@section('title', 'Edit ' . $user->displayName)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.show', ['id' => $user->id]) }}" style="color: var(--text-secondary); text-decoration: none; font-size: 0.9rem;">
            &larr; Back to User Details
        </a>
        <h1 class="page-title" style="margin-top: 0.5rem;">Edit User</h1>
        <p class="page-desc">ID: <code>{{ $user->id }}</code></p>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.update', ['id' => $user->id]) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label>User ID</label>
            <input type="text" value="{{ $user->id }}" class="form-control" disabled style="opacity: 0.6; cursor: not-allowed;">
            <span style="font-size: 0.8rem; color: var(--text-muted);">User identity is immutable.</span>
        </div>

        <div class="form-group">
            <label for="display_name">Display Name *</label>
            <input type="text" id="display_name" name="display_name" value="{{ old('display_name', $user->displayName) }}" class="form-control" required>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" class="form-control">
        </div>

        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control">
        </div>

        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.show', ['id' => $user->id]) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>
@endsection
