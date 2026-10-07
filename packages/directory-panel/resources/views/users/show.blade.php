@extends('directory-panel::layouts.app')

@section('title', $user->displayName)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.index') }}" style="color: var(--text-secondary); text-decoration: none; font-size: 0.9rem;">
            &larr; Back to Users
        </a>
        <h1 class="page-title" style="margin-top: 0.5rem;">{{ $user->displayName }}</h1>
        <p class="page-desc">ID: <code>{{ $user->id }}</code></p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        @if($canUpdate)
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.edit', ['id' => $user->id]) }}" class="btn btn-secondary">
                Edit User
            </a>
        @endif
        @if($user->isActive && $canArchive)
            <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.archive', ['id' => $user->id]) }}" onsubmit="return confirm('Archive this user?');">
                @csrf
                <button type="submit" class="btn btn-danger">Archive</button>
            </form>
        @elseif(!$user->isActive && $canRestore)
            <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.restore', ['id' => $user->id]) }}">
                @csrf
                <button type="submit" class="btn btn-success">Restore</button>
            </form>
        @endif
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
        User Profile
    </h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Status</div>
            <div style="margin-top: 0.25rem;">
                <span class="badge {{ $user->isActive ? 'badge-active' : 'badge-inactive' }}">{{ $user->status }}</span>
            </div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Email</div>
            <div style="margin-top: 0.25rem;">{{ $user->email ?? '—' }}</div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Phone</div>
            <div style="margin-top: 0.25rem;">{{ $user->phone ?? '—' }}</div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Created At</div>
            <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--text-secondary);">{{ $user->createdAt ?? '—' }}</div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Organizations</div>
            <div style="margin-top: 0.25rem; font-size: 1.1rem; font-weight: 600;">{{ count($memberships) }}</div>
        </div>
    </div>
</div>

<!-- Organization Memberships Card -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1rem;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 600;">Organization Memberships</h2>
            <p style="color: var(--text-muted); font-size: 0.85rem;">Organizations this user belongs to and membership history.</p>
        </div>
    </div>

    @if($canCreateMembership && !empty($organizations))
        <div style="background-color: var(--bg-page); border: 1px solid var(--border-color); border-radius: 0.375rem; padding: 1rem; margin-bottom: 1.5rem;">
            <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'memberships.store') }}" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
                @csrf
                <input type="hidden" name="user_id" value="{{ $user->id }}">
                <input type="hidden" name="return_to" value="user">
                <div style="flex: 2; min-width: 200px;">
                    <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Select Organization</label>
                    <select name="organization_id" class="form-control" required>
                        <option value="">Choose an organization...</option>
                        @foreach($organizations as $org)
                            <option value="{{ $org->id }}">{{ $org->name }} ({{ $org->id }})</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Role (Optional)</label>
                    <input type="text" name="role" placeholder="e.g. member, owner..." class="form-control">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 40px;">+ Add to Organization</button>
            </form>
        </div>
    @endif

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Organization</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th>Ended</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($memberships as $mem)
                    <tr>
                        <td>
                            <strong>
                                <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.show', ['id' => $mem->organizationId]) }}" style="color: var(--accent-blue); text-decoration: none;">
                                    {{ $mem->organizationName }}
                                </a>
                            </strong>
                            <div style="color: var(--text-muted); font-size: 0.8rem;"><code>{{ $mem->organizationId }}</code></div>
                        </td>
                        <td>
                            <span style="font-size: 0.85rem; font-weight: 500;">{{ $mem->role ?? 'member' }}</span>
                        </td>
                        <td>
                            <span class="badge {{ $mem->isActive ? 'badge-active' : 'badge-inactive' }}">
                                {{ $mem->status }}
                            </span>
                        </td>
                        <td style="color: var(--text-secondary); font-size: 0.85rem;">
                            {{ $mem->createdAt ?? '—' }}
                        </td>
                        <td style="color: var(--text-secondary); font-size: 0.85rem;">
                            {{ $mem->endedAt ?? '—' }}
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 0.5rem;">
                                <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.show', ['id' => $mem->organizationId]) }}" class="btn btn-secondary btn-sm">
                                    View Org
                                </a>
                                @if($mem->isActive && $canEndMembership)
                                    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'memberships.end') }}" onsubmit="return confirm('End this organization membership?');" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                                        <input type="hidden" name="organization_id" value="{{ $mem->organizationId }}">
                                        <input type="hidden" name="return_to" value="user">
                                        <button type="submit" class="btn btn-danger btn-sm">End</button>
                                    </form>
                                @elseif(!$mem->isActive && $canCreateMembership)
                                    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'memberships.restore') }}" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                                        <input type="hidden" name="organization_id" value="{{ $mem->organizationId }}">
                                        <input type="hidden" name="return_to" value="user">
                                        <button type="submit" class="btn btn-success btn-sm">Reactivate</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            User has no organization memberships.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
