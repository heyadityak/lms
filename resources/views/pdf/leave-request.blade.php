<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leave application</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12pt; color: #1f1f1f; }
        h1 { font-size: 16pt; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 4px; vertical-align: top; border-bottom: 1px solid #e5e5e5; }
        td:first-child { width: 28%; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $brandName }} — Leave application</h1>
    <table>
        <tr><td>Employee</td><td>{{ $leaveRequest->user->name }}@if ($leaveRequest->user->employee_code) ({{ $leaveRequest->user->employee_code }})@endif</td></tr>
        <tr><td>Type</td><td>{{ $leaveRequest->leaveType->name }} ({{ $leaveRequest->leaveType->code }})</td></tr>
        <tr><td>Dates</td><td>{{ $leaveRequest->start_date->toDateString() }} to {{ $leaveRequest->end_date->toDateString() }}</td></tr>
        <tr><td>Day part</td><td>{{ $leaveRequest->day_part->value }}</td></tr>
        @if ($leaveRequest->hours)
            <tr><td>Hours</td><td>{{ $leaveRequest->hours }}</td></tr>
        @endif
        <tr><td>Days</td><td>{{ $leaveRequest->days }}</td></tr>
        <tr><td>Status</td><td>{{ $leaveRequest->status->value }}</td></tr>
        <tr><td>Reason</td><td>{{ $leaveRequest->reason }}</td></tr>
        @if ($leaveRequest->handover_note)
            <tr><td>Handover</td><td>{{ $leaveRequest->handover_note }}</td></tr>
        @endif
        @if ($leaveRequest->decision_comment)
            <tr><td>Decision</td><td>{{ $leaveRequest->decision_comment }}</td></tr>
        @endif
    </table>
</body>
</html>
