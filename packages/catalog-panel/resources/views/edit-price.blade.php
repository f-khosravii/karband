@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', 'Configure Price - ' . $service->name)

@section('content')
<div class="card" style="max-width: 700px; margin: 0 auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Configure Service Price</h1>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-top: 0.25rem;">Changing financial terms advances the catalog to a new revision (Current: Revision {{ $service->revision }}).</p>
        </div>
    </div>

    <form method="POST" action="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'update-price', ['code' => $service->code]) }}">
        @csrf
        <input type="hidden" name="expected_revision" value="{{ $service->revision }}">

        <div class="form-group">
            <label class="form-label">Service Code</label>
            <input type="text" value="{{ $service->code }}" disabled class="form-control" style="opacity: 0.7; cursor: not-allowed;">
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label class="form-label" for="price">Price Amount (Major Units)</label>
                <input type="number" step="0.01" min="0" id="price" name="price" value="{{ old('price', $priceMajor) }}" required placeholder="e.g. 13.00" class="form-control">
                <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem; display: block;">
                    Entered as human major units (e.g. 13.00), persisted safely as integer minor units (1300).
                </small>
            </div>

            <div class="form-group">
                <label class="form-label" for="currency">Currency Code</label>
                <input type="text" maxlength="3" id="currency" name="currency" value="{{ old('currency', $service->currency ?: 'EUR') }}" required placeholder="EUR" class="form-control" style="text-transform: uppercase;">
                <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem; display: block;">
                    ISO 4217 code (EUR, USD, etc.)
                </small>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $service->code]) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Price</button>
        </div>
    </form>
</div>
@endsection
