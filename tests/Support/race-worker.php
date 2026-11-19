<?php

declare(strict_types=1);

/*
 * Child process for LeaveConcurrencyTest: boots the app on its own DB connection, waits for a shared start time,
 * then runs one leave Action. Holds the row lock 300 ms after taking it so the sibling process really contends.
 * Usage: php race-worker.php <approve|reject|cancel> <leave_request_id> <actor_id> <start_at_unix_float>
 * Prints: ok | 409 | <exception class>: <message>
 */

use App\Actions\Leave\ApproveLeaveRequest;
use App\Actions\Leave\CancelLeaveRequest;
use App\Actions\Leave\RejectLeaveRequest;
use App\Exceptions\InvalidTransition;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $requestId, $actorId, $startAt] = $argv;

DB::listen(function (QueryExecuted $query): void {
    if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'leave_requests')) {
        usleep(300_000);
    }
});

$request = LeaveRequest::query()->findOrFail((int) $requestId);
$actor = User::query()->findOrFail((int) $actorId);

while (microtime(true) < (float) $startAt) {
    usleep(1_000);
}

try {
    match ($action) {
        'approve' => $app->make(ApproveLeaveRequest::class)->handle($request, $actor),
        'reject' => $app->make(RejectLeaveRequest::class)->handle($request, $actor, 'Race reject'),
        'cancel' => $app->make(CancelLeaveRequest::class)->handle($request, $actor),
    };
    echo 'ok';
} catch (InvalidTransition) {
    echo '409';
} catch (Throwable $e) {
    echo $e::class.': '.$e->getMessage();
}
