@extends('directory-panel::layouts.app')

@section('title', 'Create User')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.index') }}" style="color: var(--text-secondary); text-decoration: none; font-size: 0.9rem;">
            &larr; Back to Users
        </a>
        <h1 class="page-title" style="margin-top: 0.5rem;">Create User</h1>
        <p class="page-desc">Add a new person identity record to the Directory.</p>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.store') }}">
        @csrf

        <div class="form-group">
            <label for="id">User ID *</label>
            <input type="text" id="id" name="id" value="{{ old('id') }}" class="form-control" placeholder="e.g. usr_1001 or ULID" required>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Unique, immutable identifier for this person/user record.</span>
        </div>

        <div class="form-group">
            <label for="display_name">Display Name *</label>
            <input type="text" id="display_name" name="display_name" value="{{ old('display_name') }}" class="form-control" placeholder="e.g. Sara Rad" required>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control" placeholder="sara@organization.gov">
        </div>

        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="{{ old('phone') }}" class="form-control" placeholder="+98912...">
        </div>

        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create User</button>
        </div>
    </form>
</div>
@endsection
