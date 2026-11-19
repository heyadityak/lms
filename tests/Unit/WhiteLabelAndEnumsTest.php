<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Support\BrandColor;
use App\Support\Modules;
use PHPUnit\Framework\TestCase;

final class WhiteLabelAndEnumsTest extends TestCase
{
    public function test_modules_resolve_is_licensed_and_available_minus_disabled(): void
    {
        $registry = ['holidays' => ['available' => true], 'reports' => ['available' => true], 'delegation' => ['available' => false]];

        $this->assertSame(['holidays'], Modules::resolve(['holidays', 'reports', 'delegation', 'unknown'], $registry, ['reports']));
        // Disabling an unlicensed module changes nothing; nothing outside the licence is ever enabled (D-34).
        $this->assertSame(['reports'], Modules::resolve(['reports'], $registry, ['holidays']));
        $this->assertSame([], Modules::resolve([], $registry, []));
    }

    public function test_brand_color(): void
    {
        $this->assertTrue(BrandColor::isValidHex('#1f6FEB'));
        foreach (['1f6feb', '#1f6fe', '#1f6febb', '#ggg000', 'red;}', null, 123] as $bad) {
            $this->assertFalse(BrandColor::isValidHex($bad));
        }
        $this->assertSame('#ffffff', BrandColor::contrast('#1f6feb'));
        $this->assertSame('#000000', BrandColor::contrast('#ffd700'));
        $this->assertSame('#ffffff', BrandColor::contrast('#000000'));
        $this->assertSame('#000000', BrandColor::contrast('#ffffff'));
    }

    public function test_role_from_keycloak_roles_highest_base_wins(): void
    {
        $this->assertSame(Role::HrAdmin, Role::fromKeycloakRoles(['employee', 'admin', 'hr_admin', 'manager']));
        $this->assertSame(Role::Manager, Role::fromKeycloakRoles(['default-roles-lms', 'manager', 'employee']));
        $this->assertNull(Role::fromKeycloakRoles(['admin', 'offline_access']));
        $this->assertFalse(Role::Admin->isBase());
        $this->assertGreaterThan(Role::Manager->rank(), Role::HrAdmin->rank());
    }

    public function test_leave_status_transitions(): void
    {
        $allowed = [['pending', 'approved'], ['pending', 'rejected'], ['pending', 'cancelled'], ['approved', 'cancelled']];
        foreach (LeaveStatus::cases() as $from) {
            foreach (LeaveStatus::cases() as $to) {
                $this->assertSame(in_array([$from->value, $to->value], $allowed, true), $from->canTransitionTo($to), "{$from->value} → {$to->value}");
            }
        }
        $this->assertTrue(LeaveStatus::Approved->isOpen());
        $this->assertFalse(LeaveStatus::Cancelled->isOpen());
    }
}
