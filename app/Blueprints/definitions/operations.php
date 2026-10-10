<?php

use App\Blueprints\Logic\ComplianceLogic;
use App\Blueprints\Logic\FleetLogic;
use App\Blueprints\Logic\KombiCollectionsLogic;
use App\Blueprints\Logic\MaintenanceLogic;
use App\Blueprints\Logic\VisitorsLogic;

/*
 * Operations & transport apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'fleet' => ['Fleet & vehicles', 'car', 'Vehicles, drivers, fuel and service schedules.', [
        'vehicles' => ['Vehicle', 'Registration', 'active,in_service,off_road,sold', [
            'make_model|Make & model*',
            'year:number',
            'type:select=car,bakkie,truck,minibus,bus,motorbike',
            'odometer:number|Odometer (km)',
            'driver:user|Default driver',
            'licence_expiry:date|Licence disc expiry',
            'insurance_expiry:date|Insurance expiry',
        ], ['icon' => 'car', 'prefix' => 'FV-', 'due' => 'Next service', 'list' => ['make_model', 'type', 'driver']]],
        'fuel' => ['Fuel slip', 'Station', 'recorded', [
            'vehicle:record=vehicles|Vehicle*',
            'litres:number|Litres*',
            'odometer:number|Odometer (km)',
        ], ['icon' => 'fuel', 'prefix' => 'FU-', 'date' => 'Date', 'amount' => 'Cost', 'assignee' => true, 'list' => ['vehicle', 'litres', 'odometer']]],
        'services' => ['Service', 'Work done', 'booked,done', [
            'vehicle:record=vehicles|Vehicle*',
            'odometer:number|Odometer (km)',
            'workshop',
        ], ['icon' => 'wrench', 'prefix' => 'FS-', 'date' => 'Date', 'amount' => 'Cost', 'list' => ['vehicle', 'workshop']]],
    ], ['logic' => FleetLogic::class]],

    'kombi-collections' => ['Minibus daily collections', 'bus-front', 'Daily cash-ups from drivers and conductors, per vehicle.', [
        'collections' => ['Collection', 'Driver name', 'pending,received,short', [
            'vehicle|Vehicle registration*',
            'route',
            'expected:money|Expected amount',
            'fuel:money|Fuel spent',
            'notes:textarea',
        ], ['icon' => 'banknote', 'prefix' => 'KC-', 'date' => 'Date', 'amount' => 'Cash handed in', 'list' => ['vehicle', 'route', 'expected']]],
    ], ['logic' => KombiCollectionsLogic::class]],

    'maintenance' => ['Equipment maintenance', 'wrench', 'Asset register with preventive and breakdown maintenance.', [
        'equipment' => ['Equipment', 'Equipment name', 'operational,faulty,retired', [
            'serial_number|Serial number',
            'location',
            'service_interval_days:number|Service interval (days)',
        ], ['icon' => 'cog', 'prefix' => 'EQ-', 'plural' => 'Equipment', 'due' => 'Next service', 'list' => ['serial_number', 'location']]],
        'work_orders' => ['Work order', 'Work required', 'open,in_progress,done,cancelled', [
            'equipment:record=equipment|Equipment*',
            'type:select=preventive,breakdown,inspection',
            'downtime_hours:number|Downtime (hours)',
            'notes:textarea',
        ], ['icon' => 'clipboard-list', 'prefix' => 'WO-', 'date' => 'Raised on', 'due' => 'Due', 'amount' => 'Cost', 'assignee' => true, 'list' => ['equipment', 'type']]],
    ], ['logic' => MaintenanceLogic::class]],

    'visitors' => ['Visitor management', 'id-card', 'Sign visitors in and out at reception or the gate.', [
        'visits' => ['Visit', 'Visitor name', 'signed_in,signed_out', [
            'id_number|ID number',
            'company',
            'phone:phone',
            'host:user|Visiting',
            'purpose',
            'vehicle|Vehicle registration',
            'time_in:time|Time in',
            'time_out:time|Time out',
        ], ['icon' => 'id-card', 'prefix' => 'VIS-', 'date' => 'Date', 'list' => ['company', 'host', 'time_in']]],
    ], ['logic' => VisitorsLogic::class]],

    'compliance' => ['Compliance & audits', 'clipboard-check', 'Licences, inspections and corrective actions that must not lapse.', [
        'obligations' => ['Obligation', 'Licence / requirement', 'compliant,due_soon,lapsed', [
            'authority|Issuing authority',
            'reference|Licence number',
            'renewal_steps:textarea|How to renew',
        ], ['icon' => 'file-check-2', 'prefix' => 'OBL-', 'amount' => 'Renewal fee', 'date' => 'Issued on', 'due' => 'Expires on', 'assignee' => true, 'list' => ['authority', 'reference']]],
        'findings' => ['Finding', 'Finding', 'open,in_progress,closed', [
            'obligation:record=obligations|Related obligation',
            'severity:select=low,medium,high,critical',
            'corrective_action:textarea|Corrective action',
        ], ['icon' => 'search-check', 'prefix' => 'FND-', 'date' => 'Raised on', 'due' => 'Fix by', 'assignee' => true, 'list' => ['severity', 'obligation']]],
    ], ['logic' => ComplianceLogic::class]],
];
