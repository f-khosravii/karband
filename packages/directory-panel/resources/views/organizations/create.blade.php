@extends('directory-panel::layouts.app')

@section('title', 'Create Organization')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}" style="color: var(--text-secondary); text-decoration: none; font-size: 0.9rem;">
            &larr; Back to Organizations
        </a>
        <h1 class="page-title" style="margin-top: 0.5rem;">Create Organization</h1>
        <p class="page-desc">Add a new organization identity to the Directory.</p>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.store') }}">
        @csrf

        <div class="form-group">
            <label for="id">Organization ID / Code *</label>
            <input type="text" id="id" name="id" value="{{ old('id') }}" class="form-control" placeholder="e.g. org_tehran_tax or ULID" required>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Unique, immutable identifier for this organization.</span>
        </div>

        <div class="form-group">
            <label for="name">Organization Name *</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-control" placeholder="e.g. Tehran Tax Administration" required>
        </div>

        <div class="form-group">
            <label for="email">Contact Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control" placeholder="contact@organization.gov">
        </div>

        <div class="form-group">
            <label for="phone">Contact Phone</label>
            <input type="text" id="phone" name="phone" value="{{ old('phone') }}" class="form-control" placeholder="+98...">
        </div>

        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Organization</button>
        </div>
    </form>
</div>
@endsection
