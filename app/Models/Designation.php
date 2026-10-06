<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Designation extends Model
{
    use HasFactory, SoftDeletes;

    public const CACHE_KEY = 'feedback:designations:list';

    protected $table = 'designations';

    protected $fillable = [
        'name',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(function (): void {
            DB::afterCommit(function (): void {
                Cache::forget(self::CACHE_KEY);
            });
        });

        static::deleted(function (): void {
            DB::afterCommit(function (): void {
                Cache::forget(self::CACHE_KEY);
            });
        });

        static::restored(function (): void {
            DB::afterCommit(function (): void {
                Cache::forget(self::CACHE_KEY);
            });
        });
    }

    /**
     * Get the cached list of active designations.
     *
     * @return array<int, string>
     */
    public static function getCachedList(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return static::query()
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all();
        });
    }

    /**
     * Find or create a designation and return its name.
     */
    public static function findOrCreateByName(?string $name, ?int $createdBy = null): ?string
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            return null;
        }

        $existing = static::where('name', $trimmed)->first();
        if ($existing) {
            return $existing->name;
        }

        $designation = static::createOrFirst(
            ['name' => $trimmed],
            ['created_by' => $createdBy]
        );

        return $designation->name;
    }

    /**
     * The user who created the designation.
     *
     * @return BelongsTo<User, Designation>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
