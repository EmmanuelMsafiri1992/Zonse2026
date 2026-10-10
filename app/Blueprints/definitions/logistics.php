<?php

use App\Blueprints\Logic\DeliveryLogic;
use App\Blueprints\Logic\FreightLogic;
use App\Blueprints\Logic\InventoryLogic;
use App\Blueprints\Logic\ManufacturingLogic;
use App\Blueprints\Logic\OrdersLogic;
use App\Blueprints\Logic\SuppliersLogic;
use App\Blueprints\Logic\WarehouseLogic;

/*
 * Operations & logistics apps: stock, warehouses, suppliers, production, orders and dispatch.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'inventory' => ['Inventory & stock', 'package', 'Items, stock levels, batches, expiry dates and stock movements.', [
        'items' => ['Item', 'Item name', 'active,discontinued', [
            'sku|SKU / code*',
            'barcode',
            'category',
            'unit:select=each,box,pack,kg,litre,metre,dozen',
            'cost_price:money|Cost price',
            'selling_price:money|Selling price',
            'quantity:number|Quantity on hand',
            'reorder_level:number|Reorder level',
            'location|Location / bin',
        ], ['icon' => 'package', 'prefix' => 'ITM-', 'list' => ['sku', 'quantity', 'selling_price']]],
        'batches' => ['Batch', 'Batch / lot number', 'in_stock,expired,recalled,used_up', [
            'item:record=items|Item*',
            'quantity:number*',
            'serial_numbers:textarea|Serial numbers',
        ], ['icon' => 'boxes', 'prefix' => 'LOT-', 'plural' => 'Batches', 'date' => 'Received on', 'due' => 'Expiry date', 'list' => ['item', 'quantity']]],
        'movements' => ['Stock movement', 'Reason', 'posted,reversed', [
            'item:record=items|Item*',
            'type:select=received,sold,adjustment_in,adjustment_out,damaged,returned,transfer*',
            'quantity:number*',
            'reference',
        ], ['icon' => 'arrow-left-right', 'prefix' => 'MOV-', 'amount' => 'Value', 'date' => 'Date', 'list' => ['item', 'type', 'quantity']]],
    ], ['logic' => InventoryLogic::class]],

    'warehouse' => ['Warehouse management', 'warehouse', 'Warehouses, bins, pick lists and stock transfers.', [
        'warehouses' => ['Warehouse', 'Warehouse name', 'active,closed', [
            'address',
            'manager:user|Manager',
            'capacity|Capacity',
        ], ['icon' => 'warehouse', 'prefix' => 'WH-', 'list' => ['address', 'manager']]],
        'bins' => ['Bin', 'Bin code', 'empty,in_use,blocked', [
            'warehouse:record=warehouses|Warehouse*',
            'zone',
            'contents:textarea',
        ], ['icon' => 'grid-3x3', 'prefix' => 'BIN-', 'list' => ['warehouse', 'zone']]],
        'picks' => ['Pick list', 'Order / reference', 'to_pick,picking,packed,dispatched', [
            'warehouse:record=warehouses|Warehouse*',
            'lines:textarea|Items & bins*',
            'packages:number',
        ], ['icon' => 'list-checks', 'prefix' => 'PK-', 'date' => 'Date', 'assignee' => true, 'list' => ['warehouse', 'packages']]],
        'transfers' => ['Transfer', 'Transfer', 'draft,in_transit,received,cancelled', [
            'from_warehouse:record=warehouses|From*',
            'to_warehouse:record=warehouses|To*',
            'items:textarea|Items & quantities*',
        ], ['icon' => 'arrow-right-left', 'prefix' => 'TRF-', 'date' => 'Sent on', 'list' => ['from_warehouse', 'to_warehouse']]],
    ], ['logic' => WarehouseLogic::class]],

    'suppliers' => ['Suppliers & vendors', 'truck', 'Supplier records, price lists and performance ratings.', [
        'suppliers' => ['Supplier', 'Supplier name', 'approved,pending,blocked', [
            'category',
            'tax_number|Tax number',
            'payment_terms|Payment terms',
            'bank_details:textarea|Bank details',
            'rating:select=1,2,3,4,5',
        ], ['icon' => 'truck', 'prefix' => 'SUP-', 'contact' => 'Contact', 'assignee' => true, 'list' => ['category', 'payment_terms', 'rating']]],
        'prices' => ['Price list item', 'Item', 'current,expired', [
            'supplier:record=suppliers|Supplier*',
            'unit_price:money|Unit price*',
            'lead_time:number|Lead time (days)',
            'minimum_order|Minimum order',
        ], ['icon' => 'tags', 'prefix' => 'SPL-', 'due' => 'Valid until', 'list' => ['supplier', 'unit_price', 'lead_time']]],
    ], ['logic' => SuppliersLogic::class]],

    'manufacturing' => ['Manufacturing & production', 'factory', 'Bills of materials, work orders and quality checks.', [
        'boms' => ['Bill of materials', 'Product', 'active,draft,obsolete', [
            'output_quantity:number|Output quantity*',
            'components:textarea|Components & quantities*',
            'instructions:textarea',
        ], ['icon' => 'list-tree', 'prefix' => 'BOM-', 'plural' => 'Bills of materials', 'amount' => 'Unit cost', 'list' => ['output_quantity']]],
        'work_orders' => ['Work order', 'Work order', 'planned,in_progress,qc,completed,cancelled', [
            'bom:record=boms|Bill of materials*',
            'quantity:number|Quantity to make*',
            'quantity_made:number|Quantity made',
            'line|Production line',
            'scrap:number',
        ], ['icon' => 'factory', 'prefix' => 'WO-', 'date' => 'Start date', 'due' => 'Due date', 'assignee' => true, 'list' => ['bom', 'quantity', 'quantity_made']]],
        'quality_checks' => ['Quality check', 'Check', 'passed,failed,rework', [
            'work_order:record=work_orders|Work order*',
            'sample_size:number|Sample size',
            'defects:number',
            'notes:textarea',
        ], ['icon' => 'badge-check', 'prefix' => 'QC-', 'date' => 'Checked on', 'assignee' => true, 'list' => ['work_order', 'defects']]],
    ], ['logic' => ManufacturingLogic::class]],

    'orders' => ['Order management', 'clipboard-list', 'Customer orders, back-orders and drop-ship fulfilment.', [
        'orders' => ['Order', 'Order', 'new,confirmed,picking,part_shipped,shipped,delivered,cancelled', [
            'channel:select=phone,walk_in,online,whatsapp,sales_rep,marketplace',
            'items:textarea|Items & quantities*',
            'fulfilment:select=from_stock,back_order,drop_ship',
            'delivery_address|Delivery address',
            'paid:checkbox',
        ], ['icon' => 'clipboard-list', 'prefix' => 'SO-', 'contact' => 'Customer', 'amount' => 'Order total', 'date' => 'Order date', 'due' => 'Promised date', 'assignee' => true, 'list' => ['channel', 'fulfilment', 'paid']]],
        'backorders' => ['Back-order', 'Item', 'waiting,ready,fulfilled,cancelled', [
            'order:record=orders|Order*',
            'quantity:number*',
        ], ['icon' => 'hourglass', 'prefix' => 'BO-', 'due' => 'Expected on', 'list' => ['order', 'quantity']]],
    ], ['logic' => OrdersLogic::class]],

    'delivery' => ['Delivery & courier', 'map', 'Parcels, delivery runs and proof of delivery.', [
        'parcels' => ['Parcel', 'Contents', 'booked,collected,in_transit,out_for_delivery,delivered,failed,returned', [
            'recipient|Recipient name*',
            'recipient_phone:phone|Recipient phone',
            'address:textarea|Delivery address*',
            'weight:number|Weight (kg)',
            'cod_amount:money|Cash on delivery',
            'tracking_number|Tracking number',
            'received_by|Received by (proof of delivery)',
        ], ['icon' => 'package', 'prefix' => 'PCL-', 'contact' => 'Sender', 'amount' => 'Delivery fee', 'date' => 'Booked on', 'due' => 'Deliver by', 'assignee' => true, 'list' => ['recipient', 'tracking_number']]],
        'runs' => ['Delivery run', 'Route', 'planned,on_the_road,completed', [
            'driver:user|Driver*',
            'vehicle',
            'stops:number',
        ], ['icon' => 'route', 'prefix' => 'RUN-', 'date' => 'Date', 'list' => ['driver', 'vehicle', 'stops']]],
    ], ['logic' => DeliveryLogic::class]],

    'freight-forwarding' => ['Freight forwarding', 'ship', 'Shipments, containers and customs clearing.', [
        'shipments' => ['Shipment', 'Description', 'booked,in_transit,at_port,clearing,released,delivered', [
            'mode:select=sea,air,road,rail*',
            'origin*',
            'destination*',
            'bill_of_lading|Bill of lading / AWB',
            'incoterm:select=exw,fob,cfr,cif,dap,ddp',
            'weight:number|Weight (kg)',
        ], ['icon' => 'ship', 'prefix' => 'SHP-', 'contact' => 'Client', 'amount' => 'Charges', 'date' => 'Departure', 'due' => 'ETA', 'assignee' => true, 'list' => ['mode', 'origin', 'destination']]],
        'containers' => ['Container', 'Container number', 'empty,loaded,in_transit,returned', [
            'shipment:record=shipments|Shipment*',
            'size:select=20ft,40ft,40ft_hc,reefer*',
            'seal_number|Seal number',
        ], ['icon' => 'container', 'prefix' => 'CTN-', 'due' => 'Return by', 'list' => ['shipment', 'size']]],
        'clearances' => ['Customs entry', 'Entry', 'lodged,assessed,paid,released,queried', [
            'shipment:record=shipments|Shipment*',
            'entry_number|Entry number',
            'hs_codes|HS codes',
            'duty:money',
        ], ['icon' => 'file-check', 'prefix' => 'CE-', 'plural' => 'Customs entries', 'amount' => 'Total duties & taxes', 'date' => 'Lodged on', 'list' => ['shipment', 'entry_number']]],
    ], ['logic' => FreightLogic::class]],

    'wholesale-distribution-consignment-stock' => ['Wholesale distribution & consignment stock', 'boxes', 'Stock placed with dealers on consignment, and settlements for what sold.', [
        'consignments' => ['Consignment', 'Consignment', 'placed,partly_sold,settled,returned', [
            'items:textarea|Items & quantities*',
            'sold_value:money|Sold to date',
            'commission_rate:number|Dealer commission %',
        ], ['icon' => 'boxes', 'prefix' => 'CSG-', 'contact' => 'Dealer', 'amount' => 'Stock value', 'date' => 'Placed on', 'due' => 'Settle by', 'assignee' => true, 'list' => ['sold_value', 'commission_rate']]],
        'settlements' => ['Settlement', 'Reference', 'pending,paid', [
            'consignment:record=consignments|Consignment*',
            'units_sold:number',
        ], ['icon' => 'hand-coins', 'prefix' => 'STL-', 'amount' => 'Amount due', 'date' => 'Date', 'list' => ['consignment', 'units_sold']]],
    ]],

    'equipment-tool-hire-tents' => ['Equipment & tool hire (tents, chairs, machinery, scaffolding)', 'tent', 'Hire items and hire bookings with deposits and returns.', [
        'items' => ['Hire item', 'Item', 'available,on_hire,maintenance,retired', [
            'category:select=tent,chairs,tables,decor,machinery,scaffolding,tools,sound,other',
            'quantity:number|Quantity owned',
            'daily_rate:money|Daily rate',
        ], ['icon' => 'tent', 'prefix' => 'HI-', 'list' => ['category', 'quantity', 'daily_rate']]],
        'bookings' => ['Hire booking', 'Event / job', 'quoted,booked,out,returned,closed', [
            'items:textarea|Items & quantities*',
            'deposit:money',
            'delivery_address|Delivery address',
            'damages:textarea|Damages / missing',
        ], ['icon' => 'calendar-check', 'prefix' => 'HB-', 'contact' => 'Customer', 'amount' => 'Hire total', 'date' => 'Out on', 'due' => 'Return by', 'assignee' => true, 'list' => ['deposit']]],
    ]],

    'weighbridge-bulk-dispatch-quarry' => ['Weighbridge & bulk dispatch (quarry, grain, mining)', 'weight', 'Weighbridge tickets for trucks in and out, with net mass and dispatch notes.', [
        'tickets' => ['Weighbridge ticket', 'Truck registration', 'first_weigh,completed,void', [
            'direction:select=inbound,outbound*',
            'product*',
            'gross_mass:number|Gross mass (kg)*',
            'tare_mass:number|Tare mass (kg)',
            'net_mass:number|Net mass (kg)',
            'driver',
            'order_reference|Order / delivery note',
        ], ['icon' => 'weight', 'prefix' => 'WB-', 'contact' => 'Customer / supplier', 'amount' => 'Value', 'date' => 'Weighed at', 'assignee' => true, 'list' => ['direction', 'product', 'net_mass']]],
    ]],

    'quality-management-iso' => ['Quality management (ISO, NCRs, CAPA)', 'badge-check', 'Non-conformances, corrective actions and internal audits.', [
        'ncrs' => ['Non-conformance', 'Description', 'open,investigating,closed', [
            'source:select=customer_complaint,internal_audit,supplier,process,inspection*',
            'severity:select=minor,major,critical*',
            'root_cause:textarea|Root cause',
        ], ['icon' => 'circle-alert', 'prefix' => 'NCR-', 'plural' => 'Non-conformances', 'date' => 'Raised on', 'assignee' => true, 'list' => ['source', 'severity']]],
        'capas' => ['Corrective action', 'Action', 'planned,in_progress,verified,closed', [
            'ncr:record=ncrs|Non-conformance*',
            'type:select=corrective,preventive*',
            'effectiveness:textarea|Effectiveness check',
        ], ['icon' => 'wrench', 'prefix' => 'CAPA-', 'due' => 'Due date', 'assignee' => true, 'list' => ['ncr', 'type']]],
        'audits' => ['Internal audit', 'Area / clause', 'planned,done,reported', [
            'standard:select=iso_9001,iso_14001,iso_45001,iso_22000,iso_27001,other',
            'findings:textarea',
        ], ['icon' => 'clipboard-check', 'prefix' => 'IA-', 'date' => 'Audit date', 'assignee' => true, 'list' => ['standard']]],
    ]],

    'asset-tracking-with-qr' => ['Asset tracking with QR/RFID', 'scan-qr-code', 'Tagged assets and who has checked them out.', [
        'assets' => ['Asset', 'Asset name', 'available,checked_out,in_repair,lost,disposed', [
            'tag|QR / RFID tag*',
            'category',
            'serial_number|Serial number',
            'location',
        ], ['icon' => 'scan-qr-code', 'prefix' => 'AST-', 'amount' => 'Value', 'list' => ['tag', 'category', 'location']]],
        'checkouts' => ['Check-out', 'Purpose', 'out,returned,overdue', [
            'asset:record=assets|Asset*',
            'holder:user|Checked out to*',
            'condition_on_return|Condition on return',
        ], ['icon' => 'log-out', 'prefix' => 'CHK-', 'date' => 'Checked out', 'due' => 'Due back', 'list' => ['asset', 'holder']]],
    ]],

    'fuel-station-management-pumps' => ['Fuel station management (pumps, shifts, tank dips)', 'fuel', 'Tanks, daily dips, pump shifts and fuel deliveries.', [
        'tanks' => ['Tank', 'Tank', 'active,out_of_service', [
            'product:select=petrol,diesel,paraffin,lpg*',
            'capacity:number|Capacity (litres)',
        ], ['icon' => 'cylinder', 'prefix' => 'TNK-', 'list' => ['product', 'capacity']]],
        'dips' => ['Tank dip', 'Reading', 'recorded', [
            'tank:record=tanks|Tank*',
            'litres:number|Litres in tank*',
            'variance:number|Variance (litres)',
        ], ['icon' => 'gauge', 'prefix' => 'DIP-', 'date' => 'Dipped at', 'assignee' => true, 'list' => ['tank', 'litres', 'variance']]],
        'shifts' => ['Pump shift', 'Pump & shift', 'open,closed,short', [
            'attendant:user|Attendant*',
            'opening_meter:number|Opening meter',
            'closing_meter:number|Closing meter',
            'litres_sold:number|Litres sold',
            'cash:money',
            'card_and_mobile:money|Card & mobile money',
        ], ['icon' => 'fuel', 'prefix' => 'PS-', 'amount' => 'Expected takings', 'date' => 'Shift date', 'list' => ['attendant', 'litres_sold', 'cash']]],
        'deliveries' => ['Fuel delivery', 'Delivery note', 'received,disputed', [
            'tank:record=tanks|Tank*',
            'litres:number*',
        ], ['icon' => 'truck', 'prefix' => 'FD-', 'contact' => 'Supplier', 'amount' => 'Cost', 'date' => 'Delivered on', 'list' => ['tank', 'litres']]],
    ]],

    'lpg-gas-cylinder-distribution' => ['LPG / gas cylinder distribution', 'cylinder', 'Cylinder register, refills and exchanges with customers.', [
        'cylinders' => ['Cylinder', 'Serial number', 'full,empty,with_customer,condemned', [
            'size:select=3kg,5kg,9kg,14kg,19kg,48kg*',
            'test_due:date|Pressure test due',
        ], ['icon' => 'cylinder', 'prefix' => 'CYL-', 'contact' => 'Held by', 'list' => ['size', 'test_due']]],
        'sales' => ['Gas sale', 'Sale', 'completed,delivered,cancelled', [
            'type:select=refill,exchange,new_cylinder*',
            'size:select=3kg,5kg,9kg,14kg,19kg,48kg*',
            'quantity:number',
            'delivered:checkbox',
        ], ['icon' => 'flame', 'prefix' => 'GS-', 'contact' => 'Customer', 'amount' => 'Total', 'date' => 'Date', 'assignee' => true, 'list' => ['type', 'size', 'quantity']]],
    ]],

    'moving-removals-company' => ['Moving / removals company', 'truck', 'Survey, quote and move jobs with inventory of goods.', [
        'moves' => ['Move', 'Move', 'enquiry,surveyed,quoted,booked,in_progress,completed,cancelled', [
            'from_address:textarea|Moving from*',
            'to_address:textarea|Moving to*',
            'volume:number|Volume (m³)',
            'crew_size:number|Crew size',
            'truck',
            'inventory:textarea|Goods inventory',
            'insurance:checkbox|Goods in transit insurance',
        ], ['icon' => 'truck', 'prefix' => 'MV-', 'contact' => 'Customer', 'amount' => 'Quote', 'date' => 'Move date', 'assignee' => true, 'list' => ['volume', 'crew_size', 'truck']]],
    ]],

    'towing-roadside-assistance-dispatch' => ['Towing & roadside assistance dispatch', 'siren', 'Breakdown call-outs, dispatched trucks and jobs completed.', [
        'callouts' => ['Call-out', 'Vehicle & problem', 'received,dispatched,on_scene,towing,completed,cancelled', [
            'type:select=tow,jump_start,flat_tyre,fuel,lockout,accident*',
            'location:textarea|Pickup location*',
            'destination|Tow to',
            'registration|Vehicle registration',
            'insurer|Insurer / membership',
            'distance:number|Distance (km)',
        ], ['icon' => 'siren', 'prefix' => 'TOW-', 'contact' => 'Customer', 'amount' => 'Charge', 'date' => 'Received at', 'assignee' => true, 'list' => ['type', 'registration', 'insurer']]],
    ]],
];
