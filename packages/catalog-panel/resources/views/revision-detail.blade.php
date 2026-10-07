@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', 'Revision ' . $historical->revision . ' - ' . $historical->name)

@section('content')
<div style="margin-bottom: 1.5rem;">
    <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'history', ['code' => $historical->code]) }}" class="btn btn-secondary" style="margin-bottom: 0.5rem; font-size: 0.8rem;">
        &larr; Back to Revision History
    </a>
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="font-size: 1.75rem; font-weight: 700;">{{ $historical->name }}</h1>
            <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.25rem;">
                <span class="code-pill">{{ $historical->code }}</span>
                <span class="badge badge-{{ $historical->status }}">{{ $historical->status }}</span>
                <span class="badge" style="background-color: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid #3b82f6;">
                    Revision {{ $historical->revision }}
                </span>
                @if ($isLatest)
                    <span class="badge badge-active">Current Live Version</span>
                @else
                    <span class="badge badge-inactive">Historical Snapshot</span>
                @endif
            </div>
        </div>
        <div>
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $historical->code]) }}" class="btn btn-primary">
                View Live Service
            </a>
        </div>
    </div>
</div>

<div class="alert alert-warning">
    <strong>Immutable Historical Record:</strong>
    You are inspecting exact persistent Revision {{ $historical->revision }} of service <span class="code-pill">{{ $historical->code }}</span>. Historical catalog records cannot be modified.
</div>

<div class="grid-2">
    <div class="card">
        <h2 class="card-title" style="margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Revision Metadata</h2>
        <dl class="meta-list">
            <dt>Service Code</dt>
            <dd><span class="code-pill">{{ $historical->code }}</span></dd>

            <dt>Display Name</dt>
            <dd>{{ $historical->name }}</dd>

            <dt>Description</dt>
            <dd>{{ $historical->description ?: 'No description provided.' }}</dd>

            <dt>Status at Revision {{ $historical->revision }}</dt>
            <dd><span class="badge badge-{{ $historical->status }}">{{ $historical->status }}</span></dd>

            <dt>Revision Number</dt>
            <dd>{{ $historical->revision }}</dd>
        </dl>
    </div>

    <div class="card">
        <h2 class="card-title" style="margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Financial Terms at Revision {{ $historical->revision }}</h2>
        <dl class="meta-list">
            <dt>Configured Price</dt>
            <dd>
                @if ($historical->formattedPrice)
                    <strong style="font-size: 1.25rem; color: #38bdf8;">{{ $historical->formattedPrice }}</strong>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        ({{ $historical->fixedPriceMinor }} minor units {{ $historical->currency }})
                    </div>
                @else
                    <span style="color: var(--text-muted);">No price configured at this revision.</span>
                @endif
            </dd>

            <dt>Pricing Mode</dt>
            <dd>{{ $historical->pricingMode ?: 'fixed_catalog' }}</dd>

            <dt>Workflow Binding</dt>
            <dd>
                @if ($historical->workflowId)
                    <span class="code-pill">{{ $historical->workflowId }}</span> (v{{ $historical->workflowVersion }})
                @else
                    <span style="color: var(--text-muted);">No workflow binding</span>
                @endif
            </dd>
        </dl>
    </div>
</div>
@endsection
