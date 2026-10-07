@extends('directory-panel::layouts.app')

@section('title', 'Edit ' . $org->name)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.show', ['id' => $org->id]) }}" style="color: var(--text-secondary); text-decoration: none; font-size: 0.9rem;">
            &larr; Back to Organization Details
        </a>
        <h1 class="page-title" style="margin-top: 0.5rem;">Edit Organization</h1>
        <p class="page-desc">ID: <code>{{ $org->id }}</code></p>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.update', ['id' => $org->id]) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label>Organization ID</label>
            <input type="text" value="{{ $org->id }}" class="form-control" disabled style="opacity: 0.6; cursor: not-allowed;">
            <span style="font-size: 0.8rem; color: var(--text-muted);">Organization identity is immutable.</span>
        </div>

        <div class="form-group">
            <label for="name">Organization Name *</label>
            <input type="text" id="name" name="name" value="{{ old('name', $org->name) }}" class="form-control" required>
        </div>

        <div class="form-group">
            <label for="email">Contact Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $org->email) }}" class="form-control">
        </div>

        <div class="form-group">
            <label for="phone">Contact Phone</label>
            <input type="text" id="phone" name="phone" value="{{ old('phone', $org->phone) }}" class="form-control">
        </div>

        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.show', ['id' => $org->id]) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>
@endsection
