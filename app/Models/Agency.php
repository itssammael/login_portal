<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Agency extends Model
{
    use HasFactory, SoftDeletes;

    public const CACHE_KEY = 'feedback:agencies:list';

    protected $table = 'agencies';

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
     * Get the cached list of active agencies.
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
     * Find or create an agency and return its name.
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

        $agency = static::createOrFirst(
            ['name' => $trimmed],
            ['created_by' => $createdBy]
        );

        return $agency->name;
    }

    /**
     * The user who created the agency.
     *
     * @return BelongsTo<User, Agency>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
