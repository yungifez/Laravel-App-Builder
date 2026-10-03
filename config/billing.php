<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Each plan includes an amount of AI use every month, counted in what the
    | AI calls for the owner's apps cost us ("monthly_usd"). Owners see it as
    | a share of their plan, never in dollars. A month starts on the day the
    | owner subscribed, or signed up when they have no subscription.
    |
    | A paid plan opens for sign-up once "stripe_price" holds its Stripe
    | price ID. "price" is what the pricing page shows, in whole dollars a
    | month. The first plan is the one every owner without a subscription is
    | on.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'stripe_price' => null,
            'monthly_usd' => (float) env('BILLING_FREE_MONTHLY_USD', 5),
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => (int) env('BILLING_PRO_PRICE', 25),
            'stripe_price' => env('BILLING_PRO_STRIPE_PRICE'),
            'monthly_usd' => (float) env('BILLING_PRO_MONTHLY_USD', 15),
        ],
        'max' => [
            'name' => 'Max',
            'price' => (int) env('BILLING_MAX_PRICE', 100),
            'stripe_price' => env('BILLING_MAX_STRIPE_PRICE'),
            'monthly_usd' => (float) env('BILLING_MAX_MONTHLY_USD', 70),
        ],
    ],

];
