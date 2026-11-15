<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Design pass plan ruling 4: the company logo for the sidebar, the portal header and the sign-in pages. Public (sign-in
 * pages are for guests; a logo is public branding). The path comes only from company settings, never from the request.
 */
class BrandLogoController
{
    public function __invoke(): StreamedResponse
    {
        $path = CompanySetting::query()->whereKey(1)->value('logo_path');
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
