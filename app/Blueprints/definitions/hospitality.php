<?php

use App\Blueprints\Logic\BakeryLogic;
use App\Blueprints\Logic\BarLogic;
use App\Blueprints\Logic\BusBookingLogic;
use App\Blueprints\Logic\ButcheryLogic;
use App\Blueprints\Logic\CarRentalLogic;
use App\Blueprints\Logic\CateringLogic;
use App\Blueprints\Logic\FoodDeliveryLogic;
use App\Blueprints\Logic\HotelLogic;
use App\Blueprints\Logic\RestaurantLogic;
use App\Blueprints\Logic\SafariLogic;
use App\Blueprints\Logic\ToursLogic;
use App\Blueprints\Logic\TravelAgencyLogic;
use App\Blueprints\Logic\VenueHireLogic;

/*
 * Hospitality & travel apps: lodging, food and drink, tours, rentals and venues.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'hotel' => ['Hotel & lodge', 'bed-double', 'Rooms, reservations, front desk and housekeeping.', [
        'rooms' => ['Room', 'Room number', 'vacant_clean,vacant_dirty,occupied,out_of_order', [
            'type:select=single,double,twin,family,suite,chalet,dorm*',
            'rate:money|Nightly rate',
            'floor',
            'max_guests:number|Max guests',
        ], ['icon' => 'door-closed', 'prefix' => 'RM-', 'list' => ['type', 'rate', 'max_guests']]],
        'reservations' => ['Reservation', 'Guest name', 'enquiry,confirmed,checked_in,checked_out,cancelled,no_show', [
            'room:record=rooms|Room',
            'guests:number',
            'source:select=walk_in,phone,website,booking_com,airbnb,expedia,agent',
            'deposit:money',
            'special_requests:textarea|Special requests',
        ], ['icon' => 'calendar-check', 'prefix' => 'RES-', 'contact' => 'Guest', 'amount' => 'Total', 'date' => 'Check-in', 'due' => 'Check-out', 'assignee' => true, 'list' => ['room', 'guests', 'source']]],
        'housekeeping' => ['Housekeeping task', 'Task', 'to_do,in_progress,done,inspected', [
            'room:record=rooms|Room*',
            'type:select=checkout_clean,stayover_clean,deep_clean,turndown,maintenance*',
            'notes:textarea',
        ], ['icon' => 'sparkles', 'prefix' => 'HK-', 'date' => 'Date', 'assignee' => true, 'list' => ['room', 'type']]],
    ], ['logic' => HotelLogic::class]],

    'restaurant' => ['Restaurant & kitchen', 'utensils', 'Menu, tables, orders for the kitchen and reservations.', [
        'menu' => ['Menu item', 'Dish', 'available,sold_out,hidden', [
            'category:select=starter,main,dessert,drink,side,special*',
            'price:money*',
            'prep_station:select=kitchen,grill,bar,pastry',
            'allergens',
        ], ['icon' => 'chef-hat', 'prefix' => 'MNU-', 'plural' => 'Menu', 'list' => ['category', 'price', 'prep_station']]],
        'tables' => ['Table', 'Table name', 'free,occupied,reserved,dirty', [
            'seats:number*',
            'area|Area / section',
            'qr_code|QR menu code',
        ], ['icon' => 'armchair', 'prefix' => 'TBL-', 'list' => ['seats', 'area']]],
        'orders' => ['Order', 'Order', 'open,sent_to_kitchen,ready,served,paid,cancelled', [
            'table:record=tables|Table',
            'type:select=dine_in,takeaway,delivery*',
            'items:textarea|Items*',
            'kitchen_notes|Kitchen notes',
        ], ['icon' => 'receipt', 'prefix' => 'ORD-', 'amount' => 'Bill total', 'date' => 'Date', 'assignee' => true, 'list' => ['table', 'type']]],
        'reservations' => ['Reservation', 'Guest name', 'booked,seated,completed,no_show,cancelled', [
            'time:time|Time*',
            'party_size:number|Party size*',
            'table:record=tables|Table',
            'phone:phone',
        ], ['icon' => 'calendar-check', 'prefix' => 'RSV-', 'contact' => 'Guest', 'date' => 'Date', 'list' => ['time', 'party_size', 'table']]],
    ], ['logic' => RestaurantLogic::class]],

    'food-delivery' => ['Food ordering & delivery', 'bike', 'Online food orders, riders and delivery status.', [
        'orders' => ['Delivery order', 'Customer name', 'received,preparing,ready,out_for_delivery,delivered,cancelled', [
            'items:textarea*',
            'address:textarea|Delivery address*',
            'phone:phone*',
            'channel:select=website,whatsapp,phone,uber_eats,mr_d,glovo,bolt_food',
            'rider:user|Rider',
            'delivery_fee:money|Delivery fee',
            'payment:select=paid_online,cash_on_delivery,card_on_delivery',
        ], ['icon' => 'bike', 'prefix' => 'FD-', 'contact' => 'Customer', 'amount' => 'Order total', 'date' => 'Ordered at', 'list' => ['channel', 'rider', 'payment']]],
    ], ['logic' => FoodDeliveryLogic::class]],

    'tours' => ['Tours & travel', 'compass', 'Tour packages, departures, bookings and guides.', [
        'tours' => ['Tour', 'Tour name', 'active,seasonal,retired', [
            'duration|Duration',
            'itinerary:textarea*',
            'price_per_person:money|Price per person',
            'max_group:number|Max group size',
        ], ['icon' => 'compass', 'prefix' => 'TR-', 'list' => ['duration', 'price_per_person']]],
        'departures' => ['Departure', 'Departure', 'scheduled,confirmed,running,completed,cancelled', [
            'tour:record=tours|Tour*',
            'guide:user|Guide',
            'vehicle',
            'seats_booked:number|Seats booked',
        ], ['icon' => 'calendar-days', 'prefix' => 'DEP-', 'date' => 'Departs on', 'due' => 'Returns on', 'list' => ['tour', 'guide', 'seats_booked']]],
        'bookings' => ['Tour booking', 'Lead traveller', 'enquiry,booked,paid,cancelled', [
            'departure:record=departures|Departure*',
            'travellers:number*',
            'pickup|Pick-up point',
            'passport_details:textarea|Passport / ID details',
        ], ['icon' => 'ticket', 'prefix' => 'TB-', 'contact' => 'Customer', 'amount' => 'Total', 'date' => 'Booked on', 'list' => ['departure', 'travellers']]],
    ], ['logic' => ToursLogic::class]],

    'car-rental' => ['Car rental', 'key-round', 'Rental fleet, bookings, handovers and returns.', [
        'cars' => ['Rental car', 'Make & model', 'available,rented,reserved,maintenance', [
            'registration*',
            'category:select=economy,compact,sedan,suv,4x4,van,luxury*',
            'daily_rate:money|Daily rate',
            'mileage:number|Mileage (km)',
        ], ['icon' => 'car', 'prefix' => 'CAR-', 'list' => ['registration', 'category', 'daily_rate']]],
        'rentals' => ['Rental', 'Customer name', 'reserved,out,returned,cancelled', [
            'car:record=cars|Car*',
            'licence_number|Driver licence*',
            'deposit:money',
            'mileage_out:number|Mileage out',
            'mileage_in:number|Mileage in',
            'fuel_out:select=full,3_4,1_2,1_4,empty|Fuel out',
            'damage_notes:textarea|Damage notes',
        ], ['icon' => 'key-round', 'prefix' => 'RNT-', 'contact' => 'Customer', 'amount' => 'Rental total', 'date' => 'Pick-up', 'due' => 'Return', 'assignee' => true, 'list' => ['car', 'deposit']]],
    ], ['logic' => CarRentalLogic::class]],

    'bus-booking' => ['Bus & coach seats', 'bus', 'Routes, scheduled trips and seat bookings.', [
        'trips' => ['Trip', 'Route', 'scheduled,boarding,departed,arrived,cancelled', [
            'bus|Bus / registration*',
            'departure_time:time|Departure time*',
            'seats:number|Seats*',
            'seats_sold:number|Seats sold',
            'fare:money',
        ], ['icon' => 'bus', 'prefix' => 'TRP-', 'date' => 'Date', 'assignee' => true, 'list' => ['bus', 'departure_time', 'seats_sold']]],
        'tickets' => ['Seat ticket', 'Passenger name', 'booked,paid,boarded,cancelled,no_show', [
            'trip:record=trips|Trip*',
            'seat_number|Seat number*',
            'phone:phone',
            'id_number|ID / passport',
            'luggage:number|Bags',
        ], ['icon' => 'ticket', 'prefix' => 'TKT-', 'contact' => 'Passenger', 'amount' => 'Fare', 'date' => 'Booked on', 'list' => ['trip', 'seat_number']]],
    ], ['logic' => BusBookingLogic::class]],

    'bar' => ['Bar', 'wine', 'Bar tabs, bottle service and table bookings.', [
        'tabs' => ['Tab', 'Customer / table', 'open,closed,unpaid', [
            'items:textarea*',
            'bartender:user|Bartender',
            'payment:select=cash,card,mobile_money,account',
        ], ['icon' => 'beer', 'prefix' => 'TAB-', 'amount' => 'Tab total', 'date' => 'Date', 'list' => ['bartender', 'payment']]],
        'bookings' => ['VIP booking', 'Guest name', 'booked,arrived,completed,cancelled', [
            'section|Table / section',
            'party_size:number|Party size',
            'bottle_package|Bottle package',
            'minimum_spend:money|Minimum spend',
        ], ['icon' => 'wine', 'prefix' => 'VIP-', 'contact' => 'Guest', 'amount' => 'Deposit', 'date' => 'Date', 'list' => ['section', 'party_size', 'minimum_spend']]],
    ], ['logic' => BarLogic::class]],

    'catering-event-food-orders' => ['Catering & event food orders', 'cooking-pot', 'Catering quotes, event menus and kitchen production.', [
        'events' => ['Catering job', 'Event', 'enquiry,quoted,confirmed,delivered,invoiced,cancelled', [
            'venue',
            'guests:number*',
            'service:select=buffet,plated,boxed,canapes,drop_off',
            'menu:textarea*',
            'dietary:textarea|Dietary requirements',
            'staff_needed:number|Staff needed',
        ], ['icon' => 'cooking-pot', 'prefix' => 'CAT-', 'contact' => 'Client', 'amount' => 'Quote', 'date' => 'Event date', 'assignee' => true, 'list' => ['venue', 'guests', 'service']]],
    ], ['logic' => CateringLogic::class]],

    'venue-hire' => ['Venues & banquets', 'party-popper', 'Halls and rooms for hire, with bookings and packages.', [
        'spaces' => ['Space', 'Space name', 'available,maintenance', [
            'capacity:number*',
            'layouts|Layouts (theatre, banquet, classroom…)',
            'day_rate:money|Day rate',
        ], ['icon' => 'building', 'prefix' => 'SPC-', 'list' => ['capacity', 'day_rate']]],
        'bookings' => ['Venue booking', 'Event name', 'provisional,confirmed,completed,cancelled', [
            'space:record=spaces|Space*',
            'guests:number',
            'start_time:time|Start',
            'end_time:time|End',
            'package|Package / extras',
            'deposit:money',
        ], ['icon' => 'party-popper', 'prefix' => 'VB-', 'contact' => 'Client', 'amount' => 'Total', 'date' => 'Event date', 'assignee' => true, 'list' => ['space', 'guests', 'start_time']]],
    ], ['logic' => VenueHireLogic::class]],

    'travel-agency' => ['Travel agency', 'plane', 'Flight bookings, visa applications, packages and commissions.', [
        'bookings' => ['Travel booking', 'Traveller name', 'quoted,booked,ticketed,travelled,cancelled,refunded', [
            'type:select=flight,hotel,package,car,insurance*',
            'route|Route / destination*',
            'pnr|PNR / booking reference',
            'supplier|Airline / supplier',
            'commission:money',
        ], ['icon' => 'plane', 'prefix' => 'TRV-', 'contact' => 'Customer', 'amount' => 'Selling price', 'date' => 'Travel date', 'due' => 'Ticketing deadline', 'assignee' => true, 'list' => ['type', 'route', 'pnr']]],
        'visas' => ['Visa application', 'Applicant name', 'documents_pending,submitted,approved,rejected,collected', [
            'country*',
            'visa_type|Visa type',
            'passport_number|Passport number',
            'embassy_reference|Embassy reference',
        ], ['icon' => 'stamp', 'prefix' => 'VSA-', 'contact' => 'Customer', 'amount' => 'Fee', 'date' => 'Submitted on', 'due' => 'Travel date', 'assignee' => true, 'list' => ['country', 'visa_type']]],
    ], ['logic' => TravelAgencyLogic::class]],

    'safari-camping-activity-bookings' => ['Safari / camping / activity bookings', 'tent-tree', 'Campsites, activities and guest bookings.', [
        'activities' => ['Activity / site', 'Name', 'available,closed', [
            'type:select=game_drive,walking_safari,campsite,boat_cruise,rafting,bungee,horse_ride,other*',
            'capacity:number',
            'price_per_person:money|Price per person',
            'duration',
        ], ['icon' => 'tent-tree', 'prefix' => 'ACT-', 'plural' => 'Activities & sites', 'list' => ['type', 'capacity', 'price_per_person']]],
        'bookings' => ['Booking', 'Lead guest', 'booked,paid,completed,cancelled', [
            'activity:record=activities|Activity / site*',
            'guests:number*',
            'start_time:time|Start time',
            'indemnity_signed:checkbox|Indemnity signed',
        ], ['icon' => 'calendar-check', 'prefix' => 'SB-', 'contact' => 'Guest', 'amount' => 'Total', 'date' => 'Date', 'due' => 'Until', 'list' => ['activity', 'guests', 'indemnity_signed']]],
    ], ['logic' => SafariLogic::class]],

    'bakery-confectionery-orders-custom' => ['Bakery & confectionery orders (custom cakes)', 'cake', 'Custom cake orders and daily bake production.', [
        'orders' => ['Cake order', 'Cake / occasion', 'enquiry,confirmed,baking,decorating,ready,collected,cancelled', [
            'size|Size / tiers*',
            'flavour',
            'design:textarea|Design & message',
            'reference_image:url|Reference picture',
            'deposit:money',
            'delivery:checkbox',
        ], ['icon' => 'cake', 'prefix' => 'CK-', 'contact' => 'Customer', 'amount' => 'Price', 'date' => 'Ordered on', 'due' => 'Collection date', 'assignee' => true, 'list' => ['size', 'flavour', 'deposit']]],
        'production' => ['Bake batch', 'Product', 'planned,baked,sold_out', [
            'quantity:number*',
            'sold:number',
            'wasted:number',
        ], ['icon' => 'croissant', 'prefix' => 'BAK-', 'plural' => 'Daily production', 'date' => 'Date', 'list' => ['quantity', 'sold', 'wasted']]],
    ], ['logic' => BakeryLogic::class]],

    'butchery-with-scale-integration' => ['Butchery with scale integration', 'beef', 'Carcass intake, cuts sold by weight and meat-pack orders.', [
        'carcasses' => ['Carcass', 'Tag / description', 'hanging,cut,sold_out', [
            'animal:select=beef,pork,lamb,goat,chicken,game*',
            'weight:number|Hanging weight (kg)*',
            'yield:number|Yield (kg)',
        ], ['icon' => 'beef', 'prefix' => 'CRC-', 'contact' => 'Supplier', 'amount' => 'Cost', 'date' => 'Received on', 'list' => ['animal', 'weight', 'yield']]],
        'sales' => ['Weighed sale', 'Cut', 'completed,refunded', [
            'weight:number|Weight (kg)*',
            'price_per_kg:money|Price per kg*',
            'scale_ticket|Scale ticket',
        ], ['icon' => 'weight', 'prefix' => 'BS-', 'contact' => 'Customer', 'amount' => 'Total', 'date' => 'Date', 'assignee' => true, 'list' => ['weight', 'price_per_kg']]],
    ], ['logic' => ButcheryLogic::class]],
];
