<div class="list-table table-responsive">
    <table class="table table-hover border-bottom">
        <thead>
        <tr>
            <th>Date</th>
            <th>Status</th>
            <th>Content</th>
        </tr>
        </thead>
        <tbody>
        @php($logs = $formModel->listSyncHistory())
        @foreach ($logs as $log)
            <tr>
                <td class="text-">{{$log->created_at->toDateTimeString()}}</td>
                <td>{{$log->is_success ? 'Success' : 'Error'}}</td>
                <td
                    class="text"
                    role="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#stack-{{ $log->uuid }}"
                    aria-expanded="false"
                    aria-controls="stack{{ $log->uuid }}"
                >
                    Click to see notes...

                    <div class="collapse" id="stack-{{ $log->uuid }}">
                        {!! nl2br(implode(PHP_EOL, $log->notes ?? [])) !!}
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
{{ $logs->links() }}
