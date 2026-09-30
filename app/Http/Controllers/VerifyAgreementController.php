<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use Illuminate\Http\Response;

/** Spec §9.4: public, rate-limited, noindex; shows only number, status and dates. */
final class VerifyAgreementController
{
    public function __invoke(string $token): Response
    {
        $agreement = Agreement::query()->where('verify_token', $token)->firstOrFail();

        return response()
            ->view('verify', [
                'number' => $agreement->number,
                'status' => $agreement->status->label(),
                'start' => $agreement->start_date->format('d/m/Y'),
                'end' => $agreement->end_date->format('d/m/Y'),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
