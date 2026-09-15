<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Department extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'departments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'acronym',
        'contact_person_name',
        'contact_person_position',
        'department_phone_extension',
        'email',
        'department_address',
    ];

    /**
     * The sections that belong to this department.
     *
     * @return HasMany<Section>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * The employees (users) in this department through its sections.
     *
     * @return HasManyThrough<User>
     */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, Section::class);
    }

    /**
     * Alias for users relationship.
     *
     * @return HasManyThrough<User>
     */
    public function employees(): HasManyThrough
    {
        return $this->users();
    }
}
