<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LguActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LguActivityController extends Controller
{
    /**
     * Get list of LGU activities for the specified or current month & year.
     */
    public function index(Request $request): JsonResponse
    {
        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        $date = Carbon::createFromDate($year, $month, 1);

        $activities = LguActivity::forMonth($month, $year)
            ->orderBy('date', 'asc')
            ->get()
            ->map(function (LguActivity $activity) {
                $carbonDate = Carbon::parse($activity->date);

                return [
                    'id' => $activity->id,
                    'title' => $activity->title,
                    'description' => $activity->description,
                    'category' => $activity->category,
                    'date' => $carbonDate->format('Y-m-d'),
                    'day' => $carbonDate->format('d'),
                    'month_short' => strtoupper($carbonDate->format('M')),
                    'day_name' => $carbonDate->format('D'),
                    'formatted_date' => $carbonDate->format('M d, Y'),
                    'time' => $activity->time,
                    'location' => $activity->location,
                    'status' => $activity->status,
                    'organizer' => $activity->organizer,
                ];
            });

        return response()->json([
            'status' => 'success',
            'month' => (int) $month,
            'year' => (int) $year,
            'month_name' => $date->format('F'),
            'month_year' => $date->format('F Y'),
            'total' => $activities->count(),
            'data' => $activities,
        ]);
    }
}
