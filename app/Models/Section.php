<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sections';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'section_contact_person_name',
        'section_contact_person_position',
        'section_phone_extension',
        'section_email',
        'section_address',
    ];

    /**
     * The department that this section belongs to.
     *
     * @return BelongsTo<Department, Section>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The employees (users) belonging to this section.
     *
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Alias for users relationship.
     *
     * @return HasMany<User>
     */
    public function employees(): HasMany
    {
        return $this->users();
    }
}
