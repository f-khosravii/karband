@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', 'Edit Metadata - ' . $service->name)

@section('content')
<div class="card" style="max-width: 700px; margin: 0 auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Edit Service Metadata</h1>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-top: 0.25rem;">Updating metadata creates an immutable new revision (Current: Revision {{ $service->revision }}).</p>
        </div>
    </div>

    <form method="POST" action="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'update', ['code' => $service->code]) }}">
        @csrf
        <input type="hidden" name="expected_revision" value="{{ $service->revision }}">

        <div class="form-group">
            <label class="form-label">Service Code</label>
            <input type="text" value="{{ $service->code }}" disabled class="form-control" style="opacity: 0.7; cursor: not-allowed;">
            <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem; display: block;">Service code identity is immutable.</small>
        </div>

        <div class="form-group">
            <label class="form-label" for="name">Display Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $service->name) }}" required class="form-control">
        </div>

        <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" rows="4" class="form-control">{{ old('description', $service->description) }}</textarea>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $service->code]) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>
@endsection
