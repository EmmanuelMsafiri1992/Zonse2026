<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Profession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Profession → starter bundle (section 21 of the catalogue). Refs are
 * resolved to module keys at seed time so the bundle survives key changes.
 */
class ProfessionSeeder extends Seeder
{
    /** [name, group, icon, refs, featured] */
    protected array $professions = [
        ['Doctor / clinic', 'Healthcare', 'stethoscope', '7.1, 7.2, 7.3, 7.11, 7.8, 1.2, 1.1, 7.22', true],
        ['Dentist / optometrist / physio', 'Healthcare', 'smile', '7.9, 7.2, 7.3, 1.2, 1.1', false],
        ['Pharmacy', 'Healthcare', 'pill', '7.4, 1.10, 4.1, 1.1', true],
        ['Hospital', 'Healthcare', 'hospital', '7.6, 7.1, 7.2, 7.3, 7.4, 7.5, 7.8, 7.12, 7.13, 5.1, 5.5, 1.1, 1.2', false],
        ['Vet', 'Healthcare', 'paw-print', '7.10, 7.2, 4.1, 1.2', false],
        ['Accountant / bookkeeper', 'Finance & professional services', 'calculator', '1.25, 1.1, 1.2, 1.9, 1.5, 6.3, 2.12', true],
        ['Lawyer', 'Finance & professional services', 'scale', '13.6, 6.3, 1.2, 2.6, 6.4', true],
        ['Insurance broker', 'Finance & professional services', 'shield', '1.27, 2.1, 1.2', false],
        ['Microfinance / SACCO', 'Finance & professional services', 'piggy-bank', '1.15, 1.16, 1.24, 1.1, 3.2', false],
        ['Freelancer / consultant', 'Finance & professional services', 'laptop', '6.15, 6.3, 1.2, 2.1, 6.14', true],
        ['Software / IT company', 'Finance & professional services', 'server', '13.16, 2.2, 6.12, 6.2, 13.17', false],
        ['Retail shop / supermarket', 'Retail & hospitality', 'shopping-cart', '1.10, 14.6, 4.1, 1.2, 2.4', true],
        ['Restaurant / café / bar', 'Retail & hospitality', 'utensils', '9.2, 9.3, 1.10, 4.1, 5.5', true],
        ['Hotel / lodge', 'Retail & hospitality', 'bed-double', '9.1, 9.2, 1.1, 5.1, 5.5', false],
        ['School', 'Education', 'school', '8.1, 8.3, 8.4, 8.5, 8.7, 8.11, 1.1', true],
        ['University / college', 'Education', 'graduation-cap', '8.9, 8.2, 8.3, 8.10, 1.1', false],
        ['Online tutor / trainer', 'Education', 'presentation', '8.2, 8.6, 13.1, 1.2', false],
        ['Church', 'Community & events', 'church', '12.5, 12.2, 1.1, 3.2, 12.10', true],
        ['NGO', 'Community & events', 'heart-handshake', '12.7, 12.10, 1.1, 1.8, 6.2', false],
        ['Event organiser', 'Community & events', 'ticket', '12.1, 12.2, 12.3, 3.5, 1.2', true],
        ['Salon / spa / barber', 'Services', 'scissors', '13.2, 13.1, 1.10, 2.4, 3.2', true],
        ['Gym', 'Services', 'dumbbell', '13.3, 1.17, 2.4, 13.1', false],
        ['Electrician / plumber / technician', 'Services', 'wrench', '13.4, 11.9, 1.3, 1.2', false],
        ['Garage / mechanic', 'Services', 'car', '13.8, 4.1, 1.2, 14.11', false],
        ['Security company', 'Services', 'shield-check', '13.15, 5.5, 5.4, 1.2', false],
        ['Landlord / estate agent', 'Property & construction', 'home', '10.1, 10.2, 10.3, 10.4, 1.1', true],
        ['Construction / contractor', 'Property & construction', 'hard-hat', '11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 6.2, 1.19, 4.11', false],
        ['Transport / fleet owner', 'Transport & logistics', 'truck', '4.7, 16.2, 16.6, 16.10, 1.1', false],
        ['Bus company', 'Transport & logistics', 'bus', '9.6, 4.7, 1.10', false],
        ['Taxi / ride owner', 'Transport & logistics', 'car-taxi-front', '16.1, 16.2, 4.7', false],
        ['Farmer / cooperative', 'Agriculture & industry', 'tractor', '15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 1.1', true],
        ['Manufacturer', 'Agriculture & industry', 'factory', '4.4, 4.1, 4.2, 4.13, 1.21, 1.1', false],
        ['ISP / WiFi business', 'Agriculture & industry', 'wifi', '17.2, 1.17, 2.2', false],
        ['Government department / council', 'Public sector', 'landmark', '18.1, 18.2, 18.6, 18.7, 18.12, 0.12, 0.14', false],
        ['Individual', 'Personal', 'user', '20.1, 20.2, 20.3, 20.7', false],
    ];

    /**
     * Everyday words people use for each business, matched against the
     * onboarding "What do you do?" box (see App\Support\BundleAdvisor).
     *
     * @var array<string, array<int, string>>
     */
    protected array $keywords = [
        'doctor-clinic' => ['doctor', 'gp', 'clinic', 'surgery', 'medical', 'practice', 'nurse', 'health'],
        'dentist-optometrist-physio' => ['dentist', 'dental', 'optometrist', 'optician', 'eye', 'physio', 'physiotherapy', 'chiropractor'],
        'pharmacy' => ['pharmacy', 'chemist', 'drugstore', 'medicine', 'pharmacist', 'dispensary'],
        'hospital' => ['hospital', 'ward', 'wards', 'inpatient', 'theatre', 'maternity', 'beds'],
        'vet' => ['vet', 'veterinary', 'animal', 'animals', 'pets', 'pet'],
        'accountant-bookkeeper' => ['accountant', 'accounting', 'bookkeeper', 'bookkeeping', 'audit', 'tax', 'payroll bureau'],
        'lawyer' => ['lawyer', 'law', 'legal', 'attorney', 'advocate', 'solicitor', 'conveyancing'],
        'insurance-broker' => ['insurance', 'broker', 'policies', 'funeral cover', 'underwriting'],
        'microfinance-sacco' => ['microfinance', 'sacco', 'loans', 'lending', 'savings', 'mukando', 'stokvel', 'credit'],
        'freelancer-consultant' => ['freelancer', 'freelance', 'consultant', 'consulting', 'designer', 'writer', 'photographer', 'coach'],
        'software-it-company' => ['software', 'it company', 'it support', 'developer', 'web', 'apps', 'tech', 'hosting', 'computer'],
        'retail-shop-supermarket' => ['shop', 'store', 'retail', 'supermarket', 'tuckshop', 'spaza', 'grocery', 'hardware', 'boutique'],
        'restaurant-cafe-bar' => ['restaurant', 'cafe', 'coffee', 'bar', 'pub', 'takeaway', 'food', 'bakery', 'kitchen', 'catering'],
        'hotel-lodge' => ['hotel', 'lodge', 'guesthouse', 'bnb', 'airbnb', 'accommodation', 'resort', 'backpackers'],
        'school' => ['school', 'primary', 'secondary', 'high school', 'preschool', 'creche', 'pupils', 'students'],
        'university-college' => ['university', 'college', 'polytechnic', 'campus', 'tertiary', 'lecturers'],
        'online-tutor-trainer' => ['tutor', 'tutoring', 'trainer', 'training', 'courses', 'lessons', 'teacher', 'online classes'],
        'church' => ['church', 'ministry', 'congregation', 'pastor', 'parish', 'mosque', 'temple', 'tithes'],
        'ngo' => ['ngo', 'charity', 'nonprofit', 'non-profit', 'foundation', 'trust', 'donors', 'grants'],
        'event-organiser' => ['events', 'event', 'weddings', 'concerts', 'conference', 'tickets', 'festival', 'planner'],
        'salon-spa-barber' => ['salon', 'hair', 'hairdresser', 'braids', 'barber', 'barbershop', 'spa', 'nails', 'beauty', 'massage'],
        'gym' => ['gym', 'fitness', 'yoga', 'pilates', 'crossfit', 'personal trainer', 'members'],
        'electrician-plumber-technician' => ['electrician', 'plumber', 'technician', 'repairs', 'handyman', 'solar installer', 'aircon', 'maintenance'],
        'garage-mechanic' => ['garage', 'mechanic', 'workshop', 'car repairs', 'panel beater', 'tyres', 'car sales'],
        'security-company' => ['security', 'guards', 'guarding', 'patrol', 'alarm'],
        'landlord-estate-agent' => ['landlord', 'rentals', 'rent', 'tenants', 'estate agent', 'property', 'real estate', 'flats'],
        'construction-contractor' => ['construction', 'contractor', 'builder', 'building', 'civil', 'engineering', 'renovations'],
        'transport-fleet-owner' => ['transport', 'fleet', 'trucks', 'haulage', 'logistics', 'delivery', 'courier', 'kombi'],
        'bus-company' => ['bus', 'buses', 'coach', 'intercity', 'routes'],
        'taxi-ride-owner' => ['taxi', 'cab', 'ride', 'uber', 'drivers'],
        'farmer-cooperative' => ['farm', 'farmer', 'farming', 'agriculture', 'crops', 'cattle', 'poultry', 'chickens', 'dairy', 'cooperative', 'tobacco', 'maize'],
        'manufacturer' => ['manufacturer', 'manufacturing', 'factory', 'production', 'plant', 'assembly'],
        'isp-wifi-business' => ['isp', 'wifi', 'internet', 'hotspot', 'broadband', 'fibre'],
        'government-department-council' => ['government', 'council', 'municipality', 'ministry', 'department', 'public sector', 'parastatal'],
        'individual' => ['personal', 'individual', 'myself', 'family', 'household', 'budget'],
    ];

    public function run(): void
    {
        $byRef = Module::all()->keyBy('ref');

        foreach ($this->professions as $i => [$name, $group, $icon, $refs, $featured]) {
            preg_match_all('/\d+\.\d+/', $refs, $m);
            $keys = collect($m[0])->map(fn ($ref) => $byRef[$ref]->key ?? null)->filter()->unique()->values()->all();

            Profession::updateOrCreate(['key' => Str::slug($name)], [
                'name' => $name,
                'group' => $group,
                'icon' => $icon,
                'module_keys' => $keys,
                'keywords' => $this->keywords[Str::slug($name)] ?? [],
                'is_featured' => $featured,
                'sort_order' => $i,
                'description' => null,
            ]);
        }
    }
}
