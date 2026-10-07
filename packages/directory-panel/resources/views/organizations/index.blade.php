@extends('directory-panel::layouts.app')

@section('title', 'Organizations')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Organizations</h1>
        <p class="page-desc">Directory of all organizations, agencies, and providers.</p>
    </div>
    @if($canCreate)
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.create') }}" class="btn btn-primary">
            + New Organization
        </a>
    @endif
</div>

<div class="card">
    <form method="GET" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}" class="filter-bar">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, ID, or email..." class="form-control" style="max-width: 350px;">
        <select name="status" class="form-control" style="max-width: 180px;">
            <option value="">All Statuses</option>
            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
            <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive / Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        @if($search || $status)
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}" class="btn btn-secondary">Clear</a>
        @endif
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($organizations as $org)
                    <tr>
                        <td><code>{{ $org->id }}</code></td>
                        <td>
                            <strong>
                                <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.show', ['id' => $org->id]) }}" style="color: var(--accent-blue); text-decoration: none;">
                                    {{ $org->name }}
                                </a>
                            </strong>
                        </td>
                        <td>
                            <span class="badge {{ $org->isActive ? 'badge-active' : 'badge-inactive' }}">
                                {{ $org->status }}
                            </span>
                        </td>
                        <td>
                            @if($org->email)
                                <div>{{ $org->email }}</div>
                            @endif
                            @if($org->phone)
                                <div style="color: var(--text-muted); font-size: 0.85rem;">{{ $org->phone }}</div>
                            @endif
                            @if(!$org->email && !$org->phone)
                                <span style="color: var(--text-muted); font-size: 0.85rem;">—</span>
                            @endif
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.show', ['id' => $org->id]) }}" class="btn btn-secondary btn-sm">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            No organizations found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
