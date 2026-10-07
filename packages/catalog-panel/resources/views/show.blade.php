@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', $service->name . ' - Service Detail')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'index') }}" class="btn btn-secondary" style="margin-bottom: 0.5rem; font-size: 0.8rem;">
            &larr; Back to Services
        </a>
        <h1 style="font-size: 1.75rem; font-weight: 700;">{{ $service->name }}</h1>
        <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.25rem;">
            <span class="code-pill">{{ $service->code }}</span>
            <span class="badge badge-{{ $service->status }}">{{ $service->status }}</span>
            <span style="color: var(--text-muted); font-size: 0.875rem;">Current Revision: <strong>{{ $service->revision }}</strong></span>
        </div>
    </div>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'history', ['code' => $service->code]) }}" class="btn btn-secondary">
            Revision History
        </a>

        @if ($canUpdate)
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'edit', ['code' => $service->code]) }}" class="btn btn-secondary">
                Edit Metadata
            </a>
        @endif

        @if ($canPrice)
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'edit-price', ['code' => $service->code]) }}" class="btn btn-secondary">
                Configure Price
            </a>
        @endif

        @if ($service->status === 'inactive' && $canActivate)
            <form method="POST" action="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'activate', ['code' => $service->code]) }}" style="display: inline;">
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $service->revision }}">
                <button type="submit" class="btn btn-success" onclick="return confirm('Activate service {{ $service->code }}?');">
                    Activate Service
                </button>
            </form>
        @endif

        @if ($service->status === 'active' && $canDeactivate)
            <form method="POST" action="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'deactivate', ['code' => $service->code]) }}" style="display: inline;">
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $service->revision }}">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Deactivate service {{ $service->code }}?');">
                    Deactivate Service
                </button>
            </form>
        @endif
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2 class="card-title" style="margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Service Metadata</h2>
        <dl class="meta-list">
            <dt>Service Code</dt>
            <dd><span class="code-pill">{{ $service->code }}</span></dd>

            <dt>Display Name</dt>
            <dd>{{ $service->name }}</dd>

            <dt>Description</dt>
            <dd>{{ $service->description ?: 'No description provided.' }}</dd>

            <dt>Lifecycle Status</dt>
            <dd><span class="badge badge-{{ $service->status }}">{{ $service->status }}</span></dd>

            <dt>Active Revision</dt>
            <dd>{{ $service->revision }}</dd>
        </dl>
    </div>

    <div class="card">
        <h2 class="card-title" style="margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Financial Terms</h2>
        <dl class="meta-list">
            <dt>Configured Price</dt>
            <dd>
                @if ($service->formattedPrice)
                    <strong style="font-size: 1.25rem; color: #38bdf8;">{{ $service->formattedPrice }}</strong>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        ({{ $service->fixedPriceMinor }} minor units {{ $service->currency }})
                    </div>
                @else
                    <span style="color: var(--text-muted);">No price configured yet.</span>
                @endif
            </dd>

            <dt>Pricing Mode</dt>
            <dd>{{ $service->pricingMode ?: 'fixed_catalog' }}</dd>

            <dt>Workflow Binding</dt>
            <dd>
                @if ($service->workflowId)
                    <span class="code-pill">{{ $service->workflowId }}</span> (v{{ $service->workflowVersion }})
                @else
                    <span style="color: var(--text-muted);">No workflow binding</span>
                @endif
            </dd>
        </dl>
    </div>
</div>
@endsection
