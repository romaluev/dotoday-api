<?php

namespace App\Models;

use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use DateTimeInterface;
use App\Models\User;
use App\Enums\TaskPriority;

class Task extends Model
{
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'is_completed',
        'due_date',
        'priority'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'due_date' => 'datetime',
        'is_completed' => 'boolean',
        'priority' => TaskPriority::class
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_completed' => false,
        'priority' => 'low'
    ];

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Get the user that owns the task.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias for user() relationship for better readability in API responses.
     */
    public function author(): BelongsTo
    {
        return $this->user();
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'is_completed' => $this->is_completed,
            'user_id' => $this->user_id,
            'priority' => $this->priority->value,
            'due_date' => $this->due_date?->timestamp,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    /**
     * Scope a query to filter tasks by completion status.
     */
    public function scopeCompleted(Builder $query, bool $isCompleted): Builder
    {
        return $query->where('is_completed', $isCompleted);
    }

    /**
     * Scope a query to filter tasks by priority.
     */
    public function scopePriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to filter tasks by due date.
     */
    public function scopeDueDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('due_date', $date);
    }

    /**
     * Scope a query to get overdue tasks.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('is_completed', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now());
    }

    /**
     * Scope a query to get upcoming tasks.
     */
    public function scopeUpcoming(Builder $query, int $days = 7): Builder
    {
        return $query
            ->where('is_completed', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', now())
            ->whereDate('due_date', '<=', now()->addDays($days));
    }
}
