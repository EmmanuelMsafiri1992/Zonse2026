<?php

use App\Blueprints\Logic\BodaBodaLogic;
use App\Blueprints\Logic\CoachOperatorLogic;
use App\Blueprints\Logic\HaulageLogic;
use App\Blueprints\Logic\StaffTransportLogic;
use App\Blueprints\Logic\TaxiDispatchLogic;

/*
 * Transport apps: passenger, rider and haulage operations.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'taxi' => ['Taxi & ride dispatch', 'car-taxi-front', 'Drivers, ride requests and dispatch.', [
        'drivers' => ['Driver', 'Driver name', 'available,on_trip,off_duty,suspended', [
            'phone:phone*',
            'vehicle|Vehicle & registration*',
            'licence_expiry:date|Licence / PrDP expiry',
            'commission_percent:number|Company share %',
        ], ['icon' => 'user-round', 'prefix' => 'DRV-', 'list' => ['phone', 'vehicle', 'licence_expiry']]],
        'rides' => ['Ride', 'Passenger name', 'requested,assigned,picked_up,completed,cancelled', [
            'driver:record=drivers|Driver',
            'pickup|Pick-up*',
            'dropoff|Drop-off*',
            'pickup_time:time|Pick-up time',
            'distance:number|Distance (km)',
            'payment:select=cash,card,mobile_money,account',
        ], ['icon' => 'car-taxi-front', 'prefix' => 'RIDE-', 'contact' => 'Passenger', 'amount' => 'Fare', 'date' => 'Date', 'list' => ['driver', 'pickup', 'dropoff']]],
    ], ['logic' => TaxiDispatchLogic::class]],

    'motorbike-boda-boda-delivery-rider' => ['Motorbike / boda-boda riders', 'bike', 'Riders, bikes, daily remittances and delivery jobs.', [
        'riders' => ['Rider', 'Rider name', 'active,suspended,left', [
            'phone:phone*',
            'bike|Bike registration*',
            'arrangement:select=employee,work_to_own,daily_rental,commission',
            'daily_target:money|Daily remittance',
            'helmet_issued:checkbox|Helmet issued',
        ], ['icon' => 'user-round', 'prefix' => 'RDR-', 'list' => ['bike', 'arrangement', 'daily_target']]],
        'remittances' => ['Remittance', 'Rider', 'paid,short,missed', [
            'rider:record=riders|Rider*',
            'method:select=cash,mobile_money',
        ], ['icon' => 'coins', 'prefix' => 'REM-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['rider', 'method']]],
        'jobs' => ['Delivery job', 'Package / passenger', 'requested,assigned,picked_up,delivered,cancelled', [
            'rider:record=riders|Rider',
            'pickup|Pick-up*',
            'dropoff|Drop-off*',
            'recipient_phone:phone|Recipient phone',
        ], ['icon' => 'bike', 'prefix' => 'BDJ-', 'contact' => 'Customer', 'amount' => 'Fee', 'date' => 'Date', 'list' => ['rider', 'pickup', 'dropoff']]],
    ], ['logic' => BodaBodaLogic::class]],

    'bus-coach-booking' => ['Bus & coach operator', 'bus-front', 'Coach fleet, charters and crew assignments.', [
        'coaches' => ['Coach', 'Fleet number', 'available,on_trip,maintenance', [
            'registration*',
            'seats:number*',
            'cof_expiry:date|Roadworthy / COF expiry',
        ], ['icon' => 'bus-front', 'prefix' => 'CCH-', 'list' => ['registration', 'seats', 'cof_expiry']]],
        'charters' => ['Charter', 'Group / client', 'quoted,booked,on_trip,completed,cancelled', [
            'coach:record=coaches|Coach',
            'route|Route / itinerary*',
            'passengers:number',
            'driver:user|Driver',
            'departure_time:time|Departure time',
        ], ['icon' => 'route', 'prefix' => 'CHT-', 'contact' => 'Client', 'amount' => 'Price', 'date' => 'Departs on', 'due' => 'Returns on', 'list' => ['coach', 'route', 'driver']]],
    ], ['logic' => CoachOperatorLogic::class]],

    'school-bus-staff-transport' => ['School bus & staff transport', 'bus', 'Routes, passengers on each route and daily trip logs.', [
        'routes' => ['Route', 'Route name', 'active,suspended', [
            'vehicle',
            'driver',
            'stops:textarea*',
            'morning_departure:time|Morning departure',
            'afternoon_departure:time|Afternoon departure',
        ], ['icon' => 'route', 'prefix' => 'RT-', 'list' => ['vehicle', 'driver', 'morning_departure']]],
        'passengers' => ['Passenger', 'Passenger name', 'active,paused,left', [
            'route:record=routes|Route*',
            'stop|Pick-up stop',
            'guardian_phone:phone|Guardian / contact phone',
        ], ['icon' => 'users', 'prefix' => 'PSG-', 'amount' => 'Monthly fee', 'list' => ['route', 'stop']]],
        'trips' => ['Trip log', 'Route', 'completed,late,cancelled', [
            'route:record=routes|Route*',
            'run:select=morning,afternoon,special',
            'passengers_carried:number|Passengers carried',
            'odometer:number',
            'incidents:textarea',
        ], ['icon' => 'clipboard-list', 'prefix' => 'TL-', 'date' => 'Date', 'assignee' => true, 'list' => ['route', 'run', 'passengers_carried']]],
    ], ['logic' => StaffTransportLogic::class]],

    'haulage' => ['Haulage & trucking', 'truck', 'Loads, trips, rates and proof of delivery.', [
        'loads' => ['Load', 'Load reference', 'quoted,booked,loading,in_transit,delivered,invoiced', [
            'origin*',
            'destination*',
            'commodity',
            'weight:number|Weight (t)',
            'truck|Truck & trailer',
            'driver',
            'border_crossing|Border crossing',
            'pod_url:url|Proof of delivery',
        ], ['icon' => 'truck', 'prefix' => 'LD-', 'contact' => 'Customer', 'amount' => 'Rate', 'date' => 'Loading date', 'due' => 'Delivery date', 'assignee' => true, 'list' => ['origin', 'destination', 'truck']]],
        'trip_expenses' => ['Trip expense', 'Description', 'claimed,approved,paid', [
            'load:record=loads|Load*',
            'type:select=fuel,tolls,border_fees,meals,repairs,other*',
        ], ['icon' => 'receipt', 'prefix' => 'TE-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['load', 'type']]],
    ], ['logic' => HaulageLogic::class]],

    'airport-shuttles-chauffeur-services' => ['Airport shuttles & chauffeur services', 'plane-landing', 'Transfers with flight details, drivers and meet-and-greet.', [
        'transfers' => ['Transfer', 'Passenger name', 'booked,assigned,on_route,completed,no_show,cancelled', [
            'type:select=airport_pickup,airport_dropoff,point_to_point,hourly_chauffeur*',
            'flight_number|Flight number',
            'pickup_time:time|Pick-up time*',
            'pickup|Pick-up*',
            'dropoff|Drop-off*',
            'passengers:number',
            'vehicle_class:select=sedan,suv,minibus,luxury',
            'driver:user|Driver',
            'name_board|Name board text',
        ], ['icon' => 'plane-landing', 'prefix' => 'TRF-', 'contact' => 'Client', 'amount' => 'Fare', 'date' => 'Date', 'list' => ['type', 'flight_number', 'pickup_time', 'driver']]],
    ]],

    'parking-toll-management' => ['Parking lots & toll plazas', 'parking-meter', 'Lots or plazas, shifts and takings.', [
        'sites' => ['Site', 'Lot / plaza name', 'open,closed', [
            'type:select=parking_lot,toll_plaza*',
            'bays:number|Bays / lanes',
            'tariff|Tariff',
        ], ['icon' => 'parking-meter', 'prefix' => 'SITE-', 'list' => ['type', 'bays']]],
        'shifts' => ['Cashier shift', 'Cashier', 'open,closed,short,over', [
            'site:record=sites|Site*',
            'vehicles:number|Vehicles',
            'cash:money',
            'card:money',
            'expected:money|Expected takings',
        ], ['icon' => 'coins', 'prefix' => 'SH-', 'amount' => 'Total takings', 'date' => 'Date', 'assignee' => true, 'list' => ['site', 'vehicles', 'expected']]],
    ]],

    'driving-school-16-9' => ['Driving school', 'car-front', 'Learners, packages, lessons and test bookings.', [
        'learners' => ['Learner', 'Learner name', 'enrolled,learning,test_booked,passed,left', [
            'phone:phone*',
            'licence_code:select=a,a1,b,c1,c,eb,ec|Licence code*',
            'learners_licence_expiry:date|Learner licence expiry',
            'package|Lesson package',
            'lessons_remaining:number|Lessons remaining',
        ], ['icon' => 'user-round', 'prefix' => 'LRN-', 'contact' => 'Learner', 'amount' => 'Package price', 'date' => 'Enrolled on', 'list' => ['licence_code', 'lessons_remaining']]],
        'lessons' => ['Driving lesson', 'Learner', 'booked,completed,missed,cancelled', [
            'learner:record=learners|Learner*',
            'start_time:time|Start time*',
            'vehicle',
            'instructor:user|Instructor',
            'feedback:textarea',
        ], ['icon' => 'car-front', 'prefix' => 'DL-', 'date' => 'Date', 'list' => ['learner', 'start_time', 'instructor']]],
        'tests' => ['Driving test', 'Learner', 'booked,passed,failed,cancelled', [
            'learner:record=learners|Learner*',
            'testing_centre|Testing centre',
            'booking_reference|Booking reference',
        ], ['icon' => 'clipboard-check', 'prefix' => 'DT-', 'amount' => 'Test fee', 'date' => 'Test date', 'list' => ['learner', 'testing_centre']]],
    ]],

    'gps-tracking' => ['GPS tracking & trip log', 'map-pinned', 'Tracker devices, trips and geofence alerts recorded per vehicle.', [
        'trackers' => ['Tracker', 'Vehicle / asset', 'online,offline,removed', [
            'imei|Tracker IMEI*',
            'sim_number|SIM number',
            'provider',
            'subscription_until:date|Subscription until',
        ], ['icon' => 'satellite-dish', 'prefix' => 'GPS-', 'list' => ['imei', 'provider', 'subscription_until']]],
        'trips' => ['Trip', 'Vehicle / asset', 'logged,reviewed', [
            'tracker:record=trackers|Tracker*',
            'from|From',
            'to|To',
            'distance:number|Distance (km)',
            'max_speed:number|Max speed (km/h)',
            'idle_minutes:number|Idle (min)',
        ], ['icon' => 'route', 'prefix' => 'TRP-', 'date' => 'Date', 'assignee' => true, 'list' => ['tracker', 'distance', 'max_speed']]],
        'alerts' => ['Alert', 'Alert', 'new,acknowledged,closed', [
            'tracker:record=trackers|Tracker*',
            'type:select=speeding,geofence_exit,geofence_entry,harsh_braking,after_hours,panic,power_cut*',
            'location',
        ], ['icon' => 'siren', 'prefix' => 'ALR-', 'date' => 'Date', 'assignee' => true, 'list' => ['tracker', 'type', 'location']]],
    ]],
];
