@extends('directory-panel::layouts.app')

@section('title', 'Users')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Users</h1>
        <p class="page-desc">Directory user identities and account records.</p>
    </div>
    @if($canCreate)
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.create') }}" class="btn btn-primary">
            + New User
        </a>
    @endif
</div>

<div class="card">
    <form method="GET" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.index') }}" class="filter-bar">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, ID, or email..." class="form-control" style="max-width: 350px;">
        <select name="status" class="form-control" style="max-width: 180px;">
            <option value="">All Statuses</option>
            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
            <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive / Archived</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        @if($search || $status)
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.index') }}" class="btn btn-secondary">Clear</a>
        @endif
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Display Name</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td><code>{{ $user->id }}</code></td>
                        <td>
                            <strong>
                                <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.show', ['id' => $user->id]) }}" style="color: var(--accent-blue); text-decoration: none;">
                                    {{ $user->displayName }}
                                </a>
                            </strong>
                        </td>
                        <td>
                            <span class="badge {{ $user->isActive ? 'badge-active' : 'badge-inactive' }}">
                                {{ $user->status }}
                            </span>
                        </td>
                        <td>
                            @if($user->email)
                                <div>{{ $user->email }}</div>
                            @endif
                            @if($user->phone)
                                <div style="color: var(--text-muted); font-size: 0.85rem;">{{ $user->phone }}</div>
                            @endif
                            @if(!$user->email && !$user->phone)
                                <span style="color: var(--text-muted); font-size: 0.85rem;">—</span>
                            @endif
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.show', ['id' => $user->id]) }}" class="btn btn-secondary btn-sm">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            No users found in directory.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
