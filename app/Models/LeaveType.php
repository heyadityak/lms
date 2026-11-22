<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccrualFrequency;
use App\Models\Concerns\HasPublicId;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'code', 'name', 'annual_quota', 'tracks_balance', 'carry_forward_cap', 'max_balance',
        'allow_half_day', 'max_backdate_days', 'is_active', 'sandwich', 'accrual_frequency',
    ];

    protected $attributes = [
        'annual_quota' => 0,
        'tracks_balance' => true,
        'allow_half_day' => true,
        'max_backdate_days' => 0,
        'is_active' => true,
        'sandwich' => false,
        'accrual_frequency' => 'yearly',
    ];

    protected function casts(): array
    {
        return [
            'annual_quota' => 'float',
            'tracks_balance' => 'boolean',
            'carry_forward_cap' => 'float',
            'max_balance' => 'float',
            'allow_half_day' => 'boolean',
            'max_backdate_days' => 'integer',
            'is_active' => 'boolean',
            'sandwich' => 'boolean',
            'accrual_frequency' => AccrualFrequency::class,
        ];
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** @return HasMany<LeaveLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }
}
