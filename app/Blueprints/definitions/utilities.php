<?php

use App\Blueprints\Logic\IspBillingLogic;
use App\Blueprints\Logic\SolarPaygLogic;
use App\Blueprints\Logic\UtilityBillingLogic;
use App\Blueprints\Logic\WasteCollectionLogic;

/*
 * Utilities & telecoms apps: metered billing, subscriptions and service delivery.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'utility-billing' => ['Water & electricity billing', 'gauge', 'Customer meters, readings and utility bills.', [
        'meters' => ['Meter', 'Meter number', 'active,disconnected,faulty', [
            'type:select=water,electricity,gas*',
            'account_holder|Account holder*',
            'address:textarea',
            'tariff|Tariff',
            'prepaid:checkbox',
        ], ['icon' => 'gauge', 'prefix' => 'MTR-', 'contact' => 'Customer', 'list' => ['type', 'account_holder', 'tariff']]],
        'readings' => ['Meter reading', 'Period', 'read,estimated,billed,disputed', [
            'meter:record=meters|Meter*',
            'previous:number|Previous reading',
            'current:number|Current reading*',
            'consumption:number',
            'photo_url:url|Meter photo',
        ], ['icon' => 'scan', 'prefix' => 'RDG-', 'amount' => 'Bill amount', 'date' => 'Read on', 'due' => 'Due date', 'assignee' => true, 'list' => ['meter', 'current', 'consumption']]],
    ], ['logic' => UtilityBillingLogic::class]],

    'isp-billing' => ['ISP / WISP billing', 'wifi', 'Internet subscribers, packages, installations and service status.', [
        'subscribers' => ['Subscriber', 'Subscriber name', 'pending_install,active,suspended,cancelled', [
            'package:select=10mbps,20mbps,50mbps,100mbps,business,custom*',
            'connection:select=fibre,wireless,lte,satellite',
            'username|PPPoE / RADIUS username',
            'ip_address|IP address',
            'router_serial|Router serial',
            'address:textarea',
        ], ['icon' => 'wifi', 'prefix' => 'SUB-', 'contact' => 'Customer', 'amount' => 'Monthly price', 'date' => 'Installed on', 'due' => 'Paid until', 'assignee' => true, 'list' => ['package', 'connection', 'username']]],
        'faults' => ['Fault', 'Fault', 'logged,investigating,technician_sent,resolved', [
            'subscriber:record=subscribers|Subscriber*',
            'type:select=no_connection,slow,intermittent,equipment,billing*',
            'notes:textarea',
        ], ['icon' => 'router', 'prefix' => 'FLT-', 'date' => 'Logged on', 'assignee' => true, 'list' => ['subscriber', 'type']]],
    ], ['logic' => IspBillingLogic::class]],

    'solar-energy-systems-monitoring' => ['Solar energy & PAYG systems', 'sun', 'Installed solar systems, pay-as-you-go accounts and generation readings.', [
        'systems' => ['Solar system', 'Customer / site', 'active,locked,repossessed,paid_off', [
            'kit|Kit / size*',
            'serial_number|Controller serial',
            'payg:checkbox|Pay-as-you-go',
            'daily_rate:money|Daily rate',
            'balance:money|Outstanding balance',
            'unlock_code|Last unlock code',
        ], ['icon' => 'sun', 'prefix' => 'SOL-', 'contact' => 'Customer', 'amount' => 'Contract value', 'date' => 'Installed on', 'due' => 'Paid until', 'assignee' => true, 'list' => ['kit', 'payg', 'balance']]],
        'readings' => ['Generation reading', 'System', 'logged', [
            'system:record=systems|System*',
            'kwh:number|Generated (kWh)',
            'battery_health:number|Battery health %',
            'alerts|Alerts',
        ], ['icon' => 'zap', 'prefix' => 'GEN-', 'date' => 'Date', 'list' => ['system', 'kwh', 'battery_health']]],
    ], ['logic' => SolarPaygLogic::class]],

    'waste-management-refuse-collection' => ['Waste management & refuse collection', 'trash-2', 'Customers, collection routes and collections done.', [
        'customers' => ['Collection point', 'Customer / address', 'active,suspended,cancelled', [
            'address:textarea*',
            'bins:number',
            'bin_type:select=wheelie_bin,skip,bags,recycling,hazardous',
            'collection_day:select=monday,tuesday,wednesday,thursday,friday,saturday',
            'route|Route',
        ], ['icon' => 'trash-2', 'prefix' => 'WST-', 'contact' => 'Customer', 'amount' => 'Monthly fee', 'list' => ['bins', 'collection_day', 'route']]],
        'collections' => ['Collection run', 'Route', 'scheduled,in_progress,completed,missed', [
            'truck',
            'stops_done:number|Stops done',
            'tonnage:number|Tonnage (t)',
            'landfill_ticket|Landfill / weighbridge ticket',
            'missed_stops:textarea|Missed stops',
        ], ['icon' => 'recycle', 'prefix' => 'COL-', 'date' => 'Date', 'assignee' => true, 'list' => ['truck', 'stops_done', 'tonnage']]],
    ], ['logic' => WasteCollectionLogic::class]],

    'borehole-water-delivery-tanker' => ['Borehole & water tanker delivery', 'droplet', 'Water orders delivered by tanker, plus borehole drilling jobs.', [
        'deliveries' => ['Water delivery', 'Customer name', 'ordered,dispatched,delivered,paid,cancelled', [
            'litres:number*',
            'address:textarea*',
            'tanker|Tanker',
            'driver',
            'phone:phone',
        ], ['icon' => 'droplet', 'prefix' => 'WTR-', 'contact' => 'Customer', 'amount' => 'Price', 'date' => 'Delivery date', 'assignee' => true, 'list' => ['litres', 'tanker', 'driver']]],
        'boreholes' => ['Borehole job', 'Site', 'survey,quoted,drilling,cased,pump_installed,completed', [
            'depth:number|Depth (m)',
            'yield:number|Yield (L/hour)',
            'water_quality|Water quality',
            'pump',
        ], ['icon' => 'drill', 'prefix' => 'BH-', 'contact' => 'Client', 'amount' => 'Contract value', 'date' => 'Start date', 'assignee' => true, 'list' => ['depth', 'yield', 'pump']]],
    ]],

    'cable-satellite-tv-subscriptions' => ['Cable / satellite TV subscriptions', 'tv', 'Subscribers, decoders, bouquets and installations.', [
        'subscribers' => ['TV subscriber', 'Subscriber name', 'active,suspended,disconnected', [
            'decoder_number|Decoder / smartcard number*',
            'bouquet:select=basic,family,compact,premium,sports,custom*',
            'phone:phone',
            'address:textarea',
        ], ['icon' => 'tv', 'prefix' => 'TVS-', 'contact' => 'Customer', 'amount' => 'Monthly price', 'date' => 'Activated on', 'due' => 'Renewal date', 'list' => ['decoder_number', 'bouquet']]],
        'installations' => ['Installation', 'Customer / address', 'booked,installed,failed', [
            'subscriber:record=subscribers|Subscriber',
            'dish_size|Dish size',
            'signal_strength:number|Signal strength %',
        ], ['icon' => 'satellite-dish', 'prefix' => 'TVI-', 'amount' => 'Installation fee', 'date' => 'Date', 'assignee' => true, 'list' => ['subscriber', 'signal_strength']]],
    ]],

    'telecom-airtime-bundle-reseller' => ['Airtime & bundle reseller', 'smartphone', 'Airtime, data bundles and electricity tokens sold, plus agent float.', [
        'sales' => ['Sale', 'Customer phone / meter', 'successful,failed,reversed', [
            'product:select=airtime,data_bundle,electricity_token,tv_payment,voucher*',
            'network|Network / provider*',
            'commission:money',
            'token_reference|Token / reference',
        ], ['icon' => 'smartphone', 'prefix' => 'AIR-', 'amount' => 'Amount', 'date' => 'Date', 'assignee' => true, 'list' => ['product', 'network', 'commission']]],
        'float' => ['Float top-up', 'Provider', 'requested,received', [
            'method:select=bank,cash,mobile_money',
            'reference',
        ], ['icon' => 'wallet', 'prefix' => 'FLT-', 'plural' => 'Float top-ups', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['method', 'reference']]],
    ]],
];
