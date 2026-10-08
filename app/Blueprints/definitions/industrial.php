<?php

/*
 * Industrial apps: mining, weighing, production floor, labs and site safety.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'mine-quarry-operations' => ['Mine & quarry operations', 'pickaxe', 'Shift production, blasts and stockpiles.', [
        'shifts' => ['Shift report', 'Shift', 'open,submitted,approved', [
            'pit|Pit / section*',
            'shift:select=day,night,a,b,c*',
            'tonnes_mined:number|Tonnes mined',
            'tonnes_hauled:number|Tonnes hauled',
            'loads:number',
            'downtime_minutes:number|Downtime (min)',
            'notes:textarea',
        ], ['icon' => 'pickaxe', 'prefix' => 'SHF-', 'date' => 'Date', 'assignee' => true, 'list' => ['pit', 'shift', 'tonnes_mined']]],
        'blasts' => ['Blast', 'Blast location', 'planned,fired,misfire,cleared', [
            'blaster|Licensed blaster*',
            'holes:number',
            'explosives_kg:number|Explosives (kg)',
            'fire_time:time|Fire time',
            'vibration:number|Vibration (mm/s)',
        ], ['icon' => 'flame', 'prefix' => 'BLS-', 'date' => 'Date', 'assignee' => true, 'list' => ['blaster', 'holes', 'explosives_kg']]],
        'stockpiles' => ['Stockpile', 'Product / stockpile', 'active,depleted', [
            'product:select=ore,waste,aggregate_6mm,aggregate_13mm,aggregate_19mm,crusher_dust,sand,g5,other*',
            'tonnes:number|Tonnes on hand',
            'location',
        ], ['icon' => 'mountain', 'prefix' => 'STK-', 'date' => 'Surveyed on', 'list' => ['product', 'tonnes']]],
    ]],

    'weighbridge' => ['Weighbridge', 'weight', 'Weighbridge tickets with gross, tare and net mass.', [
        'tickets' => ['Weighbridge ticket', 'Vehicle registration', 'first_weigh,completed,void', [
            'direction:select=in,out*',
            'product',
            'gross:number|Gross mass (kg)*',
            'tare:number|Tare mass (kg)',
            'net:number|Net mass (kg)',
            'driver',
            'order_reference|Order / delivery note',
        ], ['icon' => 'weight', 'prefix' => 'WB-', 'contact' => 'Customer / supplier', 'amount' => 'Value', 'date' => 'Date', 'assignee' => true, 'list' => ['direction', 'product', 'net']]],
    ]],

    'manufacturing-execution' => ['Shop-floor execution (MES)', 'factory', 'Machines, production runs, output, scrap and downtime.', [
        'machines' => ['Machine / line', 'Machine name', 'running,idle,down,maintenance', [
            'line|Line / cell',
            'rated_output:number|Rated output per hour',
        ], ['icon' => 'cog', 'prefix' => 'MC-', 'plural' => 'Machines & lines', 'list' => ['line', 'rated_output']]],
        'runs' => ['Production run', 'Product / job', 'planned,running,completed,stopped', [
            'machine:record=machines|Machine / line*',
            'shift:select=day,night,a,b,c',
            'planned_quantity:number|Planned quantity',
            'good_quantity:number|Good quantity',
            'scrap:number',
            'downtime_minutes:number|Downtime (min)',
            'downtime_reason|Downtime reason',
        ], ['icon' => 'factory', 'prefix' => 'RUN-', 'date' => 'Date', 'assignee' => true, 'list' => ['machine', 'good_quantity', 'scrap']]],
    ]],

    'lab-testing-certificates-of' => ['Lab testing & certificates of analysis', 'flask-conical', 'Samples received, test results and certificates of analysis.', [
        'samples' => ['Sample', 'Sample description', 'received,testing,results_ready,certificate_issued,rejected', [
            'sample_number|Sample number*',
            'matrix:select=water,food,soil,feed,ore,fuel,pharma,other*',
            'tests_requested:textarea|Tests requested*',
            'batch_number|Client batch number',
            'storage|Storage condition',
        ], ['icon' => 'test-tube', 'prefix' => 'SMP-', 'contact' => 'Client', 'amount' => 'Test fee', 'date' => 'Received on', 'due' => 'Results due', 'assignee' => true, 'list' => ['sample_number', 'matrix']]],
        'results' => ['Result', 'Test / parameter', 'pending,pass,fail,inconclusive', [
            'sample:record=samples|Sample*',
            'method|Method / standard',
            'value|Result value*',
            'unit',
            'specification|Specification limit',
            'analyst:user|Analyst',
        ], ['icon' => 'flask-conical', 'prefix' => 'RES-', 'date' => 'Tested on', 'list' => ['sample', 'value', 'specification']]],
    ]],

    'permit-to-work-safety' => ['Permit to work', 'file-lock', 'Hot-work, confined-space and isolation permits with sign-offs.', [
        'permits' => ['Work permit', 'Work description', 'requested,approved,active,suspended,closed,cancelled', [
            'type:select=hot_work,confined_space,working_at_height,electrical_isolation,excavation,lifting,general*',
            'location*',
            'contractor|Contractor / crew',
            'hazards:textarea|Hazards & controls*',
            'isolations:textarea|Isolations / lock-out',
            'gas_test|Gas test result',
            'issuer:user|Issued by',
            'start_time:time|Valid from',
            'end_time:time|Valid to',
        ], ['icon' => 'file-lock', 'prefix' => 'PTW-', 'date' => 'Date', 'assignee' => true, 'list' => ['type', 'location', 'contractor']]],
    ]],

    'contractor-site-access-management' => ['Contractor & site access', 'badge-check', 'Contractor companies, worker inductions and site sign-ins.', [
        'workers' => ['Contractor worker', 'Worker name', 'pending,inducted,blocked,expired', [
            'company*',
            'id_number|ID / passport',
            'induction_date:date|Induction date',
            'induction_expiry:date|Induction expiry',
            'medical_expiry:date|Medical expiry',
            'competencies|Tickets / competencies',
        ], ['icon' => 'hard-hat', 'prefix' => 'CW-', 'list' => ['company', 'induction_expiry', 'medical_expiry']]],
        'sign_ins' => ['Site sign-in', 'Worker', 'on_site,signed_out', [
            'worker:record=workers|Worker*',
            'time_in:time|Time in*',
            'time_out:time|Time out',
            'area|Work area',
            'host|Host / supervisor',
        ], ['icon' => 'log-in', 'prefix' => 'SI-', 'date' => 'Date', 'list' => ['worker', 'time_in', 'time_out']]],
    ]],
];
