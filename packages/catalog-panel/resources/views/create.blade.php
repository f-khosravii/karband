@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', 'Create New Service')

@section('content')
<div class="card" style="max-width: 700px; margin: 0 auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Create Service</h1>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-top: 0.25rem;">Define a new service code and metadata. Initial state will be safely inactive (Revision 0).</p>
        </div>
    </div>

    <form method="POST" action="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'store') }}">
        @csrf

        <div class="form-group">
            <label class="form-label" for="code">Service Code (Stable Identity)</label>
            <input type="text" id="code" name="code" value="{{ old('code') }}" required placeholder="e.g. standard-service or express-delivery" class="form-control">
            <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem; display: block;">
                Must be lowercase alphanumeric with hyphens. Cannot be changed after creation.
            </small>
        </div>

        <div class="form-group">
            <label class="form-label" for="name">Display Name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. Standard Service" class="form-control">
        </div>

        <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" rows="4" placeholder="Brief summary of what this service provides..." class="form-control">{{ old('description') }}</textarea>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Service</button>
        </div>
    </form>
</div>
@endsection
