<div class="li-stack">
    <form class="li-card li-filters" wire:submit.prevent>
        <label class="li-field">
            <span>Time range</span>
            <select wire:model.live="since" name="since">
                @foreach ($timeRanges as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="li-field">
            <span>Minimum severity</span>
            <select wire:model.live="severity" name="severity">
                <option value="">Any</option>
                @foreach ($severities as $value)
                    <option value="{{ $value }}">{{ ucfirst($value) }}</option>
                @endforeach
            </select>
        </label>

        <label class="li-field">
            <span>Status</span>
            <select wire:model.live="status" name="status">
                <option value="">Any</option>
                @foreach ($statuses as $value)
                    <option value="{{ $value }}">{{ ucfirst($value) }}</option>
                @endforeach
            </select>
        </label>

        @if ($filtered)
            <button type="button" class="li-button" wire:click="resetFilters">Reset filters</button>
        @endif

        <span class="li-muted li-loading" wire:loading>Loading…</span>
    </form>

    @if ($error !== null)
        <div class="li-card li-notice li-notice-error" role="alert">{{ $error }}</div>
    @elseif ($clusters === null || $clusters->total() === 0)
        <div class="li-card li-notice">
            @if ($filtered)
                No clusters match the filters.
            @else
                No clusters stored yet. Run <code>php artisan log-insight:analyze</code> first.
            @endif
        </div>
    @else
        <div class="li-card li-table-wrap">
            <table class="li-table">
                <thead>
                    <tr>
                        <th scope="col" class="li-num">Count</th>
                        <th scope="col">Error</th>
                        <th scope="col">Message</th>
                        <th scope="col">Location</th>
                        <th scope="col">Last seen</th>
                        <th scope="col">Status</th>
                        <th scope="col">Severity</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clusters as $row)
                        @php($cluster = $row->cluster)
                        <tr wire:key="cluster-{{ $cluster->id }}">
                            <td class="li-num">{{ number_format($cluster->occurrence_count) }}</td>
                            <td>
                                @if ($cluster->exception_class !== null)
                                    <span title="{{ $cluster->exception_class }}">{{ class_basename($cluster->exception_class) }}</span>
                                @else
                                    <span class="li-level">{{ strtoupper($cluster->level->value) }}</span>
                                @endif
                            </td>
                            <td class="li-message" title="{{ $cluster->message }}">{{ \Illuminate\Support\Str::limit($cluster->message, 120) }}</td>
                            <td class="li-code">
                                @if ($cluster->app_frame !== null)
                                    {{-- Allow line breaks after path separators only. --}}
                                    {!! str_replace('/', '/<wbr>', e($cluster->app_frame)) !!}
                                @else
                                    –
                                @endif
                            </td>
                            <td class="li-nowrap">
                                <time datetime="{{ $cluster->last_seen_at->toIso8601String() }}" title="{{ $cluster->last_seen_at->toDayDateTimeString() }}">{{ $cluster->last_seen_at->diffForHumans() }}</time>
                            </td>
                            <td><span class="li-badge li-status-{{ $row->status() }}">{{ $row->status() }}</span></td>
                            <td class="li-nowrap">
                                @if ($row->analysis !== null)
                                    <span class="li-badge li-severity-{{ $row->analysis->severity->value }}">{{ $row->analysis->severity->value }}</span>
                                    <span class="li-muted">{{ (int) round($row->analysis->confidence * 100) }}%</span>
                                @else
                                    <span class="li-muted">–</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="li-footer">
            <span class="li-muted">
                {{ $clusters->firstItem() }}–{{ $clusters->lastItem() }} of {{ number_format($clusters->total()) }} {{ \Illuminate\Support\Str::plural('cluster', $clusters->total()) }}, most frequent first
            </span>
            {{ $clusters->links() }}
        </div>
    @endif
</div>
