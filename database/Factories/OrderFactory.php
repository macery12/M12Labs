<?php

namespace Database\Factories;

use Everest\Models\Billing\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'user_id' => 1,
            'description' => $this->faker->sentence(),
            'total' => $this->faker->randomFloat(2, 5, 100),
            'status' => Order::STATUS_PENDING,
            'product_id' => 1,
            'egg_id' => null,
            'type' => Order::TYPE_NEW,
            'threat_index' => 0,
            'payment_intent_id' => 'pi_' . $this->faker->unique()->uuid(),
            'coupon_id' => null,
            'subtotal' => null,
            'discount' => null,
        ];
    }

    /**
     * Indicate that the order is expired.
     */
    public function expired(): static
    {
        return $this->state(['status' => Order::STATUS_EXPIRED]);
    }

    /**
     * Indicate that the order is processed.
     */
    public function processed(): static
    {
        return $this->state(['status' => Order::STATUS_PROCESSED]);
    }

    /**
     * Indicate that the order has failed.
     */
    public function failed(): static
    {
        return $this->state(['status' => Order::STATUS_FAILED]);
    }
}
