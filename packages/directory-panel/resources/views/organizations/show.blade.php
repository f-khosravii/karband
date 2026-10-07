@extends('directory-panel::layouts.app')

@section('title', $org->name)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}" style="color: var(--text-secondary); text-decoration: none; font-size: 0.9rem;">
            &larr; Back to Organizations
        </a>
        <h1 class="page-title" style="margin-top: 0.5rem;">{{ $org->name }}</h1>
        <p class="page-desc">ID: <code>{{ $org->id }}</code></p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        @if($canUpdate)
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.edit', ['id' => $org->id]) }}" class="btn btn-secondary">
                Edit Organization
            </a>
        @endif
        @if($org->isActive && $canArchive)
            <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.archive', ['id' => $org->id]) }}" onsubmit="return confirm('Archive this organization?');">
                @csrf
                <button type="submit" class="btn btn-danger">Archive</button>
            </form>
        @elseif(!$org->isActive && $canRestore)
            <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.restore', ['id' => $org->id]) }}">
                @csrf
                <button type="submit" class="btn btn-success">Restore</button>
            </form>
        @endif
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
        Organization Details
    </h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Status</div>
            <div style="margin-top: 0.25rem;">
                <span class="badge {{ $org->isActive ? 'badge-active' : 'badge-inactive' }}">{{ $org->status }}</span>
            </div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Email</div>
            <div style="margin-top: 0.25rem;">{{ $org->email ?? '—' }}</div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Phone</div>
            <div style="margin-top: 0.25rem;">{{ $org->phone ?? '—' }}</div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Created At</div>
            <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--text-secondary);">{{ $org->createdAt ?? '—' }}</div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Total Members</div>
            <div style="margin-top: 0.25rem; font-size: 1.1rem; font-weight: 600;">{{ count($memberships) }}</div>
        </div>
    </div>
</div>

<!-- Memberships Table Card -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1rem;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 600;">Members</h2>
            <p style="color: var(--text-muted); font-size: 0.85rem;">Users belonging to this organization and membership history.</p>
        </div>
    </div>

    @if($canCreateMembership)
        <div style="background-color: var(--bg-page); border: 1px solid var(--border-color); border-radius: 0.375rem; padding: 1rem; margin-bottom: 1.5rem;">
            <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'memberships.store') }}" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
                @csrf
                <input type="hidden" name="organization_id" value="{{ $org->id }}">
                <input type="hidden" name="return_to" value="organization">
                <div style="flex: 2; min-width: 200px;">
                    <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">User ID</label>
                    <input type="text" name="user_id" placeholder="Enter User ID to add..." class="form-control" required>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Role (Optional)</label>
                    <input type="text" name="role" placeholder="e.g. member, owner..." class="form-control">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 40px;">+ Add Member</button>
            </form>
        </div>
    @endif

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Display Name</th>
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
                        <td><code>{{ $mem->userId }}</code></td>
                        <td>
                            <strong>
                                <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.show', ['id' => $mem->userId]) }}" style="color: var(--accent-blue); text-decoration: none;">
                                    {{ $mem->userDisplayName }}
                                </a>
                            </strong>
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
                                <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.show', ['id' => $mem->userId]) }}" class="btn btn-secondary btn-sm">
                                    View User
                                </a>
                                @if($mem->isActive && $canEndMembership)
                                    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'memberships.end') }}" onsubmit="return confirm('End this user membership?');" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $mem->userId }}">
                                        <input type="hidden" name="organization_id" value="{{ $org->id }}">
                                        <input type="hidden" name="return_to" value="organization">
                                        <button type="submit" class="btn btn-danger btn-sm">End</button>
                                    </form>
                                @elseif(!$mem->isActive && $canCreateMembership)
                                    <form method="POST" action="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'memberships.restore') }}" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $mem->userId }}">
                                        <input type="hidden" name="organization_id" value="{{ $org->id }}">
                                        <input type="hidden" name="return_to" value="organization">
                                        <button type="submit" class="btn btn-success btn-sm">Reactivate</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            No members found in this organization.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
