@extends(config('catalog-panel.layout', 'catalog-panel::layouts.app'))

@section('title', 'Services List')

@section('content')
<div class="card">
    <div class="card-header">
        <div>
            <h1 class="card-title">Service Catalog</h1>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-top: 0.25rem;">Configured services and active definitions</p>
        </div>
        @if ($canCreate)
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'create') }}" class="btn btn-primary">
                + Create Service
            </a>
        @endif
    </div>

    <form method="GET" action="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'index') }}" style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
        <input type="text" name="q" value="{{ $search }}" placeholder="Search by name or code..." class="form-control" style="max-width: 350px;">
        <select name="status" class="form-control" style="max-width: 180px;">
            <option value="">All Statuses</option>
            <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>Active</option>
            <option value="inactive" {{ $statusFilter === 'inactive' ? 'selected' : '' }}>Inactive</option>
            <option value="archived" {{ $statusFilter === 'archived' ? 'selected' : '' }}>Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        @if ($search || $statusFilter)
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'index') }}" class="btn btn-secondary">Clear</a>
        @endif
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Service Code</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Configured Price</th>
                    <th>Revision</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $service)
                    <tr>
                        <td><span class="code-pill">{{ $service->code }}</span></td>
                        <td>
                            <strong>{{ $service->name }}</strong>
                            @if ($service->description)
                                <div style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.2rem;">{{ \Illuminate\Support\Str::limit($service->description, 60) }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-{{ $service->status }}">
                                {{ $service->status }}
                            </span>
                        </td>
                        <td>
                            @if ($service->formattedPrice)
                                <strong>{{ $service->formattedPrice }}</strong>
                            @else
                                <span style="color: var(--text-muted);">-</span>
                            @endif
                        </td>
                        <td>Rev {{ $service->revision }}</td>
                        <td>
                            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $service->code]) }}" class="btn btn-secondary" style="padding: 0.25rem 0.625rem; font-size: 0.8rem;">
                                Manage
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            No services found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
