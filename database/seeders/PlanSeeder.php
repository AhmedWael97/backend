<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'name_ar' => 'مجاني',
                'slug' => 'free',
                'description' => 'Perfect for personal projects and small sites.',
                'description_ar' => 'مثالي للمشاريع الشخصية والمواقع الصغيرة.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 1,
                'features' => [
                    'realtime_dashboard' => true,
                    'email_reports' => false,
                    'ai_insights' => false,
                    'session_replay' => false,
                    'company_enrichment' => false,
                    'custom_pipelines' => false,
                    'api_access' => false,
                    'white_label' => false,
                ],
                'limits' => [
                    'domains' => 1,
                    'events_per_day' => 10000,
                    'events_per_month' => 10000,
                    'retention_days' => 30,
                    'team_members' => 1,
                    'webhooks' => 0,
                    'export_jobs' => 0,
                    'ai_reports_per_month' => 0,
                ],
            ],
            [
                'name' => 'Pro',
                'name_ar' => 'احترافي',
                'slug' => 'pro',
                'description' => 'For growing teams that need more power.',
                'description_ar' => 'للفرق المتنامية التي تحتاج إلى مزيد من القوة.',
                'price_monthly' => 29,
                'price_yearly' => 290,
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 2,
                'features' => [
                    'realtime_dashboard' => true,
                    'email_reports' => true,
                    'ai_insights' => true,
                    'session_replay' => true,
                    'company_enrichment' => false,
                    'custom_pipelines' => true,
                    'api_access' => true,
                    'white_label' => false,
                ],
                'limits' => [
                    'domains' => 5,
                    'events_per_day' => 100000,
                    'events_per_month' => 100000,
                    'retention_days' => 90,
                    'team_members' => 5,
                    'webhooks' => 5,
                    'export_jobs' => 50,
                    'ai_reports_per_month' => 20,
                ],
            ],
            [
                'name' => 'Business',
                'name_ar' => 'أعمال',
                'slug' => 'business',
                'description' => 'Unlimited scale with enterprise-grade features.',
                'description_ar' => 'حجم غير محدود مع ميزات على مستوى المؤسسات.',
                'price_monthly' => 99,
                'price_yearly' => 990,
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 3,
                'features' => [
                    'realtime_dashboard' => true,
                    'email_reports' => true,
                    'ai_insights' => true,
                    'session_replay' => true,
                    'company_enrichment' => true,
                    'custom_pipelines' => true,
                    'api_access' => true,
                    'white_label' => true,
                ],
                'limits' => [
                    // Business is fully unlimited — every limit is -1 (no cap).
                    'domains' => -1,
                    'events_per_day' => -1,
                    'events_per_month' => -1,
                    'retention_days' => -1,
                    'team_members' => -1,
                    'webhooks' => -1,
                    'export_jobs' => -1,
                    'ai_reports_per_month' => -1,
                ],
            ],
        ];

        $plans[] = [
            'name' => 'Agency',
            'name_ar' => 'وكالة',
            'slug' => 'agency',
            'description' => 'For marketing agencies managing multiple client websites with a team.',
            'description_ar' => 'لوكالات التسويق التي تدير مواقع عملاء متعددة مع فريق.',
            'price_monthly' => 0, // free agency plan (per product decision)
            'price_yearly' => 0,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 4,
            'features' => [
                'realtime_dashboard' => true,
                'email_reports' => true,
                'ai_insights' => true,
                'session_replay' => true,
                'company_enrichment' => true,
                'custom_pipelines' => true,
                'api_access' => true,
                'white_label' => true,
                'team_accounts' => true, // multi-seat org with per-member domain access
            ],
            'limits' => [
                'domains' => 5,            // up to 5 client domains
                'events_per_day' => 200000,
                'events_per_month' => 200000,
                'retention_days' => 90,
                'team_members' => 10,      // up to 10 employee accounts (seats)
                'webhooks' => 10,
                'export_jobs' => 100,
                'ai_reports_per_month' => 50,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
