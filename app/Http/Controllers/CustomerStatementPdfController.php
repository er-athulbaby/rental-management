<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Billing\CustomerStatement;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class CustomerStatementPdfController
{
    public function __invoke(Request $request, Customer $customer): Response
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && Customer::visibleTo($user)->whereKey($customer->id)->exists(), 403);
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        Audit::log('customer.statement_exported', $customer, properties: $range, causer: $user);

        return response(CustomerStatement::pdf($customer, $range['from'], $range['to']), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement-'.$customer->id.'.pdf"']);
    }
}
