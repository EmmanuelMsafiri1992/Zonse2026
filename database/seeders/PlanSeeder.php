<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'key' => 'free', 'name' => 'Free', 'tagline' => 'For getting started',
                'description' => 'Invoicing, appointments and tasks for one person.',
                'price_monthly' => 0, 'price_yearly' => 0, 'trial_days' => 0, 'includes_all_modules' => false,
                'limits' => ['users' => 1, 'branches' => 1, 'modules' => 4, 'storage_gb' => 1, 'extras' => ['Community support']],
                'modules' => ['invoicing', 'appointments', 'tasks', 'quotes'],
                'is_featured' => false, 'sort_order' => 1,
            ],
            [
                'key' => 'solo', 'name' => 'Solo', 'tagline' => 'For freelancers & sole traders',
                'description' => 'Any 6 apps, one user, unlimited records.',
                'price_monthly' => 9, 'price_yearly' => 90, 'trial_days' => 14, 'includes_all_modules' => true,
                'limits' => ['users' => 1, 'branches' => 1, 'modules' => 6, 'storage_gb' => 5, 'extras' => ['Email support', 'Customer portal']],
                'modules' => [],
                'is_featured' => false, 'sort_order' => 2,
            ],
            [
                'key' => 'business', 'name' => 'Business', 'tagline' => 'For growing teams',
                'description' => 'Every app, up to 10 team members and 3 branches.',
                'price_monthly' => 29, 'price_yearly' => 290, 'trial_days' => 14, 'includes_all_modules' => true,
                'limits' => ['users' => 10, 'branches' => 3, 'modules' => -1, 'storage_gb' => 50, 'extras' => ['Priority support', 'Workflow automation', 'API access']],
                'modules' => [],
                'is_featured' => true, 'sort_order' => 3,
            ],
            [
                'key' => 'enterprise', 'name' => 'Enterprise', 'tagline' => 'For organisations & multi-branch',
                'description' => 'Unlimited everything, white-label and dedicated support.',
                'price_monthly' => 99, 'price_yearly' => 990, 'trial_days' => 14, 'includes_all_modules' => true,
                'limits' => ['users' => -1, 'branches' => -1, 'modules' => -1, 'storage_gb' => 500, 'extras' => ['Dedicated support', 'White-label & custom domain', 'SSO & audit exports', 'Onboarding assistance']],
                'modules' => [],
                'is_featured' => false, 'sort_order' => 4,
            ],
        ];

        foreach ($plans as $data) {
            $moduleKeys = $data['modules'];
            unset($data['modules']);

            $plan = Plan::updateOrCreate(['key' => $data['key']], $data + ['currency' => 'USD', 'is_active' => true]);
            $plan->modules()->sync(Module::whereIn('key', $moduleKeys)->pluck('id'));
        }
    }
}
