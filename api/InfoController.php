<?php

namespace App\Http\Controllers;

use App\Support\Waktu;
use Illuminate\Http\JsonResponse;

/* [MODUL 6] GET /ping: memastikan server hidup. Terbuka tanpa token. */
class InfoController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['data' => [
            'status' => 'ok',
            'service' => 'Disty HelpDesk API',
            'laravel' => app()->version(),
            'time' => Waktu::iso(Waktu::sekarang()),
        ]]);
    }
}
