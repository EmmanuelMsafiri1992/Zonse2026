<?php

use App\Blueprints\Logic\BookshopLogic;
use App\Blueprints\Logic\CatalogueLogic;
use App\Blueprints\Logic\DealershipLogic;
use App\Blueprints\Logic\DeviceImeiLogic;
use App\Blueprints\Logic\GiftVoucherLogic;
use App\Blueprints\Logic\GroceryLogic;
use App\Blueprints\Logic\HardwareStoreLogic;
use App\Blueprints\Logic\LiquorStoreLogic;
use App\Blueprints\Logic\MarketplaceLogic;
use App\Blueprints\Logic\OnlineStoreLogic;
use App\Blueprints\Logic\RetailPharmacyLogic;
use App\Blueprints\Logic\ShippingLogic;

/*
 * Retail & commerce apps: online selling, specialised shops and marketplaces.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'online-store' => ['Online store', 'shopping-cart', 'Products, online orders and fulfilment for an e-commerce shop.', [
        'products' => ['Product', 'Product name', 'draft,active,out_of_stock,archived', [
            'sku|SKU',
            'price:money*',
            'compare_at_price:money|Compare-at price',
            'stock:number',
            'category',
            'image_url:url|Image',
            'description:textarea',
        ], ['icon' => 'package', 'prefix' => 'PRD-', 'list' => ['sku', 'price', 'stock']]],
        'orders' => ['Online order', 'Customer name', 'pending_payment,paid,packed,shipped,delivered,cancelled,refunded', [
            'items:textarea*',
            'email:email',
            'shipping_address:textarea|Shipping address',
            'shipping_method:select=courier,post,collection,own_delivery',
            'tracking_number|Tracking number',
        ], ['icon' => 'shopping-cart', 'prefix' => 'WEB-', 'contact' => 'Customer', 'amount' => 'Order total', 'date' => 'Ordered on', 'assignee' => true, 'list' => ['shipping_method', 'tracking_number']]],
    ], ['logic' => OnlineStoreLogic::class]],

    'marketplace' => ['Multi-vendor marketplace', 'store', 'Vendors, their listings and commission payouts.', [
        'vendors' => ['Vendor', 'Shop name', 'applied,approved,suspended,closed', [
            'commission_percent:number|Commission %*',
            'bank_details:textarea|Payout bank details',
            'email:email',
        ], ['icon' => 'store', 'prefix' => 'VEN-', 'contact' => 'Owner', 'date' => 'Joined on', 'list' => ['commission_percent', 'email']]],
        'listings' => ['Listing', 'Product name', 'pending_review,live,rejected,sold_out', [
            'vendor:record=vendors|Vendor*',
            'price:money*',
            'stock:number',
            'category',
        ], ['icon' => 'tag', 'prefix' => 'LST-', 'list' => ['vendor', 'price', 'stock']]],
        'payouts' => ['Vendor payout', 'Period', 'calculated,approved,paid', [
            'vendor:record=vendors|Vendor*',
            'gross_sales:money|Gross sales',
            'commission:money',
        ], ['icon' => 'banknote', 'prefix' => 'PAY-', 'amount' => 'Payout', 'date' => 'Paid on', 'list' => ['vendor', 'gross_sales', 'commission']]],
    ], ['logic' => MarketplaceLogic::class]],

    'catalog' => ['Product catalogue & price lists', 'book-open', 'Product catalogue with customer-specific price lists.', [
        'items' => ['Catalogue item', 'Product name', 'active,discontinued', [
            'code|Product code*',
            'brand',
            'unit:select=each,box,case,kg,litre,metre,pack',
            'list_price:money|List price*',
            'specifications:textarea',
            'image_url:url|Image',
        ], ['icon' => 'package', 'prefix' => 'CAT-', 'list' => ['code', 'brand', 'list_price']]],
        'price_lists' => ['Price list', 'Price list name', 'draft,active,expired', [
            'customer_group|Customer group',
            'discount_percent:number|Discount %',
            'currency',
        ], ['icon' => 'list', 'prefix' => 'PL-', 'date' => 'Valid from', 'due' => 'Valid to', 'list' => ['customer_group', 'discount_percent']]],
    ], ['logic' => CatalogueLogic::class]],

    'shipping' => ['Shipping & courier labels', 'package-check', 'Parcels, couriers, waybills and tracking.', [
        'shipments' => ['Shipment', 'Recipient name', 'ready,collected,in_transit,out_for_delivery,delivered,returned,lost', [
            'address:textarea|Delivery address*',
            'phone:phone',
            'courier:select=courier_guy,dhl,fedex,aramex,postnet,ups,post_office,own*',
            'waybill|Waybill / tracking number',
            'parcels:number',
            'weight:number|Weight (kg)',
            'order_reference|Order reference',
        ], ['icon' => 'package-check', 'prefix' => 'SHP-', 'contact' => 'Customer', 'amount' => 'Shipping cost', 'date' => 'Shipped on', 'due' => 'Expected delivery', 'assignee' => true, 'list' => ['courier', 'waybill', 'parcels']]],
    ], ['logic' => ShippingLogic::class]],

    'gift-cards-vouchers' => ['Gift vouchers', 'gift', 'Issue, sell and redeem shop gift vouchers.', [
        'vouchers' => ['Voucher', 'Voucher code', 'active,partly_used,redeemed,expired,void', [
            'balance:money*',
            'purchaser',
            'recipient',
        ], ['icon' => 'gift', 'prefix' => 'GV-', 'contact' => 'Purchaser', 'amount' => 'Value', 'date' => 'Issued on', 'due' => 'Expires on', 'list' => ['balance', 'recipient']]],
        'redemptions' => ['Redemption', 'Reference', 'redeemed,reversed', [
            'voucher:record=vouchers|Voucher*',
            'till|Till / branch',
        ], ['icon' => 'receipt', 'prefix' => 'RDM-', 'amount' => 'Amount used', 'date' => 'Date', 'assignee' => true, 'list' => ['voucher', 'till']]],
    ], ['logic' => GiftVoucherLogic::class]],

    'grocery-pos' => ['Supermarket / grocery', 'shopping-basket', 'Fresh produce, expiry tracking, markdowns and shelf price checks.', [
        'products' => ['Grocery item', 'Product name', 'on_shelf,low_stock,out_of_stock,delisted', [
            'barcode*',
            'department:select=fresh_produce,bakery,butchery,dairy,frozen,dry_goods,beverages,household,toiletries*',
            'price:money*',
            'sold_by:select=unit,kg',
            'stock:number',
            'reorder_level:number|Reorder level',
        ], ['icon' => 'shopping-basket', 'prefix' => 'GR-', 'list' => ['barcode', 'department', 'price']]],
        'expiries' => ['Expiry / markdown', 'Product & batch', 'tracked,marked_down,sold,written_off', [
            'product:record=products|Product*',
            'quantity:number*',
            'markdown_price:money|Markdown price',
        ], ['icon' => 'calendar-x', 'prefix' => 'EXP-', 'plural' => 'Expiries & markdowns', 'due' => 'Expiry date', 'assignee' => true, 'list' => ['product', 'quantity', 'markdown_price']]],
    ], ['logic' => GroceryLogic::class]],

    'pharmacy-retail-pos' => ['Retail pharmacy', 'pill', 'Front-shop pharmacy products with scheduled-medicine and batch control.', [
        'products' => ['Pharmacy product', 'Product name', 'in_stock,low_stock,out_of_stock,recalled', [
            'barcode',
            'schedule:select=unscheduled,s1,s2,s3,s4,s5,s6|Schedule',
            'batch_number|Batch number',
            'expiry_date:date|Expiry date',
            'price:money*',
            'stock:number',
        ], ['icon' => 'pill', 'prefix' => 'PH-', 'list' => ['schedule', 'expiry_date', 'stock']]],
        'scheduled_sales' => ['Scheduled sale', 'Patient name', 'dispensed,returned', [
            'product:record=products|Product*',
            'quantity:number*',
            'id_number|Patient ID',
            'prescription_number|Prescription number',
            'pharmacist:user|Pharmacist*',
        ], ['icon' => 'clipboard-list', 'prefix' => 'SCH-', 'plural' => 'Scheduled-medicine register', 'date' => 'Date', 'list' => ['product', 'quantity', 'pharmacist']]],
    ], ['logic' => RetailPharmacyLogic::class]],

    'hardware-building-supplies-store' => ['Hardware & building supplies', 'hammer', 'Building-material quotes, yard stock and deliveries to site.', [
        'quotes' => ['Material quote', 'Customer / project', 'draft,sent,accepted,expired', [
            'items:textarea|Materials list*',
            'delivery_needed:checkbox|Delivery needed',
            'site_address:textarea|Site address',
        ], ['icon' => 'file-text', 'prefix' => 'HQ-', 'contact' => 'Customer', 'amount' => 'Quote total', 'date' => 'Quoted on', 'due' => 'Valid until', 'assignee' => true, 'list' => ['delivery_needed']]],
        'deliveries' => ['Site delivery', 'Site / customer', 'scheduled,loaded,delivered,returned', [
            'quote:record=quotes|Quote',
            'vehicle',
            'driver',
            'delivery_note|Delivery note number',
        ], ['icon' => 'truck', 'prefix' => 'HDL-', 'date' => 'Delivery date', 'assignee' => true, 'list' => ['quote', 'vehicle', 'driver']]],
    ], ['logic' => HardwareStoreLogic::class]],

    'liquor-store-bottle-store' => ['Liquor / bottle store', 'wine', 'Liquor stock, empties deposits and licence compliance.', [
        'products' => ['Liquor product', 'Product name', 'in_stock,low_stock,out_of_stock', [
            'type:select=beer,wine,spirits,cider,rtd,soft_drink*',
            'size|Size (e.g. 750ml)',
            'price:money*',
            'case_price:money|Case price',
            'stock:number',
        ], ['icon' => 'wine', 'prefix' => 'LQ-', 'list' => ['type', 'size', 'stock']]],
        'empties' => ['Empties return', 'Customer', 'refunded,credited', [
            'crates:number',
            'bottles:number',
        ], ['icon' => 'recycle', 'prefix' => 'EMP-', 'plural' => 'Empties returns', 'amount' => 'Deposit refunded', 'date' => 'Date', 'assignee' => true, 'list' => ['crates', 'bottles']]],
    ], ['logic' => LiquorStoreLogic::class]],

    'mobile-phones-electronics-imei' => ['Mobile phones & electronics (IMEI)', 'smartphone', 'Serialised devices by IMEI, sales and phone repairs.', [
        'devices' => ['Device', 'Make & model', 'in_stock,sold,in_repair,returned', [
            'imei|IMEI / serial*',
            'condition:select=new,refurbished,used',
            'storage',
            'colour',
            'price:money*',
            'warranty_months:number|Warranty (months)',
        ], ['icon' => 'smartphone', 'prefix' => 'IMEI-', 'contact' => 'Buyer', 'date' => 'Sold on', 'list' => ['imei', 'condition', 'price']]],
        'repairs' => ['Repair', 'Device', 'booked_in,diagnosing,awaiting_parts,repairing,ready,collected', [
            'imei|IMEI / serial',
            'fault:textarea*',
            'passcode|Passcode (if given)',
            'technician:user|Technician',
        ], ['icon' => 'wrench', 'prefix' => 'REP-', 'contact' => 'Customer', 'amount' => 'Repair price', 'date' => 'Booked in', 'due' => 'Promised by', 'list' => ['imei', 'technician']]],
    ], ['logic' => DeviceImeiLogic::class]],

    'vehicle-dealership' => ['Vehicle dealership', 'car-front', 'Vehicle stock, test drives, deals and finance applications.', [
        'vehicles' => ['Vehicle', 'Make & model', 'in_stock,reserved,sold,in_prep', [
            'year:number*',
            'vin|VIN',
            'registration',
            'mileage:number|Mileage (km)',
            'colour',
            'cost_price:money|Cost price',
            'price:money|Selling price*',
        ], ['icon' => 'car-front', 'prefix' => 'VEH-', 'list' => ['year', 'mileage', 'price']]],
        'deals' => ['Deal', 'Customer name', 'enquiry,test_drive,offer,finance,sold,delivered,lost', [
            'vehicle:record=vehicles|Vehicle*',
            'trade_in|Trade-in vehicle',
            'trade_in_value:money|Trade-in value',
            'finance_bank|Finance bank',
            'deposit:money',
        ], ['icon' => 'handshake', 'prefix' => 'DL-', 'contact' => 'Customer', 'amount' => 'Deal value', 'date' => 'Date', 'assignee' => true, 'list' => ['vehicle', 'finance_bank', 'trade_in_value']]],
    ], ['logic' => DealershipLogic::class]],

    'bookshop-stationery' => ['Bookshop & stationery', 'book-open-text', 'Books by ISBN, stationery lists and special orders.', [
        'titles' => ['Title', 'Title', 'in_stock,low_stock,out_of_stock,on_order', [
            'isbn|ISBN / barcode',
            'author',
            'publisher',
            'category:select=fiction,non_fiction,textbook,children,stationery,other',
            'price:money*',
            'stock:number',
        ], ['icon' => 'book-open-text', 'prefix' => 'BK-', 'list' => ['isbn', 'author', 'stock']]],
        'orders' => ['Special order', 'Customer name', 'ordered,arrived,collected,cancelled', [
            'items:textarea|Books / school stationery list*',
            'school|School',
            'deposit:money',
        ], ['icon' => 'clipboard-list', 'prefix' => 'SPO-', 'contact' => 'Customer', 'amount' => 'Total', 'date' => 'Ordered on', 'due' => 'Expected', 'list' => ['school', 'deposit']]],
    ], ['logic' => BookshopLogic::class]],

    'second-hand-consignment-thrift' => ['Second-hand / consignment / thrift', 'shirt', 'Consignors, consigned items and their payouts.', [
        'consignors' => ['Consignor', 'Consignor name', 'active,inactive', [
            'split_percent:number|Consignor share %*',
            'phone:phone',
            'bank_details:textarea|Payout details',
        ], ['icon' => 'user-round', 'prefix' => 'CSG-', 'contact' => 'Contact', 'list' => ['split_percent', 'phone']]],
        'items' => ['Consigned item', 'Item description', 'received,listed,sold,returned,donated', [
            'consignor:record=consignors|Consignor*',
            'price:money*',
            'condition:select=new_with_tags,excellent,good,fair',
            'paid_out:checkbox|Consignor paid',
        ], ['icon' => 'tag', 'prefix' => 'ITM-', 'amount' => 'Sold for', 'date' => 'Received on', 'due' => 'Return by', 'list' => ['consignor', 'price', 'paid_out']]],
    ]],

    'classifieds-directory-listings' => ['Classifieds & directory listings', 'newspaper', 'Paid listings and ads with moderation and expiry.', [
        'listings' => ['Listing', 'Listing title', 'pending,live,featured,expired,rejected', [
            'category:select=business,property,vehicles,jobs,for_sale,services,events*',
            'description:textarea*',
            'location',
            'phone:phone',
            'website:url',
            'package:select=free,standard,featured,premium',
        ], ['icon' => 'newspaper', 'prefix' => 'CL-', 'contact' => 'Advertiser', 'amount' => 'Listing fee', 'date' => 'Published on', 'due' => 'Expires on', 'assignee' => true, 'list' => ['category', 'location', 'package']]],
    ]],

    'job-board-freelance-marketplace' => ['Job board / freelance marketplace', 'briefcase', 'Job and gig postings with applications.', [
        'jobs' => ['Job / gig', 'Title', 'draft,open,closed,filled', [
            'employer|Employer / client*',
            'type:select=full_time,part_time,contract,freelance_gig,internship*',
            'location|Location / remote',
            'budget:money|Salary / budget',
            'description:textarea*',
        ], ['icon' => 'briefcase', 'prefix' => 'JOB-', 'contact' => 'Employer', 'amount' => 'Posting fee', 'date' => 'Posted on', 'due' => 'Closing date', 'list' => ['employer', 'type', 'location']]],
        'applications' => ['Application', 'Applicant name', 'received,shortlisted,interview,hired,rejected', [
            'job:record=jobs|Job*',
            'email:email*',
            'cv_url:url|CV / portfolio',
            'proposal:textarea|Cover letter / proposal',
            'bid:money|Bid amount',
        ], ['icon' => 'file-user', 'prefix' => 'APP-', 'date' => 'Applied on', 'list' => ['job', 'email', 'bid']]],
    ]],

    'service-marketplace-handymen' => ['Service marketplace (handymen)', 'hammer', 'Vetted providers, customer requests and job matching.', [
        'providers' => ['Provider', 'Provider name', 'applied,vetted,active,suspended', [
            'trade:select=plumber,electrician,painter,carpenter,handyman,gardener,cleaner,mechanic*',
            'area|Service area',
            'phone:phone*',
            'rating:number',
            'commission_percent:number|Commission %',
        ], ['icon' => 'hard-hat', 'prefix' => 'PRV-', 'list' => ['trade', 'area', 'rating']]],
        'requests' => ['Service request', 'Job description', 'new,matched,quoted,booked,completed,cancelled', [
            'trade:select=plumber,electrician,painter,carpenter,handyman,gardener,cleaner,mechanic*',
            'address:textarea*',
            'provider:record=providers|Assigned provider',
            'customer_rating:number|Customer rating',
        ], ['icon' => 'hammer', 'prefix' => 'SR-', 'contact' => 'Customer', 'amount' => 'Job value', 'date' => 'Requested on', 'due' => 'Needed by', 'assignee' => true, 'list' => ['trade', 'provider']]],
    ]],

    'social-commerce-whatsapp-catalog' => ['Social commerce (WhatsApp catalogue)', 'message-circle', 'Products shared on WhatsApp, Instagram and Facebook, with chat orders.', [
        'products' => ['Catalogue product', 'Product name', 'active,hidden,sold_out', [
            'price:money*',
            'image_url:url|Image',
            'share_link:url|Share link',
            'stock:number',
        ], ['icon' => 'tag', 'prefix' => 'SCP-', 'list' => ['price', 'stock']]],
        'orders' => ['Chat order', 'Customer name', 'enquiry,confirmed,paid,dispatched,delivered,cancelled', [
            'channel:select=whatsapp,instagram,facebook,tiktok*',
            'phone:phone*',
            'items:textarea*',
            'proof_of_payment:url|Proof of payment',
            'delivery_address:textarea|Delivery address',
        ], ['icon' => 'message-circle', 'prefix' => 'SCO-', 'contact' => 'Customer', 'amount' => 'Order total', 'date' => 'Ordered on', 'assignee' => true, 'list' => ['channel', 'phone']]],
    ]],
];
