<?php

namespace Database\Factories;

use App\Models\NodeOperator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeOperator>
 */
class NodeOperatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'local-test-operator-password',
            'is_active' => true,
        ];
    }
}
