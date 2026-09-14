<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoCity;
use App\Models\GeoState;

class GeoController extends Controller
{
    public function locations()
    {
        return response()->json([
            'states' => GeoState::query()->orderBy('id')->get(['id', 'title', 'slug']),
            'cities' => GeoCity::query()->orderBy('title')->get(['id', 'state_id', 'title', 'sub_title', 'slug']),
        ]);
    }
}
