<span class="status status-{{ $request->status->value }}">{{ ucfirst($request->status->value) }}{{ $request->isTaken() ? ' (taken)' : '' }}</span>
