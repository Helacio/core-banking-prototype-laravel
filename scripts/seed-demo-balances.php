<?php

use App\Domain\Account\Aggregates\AssetTransactionAggregate;
use App\Domain\Account\Models\Account;

$amount = 20000;

foreach (Account::all() as $account) {
    AssetTransactionAggregate::retrieve($account->uuid)
        ->credit('EUR', $amount)
        ->persist();

    echo "Seeded {$account->uuid} with {$amount} EUR minor units\n";
}
