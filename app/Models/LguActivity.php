<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LguActivity extends Model
{
    protected $fillable = [
        'title',
        'description',
        'category',
        'date',
        'time',
        'location',
        'status',
        'organizer',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    /**
     * Scope query to filter by month and year.
     *
     * @param  Builder  $query
     * @param  int|string|null  $month
     * @param  int|string|null  $year
     * @return Builder
     */
    public function scopeForMonth($query, $month = null, $year = null)
    {
        $month = $month ?: now()->month;
        $year = $year ?: now()->year;

        return $query->whereYear('date', $year)
            ->whereMonth('date', $month);
    }
}
