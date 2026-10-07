@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', 'Revision History - ' . $service->name)

@section('content')
<div class="card">
    <div class="card-header">
        <div>
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $service->code]) }}" class="btn btn-secondary" style="margin-bottom: 0.5rem; font-size: 0.8rem;">
                &larr; Back to {{ $service->name }}
            </a>
            <h1 class="card-title">Catalog Revision History</h1>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-top: 0.25rem;">
                Exact persisted historical service definitions for <strong>{{ $service->name }}</strong> (<span class="code-pill">{{ $service->code }}</span>).
            </p>
        </div>
        <div>
            <span class="badge badge-{{ $service->status }}">{{ $service->status }}</span>
            <span style="color: var(--text-muted); font-size: 0.875rem; margin-left: 0.5rem;">Current: Rev {{ $service->revision }}</span>
        </div>
    </div>

    <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
        <strong>Configuration History vs Financial Snapshots:</strong>
        This view shows the immutable evolution of catalog definitions (pricing terms, metadata, status). Financial transactions (Payment Attempts) continue to independently snapshot their own immutable historical pricing terms at time of request creation.
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Revision</th>
                    <th>Display Name</th>
                    <th>Status</th>
                    <th>Configured Price</th>
                    <th>Workflow Binding</th>
                    <th>Recorded At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($revisions as $rev)
                    <tr style="{{ $rev->revision === $service->revision ? 'background-color: rgba(59, 130, 246, 0.05); font-weight: 500;' : '' }}">
                        <td>
                            <strong>Rev {{ $rev->revision }}</strong>
                            @if ($rev->revision === $service->revision)
                                <span class="badge badge-active" style="margin-left: 0.25rem; font-size: 0.65rem;">Current</span>
                            @endif
                        </td>
                        <td>{{ $rev->name }}</td>
                        <td>
                            <span class="badge badge-{{ $rev->status }}">{{ $rev->status }}</span>
                        </td>
                        <td>
                            @if ($rev->formattedPrice)
                                <span>{{ $rev->formattedPrice }}</span>
                                <span style="color: var(--text-muted); font-size: 0.75rem;">({{ $rev->fixedPriceMinor }} minor)</span>
                            @else
                                <span style="color: var(--text-muted);">-</span>
                            @endif
                        </td>
                        <td>
                            @if ($rev->workflowId)
                                <span class="code-pill" style="font-size: 0.75rem;">{{ $rev->workflowId }} (v{{ $rev->workflowVersion }})</span>
                            @else
                                <span style="color: var(--text-muted);">-</span>
                            @endif
                        </td>
                        <td style="color: var(--text-muted); font-size: 0.85rem;">
                            {{ $rev->createdAt ?: '-' }}
                        </td>
                        <td>
                            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'history.revision', ['code' => $service->code, 'revision' => $rev->revision]) }}" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                Inspect Definition
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            No revision records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
