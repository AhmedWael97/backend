<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\DomainExclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainExclusion>
 */
class DomainExclusionFactory extends Factory
{
    protected $model = DomainExclusion::class;

    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'type' => fake()->randomElement(['ip', 'cookie', 'user_agent']),
            'value' => fake()->ipv4(),
        ];
    }
}
