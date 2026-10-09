<?php

use App\Blueprints\Logic\CooperativeLogic;
use App\Blueprints\Logic\DairyLogic;
use App\Blueprints\Logic\FarmLogic;
use App\Blueprints\Logic\FishFarmingLogic;
use App\Blueprints\Logic\GreenhouseLogic;
use App\Blueprints\Logic\LivestockLogic;
use App\Blueprints\Logic\PoultryLogic;
use App\Blueprints\Logic\ProduceSalesLogic;

/*
 * Agriculture apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'farm' => ['Farm & crops', 'tractor', 'Fields, plantings, farm activities and harvests.', [
        'fields' => ['Field', 'Field name', 'active,fallow,leased_out', [
            'size_ha:number|Size (ha)',
            'soil_type:select=clay,loam,sandy,silt|Soil type',
            'irrigated:checkbox',
            'location',
        ], ['icon' => 'map', 'prefix' => 'FLD-', 'list' => ['size_ha', 'soil_type', 'irrigated']]],
        'plantings' => ['Planting', 'Crop', 'planned,planted,growing,harvested,failed', [
            'field:record=fields|Field*',
            'variety',
            'season',
            'area_ha:number|Area (ha)',
            'seed_rate|Seed rate',
            'expected_yield:number|Expected yield (t)',
        ], ['icon' => 'sprout', 'prefix' => 'PLT-', 'date' => 'Planted on', 'due' => 'Expected harvest', 'list' => ['field', 'variety', 'area_ha']]],
        'activities' => ['Activity', 'Activity', 'planned,done,skipped', [
            'planting:record=plantings|Planting',
            'type:select=land_prep,fertilising,spraying,weeding,irrigation,scouting,other*',
            'inputs_used:textarea|Inputs used',
        ], ['icon' => 'shovel', 'prefix' => 'ACT-', 'plural' => 'Activities', 'date' => 'Date', 'amount' => 'Cost', 'assignee' => true, 'list' => ['planting', 'type']]],
        'harvests' => ['Harvest', 'Lot / batch', 'in_store,sold,spoiled', [
            'planting:record=plantings|Planting*',
            'quantity:number|Quantity*',
            'unit:select=kg,tonnes,bags,crates',
            'grade',
            'storage|Stored at',
        ], ['icon' => 'wheat', 'prefix' => 'HRV-', 'date' => 'Harvested on', 'amount' => 'Value', 'list' => ['planting', 'quantity', 'unit']]],
    ], ['logic' => FarmLogic::class]],

    'livestock' => ['Livestock', 'beef', 'Herd register, health treatments, breeding and sales.', [
        'animals' => ['Animal', 'Tag number', 'alive,sold,died,slaughtered', [
            'species:select=cattle,goat,sheep,pig,other*',
            'breed',
            'sex:select=female,male',
            'date_of_birth:date|Date of birth',
            'dam_tag|Mother tag',
            'paddock|Paddock / kraal',
        ], ['icon' => 'beef', 'prefix' => 'LV-', 'list' => ['species', 'breed', 'sex']]],
        'treatments' => ['Treatment', 'Treatment', 'given,scheduled', [
            'animal:record=animals|Animal*',
            'product|Product / medicine',
            'dose',
            'withdrawal_days:number|Withdrawal period (days)',
        ], ['icon' => 'syringe', 'prefix' => 'TRT-', 'date' => 'Given on', 'due' => 'Next due', 'amount' => 'Cost', 'list' => ['animal', 'product']]],
        'weighings' => ['Weighing', 'Occasion', 'recorded', [
            'animal:record=animals|Animal*',
            'weight_kg:number|Weight (kg)*',
            'body_condition:select=1,2,3,4,5|Body condition score',
        ], ['icon' => 'scale', 'prefix' => 'WT-', 'date' => 'Date', 'list' => ['animal', 'weight_kg']]],
    ], ['logic' => LivestockLogic::class]],

    'poultry' => ['Poultry', 'egg', 'Flocks, daily production, feed and mortality.', [
        'flocks' => ['Flock', 'Flock name', 'active,sold,closed', [
            'type:select=layers,broilers,breeders,indigenous*',
            'birds_placed:number|Birds placed',
            'house|House / pen',
            'supplier',
        ], ['icon' => 'bird', 'prefix' => 'FLK-', 'date' => 'Placed on', 'amount' => 'Chick cost', 'list' => ['type', 'birds_placed', 'house']]],
        'daily_records' => ['Daily record', 'Day', 'recorded', [
            'flock:record=flocks|Flock*',
            'eggs_collected:number|Eggs collected',
            'feed_kg:number|Feed used (kg)',
            'mortality:number',
            'notes:textarea',
        ], ['icon' => 'clipboard-list', 'prefix' => 'DR-', 'date' => 'Date', 'list' => ['flock', 'eggs_collected', 'mortality']]],
    ], ['logic' => PoultryLogic::class]],

    'dairy' => ['Dairy & milk collection', 'milk', 'Milk deliveries from farmers and monthly payouts.', [
        'deliveries' => ['Delivery', 'Farmer', 'accepted,rejected', [
            'session:select=morning,evening',
            'litres:number|Litres*',
            'fat_percent:number|Fat %',
            'centre|Collection centre',
        ], ['icon' => 'milk', 'prefix' => 'MD-', 'plural' => 'Deliveries', 'contact' => 'Farmer contact', 'date' => 'Date', 'amount' => 'Value', 'list' => ['session', 'litres', 'centre']]],
    ], ['logic' => DairyLogic::class]],

    'cooperative' => ['Cooperative & members', 'users-round', 'Member register, share capital and member contributions.', [
        'members' => ['Member', 'Member name', 'active,suspended,exited', [
            'member_number|Member number',
            'phone:phone',
            'village|Village / ward',
            'shares:number|Shares held',
            'farm_size_ha:number|Farm size (ha)',
        ], ['icon' => 'user-round', 'prefix' => 'MBR-', 'contact' => 'Contact', 'date' => 'Joined on', 'list' => ['member_number', 'village', 'shares']]],
        'contributions' => ['Contribution', 'Description', 'received,pending,reversed', [
            'member:record=members|Member*',
            'type:select=share_capital,subscription,levy,savings*',
            'reference',
        ], ['icon' => 'coins', 'prefix' => 'CON-', 'date' => 'Date', 'amount' => 'Amount', 'list' => ['member', 'type']]],
    ], ['logic' => CooperativeLogic::class]],

    'produce-sales' => ['Produce sales & grading', 'apple', 'Harvest lots, grading and sales to buyers and markets.', [
        'lots' => ['Harvest lot', 'Crop & lot', 'harvested,graded,in_store,sold,spoiled', [
            'crop*',
            'field|Field / block',
            'quantity:number|Quantity (kg)*',
            'grade:select=grade_a,grade_b,grade_c,reject',
        ], ['icon' => 'apple', 'prefix' => 'LOT-', 'date' => 'Harvested on', 'assignee' => true, 'list' => ['crop', 'quantity', 'grade']]],
        'sales' => ['Produce sale', 'Buyer name', 'agreed,delivered,paid', [
            'lot:record=lots|Harvest lot*',
            'quantity:number|Quantity (kg)*',
            'price_per_kg:money|Price per kg',
            'market:select=farm_gate,fresh_produce_market,retailer,processor,export,contract',
        ], ['icon' => 'scale', 'prefix' => 'PS-', 'contact' => 'Buyer', 'amount' => 'Total', 'date' => 'Sold on', 'list' => ['lot', 'quantity', 'market']]],
    ], ['logic' => ProduceSalesLogic::class]],

    'fish-farming-aquaculture' => ['Fish farming / aquaculture', 'fish', 'Ponds and cages, stocking, feeding, water quality and harvests.', [
        'units' => ['Pond / cage', 'Pond or cage', 'empty,stocked,harvesting,maintenance', [
            'species:select=tilapia,catfish,trout,carp,prawns,other',
            'volume:number|Volume (m³)',
            'fish_count:number|Fish count',
            'average_weight:number|Average weight (g)',
        ], ['icon' => 'waves', 'prefix' => 'PND-', 'plural' => 'Ponds & cages', 'date' => 'Stocked on', 'list' => ['species', 'fish_count', 'average_weight']]],
        'logs' => ['Daily log', 'Pond / cage', 'logged', [
            'unit:record=units|Pond / cage*',
            'feed:number|Feed (kg)',
            'mortalities:number',
            'temperature:number|Water temp (°C)',
            'oxygen:number|Dissolved oxygen (mg/L)',
            'ph:number|pH',
        ], ['icon' => 'thermometer', 'prefix' => 'FL-', 'date' => 'Date', 'assignee' => true, 'list' => ['unit', 'feed', 'mortalities']]],
        'harvests' => ['Harvest', 'Pond / cage', 'harvested,sold', [
            'unit:record=units|Pond / cage*',
            'weight:number|Total weight (kg)*',
            'buyer',
        ], ['icon' => 'fish', 'prefix' => 'FH-', 'amount' => 'Sales value', 'date' => 'Harvested on', 'list' => ['unit', 'weight', 'buyer']]],
    ], ['logic' => FishFarmingLogic::class]],

    'greenhouse-irrigation-scheduling' => ['Greenhouse & irrigation scheduling', 'sprout', 'Greenhouses or blocks, irrigation and fertigation schedules.', [
        'blocks' => ['Greenhouse / block', 'Name', 'planted,fallow,harvesting', [
            'crop',
            'area:number|Area (m²)',
            'irrigation:select=drip,sprinkler,pivot,flood,hydroponic',
        ], ['icon' => 'sprout', 'prefix' => 'GH-', 'plural' => 'Greenhouses & blocks', 'date' => 'Planted on', 'list' => ['crop', 'area', 'irrigation']]],
        'irrigations' => ['Irrigation run', 'Block', 'scheduled,done,skipped', [
            'block:record=blocks|Greenhouse / block*',
            'start_time:time|Start time',
            'minutes:number|Duration (min)',
            'water:number|Water (litres)',
            'fertiliser|Fertigation mix',
            'ec:number|EC',
        ], ['icon' => 'droplets', 'prefix' => 'IRR-', 'date' => 'Date', 'assignee' => true, 'list' => ['block', 'start_time', 'minutes']]],
    ], ['logic' => GreenhouseLogic::class]],

    'agro-dealer-input-supply-store' => ['Agro-dealer / input supply store', 'store', 'Seed, fertiliser and chemical stock with farmer credit sales.', [
        'products' => ['Input product', 'Product name', 'in_stock,low_stock,out_of_stock', [
            'type:select=seed,fertiliser,herbicide,pesticide,fungicide,animal_feed,vet_medicine,tools*',
            'pack_size|Pack size',
            'batch_number|Batch number',
            'expiry_date:date|Expiry date',
            'price:money*',
            'stock:number',
        ], ['icon' => 'package', 'prefix' => 'AGI-', 'list' => ['type', 'pack_size', 'stock']]],
        'sales' => ['Farmer sale', 'Farmer name', 'paid,on_credit,settled', [
            'items:textarea*',
            'phone:phone',
            'voucher|Subsidy voucher number',
        ], ['icon' => 'receipt', 'prefix' => 'AGS-', 'contact' => 'Farmer', 'amount' => 'Total', 'date' => 'Date', 'due' => 'Pay by (harvest)', 'assignee' => true, 'list' => ['phone', 'voucher']]],
    ]],

    'contract-farming-out-grower-schemes' => ['Contract farming / out-grower schemes', 'handshake', 'Out-growers, input loans and deliveries against contracts.', [
        'growers' => ['Out-grower', 'Grower name', 'contracted,active,suspended,exited', [
            'grower_number|Grower number*',
            'crop*',
            'hectares:number',
            'village|Village / area',
            'phone:phone',
            'input_loan:money|Input loan',
        ], ['icon' => 'users', 'prefix' => 'OG-', 'contact' => 'Grower', 'date' => 'Contracted on', 'list' => ['grower_number', 'crop', 'hectares']]],
        'deliveries' => ['Delivery', 'Delivery note', 'received,graded,paid', [
            'grower:record=growers|Grower*',
            'weight:number|Weight (kg)*',
            'grade',
            'loan_deduction:money|Loan deduction',
        ], ['icon' => 'truck', 'prefix' => 'OGD-', 'amount' => 'Gross value', 'date' => 'Delivered on', 'assignee' => true, 'list' => ['grower', 'weight', 'loan_deduction']]],
    ]],

    'tractor-machinery-hire' => ['Tractor & machinery hire', 'tractor', 'Tractor services for farmers: ploughing, planting and harvesting by hectare.', [
        'machines' => ['Machine', 'Machine', 'available,booked,in_field,repair', [
            'type:select=tractor,planter,harvester,sprayer,trailer,baler*',
            'operator',
            'hour_meter:number|Hour meter',
        ], ['icon' => 'tractor', 'prefix' => 'MCH-', 'list' => ['type', 'operator', 'hour_meter']]],
        'jobs' => ['Hire job', 'Farmer name', 'booked,in_progress,done,paid', [
            'machine:record=machines|Machine*',
            'service:select=ploughing,harrowing,planting,spraying,harvesting,transport*',
            'location|Farm / village',
            'hectares:number*',
            'rate_per_ha:money|Rate per hectare',
            'fuel:number|Fuel used (L)',
        ], ['icon' => 'tractor', 'prefix' => 'TH-', 'contact' => 'Farmer', 'amount' => 'Total', 'date' => 'Date', 'assignee' => true, 'list' => ['machine', 'service', 'hectares']]],
    ]],

    'grain-storage' => ['Grain storage & warehouse receipts', 'wheat', 'Silos and bags, grain receipts, moisture and warehouse receipts.', [
        'stores' => ['Silo / store', 'Name', 'active,empty,fumigating', [
            'capacity:number|Capacity (t)*',
            'commodity:select=maize,wheat,soya,sorghum,rice,beans,other',
            'current_stock:number|Current stock (t)',
        ], ['icon' => 'warehouse', 'prefix' => 'SILO-', 'plural' => 'Silos & stores', 'list' => ['capacity', 'commodity', 'current_stock']]],
        'receipts' => ['Warehouse receipt', 'Depositor name', 'issued,pledged,withdrawn,cancelled', [
            'store:record=stores|Silo / store*',
            'commodity:select=maize,wheat,soya,sorghum,rice,beans,other*',
            'weight:number|Net weight (t)*',
            'moisture:number|Moisture %',
            'grade',
            'pledged_to|Pledged to (bank)',
        ], ['icon' => 'file-badge', 'prefix' => 'WR-', 'contact' => 'Depositor', 'amount' => 'Storage fees', 'date' => 'Deposited on', 'list' => ['store', 'weight', 'grade']]],
    ]],

    'extension-services-farmer-training' => ['Extension services & farmer training', 'presentation', 'Farmer register, field visits and training days.', [
        'farmers' => ['Farmer', 'Farmer name', 'active,inactive', [
            'village|Village / ward',
            'phone:phone',
            'farm_size:number|Farm size (ha)',
            'main_crops|Main crops / livestock',
            'group|Farmer group / co-op',
        ], ['icon' => 'user-round', 'prefix' => 'FRM-', 'list' => ['village', 'farm_size', 'group']]],
        'visits' => ['Field visit', 'Farmer', 'planned,done', [
            'farmer:record=farmers|Farmer*',
            'topic|Topic / problem',
            'advice:textarea|Advice given',
            'follow_up:date|Follow-up date',
        ], ['icon' => 'map-pin', 'prefix' => 'FV-', 'date' => 'Date', 'assignee' => true, 'list' => ['farmer', 'topic', 'follow_up']]],
        'trainings' => ['Training day', 'Topic', 'planned,held,cancelled', [
            'venue',
            'attendees:number',
            'women_attendees:number|Women attending',
            'notes:textarea',
        ], ['icon' => 'presentation', 'prefix' => 'FT-', 'date' => 'Date', 'assignee' => true, 'list' => ['venue', 'attendees']]],
    ]],

    'veterinary-animal-health-visits' => ['Veterinary & animal-health visits', 'stethoscope', 'Farm calls, treatments, vaccinations and withdrawal periods.', [
        'visits' => ['Farm call', 'Farm / owner', 'booked,on_route,done,invoiced', [
            'species:select=cattle,sheep,goats,pigs,poultry,horses,dogs_cats,other*',
            'animals:number|Animals seen',
            'diagnosis:textarea',
            'treatment:textarea',
            'withdrawal_until:date|Milk / meat withdrawal until',
        ], ['icon' => 'stethoscope', 'prefix' => 'VET-', 'contact' => 'Farmer', 'amount' => 'Fee', 'date' => 'Date', 'assignee' => true, 'list' => ['species', 'animals', 'withdrawal_until']]],
        'vaccinations' => ['Vaccination campaign', 'Disease / vaccine', 'planned,running,completed', [
            'area',
            'animals_vaccinated:number|Animals vaccinated',
        ], ['icon' => 'syringe', 'prefix' => 'VAC-', 'date' => 'Starts on', 'due' => 'Ends on', 'assignee' => true, 'list' => ['area', 'animals_vaccinated']]],
    ]],

    'land-leasing-land-registry' => ['Farm land leasing & land registry', 'map', 'Farm parcels, owners and lease agreements.', [
        'parcels' => ['Parcel', 'Parcel / farm name', 'owned,leased_out,leased_in,vacant', [
            'parcel_number|Parcel / title number*',
            'hectares:number*',
            'location',
            'gps|GPS coordinates',
            'owner',
            'land_use:select=crop,grazing,orchard,forest,mixed',
        ], ['icon' => 'map', 'prefix' => 'PCL-', 'list' => ['parcel_number', 'hectares', 'land_use']]],
        'leases' => ['Land lease', 'Lessee name', 'draft,active,expired,terminated', [
            'parcel:record=parcels|Parcel*',
            'rent_basis:select=per_hectare,fixed,crop_share',
            'payment_frequency:select=monthly,seasonal,annual',
        ], ['icon' => 'file-signature', 'prefix' => 'LL-', 'contact' => 'Lessee', 'amount' => 'Annual rent', 'date' => 'Start date', 'due' => 'End date', 'list' => ['parcel', 'rent_basis']]],
    ]],
];
