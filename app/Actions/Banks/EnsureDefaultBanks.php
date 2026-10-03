<?php

namespace App\Actions\Banks;

use App\Models\Bank;

/** A new install starts with the banks operating in Bahrain; the Admin adds, renames or switches them off. Safe to run twice. */
final class EnsureDefaultBanks
{
    public const array DEFAULTS = [
        'National Bank of Bahrain (NBB)', 'Bank of Bahrain and Kuwait (BBK)', 'Kuwait Finance House Bahrain', 'Ahli United Bank',
        'Bahrain Islamic Bank (BisB)', 'Al Salam Bank', 'Khaleeji Bank', 'Ithmaar Bank', 'ila Bank', 'Al Baraka Islamic Bank',
        'National Bank of Kuwait (Bahrain)', 'Arab Bank', 'Standard Chartered Bank Bahrain', 'HSBC Bank Middle East (Bahrain)',
        'Citibank Bahrain', 'State Bank of India (Bahrain)', 'Habib Bank Limited', 'United Bank Limited', 'Bank of Baroda',
        'First Abu Dhabi Bank (Bahrain)', 'Emirates NBD (Bahrain)', 'Mashreq (Bahrain)',
    ];

    public function __invoke(): void
    {
        foreach (self::DEFAULTS as $name) {
            Bank::query()->firstOrCreate(['name' => $name]);
        }
    }
}
