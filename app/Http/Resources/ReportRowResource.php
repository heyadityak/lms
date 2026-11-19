<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use Illuminate\Http\Resources\Json\JsonResource;

/** Report rows are already arrays in the openapi row shape (App\Services\ReportQuery). */
final class ReportRowResource extends JsonResource
{
    use Envelopes;
}
