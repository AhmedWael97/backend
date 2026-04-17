<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Free', 'Starter', 'Pro', 'Business', 'Enterprise']);

        return [
            'name' => $name,
            'name_ar' => $name,
            'slug' => strtolower($name),
            'description' => fake()->sentence(),
            'price_monthly' => fake()->randomElement([0, 9, 29, 99]),
            'price_yearly' => fake()->randomElement([0, 90, 290, 990]),
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 1,
            'features' => [],
            'limits' => [
                'domains' => 1,
                'events_per_day' => 10000,
                'retention_days' => 30,
                'team_members' => 1,
            ],
        ];
    }
}
